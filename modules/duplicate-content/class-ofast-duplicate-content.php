<?php

/**
 * Ofast X - Content Duplicator Module
 * Duplicate posts and pages with one click
 */

if (!defined('ABSPATH')) {
    exit;
}

class Ofast_X_Duplicate_Content
{
    /**
     * Initialize module
     */
    public function init()
    {
        // Only load if module is enabled
        $enabled = get_option('ofastx_modules_enabled', array());
        if (empty($enabled['duplicate-content'])) {
            return;
        }

        // Add duplicate link to post row actions
        add_filter('post_row_actions', array($this, 'add_duplicate_link'), 10, 2);
        add_filter('page_row_actions', array($this, 'add_duplicate_link'), 10, 2);

        // Handle duplicate action
        add_action('admin_action_ofast_duplicate_post', array($this, 'duplicate_post'));

        // Admin notices
        add_action('admin_notices', array($this, 'show_duplicate_notice'));
    }

    /**
     * Add duplicate link to row actions
     */
    public function add_duplicate_link($actions, $post)
    {
        if (!current_user_can('edit_posts')) {
            return $actions;
        }

        // Only for posts and pages (can extend to custom post types)
        $allowed_types = array('post', 'page');
        if (!in_array($post->post_type, $allowed_types)) {
            return $actions;
        }

        $url = wp_nonce_url(
            admin_url('admin.php?action=ofast_duplicate_post&post=' . $post->ID),
            'ofast_duplicate_' . $post->ID,
            'ofast_nonce'
        );

        $actions['duplicate'] = sprintf(
            '<a href="%s" title="%s" style="color: #2271b1;">%s</a>',
            esc_url($url),
            esc_attr__('Duplicate this item'),
            'Duplicate'
        );

        return $actions;
    }

    /**
     * Duplicate post handler
     */
    public function duplicate_post()
    {
        // Security checks
        if (!isset($_GET['post']) || !isset($_GET['ofast_nonce'])) {
            wp_die('Invalid request');
        }

        $post_id = intval($_GET['post']);

        if (!wp_verify_nonce($_GET['ofast_nonce'], 'ofast_duplicate_' . $post_id)) {
            wp_die('Security check failed');
        }

        if (!current_user_can('edit_posts')) {
            wp_die('You do not have permission to duplicate posts');
        }

        // Get original post
        $post = get_post($post_id);
        if (!$post) {
            wp_die('Post not found');
        }

        // Create duplicate post data
        $new_post = array(
            'post_title'     => $post->post_title . ' (Copy)',
            'post_content'   => $post->post_content,
            'post_excerpt'   => $post->post_excerpt,
            'post_status'    => 'draft',
            'post_type'      => $post->post_type,
            'post_author'    => get_current_user_id(),
            'post_parent'    => $post->post_parent,
            'menu_order'     => $post->menu_order,
            'comment_status' => $post->comment_status,
            'ping_status'    => $post->ping_status,
            'post_password'  => $post->post_password,
        );

        // Insert new post
        $new_post_id = wp_insert_post($new_post);

        if (is_wp_error($new_post_id)) {
            wp_die('Failed to duplicate post: ' . $new_post_id->get_error_message());
        }

        // Copy post meta
        $this->duplicate_post_meta($post_id, $new_post_id);

        // Copy taxonomies (categories, tags, custom taxonomies)
        $this->duplicate_taxonomies($post_id, $new_post_id, $post->post_type);

        // Copy featured image
        $thumbnail_id = get_post_thumbnail_id($post_id);
        if ($thumbnail_id) {
            set_post_thumbnail($new_post_id, $thumbnail_id);
        }

        // Redirect to edit screen with success message
        $redirect_url = add_query_arg(
            array(
                'post_type' => $post->post_type,
                'ofast_duplicated' => 1,
                'new_post' => $new_post_id,
            ),
            admin_url('edit.php')
        );

        wp_safe_redirect($redirect_url);
        exit;
    }

    /**
     * Duplicate post meta
     */
    private function duplicate_post_meta($original_id, $new_id)
    {
        global $wpdb;

        // Get all post meta
        $post_meta = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id = %d",
                $original_id
            )
        );

        if (empty($post_meta)) {
            return;
        }

        // Meta keys to skip
        $skip_keys = array('_edit_lock', '_edit_last', '_wp_old_slug', '_wp_old_date');

        foreach ($post_meta as $meta) {
            if (in_array($meta->meta_key, $skip_keys)) {
                continue;
            }

            // Skip internal WordPress meta that starts with _wp_
            if (strpos($meta->meta_key, '_wp_attached') === 0) {
                continue;
            }

            add_post_meta($new_id, $meta->meta_key, maybe_unserialize($meta->meta_value));
        }
    }

    /**
     * Duplicate taxonomies
     */
    private function duplicate_taxonomies($original_id, $new_id, $post_type)
    {
        $taxonomies = get_object_taxonomies($post_type);

        foreach ($taxonomies as $taxonomy) {
            $terms = wp_get_object_terms($original_id, $taxonomy, array('fields' => 'ids'));

            if (!empty($terms) && !is_wp_error($terms)) {
                wp_set_object_terms($new_id, $terms, $taxonomy);
            }
        }
    }

    /**
     * Show success notice after duplication
     */
    public function show_duplicate_notice()
    {
        if (!isset($_GET['ofast_duplicated']) || $_GET['ofast_duplicated'] != 1) {
            return;
        }

        $new_post_id = isset($_GET['new_post']) ? intval($_GET['new_post']) : 0;
        $edit_link = $new_post_id ? get_edit_post_link($new_post_id) : '';

?>
        <div class="notice notice-success is-dismissible">
            <p>
                ✅ Content duplicated successfully!
                <?php if ($edit_link): ?>
                    <a href="<?php echo esc_url($edit_link); ?>">Edit the duplicate</a>
                <?php endif; ?>
            </p>
        </div>
<?php
    }
}
