<?php
if (!defined('ABSPATH')) { exit; }

function portfolio_order_items($kind, $category = '') {
    $query = ['post_type' => $kind === 'projects' ? 'post' : 'technology', 'post_status' => ['publish', 'draft', 'pending', 'private', 'future'], 'numberposts' => -1, 'orderby' => ['date' => 'DESC', 'ID' => 'DESC'], 'suppress_filters' => false];
    if ($kind === 'projects') { $query['meta_key'] = '_portfolio_shared'; }
    $items = [];
    foreach (get_posts($query) as $post) {
        if ($kind === 'projects') {
            if (portfolio_project_root($post->ID) !== $post->ID) { continue; }
            $state = get_post_meta($post->ID, '_portfolio_shared', true);
            $order = $state['order'] ?? 0;
        } else {
            $key = get_post_meta($post->ID, 'technology_category', true);
            if (!is_string($key) || !isset(portfolio_technology_categories()[$key])) { $key = 'uncategorized'; }
            if ($category && $category !== $key) { continue; }
            $order = get_post_meta($post->ID, 'order', true);
            $state = [$order, $key];
        }
        $items[] = ['id' => $post->ID, 'title' => $post->post_title, 'status' => $post->post_status, 'order' => is_numeric($order) ? (float) $order : 0, 'version' => hash('sha256', serialize([$state, $post->post_status, $post->post_title]))];
    }
    // Keep REST date/ID order when ranks are equal.
    usort($items, static fn($a, $b) => $a['order'] <=> $b['order']);
    return $items;
}

function portfolio_order_token($items) {
    return hash('sha256', serialize($items));
}

function portfolio_order_validate($items, $ids, $token) {
    if (!hash_equals(portfolio_order_token($items), $token)) { throw new RuntimeException('The list has changed since it was opened. Refresh the page and reorder the items again.'); }
    if (!is_array($ids)) { throw new RuntimeException('Invalid list.'); }
    $result = [];
    foreach ($ids as $id) {
        if ((!is_int($id) && !is_string($id)) || !ctype_digit((string) $id) || (int) $id < 1) { throw new RuntimeException('Invalid ID.'); }
        $result[] = (int) $id;
    }
    $expected = array_column($items, 'id'); $sorted = $result;
    sort($expected); sort($sorted);
    if ($expected !== $sorted) { throw new RuntimeException('The list must contain every item exactly once.'); }
    return $result;
}

function portfolio_technology_order_field() {
    static $key;
    if ($key) { return $key; }
    foreach (acf_get_field_groups(['post_type' => 'technology']) as $group) {
        foreach (acf_get_fields($group) ?: [] as $field) {
            if ($field['name'] === 'order') { return $key = $field['key']; }
        }
    }
    throw new RuntimeException('The technology order field is missing.');
}

function portfolio_order_next($kind) {
    $orders = array_column(portfolio_order_items($kind), 'order');
    return (int) max([0, ...$orders]) + 10;
}

function portfolio_order_permissions($kind, $items) {
    if (!current_user_can('edit_others_posts')) { throw new RuntimeException('You do not have permission to reorder items.'); }
    foreach ($items as $item) {
        $ids = [$item['id']];
        if ($kind === 'projects') { $ids = array_merge($ids, wp_list_pluck(portfolio_project_translations($item['id']), 'ID')); }
        foreach ($ids as $id) { if (!current_user_can('edit_post', $id)) { throw new RuntimeException('You do not have permission to edit item: ' . $item['title']); } }
    }
}

function portfolio_order_write($kind, $ids) {
    foreach ($ids as $index => $id) {
        $rank = ($index + 1) * 10;
        if ($kind === 'projects') {
            $shared = get_post_meta($id, '_portfolio_shared', true);
            $shared['order'] = $rank;
            portfolio_store_meta($id, '_portfolio_shared', $shared);
        } else {
            update_field(portfolio_technology_order_field(), $rank, $id);
            if ((string) get_post_meta($id, 'order', true) !== (string) $rank) { throw new RuntimeException('Could not save the technology order.'); }
        }
    }
}

