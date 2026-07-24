<?php
/**
 * 媒体库列表增强。
 *
 * @package WPMediaToolkit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 列表列、引用次数 AJAX、附件详情使用位置。
 */
class WPMT_Media_Enhancements {

	/**
	 * 注册钩子（仅在设置开启时）。
	 *
	 * @return void
	 */
	public function register() {
		$settings = WPMT_Settings::get_media();
		if ( empty( $settings['enable_list_enhancements'] ) ) {
			return;
		}

		add_filter( 'manage_media_columns', array( $this, 'add_columns' ) );
		add_action( 'manage_media_custom_column', array( $this, 'render_column' ), 10, 2 );
		add_action( 'admin_footer', array( $this, 'print_list_script' ), 20 );
		add_action( 'wp_ajax_wpmt_get_usage_counts', array( $this, 'ajax_usage_counts' ) );
		add_action( 'attachment_submitbox_misc_actions', array( $this, 'render_usage_box' ) );
	}

	/**
	 * 增加自定义列。
	 *
	 * @param array<string, string> $columns 原列。
	 * @return array<string, string>
	 */
	public function add_columns( $columns ) {
		$new_columns = array();
		foreach ( $columns as $key => $value ) {
			if ( 'date' === $key ) {
				$new_columns['wpmt_file_size']      = __( '文件大小', 'wp-media-toolkit' );
				$new_columns['wpmt_file_dimensions'] = __( '分辨率', 'wp-media-toolkit' );
				$new_columns['wpmt_usage_count']    = __( '引用次数', 'wp-media-toolkit' );
			}
			$new_columns[ $key ] = $value;
		}
		return $new_columns;
	}

	/**
	 * 渲染自定义列内容。
	 *
	 * @param string $column_name 列名。
	 * @param int    $post_id     附件 ID。
	 * @return void
	 */
	public function render_column( $column_name, $post_id ) {
		$post_id = (int) $post_id;
		if ( 'wpmt_file_size' === $column_name ) {
			$file_path = get_attached_file( $post_id );
			if ( $file_path && file_exists( $file_path ) ) {
				echo esc_html( size_format( filesize( $file_path ), 2 ) );
			} else {
				echo '—';
			}
			return;
		}

		if ( 'wpmt_file_dimensions' === $column_name ) {
			$mime_type = get_post_mime_type( $post_id );
			if ( is_string( $mime_type ) && 0 === strpos( $mime_type, 'image/' ) ) {
				$metadata = wp_get_attachment_metadata( $post_id );
				if ( is_array( $metadata ) && ! empty( $metadata['width'] ) && ! empty( $metadata['height'] ) ) {
					echo esc_html( $metadata['width'] . ' × ' . $metadata['height'] );
					return;
				}
				$file_path = get_attached_file( $post_id );
				if ( $file_path && file_exists( $file_path ) ) {
					$image_info = @getimagesize( $file_path );
					if ( false !== $image_info ) {
						echo esc_html( $image_info[0] . ' × ' . $image_info[1] );
						return;
					}
				}
			}
			echo '—';
			return;
		}

		if ( 'wpmt_usage_count' === $column_name ) {
			printf(
				'<span class="wpmt-usage-count-async" data-attachment-id="%d" title="%s">—</span>',
				$post_id,
				esc_attr__( '加载中…', 'wp-media-toolkit' )
			);
		}
	}

