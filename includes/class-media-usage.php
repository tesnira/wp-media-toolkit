<?php
/**
 * 附件引用位置搜索。
 *
 * @package WPMediaToolkit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 在文章、meta、option、usermeta 中查找附件引用。
 */
class WPMT_Media_Usage {

	/**
	 * 请求内缓存。
	 *
	 * @var array<int, array<int, array<string, mixed>>>
	 */
	private static $usage_cache = array();

	/**
	 * 搜索附件在全站的使用位置。
	 *
	 * @param int $attachment_id 附件 ID。
	 * @return array<int, array<string, mixed>>
	 */
	public static function search( $attachment_id ) {
		$attachment_id = (int) $attachment_id;
		// 使用缓存避免重复搜索。
		if ( isset( self::$usage_cache[ $attachment_id ] ) ) {
			return self::$usage_cache[ $attachment_id ];
		}
    
    $attachment_url = wp_get_attachment_url($attachment_id);
    $attachment_url_path = wp_parse_url($attachment_url, PHP_URL_PATH);
    $basename = basename($attachment_url_path);
    
    // 获取原始文件名（不带扩展名）和所有可能的扩展名
    $pathinfo = pathinfo($basename);
    $filename_without_ext = $pathinfo['filename'];
    $extension = isset($pathinfo['extension']) ? $pathinfo['extension'] : '';
    
    // 获取原始文件路径（可能被转换为webp）
    $original_file = get_attached_file($attachment_id);
    $original_basename = basename($original_file);
    $original_pathinfo = pathinfo($original_basename);
    $original_filename = $original_pathinfo['filename'];
    $original_extension = isset($original_pathinfo['extension']) ? $original_pathinfo['extension'] : '';
    
    // 获取相对上传路径（例如 2025/09/image.jpg）
    $relative_path = get_post_meta($attachment_id, '_wp_attached_file', true);
    $relative_path_dir = ($relative_path && dirname($relative_path) !== '.') ? dirname($relative_path) : '';
    
    $usage_locations = [];
    
    // 获取所有可能的 URL 变体（包括不同尺寸和webp版本）
    $image_urls = [$attachment_url];
    
    // 添加webp版本的URL
    if (strtolower($extension) !== 'svg') {
        $webp_url = preg_replace('/\.(jpg|jpeg|png|gif)$/i', '.webp', $attachment_url);
        if ($webp_url !== $attachment_url) {
            $image_urls[] = $webp_url;
        }
    }
    
    // 添加原始URL（如果当前是webp）
    if (strtolower($extension) === 'webp') {
        $original_url = preg_replace('/\.webp$/i', '.jpg', $attachment_url);
        if ($original_url !== $attachment_url) {
            $image_urls[] = $original_url;
        }
        $original_url = preg_replace('/\.webp$/i', '.png', $attachment_url);
        if ($original_url !== $attachment_url) {
            $image_urls[] = $original_url;
        }
    }
    
    // 获取所有尺寸的URL
    if (strtolower($extension) !== 'svg') {
        $image_sizes = get_intermediate_image_sizes();
        foreach ($image_sizes as $size) {
            $image_url = wp_get_attachment_image_src($attachment_id, $size);
            if ($image_url) {
                $image_urls[] = $image_url[0];
                // 也添加webp版本
                $webp_size_url = preg_replace('/\.(jpg|jpeg|png|gif)$/i', '.webp', $image_url[0]);
                if ($webp_size_url !== $image_url[0]) {
                    $image_urls[] = $webp_size_url;
                }
            }
        }
    }
    
    // 去重
    $image_urls = array_unique($image_urls);
    
    // 搜索所有文章类型（包括自定义类型）
    $post_types = get_post_types(['public' => true], 'names');
    $post_types[] = 'page';
    $post_types[] = 'post';
    $post_types = array_unique($post_types);
    
    global $wpdb;
    
    // 构建搜索关键词
    $search_terms = [
        'wp-image-' . $attachment_id,
        'attachment-' . $attachment_id
    ];
    
    // 添加相对路径搜索（例如 2025/09/image.jpg），这能有效区分不同月份的同名图片
    if ($relative_path) {
        $search_terms[] = $relative_path;
        
        // 双向兼容：如果原图是 webp，搜索其可能的 jpg/png 来源
        if (strtolower($extension) === 'webp') {
            $search_terms[] = preg_replace('/\.webp$/i', '.jpg', $relative_path);
            $search_terms[] = preg_replace('/\.webp$/i', '.png', $relative_path);
        } 
        // 双向兼容：如果原图是 jpg/png/gif，搜索其可能被插件转换出的 webp 版本
        elseif (in_array(strtolower($extension), ['jpg', 'jpeg', 'png', 'gif'])) {
            $webp_relative_path = preg_replace('/\.(jpg|jpeg|png|gif)$/i', '.webp', $relative_path);
            if ($webp_relative_path !== $relative_path) {
                $search_terms[] = $webp_relative_path;
            }
        }
    }
    
    // 只有当文件名长度大于 3 或者不是极其常见的词时，才搜索纯文件名（带扩展名搜索更安全）
    // 这里的策略是：优先搜索带扩展名的完整文件名，这样可以极大减少误匹配
    $filenames_to_search = array_unique([$basename, $original_basename]);
    
    // 双向兼容：补充 webp 或 源文件的完整文件名
    if (strtolower($extension) === 'webp') {
        $filenames_to_search[] = $filename_without_ext . '.jpg';
        $filenames_to_search[] = $filename_without_ext . '.png';
    } elseif (in_array(strtolower($extension), ['jpg', 'jpeg', 'png', 'gif'])) {
        $filenames_to_search[] = $filename_without_ext . '.webp';
    }
    $filenames_to_search = array_unique($filenames_to_search);
    
    foreach ($filenames_to_search as $fname) {
        if (!empty($fname)) {
            $search_terms[] = $fname;
        }
    }
    
    // 搜索文章内容
    $search_patterns = [];
    foreach ($search_terms as $term) {
        $search_patterns[] = $wpdb->esc_like($term);
    }
    
    // 如果文件名很长且不常见，也可以考虑搜索不带扩展名的文件名，但要配合正则校验
    $unsafe_short_filenames = ['home', 'logo', 'bg', 'icon', 'image', 'img', 'banner', 'pic', 'top', 'bottom', 'left', 'right'];
    $allow_loose_filename_search = (strlen($filename_without_ext) > 5 && !in_array(strtolower($filename_without_ext), $unsafe_short_filenames));
    
    if ($allow_loose_filename_search) {
        $search_patterns[] = $wpdb->esc_like($filename_without_ext);
    }
    
    $like_conditions = [];
    foreach ($search_patterns as $pattern) {
        $like_conditions[] = "post_content LIKE '%" . $pattern . "%'";
        $like_conditions[] = "post_excerpt LIKE '%" . $pattern . "%'";
    }
    
    $posts = $wpdb->get_results("
        SELECT ID, post_title, post_type, post_status, post_content, post_excerpt
        FROM {$wpdb->posts} 
        WHERE post_type IN ('" . implode("','", array_map('esc_sql', $post_types)) . "')
        AND post_status != 'trash'
        AND (" . implode(' OR ', $like_conditions) . ")
    ");
    
    foreach ($posts as $post) {
        $content = $post->post_content . ' ' . $post->post_excerpt;
        
        // 检查是否包含图片 URL、ID 或文件名
        $found = false;
        
        // 检查URL
        foreach ($image_urls as $url) {
            if (strpos($content, $url) !== false) {
                $found = true;
                break;
            }
        }
        
        // 检查wp-image-{id}类名
        if (!$found && strpos($content, 'wp-image-' . $attachment_id) !== false) {
            $found = true;
        }
        
        // 检查attachment-{id}
        if (!$found && strpos($content, 'attachment-' . $attachment_id) !== false) {
            $found = true;
        }
        
        // 检查文件名（确保是完整的文件名匹配，支持多种扩展名）
        if (!$found) {
            $names_to_check = array_unique([$filename_without_ext, $original_filename]);
            foreach ($names_to_check as $name) {
                if (empty($name)) continue;
                if (strpos($content, $name) !== false) {
                    // 进一步验证：确保是完整的文件名匹配（避免误匹配）
                    // 强制验证目录是否匹配，防止不同目录甚至插件下的同名文件被误报
                    $path_pattern = '';
                    if (!empty($relative_path_dir)) {
                        // 强制要求匹配相对路径，同时兼顾 JSON 转义形式的 \/
                        $path_parts = explode('/', $relative_path_dir);
                        $escaped_parts = array_map(function($p) { return preg_quote($p, '/'); }, $path_parts);
                        $path_pattern = implode('\\\\?\/', $escaped_parts) . '\\\\?\/';
                    }
                    
                    $pattern = '/' . $path_pattern . preg_quote($name, '/') . '\.(jpg|jpeg|png|gif|webp|svg|ico|bmp)/i';
                    if (preg_match($pattern, $content)) {
                        $found = true;
                        break;
                    }
                }
            }
        }
        
        if ($found) {
            // 验证文章是否存在且可访问
            $view_link = '';
            $permalink = get_permalink($post->ID);
            if ($permalink && $post->post_status === 'publish') {
                // 检查文章是否真的存在
                $post_obj = get_post($post->ID);
                if ($post_obj && $post_obj->post_status === 'publish') {
                    $view_link = $permalink;
                }
            }
            
            // 显示文章状态信息
            $status_info = '';
            if ($post->post_status === 'draft') {
                $status_info = ' <span style="color: #d63638;">[草稿]</span>';
            } elseif ($post->post_status === 'pending') {
                $status_info = ' <span style="color: #d63638;">[待审核]</span>';
            } elseif ($post->post_status === 'private') {
                $status_info = ' <span style="color: #d63638;">[私有]</span>';
            } elseif ($post->post_status !== 'publish') {
                $status_info = ' <span style="color: #d63638;">[' . esc_html($post->post_status) . ']</span>';
            }
            
            $usage_locations[] = [
                'type' => 'post',
                'id' => $post->ID,
                'title' => $post->post_title . $status_info,
                'post_type' => $post->post_type,
                'status' => $post->post_status,
                'edit_link' => get_edit_post_link($post->ID),
                'view_link' => $view_link
            ];
        }
    }
    
    // 搜索文章元数据（特色图片等）
    $meta_posts = $wpdb->get_results($wpdb->prepare("
        SELECT p.ID, p.post_title, p.post_type, p.post_status
        FROM {$wpdb->posts} p
        INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
        WHERE pm.meta_key = '_thumbnail_id'
        AND pm.meta_value = %d
        AND p.post_status != 'trash'
    ", $attachment_id));
    
    foreach ($meta_posts as $post) {
        // 避免重复
        $exists = false;
        foreach ($usage_locations as $loc) {
            if ($loc['id'] == $post->ID && $loc['type'] == 'post') {
                $exists = true;
                break;
            }
        }
        if (!$exists) {
            // 验证文章是否存在且可访问
            $view_link = '';
            $permalink = get_permalink($post->ID);
            if ($permalink && $post->post_status === 'publish') {
                $post_obj = get_post($post->ID);
                if ($post_obj && $post_obj->post_status === 'publish') {
                    $view_link = $permalink;
                }
            }
            
            // 显示文章状态信息
            $status_info = '';
            if ($post->post_status === 'draft') {
                $status_info = ' <span style="color: #d63638;">[草稿]</span>';
            } elseif ($post->post_status === 'pending') {
                $status_info = ' <span style="color: #d63638;">[待审核]</span>';
            } elseif ($post->post_status === 'private') {
                $status_info = ' <span style="color: #d63638;">[私有]</span>';
            } elseif ($post->post_status !== 'publish') {
                $status_info = ' <span style="color: #d63638;">[' . esc_html($post->post_status) . ']</span>';
            }
            
            $usage_locations[] = [
                'type' => 'thumbnail',
                'id' => $post->ID,
                'title' => $post->post_title . $status_info,
                'post_type' => $post->post_type,
                'status' => $post->post_status,
                'edit_link' => get_edit_post_link($post->ID),
                'view_link' => $view_link
            ];
        }
    }
    
    // 搜索 postmeta 中包含图片ID或URL的字段（如Elementor等）
    // 排除附件自己的元数据字段和附件类型的记录
    $excluded_meta_keys = [
        '_thumbnail_id', '_edit_lock', '_edit_last',
        '_wp_attached_file', '_wp_attachment_metadata', '_wp_attachment_backup_sizes',
        '_wp_attachment_image_alt', '_wp_attachment_context',
        '_yoast_wpseo_primary_category', '_yoast_wpseo_primary_blog_category', 'rank_math_primary_category'
    ];
    
    $meta_search_conditions = [];
    $meta_search_conditions[] = $wpdb->prepare("pm.meta_value = %d", $attachment_id);
    $meta_search_conditions[] = $wpdb->prepare("pm.meta_value LIKE %s", '%"' . $attachment_id . '"%');
    $meta_search_conditions[] = $wpdb->prepare("pm.meta_value LIKE %s", '%"' . $attachment_id . ',%');
    $meta_search_conditions[] = $wpdb->prepare("pm.meta_value LIKE %s", '%,' . $attachment_id . '"%');
    
    foreach ($image_urls as $url) {
        $meta_search_conditions[] = $wpdb->prepare("pm.meta_value LIKE %s", '%' . $wpdb->esc_like($url) . '%');
    }
    
    // 添加相对路径搜索
    if ($relative_path) {
        $meta_search_conditions[] = $wpdb->prepare("pm.meta_value LIKE %s", '%' . $wpdb->esc_like($relative_path) . '%');
        if (strtolower($extension) === 'webp') {
            $meta_search_conditions[] = $wpdb->prepare("pm.meta_value LIKE %s", '%' . $wpdb->esc_like(preg_replace('/\.webp$/i', '.jpg', $relative_path)) . '%');
            $meta_search_conditions[] = $wpdb->prepare("pm.meta_value LIKE %s", '%' . $wpdb->esc_like(preg_replace('/\.webp$/i', '.png', $relative_path)) . '%');
        }
    }
    
    // 搜索带扩展名的完整文件名，避免误匹配短的文件名（如 home）
    $filenames_to_search_meta = array_unique([$basename, $original_basename]);
    foreach ($filenames_to_search_meta as $fname) {
        if (!empty($fname)) {
            $meta_search_conditions[] = $wpdb->prepare("pm.meta_value LIKE %s", '%' . $wpdb->esc_like($fname) . '%');
        }
    }
    
    // 如果文件名足够长且不常见，才搜索纯文件名
    if ($allow_loose_filename_search) {
        foreach ([$filename_without_ext, $original_filename] as $name) {
            if (!empty($name)) {
                $meta_search_conditions[] = $wpdb->prepare("pm.meta_value LIKE %s", '%' . $wpdb->esc_like($name) . '%');
            }
        }
    }
    
    $meta_posts_other = $wpdb->get_results("
        SELECT DISTINCT p.ID, p.post_title, p.post_type, p.post_status
        FROM {$wpdb->posts} p
        INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
        WHERE p.post_type IN ('" . implode("','", array_map('esc_sql', $post_types)) . "')
        AND p.post_type != 'attachment'
        AND p.ID != " . intval($attachment_id) . "
        AND p.post_status != 'trash'
        AND pm.meta_key NOT IN ('" . implode("','", array_map('esc_sql', $excluded_meta_keys)) . "')
        AND (" . implode(' OR ', $meta_search_conditions) . ")
    ");
    
    foreach ($meta_posts_other as $post) {
        // 避免重复
        $exists = false;
        foreach ($usage_locations as $loc) {
            if ($loc['id'] == $post->ID) {
                $exists = true;
                break;
            }
        }
        if (!$exists) {
            // 验证是否真的在 meta 中使用了该图片
            $found_in_meta = false;
            
            // 获取该文章的所有 meta
            $post_metas = get_post_custom($post->ID);
            if ($post_metas) {
                foreach ($post_metas as $mkey => $mvalues) {
                    if (in_array($mkey, $excluded_meta_keys)) continue;
                    foreach ($mvalues as $mval) {
                        // 检查ID
                        if ($mval == $attachment_id || strpos($mval, '"' . $attachment_id . '"') !== false) {
                            $found_in_meta = true; break;
                        }
                        // 检查URL
                        foreach ($image_urls as $url) {
                            if (strpos($mval, $url) !== false) {
                                $found_in_meta = true; break;
                            }
                        }
                        if ($found_in_meta) break;
                        // 检查完整文件名
                        foreach ($filenames_to_search_meta as $fname) {
                            if (strpos($mval, $fname) !== false) {
                                // 同样验证目录信息
                                $path_check = true;
                                if (!empty($relative_path_dir)) {
                                    if (strpos($mval, $relative_path_dir . '/' . $fname) === false && strpos($mval, $relative_path_dir . '\\' . $fname) === false) {
                                        $path_check = false;
                                    }
                                }
                                if ($path_check) {
                                    $found_in_meta = true; break;
                                }
                            }
                        }
                        if ($found_in_meta) break;
                        // 如果允许松散匹配，则检查文件名
                        if ($allow_loose_filename_search) {
                            foreach ([$filename_without_ext, $original_filename] as $name) {
                                if (empty($name)) continue;
                                if (strpos($mval, $name) !== false) {
                                    $path_pattern = '';
                                    if (!empty($relative_path_dir)) {
                        // 强制要求匹配相对路径，同时兼顾 JSON 转义形式的 \/
                                        $path_parts = explode('/', $relative_path_dir);
                                        $escaped_parts = array_map(function($p) { return preg_quote($p, '/'); }, $path_parts);
                                        $path_pattern = implode('\\\\?\/', $escaped_parts) . '\\\\?\/';
                                    }
                                    $pattern = '/' . $path_pattern . preg_quote($name, '/') . '\.(jpg|jpeg|png|gif|webp|svg|ico|bmp)/i';
                                    if (preg_match($pattern, $mval)) {
                                        $found_in_meta = true; break;
                                    }
                                }
                            }
                        }
                        if ($found_in_meta) break;
                    }
                    if ($found_in_meta) break;
                }
            }
            
            if (!$found_in_meta) continue;

            // 验证文章是否存在且可访问
            $view_link = '';
            $permalink = get_permalink($post->ID);
            if ($permalink && $post->post_status === 'publish') {
                $post_obj = get_post($post->ID);
                if ($post_obj && $post_obj->post_status === 'publish') {
                    $view_link = $permalink;
                }
            }
            
            // 获取meta字段名称以便显示更详细的信息
            // 同样需要严格匹配
            $meta_search_conditions_strict = [];
            $meta_search_conditions_strict[] = $wpdb->prepare("meta_value = %d", $attachment_id);
            $meta_search_conditions_strict[] = $wpdb->prepare("meta_value LIKE %s", '%"' . $attachment_id . '"%');
            foreach ($image_urls as $url) {
                $meta_search_conditions_strict[] = $wpdb->prepare("meta_value LIKE %s", '%' . $wpdb->esc_like($url) . '%');
            }
            if ($relative_path) {
                $meta_search_conditions_strict[] = $wpdb->prepare("meta_value LIKE %s", '%' . $wpdb->esc_like($relative_path) . '%');
            }
            foreach ($filenames_to_search_meta as $fname) {
                // 带上路径验证，虽然 SQL 层面较难完美实现，但至少比只搜文件名精准
                if (!empty($relative_path_dir)) {
                    $meta_search_conditions_strict[] = $wpdb->prepare("meta_value LIKE %s", '%' . $wpdb->esc_like($relative_path_dir . '/' . $fname) . '%');
                } else {
                    $meta_search_conditions_strict[] = $wpdb->prepare("meta_value LIKE %s", '%' . $wpdb->esc_like($fname) . '%');
                }
            }

            $meta_keys = $wpdb->get_col($wpdb->prepare("
                SELECT DISTINCT meta_key
                FROM {$wpdb->postmeta}
                WHERE post_id = %d
                AND meta_key NOT IN ('" . implode("','", array_map('esc_sql', $excluded_meta_keys)) . "')
                AND (" . implode(' OR ', $meta_search_conditions_strict) . ")
                LIMIT 3
            ", $post->ID));
            
            $meta_info = '';
            if (!empty($meta_keys)) {
                $meta_info = ' (' . implode(', ', array_slice($meta_keys, 0, 2)) . (count($meta_keys) > 2 ? '...' : '') . ')';
            }
            
            // 显示文章状态信息
            $status_info = '';
            if ($post->post_status === 'draft') {
                $status_info = ' <span style="color: #d63638;">[草稿]</span>';
            } elseif ($post->post_status === 'pending') {
                $status_info = ' <span style="color: #d63638;">[待审核]</span>';
            } elseif ($post->post_status === 'private') {
                $status_info = ' <span style="color: #d63638;">[私有]</span>';
            } elseif ($post->post_status !== 'publish') {
                $status_info = ' <span style="color: #d63638;">[' . esc_html($post->post_status) . ']</span>';
            }
            
            $usage_locations[] = [
                'type' => 'meta',
                'id' => $post->ID,
                'title' => $post->post_title . $meta_info . $status_info,
                'post_type' => $post->post_type,
                'status' => $post->post_status,
                'edit_link' => get_edit_post_link($post->ID),
                'view_link' => $view_link
            ];
        }
    }
    
    // 搜索选项表中的使用（如主题选项等）
    $option_search_terms = [];
    foreach ($image_urls as $url) {
        $option_search_terms[] = '%' . $wpdb->esc_like($url) . '%';
    }
    
    if ($relative_path) {
        $option_search_terms[] = '%' . $wpdb->esc_like($relative_path) . '%';
        if (strtolower($extension) === 'webp') {
            $option_search_terms[] = '%' . $wpdb->esc_like(preg_replace('/\.webp$/i', '.jpg', $relative_path)) . '%';
            $option_search_terms[] = '%' . $wpdb->esc_like(preg_replace('/\.webp$/i', '.png', $relative_path)) . '%';
        }
    }
    
    foreach ($filenames_to_search_meta as $fname) {
        $option_search_terms[] = '%' . $wpdb->esc_like($fname) . '%';
    }
    if ($allow_loose_filename_search) {
        foreach ([$filename_without_ext, $original_filename] as $name) {
            if (!empty($name)) {
                $option_search_terms[] = '%' . $wpdb->esc_like($name) . '%';
            }
        }
    }
    
    $option_like_conditions = [];
    foreach ($option_search_terms as $pattern) {
        $option_like_conditions[] = $wpdb->prepare("option_value LIKE %s", $pattern);
    }
    
    // 排除极其常见的选项，避免误匹配短的文件名（如 home）
    $excluded_option_names = ['home', 'siteurl', 'blogname', 'blogdescription'];
    
    $options = $wpdb->get_results("
        SELECT option_name, option_value
        FROM {$wpdb->options}
        WHERE option_name NOT IN ('" . implode("','", array_map('esc_sql', $excluded_option_names)) . "')
        AND option_name NOT LIKE '\_transient\_%'
        AND option_name NOT LIKE '\_site\_transient\_%'
        AND (" . implode(' OR ', $option_like_conditions) . ")
    ");
    
    foreach ($options as $option) {
        $found = false;
        // 检查所有可能的URL
        foreach ($image_urls as $url) {
            if (strpos($option->option_value, $url) !== false) {
                $found = true;
                break;
            }
        }
        // 检查完整文件名
        if (!$found) {
            foreach ($filenames_to_search_meta as $fname) {
                if (strpos($option->option_value, $fname) !== false) {
                    // 同样验证目录信息
                    $path_check = true;
                    if (!empty($relative_path_dir)) {
                        if (strpos($option->option_value, $relative_path_dir . '/' . $fname) === false && strpos($option->option_value, $relative_path_dir . '\\' . $fname) === false) {
                            $path_check = false;
                        }
                    }
                    if ($path_check) {
                        $found = true; break;
                    }
                }
            }
        }
        // 检查文件名（配合正则校验）
        if (!$found && $allow_loose_filename_search) {
            foreach ([$filename_without_ext, $original_filename] as $name) {
                if (empty($name)) continue;
                if (strpos($option->option_value, $name) !== false) {
                    $path_pattern = '';
                    if (!empty($relative_path_dir)) {
                        // 强制要求匹配相对路径，同时兼顾 JSON 转义形式的 \/
                        $path_parts = explode('/', $relative_path_dir);
                        $escaped_parts = array_map(function($p) { return preg_quote($p, '/'); }, $path_parts);
                        $path_pattern = implode('\\\\?\/', $escaped_parts) . '\\\\?\/';
                    }
                    $pattern = '/' . $path_pattern . preg_quote($name, '/') . '\.(jpg|jpeg|png|gif|webp|svg|ico|bmp)/i';
                    if (preg_match($pattern, $option->option_value)) {
                        $found = true;
                        break;
                    }
                }
            }
        }
        
        if ($found) {
            $usage_locations[] = [
                'type' => 'option',
                'id' => 0,
                'title' => '选项: ' . $option->option_name,
                'post_type' => '',
                'status' => '',
                'edit_link' => admin_url('options.php'),
                'view_link' => ''
            ];
        }
    }
    
    // 搜索 usermeta 表中的使用（如用户头像 Simple Local Avatars 等）
    $usermeta_like_conditions = [];
    foreach ($option_search_terms as $pattern) {
        $usermeta_like_conditions[] = $wpdb->prepare("meta_value LIKE %s", $pattern);
    }
    
    if (!empty($usermeta_like_conditions)) {
        // 排除一些常见但不可能包含文件名的用户元数据，优化查询性能
        $excluded_usermeta_keys = ['nickname', 'first_name', 'last_name', 'description', 'rich_editing', 'syntax_highlighting', 'comment_shortcuts', 'admin_color', 'use_ssl', 'show_admin_bar_front', 'locale', 'wp_capabilities', 'wp_user_level', 'dismissed_wp_pointers', 'show_welcome_panel', 'session_tokens'];
        
        $usermetas = $wpdb->get_results("
            SELECT user_id, meta_key, meta_value
            FROM {$wpdb->usermeta}
            WHERE meta_key NOT IN ('" . implode("','", array_map('esc_sql', $excluded_usermeta_keys)) . "')
            AND (" . implode(' OR ', $usermeta_like_conditions) . ")
        ");
        
        foreach ($usermetas as $umeta) {
            $found = false;
            // 检查所有的URL
            foreach ($image_urls as $url) {
                if (strpos($umeta->meta_value, $url) !== false) {
                    $found = true;
                    break;
                }
            }
            // 检查完整文件名
            if (!$found) {
                foreach ($filenames_to_search_meta as $fname) {
                    if (strpos($umeta->meta_value, $fname) !== false) {
                        $path_check = true;
                        if (!empty($relative_path_dir)) {
                            if (strpos($umeta->meta_value, $relative_path_dir . '/' . $fname) === false && strpos($umeta->meta_value, $relative_path_dir . '\\' . $fname) === false) {
                                $path_check = false;
                            }
                        }
                        if ($path_check) {
                            $found = true; break;
                        }
                    }
                }
            }
            // 检查文件名（配合正则校验）
            if (!$found && $allow_loose_filename_search) {
                foreach ([$filename_without_ext, $original_filename] as $name) {
                    if (empty($name)) continue;
                    if (strpos($umeta->meta_value, $name) !== false) {
                        $path_pattern = '';
                        if (!empty($relative_path_dir)) {
                            // 强制要求匹配相对路径，同时兼顾 JSON 转义形式的 \/
                            $path_parts = explode('/', $relative_path_dir);
                            $escaped_parts = array_map(function($p) { return preg_quote($p, '/'); }, $path_parts);
                            $path_pattern = implode('\\\\?\/', $escaped_parts) . '\\\\?\/';
                        }
                        $pattern = '/' . $path_pattern . preg_quote($name, '/') . '\.(jpg|jpeg|png|gif|webp|svg|ico|bmp)/i';
                        if (preg_match($pattern, $umeta->meta_value)) {
                            $found = true;
                            break;
                        }
                    }
                }
            }
            // 检查 ID 直接存储的情况 (例如 simple_local_avatar 的 media_id)
            if (!$found) {
                if ($umeta->meta_value == $attachment_id || strpos($umeta->meta_value, '"' . $attachment_id . '"') !== false || strpos($umeta->meta_value, ':"' . $attachment_id . '";') !== false || strpos($umeta->meta_value, 'i:' . $attachment_id . ';') !== false) {
                    $found = true;
                }
            }
            
            if ($found) {
                $user_info = get_userdata($umeta->user_id);
                $display_name = $user_info ? $user_info->display_name : '用户 ID: ' . $umeta->user_id;
                $usage_locations[] = [
                    'type' => 'usermeta',
                    'id' => $umeta->user_id,
                    'title' => '用户头像/资料 (' . $display_name . ')',
                    'post_type' => 'user',
                    'status' => '',
                    'edit_link' => get_edit_user_link($umeta->user_id),
                    'view_link' => get_author_posts_url($umeta->user_id)
                ];
            }
        }
    }

    
    self::$usage_cache[$attachment_id] = $usage_locations;
    return $usage_locations;
}
}
