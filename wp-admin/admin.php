<?php
/**
 * WordPress Administration Bootstrap
 *
 * @package WordPress
 * @subpackage Administration
 */
 
/*
本文件可被别的后台管理文件(如wordpress系统的edit.php, index.php,...)include, 也可以被browser直接访问(通常是从menu中hook进来的)做一些事

1. http://mysite.com/wp-admin/index.php  (或edit.php,...), 
在index.php中可以include本文件, 主要是:准备menu 数据, 实例化screen, 路过而已

2. http://mysite.com/wp-admin/?page=myplugin
也可以直接访问本文件, 见menu-header.php中<a href='admin.php?page={$submenu_items[0][2]}, 这里就是终点不是路过

为什么访问后台的菜单函数或者是edit.php之类的文件都要从这里过?
*/

/**
 * In WordPress Administration Screens
 *
 * @since 2.3.2
 */
if ( ! defined( 'WP_ADMIN' ) ) {
	define( 'WP_ADMIN', true );
}

if ( ! defined('WP_NETWORK_ADMIN') )
	define('WP_NETWORK_ADMIN', false);

if ( ! defined('WP_USER_ADMIN') )
	define('WP_USER_ADMIN', false);

if ( ! WP_NETWORK_ADMIN && ! WP_USER_ADMIN ) {
	define('WP_BLOG_ADMIN', true);
}

if ( isset($_GET['import']) && !defined('WP_LOAD_IMPORTERS') )
	define('WP_LOAD_IMPORTERS', true);


/*
加载插件, 初始化$wp, 
wp-load.php这个文件是前后台都要include的
*/
require_once(dirname(dirname(__FILE__)) . '/wp-load.php');

nocache_headers();

if ( get_option('db_upgraded') ) {
	flush_rewrite_rules();
	update_option( 'db_upgraded',  false );

	/**
	 * Fires on the next page load after a successful DB upgrade.
	 *
	 * @since 2.8.0
	 */
	do_action( 'after_db_upgrade' );
} elseif ( get_option('db_version') != $wp_db_version && empty($_POST) ) {
	if ( !is_multisite() ) {
		wp_redirect( admin_url( 'upgrade.php?_wp_http_referer=' . urlencode( wp_unslash( $_SERVER['REQUEST_URI'] ) ) ) );
		exit;

	/**
	 * Filter whether to attempt to perform the multisite DB upgrade routine.
	 *
	 * In single site, the user would be redirected to wp-admin/upgrade.php.
	 * In multisite, the DB upgrade routine is automatically fired, but only
	 * when this filter returns true.
	 *
	 * If the network is 50 sites or less, it will run every time. Otherwise,
	 * it will throttle itself to reduce load.
	 *
	 * @since 3.0.0
	 *
	 * @param bool $do_mu_upgrade Whether to perform the Multisite upgrade routine. Default true.
	 */
	} elseif ( apply_filters( 'do_mu_upgrade', true ) ) {
		$c = get_blog_count();

		/*
		 * If there are 50 or fewer sites, run every time. Otherwise, throttle to reduce load:
		 * attempt to do no more than threshold value, with some +/- allowed.
		 */
		if ( $c <= 50 || ( $c > 50 && mt_rand( 0, (int)( $c / 50 ) ) == 1 ) ) {
			require_once( ABSPATH . WPINC . '/http.php' );
			$response = wp_remote_get( admin_url( 'upgrade.php?step=1' ), array( 'timeout' => 120, 'httpversion' => '1.1' ) );
			/** This action is documented in wp-admin/network/upgrade.php */
			do_action( 'after_mu_upgrade', $response );
			unset($response);
		}
		unset($c);
	}
}

// include一堆文件
require_once(ABSPATH . 'wp-admin/includes/admin.php');

// 如未login, 先跳转到login页
auth_redirect();

// Schedule trash collection
if ( ! wp_next_scheduled( 'wp_scheduled_delete' ) && ! wp_installing() )
	wp_schedule_event(time(), 'daily', 'wp_scheduled_delete');

/*** 
这是个处理函数, 如果用户按了'提交屏幕选项' 按钮, 将参数保存到db中
每个后台用户都可以有自己的偏好设置, 如果有修改, 就更新它 
*/
set_screen_options();

