<?php
/**
 * 插件主控制器。
 *
 * @package WPMediaToolkit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 组装并启动各模块。
 */
class WPMT_Plugin {

	/**
	 * 注册插件功能。
	 *
	 * @return void
	 */
	public function run() {
		add_action( 'plugins_loaded', array( $this, 'load_textdomain' ) );

		$media_page   = new WPMT_Media_Settings_Page();
		$preview_page = new WPMT_Image_Preview_Page();
		$enhancements = new WPMT_Media_Enhancements();
		$upload       = new WPMT_Upload_Policy();
		$sla          = new WPMT_Sla_Fix();
		$preview      = new WPMT_Image_Preview();

		$media_page->register();
		$preview_page->register();
		$enhancements->register();
		$upload->register();
		$sla->register();
		$preview->register();
	}

	/**
	 * 加载翻译。
	 *
	 * @return void
	 */
	public function load_textdomain() {
		load_plugin_textdomain( 'wp-media-toolkit', false, dirname( plugin_basename( WPMT_FILE ) ) . '/languages' );
	}
}
