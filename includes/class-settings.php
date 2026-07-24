<?php
/**
 * 插件选项读写。
 *
 * @package WPMediaToolkit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 管理媒体设置与预览设置 option。
 */
class WPMT_Settings {

	const MEDIA_OPTION   = 'wpmt_media_settings';
	const PREVIEW_OPTION = 'wpmt_preview_settings';

	/**
	 * 媒体库设置增强的默认值（安全默认：功能关闭）。
	 *
	 * @return array<string, mixed>
	 */
	public static function media_defaults() {
		return array(
			'enable_list_enhancements' => 0,
			'enable_upload_limit'      => 0,
			'max_upload_kb'            => 512,
			'blocked_formats'          => array(),
			'allow_svg'                => 0,
			'enable_sla_fix'          => 0,
			'delete_data_on_uninstall' => 0,
		);
	}

	/**
	 * 图片预览设置默认值。
	 *
	 * @return array<string, mixed>
	 */
	public static function preview_defaults() {
		return array(
			'enabled'       => 0,
			'container_ids' => '',
		);
	}

	/**
	 * 可勾选禁止的常见上传格式（扩展名 => 展示名）。
	 *
	 * @return array<string, string>
	 */
	public static function available_blocked_formats() {
		return array(
			'jpg'  => 'JPG / JPEG',
			'png'  => 'PNG',
			'gif'  => 'GIF',
			'webp' => 'WebP',
			'bmp'  => 'BMP',
			'ico'  => 'ICO',
			'pdf'  => 'PDF',
			'mp4'  => 'MP4',
			'mp3'  => 'MP3',
			'zip'  => 'ZIP',
			'doc'  => 'DOC',
			'docx' => 'DOCX',
			'xls'  => 'XLS',
			'xlsx' => 'XLSX',
			'csv'  => 'CSV',
		);
	}

	/**
	 * 扩展名到 MIME 键的映射（用于 upload_mimes）。
	 *
	 * @return array<string, string[]>
	 */
	public static function format_mime_keys() {
		return array(
			'jpg'  => array( 'jpg|jpeg|jpe' ),
			'png'  => array( 'png' ),
			'gif'  => array( 'gif' ),
			'webp' => array( 'webp' ),
			'bmp'  => array( 'bmp' ),
			'ico'  => array( 'ico' ),
			'pdf'  => array( 'pdf' ),
			'mp4'  => array( 'mp4', 'm4v' ),
			'mp3'  => array( 'mp3|m4a|m4b' ),
			'zip'  => array( 'zip' ),
			'doc'  => array( 'doc' ),
			'docx' => array( 'docx' ),
			'xls'  => array( 'xls' ),
			'xlsx' => array( 'xlsx' ),
			'csv'  => array( 'csv' ),
		);
	}

	/**
	 * 读取媒体设置。
	 *
	 * @return array<string, mixed>
	 */
	public static function get_media() {
		$stored = get_option( self::MEDIA_OPTION, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}
		$settings = array_merge( self::media_defaults(), $stored );
		$settings['blocked_formats'] = self::sanitize_blocked_formats(
			isset( $settings['blocked_formats'] ) ? $settings['blocked_formats'] : array()
		);
		$settings['max_upload_kb'] = max( 1, (int) $settings['max_upload_kb'] );
		return $settings;
	}

	/**
	 * 读取预览设置。
	 *
	 * @return array<string, mixed>
	 */
	public static function get_preview() {
		$stored = get_option( self::PREVIEW_OPTION, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}
		return array_merge( self::preview_defaults(), $stored );
	}