$date_format = __( 'F j, Y' );
$time_format = __( 'g:i a' );

/***
加载使用common$suffix.js, 这里只是登记
$scripts->add( 'common', "/wp-admin/js/common$suffix.js", array('jquery', 'hoverIntent', 'utils'), false, 1 );

js什么时候输出到html中?
在模板文件中遇到wp_head() tag时会触发输出一系列东西,其中就包括js脚本
*/
wp_enqueue_script( 'common' );




/**
 * $pagenow is set in vars.php
 * $wp_importers is sometimes set in wp-admin/includes/import.php
 * The remaining variables are imported as globals elsewhere, declared as globals here
 *
 * @global string $pagenow
 * @global array  $wp_importers
 * @global string $hook_suffix
 * @global string $plugin_page
 * @global string $typenow
 * @global string $taxnow
 */
 /*** 
 它们的区别? 
 $pagenow: 指的是运行的脚本文件名?
 $plugin_page: 
 $...now: 参数中传入的now系列变量
 
 */
global $pagenow, $wp_importers, $hook_suffix, $plugin_page, $typenow, $taxnow;

$page_hook = null;

$editing = false;

/***
$_GET['page']参数是怎么来的? 
来源于menu slug, 见menu-header.php中
*/
if ( isset($_GET['page']) ) {
	$plugin_page = wp_unslash( $_GET['page'] );
	$plugin_page = plugin_basename($plugin_page);
}

/*** $typenow, $taxnow作用? */
if ( isset( $_REQUEST['post_type'] ) && post_type_exists( $_REQUEST['post_type'] ) )
	$typenow = $_REQUEST['post_type'];
else
	$typenow = '';

if ( isset( $_REQUEST['taxonomy'] ) && taxonomy_exists( $_REQUEST['taxonomy'] ) )
	$taxnow = $_REQUEST['taxonomy'];
else
	$taxnow = '';

if ( WP_NETWORK_ADMIN )
	// 超级用户的左侧菜单数据与普通管理员的不一样
	require(ABSPATH . 'wp-admin/network/menu.php');
elseif ( WP_USER_ADMIN )
	// 顶部user profile menu? 不像，好象也是左侧菜单
	require(ABSPATH . 'wp-admin/user/menu.php');
else
	// 准备左侧主菜单数据结构, 暂不echo
	require(ABSPATH . 'wp-admin/menu.php');

if ( current_user_can( 'manage_options' ) ) {
	/**
	 * Filter the maximum memory limit available for administration screens.
	 *
	 * This only applies to administrators, who may require more memory for tasks like updates.
	 * Memory limits when processing images (uploaded or edited by users of any role) are
	 * handled separately.
	 *
	 * The WP_MAX_MEMORY_LIMIT constant specifically defines the maximum memory limit available
	 * when in the administration back end. The default is 256M, or 256 megabytes of memory.
	 *
	 * @since 3.0.0
	 *
	 * @param string 'WP_MAX_MEMORY_LIMIT' The maximum WordPress memory limit. Default 256M.
	 */
	@ini_set( 'memory_limit', apply_filters( 'admin_memory_limit', WP_MAX_MEMORY_LIMIT ) );
}

/**
 * Fires as an admin screen or script is being initialized.
 *
 * Note, this does not just run on user-facing admin screens.
 * It runs on admin-ajax.php and admin-post.php as well.
 *
 * This is roughly analogous to the more general 'init' hook, which fires earlier.
 *
 * @since 2.5.0
 */
 /* 
 此时已经do_action( 'admin_menu')了
 
 */
do_action( 'admin_init' );
// $plugin_page = 'prowp_main_menu_slug'
// $pagenow = 'admin.php'
if ( isset($plugin_page) ) {
	if ( !empty($typenow) )
		$the_parent = $pagenow . '?post_type=' . $typenow;
	else
		$the_parent = $pagenow;

	// $page_hook = 'toplevel_page_prowp_main_menu_slug'
	if ( ! $page_hook = get_plugin_page_hook($plugin_page, $the_parent) ) {
		/*** 什么时候进到这里面来? */
		$page_hook = get_plugin_page_hook($plugin_page, $plugin_page);

		// Backwards compatibility for plugins using add_management_page().
		if ( empty( $page_hook ) && 'edit.php' == $pagenow && '' != get_plugin_page_hook($plugin_page, 'tools.php') ) {
			// There could be plugin specific params on the URL, so we need the whole query string
			if ( !empty($_SERVER[ 'QUERY_STRING' ]) )
				$query_string = $_SERVER[ 'QUERY_STRING' ];
			else
				$query_string = 'page=' . $plugin_page;
			wp_redirect( admin_url('tools.php?' . $query_string) );
			exit;
		}
	}
	unset($the_parent);
}

