<?php
/**
 * Simple Local Avatars 补丁。
 *
 * @package WPMediaToolkit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 修复环境切换 URL 与换头像时误删媒体库文件的问题。
 */
class WPMT_Sla_Fix {

	/**
	 * 注册钩子（设置开启时）。
	 *
	 * @return void
	 */
	public function register() {
		$settings = WPMT_Settings::get_media();
		if ( empty( $settings['enable_sla_fix'] ) ) {
			return;
		}
		add_filter( 'get_user_metadata', array( $this, 'filter_avatar_meta' ), 10, 4 );
	}

	/**
	 * 拦截 simple_local_avatar 读取。
	 *
	 * @param mixed  $null      过滤器短路值。
	 * @param int    $object_id 用户 ID。
	 * @param string $meta_key  Meta 键。
	 * @param bool   $single    是否单值（WP 会再取 [0]）。
	 * @return mixed
	 */
	public function filter_avatar_meta( $null, $object_id, $meta_key, $single ) {
		unset( $single );
		if ( 'simple_local_avatar' !== $meta_key ) {
			return $null;
		}

		remove_filter( 'get_user_metadata', array( $this, 'filter_avatar_meta' ), 10 );
		$raw_avatars = get_user_meta( (int) $object_id, $meta_key, false );
		add_filter( 'get_user_metadata', array( $this, 'filter_avatar_meta' ), 10, 4 );

		if ( empty( $raw_avatars ) || ! is_array( $raw_avatars ) || ! isset( $raw_avatars[0] ) ) {
			return null;
		}

		$data = $raw_avatars[0];
		if ( ! is_array( $data ) ) {
			return $null;
		}

		$upload_dir = wp_upload_dir();
		$baseurl    = rtrim( $upload_dir['baseurl'], '/' );

		foreach ( $data as $key => $val ) {
			if ( ! is_string( $val ) ) {
				continue;
			}
			$pos = strpos( $val, '/wp-content/uploads/' );
			if ( false !== $pos ) {
				$rel_path     = substr( $val, $pos + 20 );
				$data[ $key ] = $baseurl . '/' . ltrim( $rel_path, '/' );
			}
		}

		$is_deleting = false;
		$trace       = debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 15 );
		foreach ( $trace as $frame ) {
			if ( isset( $frame['function'] ) && 'avatar_delete' === $frame['function'] ) {
				$is_deleting = true;
				break;
			}
		}

		if ( $is_deleting ) {
			$full_url = isset( $data['full'] ) ? $data['full'] : '';
			if ( ! empty( $full_url ) ) {
				foreach ( $data as $key => $val ) {
					if ( 'full' !== $key && 'media_id' !== $key && 'blog_id' !== $key && $val === $full_url ) {
						unset( $data[ $key ] );
					}
				}
			}
			if ( isset( $data['media_id'] ) ) {
				$safe_data = array(
					'media_id' => $data['media_id'],
					'full'     => $full_url,
				);
				if ( isset( $data['blog_id'] ) ) {
					$safe_data['blog_id'] = $data['blog_id'];
				}
				$data = $safe_data;
			}
		}

		return array( $data );
	}
}
