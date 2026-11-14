<?php
if (!defined('ABSPATH')) { exit; }

function portfolio_project_new_url() {
    return admin_url('admin.php?page=portfolio-projects&view=new');
}

function portfolio_project_available_slug($title, $used) {
    $base = sanitize_title($title) ?: 'project';
    $slug = $base; $suffix = 2;
    while (in_array($slug, $used, true)) { $slug = $base . '-' . $suffix++; }
    return $slug;
}

function portfolio_create_project($input) {
    if (!current_user_can('edit_posts')) { throw new RuntimeException('You do not have permission to create projects.'); }
    if (!is_array($input)) { throw new RuntimeException('Invalid project form.'); }
    $title = is_string($input['title'] ?? null) ? sanitize_text_field($input['title']) : '';
    $locale = $input['locale'] ?? null; $key = $input['key'] ?? null;
    $type = portfolio_project_type($input['project_type'] ?? null);
    if ($title === '' || !$type) { throw new RuntimeException('Enter a project title and choose a project type.'); }
    if (!is_string($locale) || !isset(portfolio_languages()[$locale])) { throw new RuntimeException('Choose a registered language.'); }
    if (!is_string($key) || !wp_is_uuid($key, 4)) { throw new RuntimeException('Invalid creation form. Open Add project again.'); }
    global $wpdb;
    $lock = 'portfolio-create-project';
    if ((int) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 0)', $lock)) !== 1) { throw new RuntimeException('Another project is being created. Try again.'); }
    $id = 0;
    try {
        $existing = get_posts(['post_type' => 'post', 'post_status' => ['publish', 'draft', 'private', 'pending', 'future', 'trash'], 'numberposts' => 1, 'meta_key' => '_portfolio_project_key', 'meta_value' => $key]);
        if ($existing) {
            $id = portfolio_project_root($existing[0]->ID);
            if (!$id || get_post_status($id) === 'trash' || !current_user_can('edit_post', $id)) { throw new RuntimeException('This creation form has already been used.'); }
            return $id;
        }
        $used = [];
        foreach (get_posts(['post_type' => 'post', 'post_status' => 'any', 'numberposts' => -1]) as $post) {
            $used[] = get_post_meta($post->ID, '_portfolio_public_slug', true) ?: preg_replace('/-2$/', '', $post->post_name);
        }
        $slug = portfolio_project_available_slug($title, $used);
        if ($wpdb->query('START TRANSACTION') === false) { throw new RuntimeException('Could not start project creation.'); }
        $id = wp_insert_post(wp_slash(['post_type' => 'post', 'post_status' => 'draft', 'post_title' => $title, 'post_name' => $slug]), true);
        if (is_wp_error($id)) { throw new RuntimeException($id->get_error_message()); }
        portfolio_store_meta($id, '_portfolio_root_id', $id);
        portfolio_store_meta($id, '_portfolio_project_key', $key);
        portfolio_store_meta($id, '_portfolio_locale', $locale);
        portfolio_store_meta($id, '_portfolio_public_slug', $slug);
        portfolio_store_meta($id, '_portfolio_shared', ['project_type' => $type, 'project_url' => '', 'order' => portfolio_order_next('projects'), 'technology_ids' => [], 'gallery' => [], 'challenges' => []]);
        portfolio_store_meta($id, '_portfolio_local', portfolio_project_local_defaults());
        if ($wpdb->query('COMMIT') === false) { throw new RuntimeException('Could not commit the new project.'); }
        return $id;
    } catch (Throwable $error) {
        $wpdb->query('ROLLBACK');
        if (is_int($id) && $id > 0) { clean_post_cache($id); }
        throw $error;
    } finally { $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock)); }
}

function portfolio_project_create_page() {
    portfolio_admin_header('Add project', ['All projects' => admin_url('admin.php?page=portfolio-projects')]);
    echo '<p>Create the first language version as a draft. Add translations from the project editor.</p>';
    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><section>';
    wp_nonce_field('portfolio_create_project');
    printf('<input type="hidden" name="action" value="portfolio_create_project"><input type="hidden" name="portfolio[key]" value="%s">', esc_attr(wp_generate_uuid4()));
    echo '<p><label>Title <input class="regular-text" name="portfolio[title]" required></label></p><p><label>Initial language <select name="portfolio[locale]" required>';
    foreach (portfolio_languages() as $locale => $label) { printf('<option value="%s">%s</option>', esc_attr($locale), esc_html($label)); }
    echo '</select></label></p>';
    portfolio_admin_project_type();
    submit_button('Create draft');
    echo '</section></form>';
}

add_action('admin_post_portfolio_create_project', function () {
    check_admin_referer('portfolio_create_project');
    try { $id = portfolio_create_project(wp_unslash($_POST['portfolio'] ?? null)); }
    catch (Throwable $error) { wp_die(esc_html($error->getMessage()), 'Project not created', ['back_link' => true]); }
    wp_safe_redirect(admin_url('admin.php?page=portfolio-projects&project=' . $id)); exit;
});

add_action('admin_menu', function () {
    if (get_option('portfolio_language_ui_enabled')) { remove_menu_page('edit.php'); }
}, 99);
add_action('admin_bar_menu', function ($bar) {
    if (get_option('portfolio_language_ui_enabled') && current_user_can('edit_posts')) { $bar->add_node(['id' => 'new-post', 'parent' => 'new-content', 'title' => 'Project', 'href' => portfolio_project_new_url()]); }
}, 100);
add_action('load-edit.php', function () {
    if (get_option('portfolio_language_ui_enabled') && ($_GET['post_type'] ?? 'post') === 'post') { wp_safe_redirect(admin_url('admin.php?page=portfolio-projects')); exit; }
});
add_action('load-post-new.php', function () {
    if (get_option('portfolio_language_ui_enabled') && ($_GET['post_type'] ?? 'post') === 'post') { wp_safe_redirect(portfolio_project_new_url()); exit; }
});
add_action('load-post.php', function () {
    if (!get_option('portfolio_language_ui_enabled') || ($_GET['action'] ?? '') !== 'edit') { return; }
    $id = absint($_GET['post'] ?? 0);
    if (get_post_type($id) !== 'post' || !current_user_can('edit_post', $id)) { return; }
    $root = portfolio_project_root($id);
    wp_safe_redirect(admin_url('admin.php?page=portfolio-projects' . ($root ? '&project=' . $root : ''))); exit;
});