	/**
	 * 保存媒体设置。
	 *
	 * @param array<string, mixed> $input 表单输入。
	 * @return array<string, mixed>
	 */
	public static function update_media( $input ) {
		$defaults = self::media_defaults();
		$clean    = array(
			'enable_list_enhancements' => empty( $input['enable_list_enhancements'] ) ? 0 : 1,
			'enable_upload_limit'      => empty( $input['enable_upload_limit'] ) ? 0 : 1,
			'max_upload_kb'            => isset( $input['max_upload_kb'] ) ? max( 1, (int) $input['max_upload_kb'] ) : $defaults['max_upload_kb'],
			'blocked_formats'          => self::sanitize_blocked_formats(
				isset( $input['blocked_formats'] ) ? $input['blocked_formats'] : array()
			),
			'allow_svg'                => empty( $input['allow_svg'] ) ? 0 : 1,
			'enable_sla_fix'          => empty( $input['enable_sla_fix'] ) ? 0 : 1,
			'delete_data_on_uninstall' => empty( $input['delete_data_on_uninstall'] ) ? 0 : 1,
		);
		update_option( self::MEDIA_OPTION, $clean, false );
		return $clean;
	}

	/**
	 * 保存预览设置。
	 *
	 * @param array<string, mixed> $input 表单输入。
	 * @return array<string, mixed>
	 */
	public static function update_preview( $input ) {
		$ids = isset( $input['container_ids'] ) ? (string) $input['container_ids'] : '';
		$clean = array(
			'enabled'       => empty( $input['enabled'] ) ? 0 : 1,
			'container_ids' => self::normalize_container_ids_text( $ids ),
		);
		update_option( self::PREVIEW_OPTION, $clean, false );
		return $clean;
	}

	/**
	 * 清洗禁止格式列表。
	 *
	 * @param mixed $formats 原始值。
	 * @return string[]
	 */
	public static function sanitize_blocked_formats( $formats ) {
		if ( ! is_array( $formats ) ) {
			return array();
		}
		$allowed = array_keys( self::available_blocked_formats() );
		$out     = array();
		foreach ( $formats as $ext ) {
			$ext = strtolower( sanitize_key( (string) $ext ) );
			if ( 'jpeg' === $ext ) {
				$ext = 'jpg';
			}
			if ( in_array( $ext, $allowed, true ) ) {
				$out[] = $ext;
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * 规范化容器 ID 多行文本。
	 *
	 * @param string $text 原始文本。
	 * @return string
	 */
	public static function normalize_container_ids_text( $text ) {
		$lines = preg_split( '/\r\n|\r|\n/', (string) $text );
		if ( ! is_array( $lines ) ) {
			return '';
		}
		$ids = array();
		foreach ( $lines as $line ) {
			$id = self::normalize_element_id( $line );
			if ( '' !== $id ) {
				$ids[] = $id;
			}
		}
		return implode( "\n", array_values( array_unique( $ids ) ) );
	}

	/**
	 * 将输入规范为不含 # 的元素 ID；若是选择器则原样保留（以 . 或 [ 开头）。
	 *
	 * @param string $value 原始值。
	 * @return string
	 */
	public static function normalize_element_id( $value ) {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return '';
		}
		// 选择器形式留给 API 使用；后台列表只存纯 ID。
		if ( 0 === strpos( $value, '.' ) || 0 === strpos( $value, '[' ) ) {
			return $value;
		}
		$value = ltrim( $value, '#' );
		$value = preg_replace( '/[^a-zA-Z0-9_-]/', '', $value );
		return is_string( $value ) ? $value : '';
	}

	/**
	 * 解析预览容器为 CSS 选择器列表。
	 *
	 * @return string[]
	 */
	public static function get_preview_selectors() {
		$settings = self::get_preview();
		$text     = isset( $settings['container_ids'] ) ? (string) $settings['container_ids'] : '';
		$lines    = preg_split( '/\r\n|\r|\n/', $text );
		$selectors = array();
		if ( is_array( $lines ) ) {
			foreach ( $lines as $line ) {
				$id = self::normalize_element_id( $line );
				if ( '' === $id ) {
					continue;
				}
				if ( 0 === strpos( $id, '.' ) || 0 === strpos( $id, '[' ) ) {
					$selectors[] = $id;
				} else {
					$selectors[] = '#' . $id;
				}
			}
		}
		return array_values( array_unique( $selectors ) );
	}

	/**
	 * 是否应在卸载时删除数据。
	 *
	 * @return bool
	 */
	public static function should_delete_data_on_uninstall() {
		$settings = self::get_media();
		return ! empty( $settings['delete_data_on_uninstall'] );
	}
}
