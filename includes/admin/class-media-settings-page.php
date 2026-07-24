<?php
/**
 * 「媒体库设置增强」后台页。
 *
 * @package WPMediaToolkit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 注册菜单并渲染设置表单。
 */
class WPMT_Media_Settings_Page {

	const PAGE_SLUG = 'wpmt-media-settings';

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
			__( '媒体库设置增强', 'wp-media-toolkit' ),
			__( '媒体库设置增强', 'wp-media-toolkit' ),
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
		if ( ! isset( $_POST['wpmt_media_settings_nonce'] ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		check_admin_referer( 'wpmt_save_media_settings', 'wpmt_media_settings_nonce' );

		$input = isset( $_POST['wpmt_media'] ) && is_array( $_POST['wpmt_media'] )
			? wp_unslash( $_POST['wpmt_media'] )
			: array();
		WPMT_Settings::update_media( $input );

		add_settings_error(
			'wpmt_media_settings',
			'wpmt_media_saved',
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
		$settings = WPMT_Settings::get_media();
		$formats  = WPMT_Settings::available_blocked_formats();
		$blocked  = isset( $settings['blocked_formats'] ) ? $settings['blocked_formats'] : array();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( '媒体库设置增强', 'wp-media-toolkit' ); ?></h1>
			<?php settings_errors( 'wpmt_media_settings' ); ?>
			<form method="post" action="">
				<?php wp_nonce_field( 'wpmt_save_media_settings', 'wpmt_media_settings_nonce' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( '列表增强', 'wp-media-toolkit' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="wpmt_media[enable_list_enhancements]" value="1" <?php checked( ! empty( $settings['enable_list_enhancements'] ) ); ?> />
								<?php esc_html_e( '启用媒体库列表增强（文件大小、分辨率、引用次数）', 'wp-media-toolkit' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( '上传大小限制', 'wp-media-toolkit' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="wpmt_media[enable_upload_limit]" value="1" <?php checked( ! empty( $settings['enable_upload_limit'] ) ); ?> />
								<?php esc_html_e( '启用上传大小硬限制', 'wp-media-toolkit' ); ?>
							</label>
							<p>
								<label>
									<?php esc_html_e( '最大上传大小（KB）', 'wp-media-toolkit' ); ?>
									<input type="number" min="1" step="1" name="wpmt_media[max_upload_kb]" value="<?php echo esc_attr( (string) (int) $settings['max_upload_kb'] ); ?>" class="small-text" />
								</label>
							</p>
							<p class="description"><?php esc_html_e( '仅在启用限制后生效。', 'wp-media-toolkit' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( '禁止上传的格式', 'wp-media-toolkit' ); ?></th>
						<td>
							<fieldset>
								<legend class="screen-reader-text"><?php esc_html_e( '禁止上传的格式', 'wp-media-toolkit' ); ?></legend>
								<?php foreach ( $formats as $ext => $label ) : ?>
									<label style="display:inline-block;min-width:7rem;margin:0.25rem 1rem 0.25rem 0;">
										<input type="checkbox" name="wpmt_media[blocked_formats][]" value="<?php echo esc_attr( $ext ); ?>" <?php checked( in_array( $ext, $blocked, true ) ); ?> />
										<?php echo esc_html( $label ); ?>
									</label>
								<?php endforeach; ?>
							</fieldset>
							<p class="description"><?php esc_html_e( '勾选表示禁止上传该格式（禁止列表）。默认全不选。', 'wp-media-toolkit' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'SVG 上传', 'wp-media-toolkit' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="wpmt_media[allow_svg]" value="1" <?php checked( ! empty( $settings['allow_svg'] ) ); ?> />
								<?php esc_html_e( '允许管理员上传 SVG', 'wp-media-toolkit' ); ?>
							</label>
							<p class="description" style="color:#b32d2e;">
								<?php esc_html_e( '风险提示：SVG 可能包含脚本。当前版本不做内容消毒，仅允许具备管理权限的用户上传。请勿对不可信来源开启。', 'wp-media-toolkit' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Simple Local Avatars 补丁', 'wp-media-toolkit' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="wpmt_media[enable_sla_fix]" value="1" <?php checked( ! empty( $settings['enable_sla_fix'] ) ); ?> />
								<?php esc_html_e( '启用补丁（环境切换 URL 纠正、更换头像时避免误删媒体库文件）', 'wp-media-toolkit' ); ?>
							</label>
							<p class="description"><?php esc_html_e( '未安装 Simple Local Avatars 时开启也无害。', 'wp-media-toolkit' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( '卸载', 'wp-media-toolkit' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="wpmt_media[delete_data_on_uninstall]" value="1" <?php checked( ! empty( $settings['delete_data_on_uninstall'] ) ); ?> />
								<?php esc_html_e( '卸载插件时删除本插件的全部设置数据', 'wp-media-toolkit' ); ?>
							</label>
							<p class="description"><?php esc_html_e( '默认不勾选：卸载后配置保留，便于重装恢复。', 'wp-media-toolkit' ); ?></p>
						</td>
					</tr>
				</table>
				<?php submit_button( __( '保存设置', 'wp-media-toolkit' ) ); ?>
			</form>
		</div>
		<?php
	}
}