/*** 3种方式进来: 勾子, 插件, 脚本文件? */
$hook_suffix = '';
if ( isset( $page_hook ) ) {
	$hook_suffix = $page_hook;
} elseif ( isset( $plugin_page ) ) {
	$hook_suffix = $plugin_page;
} elseif ( isset( $pagenow ) ) {
	$hook_suffix = $pagenow;
}

/***
为当前用户当前页面实例化一个screen 对象
*/
set_current_screen();

// Handle plugin admin pages.
/* 如果是插件设置管理页面? */
if ( isset($plugin_page) ) {
	/* 按顺序显示admin-header、中部、admin-footer*/

	if ( $page_hook ) {
		/**
		 * Fires before a particular screen is loaded.
		 *
		 * The load-* hook fires in a number of contexts. This hook is for plugin screens
		 * where a callback is provided when the screen is registered.
		 *
		 * The dynamic portion of the hook name, `$page_hook`, refers to a mixture of plugin
		 * page information including:
		 * 1. The page type. If the plugin page is registered as a submenu page, such as for
		 *    Settings, the page type would be 'settings'. Otherwise the type is 'toplevel'.
		 * 2. A separator of '_page_'.
		 * 3. The plugin basename minus the file extension.
		 *
		 * Together, the three parts form the `$page_hook`. Citing the example above,
		 * the hook name used would be 'load-settings_page_pluginbasename'.
		 *
		 * @see get_plugin_page_hook()
		 *
		 * @since 2.1.0
		 */

		/*
		什么时候会使用这个hook? 
		比如以下增加一个menu时, 除了页面HTML片断外, 还希望能在加载插件页时,做点别的事,
		如设置屏幕option,
		$hook = add_menu_page( $pg_title, $menu_title, $cap, $slug, $function );
		add_action( "load-$hook", 'add_some_screen_option' );

		执行菜单中的函数之前
		*/
		do_action( 'load-' . $page_hook );
		
		if (! isset($_GET['noheader']))
			require_once(ABSPATH . 'wp-admin/admin-header.php');

		/**
		 * Used to call the registered callback for a plugin screen.
		 *
		 * @ignore
		 * @since 1.5.0
		 */
		 
		 /* 
                开始执行菜单中的函数		 
		 1. 执行menu中所定义的function, 这里$page_hook like 'toplevel_page_prowp_main_menu_slug' 
		 2. 展示插件设置页
		 */
		do_action( $page_hook );	
		
	} else {
		/* 
		如果定义menu结构时没有指定function, 就include menu中的slug文件(当前插件目录下)? 
		这种文件有点像wordress自带的edit.php, index.php, 只是是放在插件目录下?
		*/
		if ( validate_file($plugin_page) )
			wp_die(__('Invalid plugin page'));

		if ( !( file_exists(WP_PLUGIN_DIR . "/$plugin_page") && is_file(WP_PLUGIN_DIR . "/$plugin_page") ) && !( file_exists(WPMU_PLUGIN_DIR . "/$plugin_page") && is_file(WPMU_PLUGIN_DIR . "/$plugin_page") ) )
			wp_die(sprintf(__('Cannot load %s.'), htmlentities($plugin_page)));

		/**
		 * Fires before a particular screen is loaded.
		 *
		 * The load-* hook fires in a number of contexts. This hook is for plugin screens
		 * where the file to load is directly included, rather than the use of a function.
		 *
		 * The dynamic portion of the hook name, `$plugin_page`, refers to the plugin basename.
		 *
		 * @see plugin_basename()
		 *
		 * @since 1.5.0
		 */
		do_action( 'load-' . $plugin_page );

		if ( !isset($_GET['noheader']))
			require_once(ABSPATH . 'wp-admin/admin-header.php');

		if ( file_exists(WPMU_PLUGIN_DIR . "/$plugin_page") )
			include(WPMU_PLUGIN_DIR . "/$plugin_page");
		else
			include(WP_PLUGIN_DIR . "/$plugin_page");
	}

	/*** 脚部已经放在这里了, 前面的$plugin_page插件文件中就不要加上去了 */
	include(ABSPATH . 'wp-admin/admin-footer.php');

	exit();
} elseif ( isset( $_GET['import'] ) ) {
	/* 何时进到这里? */
	$importer = $_GET['import'];

	if ( ! current_user_can('import') )
		wp_die(__('You are not allowed to import.'));

	if ( validate_file($importer) ) {
		wp_redirect( admin_url( 'import.php?invalid=' . $importer ) );
		exit;
	}

	if ( ! isset($wp_importers[$importer]) || ! is_callable($wp_importers[$importer][2]) ) {
		wp_redirect( admin_url( 'import.php?invalid=' . $importer ) );
		exit;
	}

	/**
	 * Fires before an importer screen is loaded.
	 *
	 * The dynamic portion of the hook name, `$importer`, refers to the importer slug.
	 *
	 * @since 3.5.0
	 */
	do_action( 'load-importer-' . $importer );

	$parent_file = 'tools.php';
	$submenu_file = 'import.php';
	$title = __('Import');

	if (! isset($_GET['noheader']))
		require_once(ABSPATH . 'wp-admin/admin-header.php');

	require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

	define('WP_IMPORTING', true);

	/**
	 * Whether to filter imported data through kses on import.
	 *
	 * Multisite uses this hook to filter all data through kses by default,
	 * as a super administrator may be assisting an untrusted user.
	 *
	 * @since 3.1.0
	 *
	 * @param bool $force Whether to force data to be filtered through kses. Default false.
	 */
	if ( apply_filters( 'force_filtered_html_on_import', false ) ) {
		kses_init_filters();  // Always filter imported data with kses on multisite.
	}

	call_user_func($wp_importers[$importer][2]);

	include(ABSPATH . 'wp-admin/admin-footer.php');

	// Make sure rules are flushed
	flush_rewrite_rules(false);

	exit();
} else {
	/* 
	正常点后台管理中的菜单如edit.php(非插件设置页)都会进到这里 
        即执行edit.php时会路过此admin.php
	*/
	/**
	 * Fires before a particular screen is loaded.
	 *
	 * The load-* hook fires in a number of contexts. This hook is for core screens.
	 *
	 * The dynamic portion of the hook name, `$pagenow`, is a global variable
	 * referring to the filename of the current page, such as 'admin.php',
	 * 'post-new.php' etc. A complete hook for the latter would be
	 * 'load-post-new.php'.
	 *
	 * @since 2.1.0
	 */
	 /*
	 $pagenow为当前管理页面, 形如:'edit.php', 'index.php', 'plugins.php', 'edit-comments.php', 'tools.php'
	 在wp-includes/update.php中已add_action( 'load-plugins.php', 'wp_update_plugins' );
	 */
	do_action( 'load-' . $pagenow );
	/*
	 * The following hooks are fired to ensure backward compatibility.
	 * In all other cases, 'load-' . $pagenow should be used instead.
	 */
	if ( $typenow == 'page' ) {
		if ( $pagenow == 'post-new.php' )
			do_action( 'load-page-new.php' );
		elseif ( $pagenow == 'post.php' )
			do_action( 'load-page.php' );
	}  elseif ( $pagenow == 'edit-tags.php' ) {
		if ( $taxnow == 'category' )
			do_action( 'load-categories.php' );
		elseif ( $taxnow == 'link_category' )
			do_action( 'load-edit-link-categories.php' );
	} elseif( 'term.php' === $pagenow ) {
		do_action( 'load-edit-tags.php' );
	}
        /*** 回到edit.php, post-new.php等继续执行, 这里只是路过*/ 
}

/***
$_REQUEST['action']是什么时候赋值的?
*/
if ( ! empty( $_REQUEST['action'] ) ) {
	/**
	 * Fires when an 'action' request variable is sent.
	 *
	 * The dynamic portion of the hook name, `$_REQUEST['action']`,
	 * refers to the action derived from the `GET` or `POST` request.
	 *
	 * @since 2.6.0
	 */
	do_action( 'admin_action_' . $_REQUEST['action'] );
}
