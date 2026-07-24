<?php
/**
 * 「图片预览功能」后台页。
 *
 * @package WPMediaToolkit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 注册菜单并渲染预览设置。
 */
class WPMT_Image_Preview_Page {

	const PAGE_SLUG = 'wpmt-image-preview';

	/**
	 * 注册钩子。
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_init', array( $this, 'handle_save' ) );
	}

	/**
	 * 添加媒体子菜单。
	 *
	 * @return void
	 */
	public function add_menu() {
		add_media_page(
			__( '图片预览功能', 'wp-media-toolkit' ),
			__( '图片预览功能', 'wp-media-toolkit' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * 处理表单保存。
	 *
	 * @return void
	 */
	public function handle_save() {
		if ( ! isset( $_POST['wpmt_preview_settings_nonce'] ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		check_admin_referer( 'wpmt_save_preview_settings', 'wpmt_preview_settings_nonce' );

		$input = isset( $_POST['wpmt_preview'] ) && is_array( $_POST['wpmt_preview'] )
			? wp_unslash( $_POST['wpmt_preview'] )
			: array();
		WPMT_Settings::update_preview( $input );

		add_settings_error(
			'wpmt_preview_settings',
			'wpmt_preview_saved',
			__( '设置已保存。', 'wp-media-toolkit' ),
			'success'
		);
	}

	/**
	 * 渲染页面。
	 *
	 * @return void
	 */
	public function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$settings = WPMT_Settings::get_preview();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( '图片预览功能', 'wp-media-toolkit' ); ?></h1>
			<?php settings_errors( 'wpmt_preview_settings' ); ?>
			<form method="post" action="">
				<?php wp_nonce_field( 'wpmt_save_preview_settings', 'wpmt_preview_settings_nonce' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( '启用', 'wp-media-toolkit' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="wpmt_preview[enabled]" value="1" <?php checked( ! empty( $settings['enabled'] ) ); ?> />
								<?php esc_html_e( '启用图片点击预览', 'wp-media-toolkit' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="wpmt-container-ids"><?php esc_html_e( '容器元素 ID', 'wp-media-toolkit' ); ?></label></th>
						<td>
							<textarea id="wpmt-container-ids" name="wpmt_preview[container_ids]" rows="8" cols="50" class="large-text code"><?php echo esc_textarea( (string) $settings['container_ids'] ); ?></textarea>
							<p class="description">
								<?php esc_html_e( '每行一个元素 ID（无需写 #）。仅这些容器内部的 img 会启用灯箱预览。', 'wp-media-toolkit' ); ?>
							</p>
							<p class="description">
								<?php esc_html_e( '主题或插件也可调用：wpmt_image_preview_init( \'your-id\' );（与列表合并去重）。', 'wp-media-toolkit' ); ?>
							</p>
							<p class="description">
								<?php esc_html_e( '默认排除：data-type="icon"、emoji / wp-smiley 等图标类图片。', 'wp-media-toolkit' ); ?>
							</p>
						</td>
					</tr>
				</table>
				<?php submit_button( __( '保存设置', 'wp-media-toolkit' ) ); ?>
			</form>
		</div>
		<?php
	}
}
