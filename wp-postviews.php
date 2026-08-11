<?php
/**
 * Plugin Name: Post Views
 * Plugin URI: https://github.com/Jacky088/post-views
 * Description: 统计并展示文章/页面的浏览次数。
 * Version: 2.0.0
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Author: 木木
 * Author URI: https://blog.huzz.cn/
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: post-views
 *
 * @package Post-Views
 */


// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}


// Version.
define( 'WP_POSTVIEWS_VERSION', '2.0.0' );
define( 'WP_POSTVIEWS_MAIN_FILE', __FILE__ );

// Block WordPress.org update checks for this plugin.
add_filter( 'site_transient_update_plugins', function ( $transient ) {
	$plugin_basename = plugin_basename( __FILE__ );
	if ( isset( $transient->response[ $plugin_basename ] ) ) {
		unset( $transient->response[ $plugin_basename ] );
	}
	return $transient;
} );

// Add settings link on plugins page.
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), function ( $links ) {
	$settings_link = '<a href="' . esc_url( admin_url( 'options-general.php?page=post-views' ) ) . '">' . __( '设置', 'post-views' ) . '</a>';
	// Append settings link after the deactivate link: 停用 | 设置
	$links[] = $settings_link;
	return $links;
} );

// Classes. Required at file load because the activation hook and the option
// accessor are both reached before any action fires.
require_once __DIR__ . '/includes/class-postviews-options.php';
require_once __DIR__ . '/includes/class-postviews-display.php';
require_once __DIR__ . '/includes/class-postviews-query.php';
require_once __DIR__ . '/includes/class-postviews-counter.php';
require_once __DIR__ . '/includes/class-postviews-core.php';
require_once __DIR__ . '/includes/class-postviews-widget.php';
require_once __DIR__ . '/includes/class-postviews-admin.php';
require_once __DIR__ . '/includes/class-postviews-settings.php';
require_once __DIR__ . '/includes/template-tags.php';

PostViews_Options::init();
PostViews_Display::init();
PostViews_Counter::init();
PostViews_Core::init();
PostViews_Admin::init();
PostViews_Settings::init();

add_action(
	'widgets_init',
	function () {
		register_widget( 'PostViews_Widget' );
	}
);

// register_activation_hook() has to be called while the plugin file is being
// loaded, which is why this is here rather than inside a class initialiser.
register_activation_hook( __FILE__, 'postviews_activate' );

/**
 * Seed the options row, on this site or across the network.
 *
 * @param bool $network_wide Whether the plugin is being activated network wide.
 * @return void
 */
function postviews_activate( $network_wide ) {
	if ( is_multisite() && $network_wide ) {
		// wp_get_sites() was removed in WP 5.1. 'number' => 0 lifts
		// WP_Site_Query's default cap of 100 sites.
		$site_ids = get_sites(
			array(
				'fields' => 'ids',
				'number' => 0,
			)
		);

		foreach ( $site_ids as $site_id ) {
			switch_to_blog( (int) $site_id );
			PostViews_Options::install();
			restore_current_blog();
		}

		return;
	}

	PostViews_Options::install();
}