function portfolio_order_save($kind, $category, $ids, $token) {
    global $wpdb;
    $items = portfolio_order_items($kind, $category);
    portfolio_order_permissions($kind, $items);
    $ordered_ids = portfolio_order_validate($items, $ids, $token);
    $locks = []; $lock_ids = $ordered_ids; sort($lock_ids);
    try {
        foreach ($lock_ids as $id) {
            $lock = ($kind === 'projects' ? 'portfolio-project-' : 'portfolio-technology-') . $id;
            if ((int) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 0)', $lock)) !== 1) { throw new RuntimeException('An item is being saved. Try again.'); }
            $locks[] = $lock;
        }
        if ($wpdb->query('START TRANSACTION') === false) { throw new RuntimeException('Could not start saving.'); }
        if ($lock_ids) {
            $placeholders = implode(',', array_fill(0, count($lock_ids), '%d'));
            $wpdb->get_results($wpdb->prepare("SELECT meta_id FROM {$wpdb->postmeta} WHERE post_id IN ($placeholders) AND meta_key IN ('_portfolio_shared', 'order', 'technology_category') FOR UPDATE", ...$lock_ids));
            if ($wpdb->last_error) { throw new RuntimeException('Could not lock the data for saving.'); }
        }
        foreach ($lock_ids as $id) { clean_post_cache($id); }
        $current = portfolio_order_items($kind, $category);
        portfolio_order_validate($current, $ids, $token);
        portfolio_order_write($kind, $ordered_ids);
        if ($wpdb->query('COMMIT') === false) { throw new RuntimeException('Could not commit the new order.'); }
    } catch (Throwable $error) {
        $wpdb->query('ROLLBACK');
        foreach ($lock_ids as $id) { clean_post_cache($id); if ($kind !== 'projects') { acf_flush_value_cache($id, 'order'); } }
        throw $error;
    } finally {
        foreach ($locks as $lock) { $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock)); }
    }
}

function portfolio_order_scope($raw) {
    $kind = $raw['kind'] ?? 'projects';
    if (!in_array($kind, ['projects', 'technologies'], true)) { throw new RuntimeException('Unknown list type.'); }
    $category = $kind === 'projects' ? '' : ($raw['category'] ?? 'frontend');
    if ($kind === 'technologies' && (!is_string($category) || !array_key_exists($category, portfolio_technology_categories() + ['uncategorized' => []]))) { throw new RuntimeException('Unknown category.'); }
    return [$kind, $category];
}

function portfolio_order_url($kind, $category = '') {
    return add_query_arg(['page' => 'portfolio-order', 'kind' => $kind, 'category' => $category], admin_url('admin.php'));
}

add_action('admin_menu', function () {
    add_submenu_page('portfolio-projects', 'Portfolio order', 'Order', 'edit_others_posts', 'portfolio-order', 'portfolio_order_page');
});
add_action('admin_enqueue_scripts', function ($hook) {
    if ($hook !== 'portfolio_page_portfolio-order') { return; }
    wp_enqueue_script('portfolio-order', plugins_url('ordering.js', __FILE__), ['jquery-ui-sortable'], (string) filemtime(__DIR__ . '/ordering.js'), true);
    wp_enqueue_style('portfolio-order', plugins_url('ordering.css', __FILE__), [], (string) filemtime(__DIR__ . '/ordering.css'));
});

