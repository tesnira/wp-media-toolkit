<?php
/**
 * 上传策略：大小限制、格式禁止、SVG 放开。
 *
 * @package WPMediaToolkit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 根据设置拦截或放宽上传。
 */
class WPMT_Upload_Policy {

	/**
	 * 注册钩子。
	 *
	 * @return void
	 */
	public function register() {
		add_filter( 'wp_handle_upload_prefilter', array( $this, 'filter_upload' ), 999 );
		add_filter( 'wp_handle_sideload_prefilter', array( $this, 'filter_upload' ), 999 );
		add_filter( 'upload_mimes', array( $this, 'filter_upload_mimes' ), 999 );
		add_filter( 'upload_size_limit', array( $this, 'filter_upload_size_limit' ), 999 );
		add_action( 'admin_notices', array( $this, 'maybe_admin_notice' ) );
		add_action( 'admin_footer', array( $this, 'maybe_upload_ui_notice' ) );
	}

	/**
	 * 上传 / sideload 预过滤。
	 *
	 * @param array<string, mixed> $file 文件数组。
	 * @return array<string, mixed>
	 */
	public function filter_upload( $file ) {
		if ( ! empty( $file['error'] ) ) {
			return $file;
		}

		$settings = WPMT_Settings::get_media();
		$ext      = strtolower( pathinfo( isset( $file['name'] ) ? (string) $file['name'] : '', PATHINFO_EXTENSION ) );
		$type     = isset( $file['type'] ) ? strtolower( (string) $file['type'] ) : '';

		// SVG：仅在允许且当前用户为管理员时可通过；否则一律拒绝。
		if ( 'svg' === $ext || false !== strpos( $type, 'svg' ) ) {
			if ( empty( $settings['allow_svg'] ) || ! current_user_can( 'manage_options' ) ) {
				$file['error'] = __( '不允许上传 SVG 文件。', 'wp-media-toolkit' );
			}
			return $file;
		}

		$blocked = isset( $settings['blocked_formats'] ) && is_array( $settings['blocked_formats'] )
			? $settings['blocked_formats']
			: array();
		$check_ext = ( 'jpeg' === $ext ) ? 'jpg' : $ext;
		if ( in_array( $check_ext, $blocked, true ) ) {
			$file['error'] = sprintf(
				/* translators: %s: file extension */
				__( '不允许上传 .%s 格式的文件。', 'wp-media-toolkit' ),
				esc_html( $check_ext )
			);
			return $file;
		}

		if ( ! empty( $settings['enable_upload_limit'] ) ) {
			$max_bytes = max( 1, (int) $settings['max_upload_kb'] ) * 1024;
			$size      = isset( $file['size'] ) ? (int) $file['size'] : 0;
			if ( $size > $max_bytes ) {
				$file['error'] = sprintf(
					/* translators: 1: actual size, 2: max KB */
					__( '文件大小（%1$s）超过了允许的最大值 %2$s KB。请压缩后重试。', 'wp-media-toolkit' ),
					size_format( $size, 2 ),
					(int) $settings['max_upload_kb']
				);
			}
		}

		return $file;
	}

	/**
	 * 调整允许的 MIME 类型。
	 *
	 * @param array<string, string> $mimes MIME 映射。
	 * @return array<string, string>
	 */
	public function filter_upload_mimes( $mimes ) {
		$settings = WPMT_Settings::get_media();
		$blocked  = isset( $settings['blocked_formats'] ) && is_array( $settings['blocked_formats'] )
			? $settings['blocked_formats']
			: array();
		$map      = WPMT_Settings::format_mime_keys();

		foreach ( $blocked as $ext ) {
			if ( empty( $map[ $ext ] ) ) {
				continue;
			}
			foreach ( $map[ $ext ] as $mime_key ) {
				unset( $mimes[ $mime_key ] );
			}
		}

		if ( ! empty( $settings['allow_svg'] ) && current_user_can( 'manage_options' ) ) {
			$mimes['svg']  = 'image/svg+xml';
			$mimes['svgz'] = 'image/svg+xml';
		} else {
			unset( $mimes['svg'], $mimes['svgz'] );
		}

		return $mimes;
	}

	/**
	 * 调整 WordPress 上传大小上限（字节）。
	 *
	 * @param int $size 原限制。
	 * @return int
	 */
	public function filter_upload_size_limit( $size ) {
		$settings = WPMT_Settings::get_media();
		if ( empty( $settings['enable_upload_limit'] ) ) {
			return $size;
		}
		$max_bytes = max( 1, (int) $settings['max_upload_kb'] ) * 1024;
		return (int) min( (int) $size, $max_bytes );
	}

	/**
	 * 媒体库页面顶部提示。
	 *
	 * @return void
	 */
	public function maybe_admin_notice() {
		$settings = WPMT_Settings::get_media();
		if ( empty( $settings['enable_upload_limit'] ) ) {
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || ( 'upload' !== $screen->id && 'media' !== $screen->id ) ) {
			return;
		}
		$kb = (int) $settings['max_upload_kb'];
		printf(
			'<div class="notice notice-info is-dismissible"><p><strong>%s</strong> %s</p></div>',
			esc_html__( '提示：', 'wp-media-toolkit' ),
			esc_html(
				sprintf(
					/* translators: %d: max KB */
					__( '单个文件上传大小限制为 %d KB。超过此大小的文件将无法上传。', 'wp-media-toolkit' ),
					$kb
				)
			)
		);
	}

	/**
	 * 上传 UI 内联提示。
	 *
	 * @return void
	 */
	public function maybe_upload_ui_notice() {
		$settings = WPMT_Settings::get_media();
		if ( empty( $settings['enable_upload_limit'] ) ) {
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || ( 'upload' !== $screen->id && 'media' !== $screen->id ) ) {
			return;
		}
		$kb = (int) $settings['max_upload_kb'];
		$message = sprintf(
			/* translators: %d: max KB */
			__( '单个文件大小不能超过 %d KB。', 'wp-media-toolkit' ),
			$kb
		);
		?>
		<script>
		(function ($) {
			$(function () {
				if ($('#plupload-upload-ui').length) {
					var notice = $('<div class="notice notice-warning inline" style="margin:10px 0;"><p><strong><?php echo esc_js( __( '注意：', 'wp-media-toolkit' ) ); ?></strong> <?php echo esc_js( $message ); ?></p></div>');
					$('#plupload-upload-ui').before(notice);
				}
			});
		})(jQuery);
		</script>
		<?php
	}
}
