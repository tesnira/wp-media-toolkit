<?php
/**
 * 前台图片预览灯箱。
 *
 * @package WPMediaToolkit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 合并后台配置与 API 注册的选择器，输出灯箱资产。
 */
class WPMT_Image_Preview {

	/**
	 * API 额外注册的选择器。
	 *
	 * @var array<string, string>
	 */
	private static $api_selectors = array();

	/**
	 * 注册运行时钩子。
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'wp_enqueue_scripts', array( $this, 'register_assets' ) );
		// 须早于 wp_print_footer_scripts（默认 20），以便仍可入队脚本。
		add_action( 'wp_footer', array( $this, 'maybe_print_markup' ), 5 );
	}

	/**
	 * 公共 API：注册一个预览容器。
	 *
	 * 可与后台「容器元素 ID」合并去重。即使后台总开关关闭，API 注册仍会生效。
	 *
	 * @param string $id_or_selector 元素 ID 或 CSS 选择器。
	 * @return void
	 */
	public static function register_selector( $id_or_selector ) {
		if ( is_admin() ) {
			return;
		}
		$selector = self::to_selector( $id_or_selector );
		if ( '' === $selector ) {
			return;
		}
		self::$api_selectors[ $selector ] = $selector;
	}

	/**
	 * 将输入转为 CSS 选择器。
	 *
	 * @param string $value 原始值。
	 * @return string
	 */
	private static function to_selector( $value ) {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return '';
		}
		if ( 0 === strpos( $value, '#' ) || 0 === strpos( $value, '.' ) || 0 === strpos( $value, '[' ) ) {
			return $value;
		}
		$id = WPMT_Settings::normalize_element_id( $value );
		return '' === $id ? '' : '#' . $id;
	}

	/**
	 * 合并选择器：后台启用时纳入配置 ID；始终合并 API。
	 *
	 * @return string[]
	 */
	private function get_all_selectors() {
		$list     = array();
		$settings = WPMT_Settings::get_preview();
		if ( ! empty( $settings['enabled'] ) ) {
			$list = WPMT_Settings::get_preview_selectors();
		}
		foreach ( self::$api_selectors as $selector ) {
			$list[] = $selector;
		}
		return array_values( array_unique( array_filter( $list ) ) );
	}

	/**
	 * 注册前台资源。
	 *
	 * @return void
	 */
	public function register_assets() {
		wp_register_style(
			'wpmt-image-preview',
			WPMT_URL . 'assets/css/image-preview.css',
			array(),
			WPMT_VERSION
		);
		wp_register_script(
			'wpmt-image-preview',
			WPMT_URL . 'assets/js/image-preview.js',
			array(),
			WPMT_VERSION,
			true
		);
	}

	/**
	 * 有选择器时输出灯箱并入队资产。
	 *
	 * @return void
	 */
	public function maybe_print_markup() {
		$selectors = $this->get_all_selectors();
		if ( empty( $selectors ) ) {
			return;
		}

		wp_enqueue_style( 'wpmt-image-preview' );
		wp_enqueue_script( 'wpmt-image-preview' );

		$configs = array();
		foreach ( $selectors as $selector ) {
			$configs[] = array( 'selector' => $selector );
		}
		wp_add_inline_script(
			'wpmt-image-preview',
			'window.WPMT_IMAGE_PREVIEW = ' . wp_json_encode( array( 'configs' => $configs ) ) . ';',
			'before'
		);
		?>
		<div class="wpmt-lightbox" hidden aria-hidden="true">
			<div class="wpmt-lightbox__backdrop" data-role="backdrop"></div>
			<div class="wpmt-lightbox__dialog" role="dialog" aria-modal="true" aria-label="<?php esc_attr_e( '图片预览', 'wp-media-toolkit' ); ?>">
				<button type="button" class="wpmt-lightbox__close" data-role="close" aria-label="<?php esc_attr_e( '关闭', 'wp-media-toolkit' ); ?>">×</button>
				<div class="wpmt-lightbox__tools" data-role="tools">
					<button type="button" class="wpmt-lightbox__toolbtn" data-role="zoom-out" aria-label="<?php esc_attr_e( '缩小', 'wp-media-toolkit' ); ?>">−</button>
					<button type="button" class="wpmt-lightbox__toolbtn" data-role="zoom-reset" aria-label="<?php esc_attr_e( '还原', 'wp-media-toolkit' ); ?>">1:1</button>
					<button type="button" class="wpmt-lightbox__toolbtn" data-role="zoom-in" aria-label="<?php esc_attr_e( '放大', 'wp-media-toolkit' ); ?>">+</button>
				</div>
				<button type="button" class="wpmt-lightbox__nav wpmt-lightbox__nav--prev" data-role="prev" aria-label="<?php esc_attr_e( '上一张', 'wp-media-toolkit' ); ?>">‹</button>
				<button type="button" class="wpmt-lightbox__nav wpmt-lightbox__nav--next" data-role="next" aria-label="<?php esc_attr_e( '下一张', 'wp-media-toolkit' ); ?>">›</button>
				<div class="wpmt-lightbox__meta wpmt-lightbox__title" data-role="title"></div>
				<div class="wpmt-lightbox__stage" data-role="stage">
					<img class="wpmt-lightbox__img" data-role="img" alt="" />
				</div>
				<div class="wpmt-lightbox__meta" data-role="meta"></div>
			</div>
		</div>
		<?php
	}
}