function portfolio_order_page() {
    try {
        [$kind, $category] = portfolio_order_scope(wp_unslash($_GET));
        $items = portfolio_order_items($kind, $category);
        portfolio_order_permissions($kind, $items);
    } catch (Throwable $error) { wp_die(esc_html($error->getMessage())); }
    echo '<div class="wrap portfolio-order"><h1>Portfolio order</h1><nav class="nav-tab-wrapper" aria-label="List type">';
    foreach (['projects' => 'Projects', 'technologies' => 'Technologies'] as $key => $label) {
        printf('<a class="nav-tab%s" href="%s" %s>%s</a>', $kind === $key ? ' nav-tab-active' : '', esc_url(portfolio_order_url($key, $key === 'technologies' ? 'frontend' : '')), $kind === $key ? 'aria-current="page"' : '', esc_html($label));
    }
    echo '</nav>';
    if ($kind === 'technologies') {
        echo '<form method="get" class="portfolio-order-filter"><input type="hidden" name="page" value="portfolio-order"><input type="hidden" name="kind" value="technologies"><label>Category <select name="category">';
        foreach (portfolio_technology_category_definitions() + ['uncategorized' => 'Other'] as $key => $label) {
            printf('<option value="%s" %s>%s</option>', esc_attr($key), selected($category, $key, false), esc_html($label));
        }
        echo '</select></label> '; submit_button('Show', 'secondary', 'submit', false); echo '</form>';
    }
    if (isset($_GET['saved'])) { echo '<div class="notice notice-success"><p>Order saved.</p></div>'; }
    echo '<p>Drag items by their handles or use the up and down buttons. Click Save order to save your changes.</p>';
    if ($kind === 'projects') { echo '<p>Project order is shared across all language versions.</p>'; }
    echo '<p>Refresh the exported data and rebuild the frontend to show the new order on the site.</p>';
    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="portfolio-order-form">';
    wp_nonce_field('portfolio_order_' . $kind . '_' . $category);
    printf('<input type="hidden" name="action" value="portfolio_order_save"><input type="hidden" name="kind" value="%s"><input type="hidden" name="category" value="%s"><input type="hidden" name="token" value="%s">', esc_attr($kind), esc_attr($category), esc_attr(portfolio_order_token($items)));
    echo '<div class="portfolio-order-actions"><button type="submit" class="button button-primary" data-order-save disabled>Save order</button> <button type="button" class="button" data-order-reset disabled>Discard unsaved changes</button> <span data-order-status role="status" aria-live="polite">No unsaved changes.</span></div><ol class="portfolio-order-list">';
    foreach ($items as $index => $item) {
        $id = $item['id']; $title = $item['title'];
        printf('<li data-order-id="%d"><input type="hidden" name="ids[]" value="%d"><span class="portfolio-order-number">%d</span><span class="dashicons dashicons-move portfolio-order-grip" aria-hidden="true"></span>', $id, $id, $index + 1);
        echo get_the_post_thumbnail($id, 'thumbnail', ['class' => 'portfolio-order-thumbnail', 'alt' => '', 'loading' => 'lazy']);
        $href = $kind === 'projects' ? admin_url('admin.php?page=portfolio-projects&project=' . $id) : get_edit_post_link($id, '');
        printf('<span class="portfolio-order-title"><a href="%s">%s</a>', esc_url($href), esc_html($title));
        if ($kind === 'projects') {
            $locales = array_map(static fn($post) => strtoupper(get_post_meta($post->ID, '_portfolio_locale', true)), portfolio_project_translations($id));
            echo '<small>' . esc_html(implode(' / ', $locales)) . '</small>';
        }
        if ($item['status'] !== 'publish') { echo '<small>' . esc_html(get_post_status_object($item['status'])->label) . '</small>'; }
        echo '</span><span class="portfolio-order-controls">';
        foreach (['up' => 'up', 'down' => 'down'] as $direction => $label) {
            printf('<button type="button" class="button" data-order-move="%s" aria-label="%s"><span class="dashicons dashicons-arrow-%s-alt2" aria-hidden="true"></span></button>', $direction, esc_attr('Move ' . $title . ' ' . $label), $direction);
        }
        echo '</span></li>';
    }
    echo '</ol></form><noscript><p>Enable JavaScript to reorder items.</p></noscript></div>';
}

add_action('admin_post_portfolio_order_save', function () {
    try {
        $raw = wp_unslash($_POST);
        [$kind, $category] = portfolio_order_scope($raw);
        check_admin_referer('portfolio_order_' . $kind . '_' . $category);
        portfolio_order_save($kind, $category, $raw['ids'] ?? [], is_string($raw['token'] ?? null) ? $raw['token'] : '');
    } catch (Throwable $error) { wp_die(esc_html($error->getMessage()), 'Order not saved', ['back_link' => true]); }
    wp_safe_redirect(add_query_arg('saved', '1', portfolio_order_url($kind, $category))); exit;
});

add_filter('acf/prepare_field', function ($field) {
    if (!$field || ($field['_name'] ?? $field['name'] ?? '') !== 'order') { return $field; }
    $id = absint($_GET['post'] ?? 0);
    $screen = function_exists('get_current_screen') ? get_current_screen() : null;
    return ($screen && $screen->post_type === 'technology') || ($id && get_post_type($id) === 'technology') ? false : $field;
});
add_action('edit_form_after_title', function ($post) {
    if ($post->post_type === 'technology') {
        printf('<p>Set the display order in <a href="%s">Portfolio → Order → Technologies</a>.</p>', esc_url(portfolio_order_url('technologies', get_post_meta($post->ID, 'technology_category', true) ?: 'frontend')));
    }
});
add_action('wp_after_insert_post', function ($id, $post, $update, $before) {
    if ($post->post_status === 'auto-draft' || ($update && (!$before || $before->post_status !== 'auto-draft'))) { return; }
    if ($post->post_type === 'technology' && !metadata_exists('post', $id, 'order')) {
        update_field(portfolio_technology_order_field(), portfolio_order_next('technologies'), $id);
    }
}, 20, 4);