	/**
	 * 媒体库列表页异步加载引用次数脚本。
	 *
	 * @return void
	 */
	public function print_list_script() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'upload' !== $screen->id ) {
			return;
		}

		$edit_url_base = admin_url( 'post.php?post=POSTID&action=edit#usage-locations' );
		$config        = array(
			'ajaxurl'     => admin_url( 'admin-ajax.php' ),
			'nonce'       => wp_create_nonce( 'wpmt_usage_counts' ),
			'editUrlBase' => $edit_url_base,
		);
		?>
		<script>
		(function ($) {
			var config = <?php echo wp_json_encode( $config ); ?>;
			function updateUsageCountCells() {
				var $placeholders = $('.wpmt-usage-count-async');
				if (!$placeholders.length) return;
				var ids = [];
				$placeholders.each(function () {
					var id = parseInt($(this).data('attachment-id'), 10);
					if (id) ids.push(id);
				});
				if (!ids.length) return;
				$.post(config.ajaxurl, {
					action: 'wpmt_get_usage_counts',
					nonce: config.nonce,
					attachment_ids: ids
				}).done(function (res) {
					if (!res || !res.success || !res.data || !res.data.counts) return;
					var counts = res.data.counts;
					$placeholders.each(function () {
						var $el = $(this);
						var id = parseInt($el.data('attachment-id'), 10);
						var count = counts[id] !== undefined ? counts[id] : 0;
						var html;
						if (count > 0) {
							var url = config.editUrlBase.replace('POSTID', String(id));
							html = '<a href="' + url + '" class="wpmt-usage-count" data-attachment-id="' + id + '" title="<?php echo esc_js( __( '点击查看使用位置', 'wp-media-toolkit' ) ); ?>">' + count + '</a>';
						} else {
							html = '0';
						}
						$el.replaceWith(html);
					});
				}).fail(function () {
					$placeholders.text('—');
				});
			}
			$(document).ready(function () {
				updateUsageCountCells();
				$(document).ajaxComplete(function (event, xhr, settings) {
					if (settings.url && settings.url.indexOf('admin-ajax.php') !== -1) {
						setTimeout(updateUsageCountCells, 100);
					}
				});
			});
		})(jQuery);
		</script>
		<?php
	}

	/**
	 * AJAX：批量返回引用次数。
	 *
	 * @return void
	 */
	public function ajax_usage_counts() {
		check_ajax_referer( 'wpmt_usage_counts', 'nonce' );
		if ( ! current_user_can( 'upload_files' ) ) {
			wp_send_json_error( array( 'message' => 'forbidden' ), 403 );
		}

		$raw = isset( $_POST['attachment_ids'] ) ? wp_unslash( $_POST['attachment_ids'] ) : array();
		if ( ! is_array( $raw ) ) {
			$raw = explode( ',', (string) $raw );
		}
		$attachment_ids = array_filter( array_unique( array_map( 'intval', $raw ) ) );
		$counts         = array();
		foreach ( $attachment_ids as $id ) {
			$counts[ $id ] = count( WPMT_Media_Usage::search( $id ) );
		}
		wp_send_json_success( array( 'counts' => $counts ) );
	}

	/**
	 * 附件编辑页展示使用位置。
	 *
	 * @param WP_Post $post 附件对象。
	 * @return void
	 */
	public function render_usage_box( $post ) {
		if ( ! $post instanceof WP_Post || 'attachment' !== $post->post_type ) {
			return;
		}

		$usage_locations = WPMT_Media_Usage::search( (int) $post->ID );
		$count           = count( $usage_locations );
		$only_meta       = ( 1 === $count && ! empty( $usage_locations[0]['type'] ) && 'meta' === $usage_locations[0]['type'] );
		?>
		<div class="misc-pub-section misc-pub-usage-locations" id="usage-locations">
			<strong><?php esc_html_e( '使用位置：', 'wp-media-toolkit' ); ?></strong>
			<?php if ( empty( $usage_locations ) ) : ?>
				<span style="color:#666;"><?php esc_html_e( '未使用', 'wp-media-toolkit' ); ?></span>
				<p style="margin:5px 0;color:#2271b1;font-size:12px;"><?php esc_html_e( '此图片可以安全删除', 'wp-media-toolkit' ); ?></p>
			<?php else : ?>
				<?php if ( $only_meta ) : ?>
					<p style="margin:5px 0;color:#d63638;font-size:12px;">
						<?php esc_html_e( '注意：仅找到 meta 类型引用，可能是系统元数据。建议手动检查后再删除。', 'wp-media-toolkit' ); ?>
					</p>
				<?php endif; ?>
				<ul style="margin:10px 0;padding:0;list-style:none;">
					<?php foreach ( $usage_locations as $location ) : ?>
						<li style="margin:10px 0;border-bottom:1px dashed #eee;padding-bottom:8px;">
							<span style="font-weight:600;display:block;color:#333;margin-bottom:4px;"><?php echo wp_kses_post( $location['title'] ); ?></span>
							<div style="font-size:12px;display:flex;align-items:center;gap:10px;">
								<?php if ( ! empty( $location['edit_link'] ) ) : ?>
									<a href="<?php echo esc_url( $location['edit_link'] ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( '[编辑]', 'wp-media-toolkit' ); ?></a>
								<?php endif; ?>
								<?php if ( ! empty( $location['view_link'] ) ) : ?>
									<a href="<?php echo esc_url( $location['view_link'] ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( '[预览]', 'wp-media-toolkit' ); ?></a>
								<?php endif; ?>
								<span style="color:#999;font-size:11px;"><?php echo esc_html( sprintf( /* translators: %s: usage type */ __( '类型: %s', 'wp-media-toolkit' ), $location['type'] ) ); ?></span>
							</div>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</div>
		<?php
	}
}
