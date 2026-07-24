<?php
/**
 * Plugin Name: WP Media Toolkit
 * Plugin URI: https://wordpress.org/plugins/wp-media-toolkit/
 * Description: 媒体库列表增强、可配置上传策略、Simple Local Avatars 补丁与前台图片预览。
 * Version: 1.0.0
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Author: biancaplus
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: wp-media-toolkit
 * Domain Path: /languages
 *
 * @package WPMediaToolkit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WPMT_VERSION', '1.0.0' );
define( 'WPMT_FILE', __FILE__ );
define( 'WPMT_PATH', plugin_dir_path( __FILE__ ) );
define( 'WPMT_URL', plugin_dir_url( __FILE__ ) );

require_once WPMT_PATH . 'includes/class-settings.php';
require_once WPMT_PATH . 'includes/class-media-usage.php';
require_once WPMT_PATH . 'includes/class-media-enhancements.php';
require_once WPMT_PATH . 'includes/class-upload-policy.php';
require_once WPMT_PATH . 'includes/class-sla-fix.php';
require_once WPMT_PATH . 'includes/class-image-preview.php';
require_once WPMT_PATH . 'includes/admin/class-media-settings-page.php';
require_once WPMT_PATH . 'includes/admin/class-image-preview-page.php';
require_once WPMT_PATH . 'includes/class-plugin.php';

/**
 * 启动插件。
 *
 * @return void
 */
function wpmt_run_plugin() {
	$plugin = new WPMT_Plugin();
	$plugin->run();
}

/**
 * 注册图片预览容器（公共 API）。
 *
 * 可传入元素 ID（如 `content`）或 CSS 选择器（如 `#content` / `.gallery`）。
 * 需在设置中启用「图片预览」后才会输出资产；与后台 ID 列表合并去重。
 *
 * @param string $id_or_selector 元素 ID 或选择器。
 * @return void
 */
function wpmt_image_preview_init( $id_or_selector ) {
	WPMT_Image_Preview::register_selector( $id_or_selector );
}

wpmt_run_plugin();
