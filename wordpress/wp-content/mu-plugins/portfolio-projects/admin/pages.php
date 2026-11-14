<?php
if (!defined('ABSPATH')) { exit; }

function portfolio_page_locale($id) {
    return get_post_meta($id, '_portfolio_locale', true) ?: (get_field('lang', $id) ?: 'pl');
}
function portfolio_page_root($id) {
    if (get_post_type($id) !== 'page') { return 0; }
    $root = (int) get_post_meta($id, '_portfolio_page_root_id', true);
    $key = get_post_meta($id, '_portfolio_page_key', true);
    return $root && $key && get_post_type($root) === 'page' && get_post_meta($root, '_portfolio_page_key', true) === $key ? $root : $id;
}
function portfolio_page_versions($root) {
    if (!is_numeric($root) || get_post_type((int) $root) !== 'page') { return []; }
    $key = get_post_meta($root, '_portfolio_page_key', true);
    if (!$key) { return [get_post($root)]; }
    return array_values(array_filter(get_posts(['post_type' => 'page', 'post_status' => ['publish','draft','private','pending','future'], 'numberposts' => -1, 'meta_key' => '_portfolio_page_root_id', 'meta_value' => $root]), static fn($post) => get_post_meta($post->ID, '_portfolio_page_key', true) === $key));
}
function portfolio_page_url($root = 0, $locale = '') {
    return add_query_arg(array_filter(['page' => 'portfolio-pages', 'entry' => $root, 'language' => $locale]), admin_url('admin.php'));
}
function portfolio_page_token($id) {
    $meta = get_post_meta($id); ksort($meta);
    return hash('sha256', serialize([get_post($id)->to_array(), $meta]));
}
function portfolio_page_transaction($root, $callback) {
    global $wpdb;
    $lock = 'portfolio-page-' . $root;
    if ((int) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 0)', $lock)) !== 1) { throw new RuntimeException('This page is being saved. Try again.'); }
    $created = [];
    try {
        if ($wpdb->query('START TRANSACTION') === false) { throw new RuntimeException('Could not start saving the page.'); }
        $result = $callback($created);
        if ($wpdb->query('COMMIT') === false) { throw new RuntimeException('Could not commit the page.'); }
        return $result;
    } catch (Throwable $error) {
        $wpdb->query('ROLLBACK');
        foreach ($created as $id) { clean_post_cache($id); }
        if (is_numeric($root)) { clean_post_cache((int) $root); }
        foreach (portfolio_page_versions($root) as $post) { clean_post_cache($post->ID); }
        acf_get_store('values')->reset();
        throw $error;
    } finally { $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock)); }
}
function portfolio_page_identity($id, $root, $key, $locale) {
    portfolio_store_meta($id, '_portfolio_page_root_id', $root);
    portfolio_store_meta($id, '_portfolio_page_key', $key);
    portfolio_store_meta($id, '_portfolio_locale', $locale);
    update_field('field_6a71ed47f9195', $locale, $id);
    if (get_field('lang', $id) !== $locale) { throw new RuntimeException('Could not save the page language.'); }
}
function portfolio_page_create($input) {
    if (!current_user_can('edit_pages')) { throw new RuntimeException('You do not have permission to create pages.'); }
    if (!is_array($input) || !is_string($input['title'] ?? null) || trim(sanitize_text_field($input['title'])) === '') { throw new RuntimeException('Enter a page title.'); }
    $locale = $input['locale'] ?? null; $key = $input['key'] ?? null;
    if (!is_string($locale) || !isset(portfolio_languages()[$locale]) || !is_string($key) || !wp_is_uuid($key, 4)) { throw new RuntimeException('Invalid creation form or language.'); }
    if (!is_string($input['template'] ?? '')) { throw new RuntimeException('Invalid template value.'); }
    return portfolio_page_transaction('create', function (&$created) use ($input, $locale, $key) {
        $existing = get_posts(['post_type'=>'page','post_status'=>['publish','draft','private','pending','future','trash'],'numberposts'=>1,'meta_key'=>'_portfolio_page_key','meta_value'=>$key]);
        if ($existing) {
            $root = portfolio_page_root($existing[0]->ID);
            if (get_post_status($root) === 'trash' || !current_user_can('edit_post', $root)) { throw new RuntimeException('This creation form has already been used.'); }
            return $root;
        }
        $id = wp_insert_post(wp_slash(['post_type'=>'page','post_status'=>'draft','post_title'=>sanitize_text_field($input['title']), 'post_name'=>sanitize_title($input['title'])]), true);
        if (is_wp_error($id)) { throw new RuntimeException($id->get_error_message()); }
        $created[] = $id;
        portfolio_page_identity($id, $id, $key, $locale);
        update_field('field_6a71ed14f9194', wp_slash(sanitize_text_field($input['template'] ?? '')), $id);
        return $id;
    });
}
function portfolio_page_save($id, $token, $input, $raw_fields) {
    $root = portfolio_page_root($id);
    if (!$root || !current_user_can('edit_post', $root) || !current_user_can('edit_post', $id)) { throw new RuntimeException('You do not have permission to edit this page.'); }
    return portfolio_page_transaction($root, function () use ($id, $token, $input, $raw_fields) {
        clean_post_cache($id);
        if (!is_string($token) || !hash_equals(portfolio_page_token($id), $token)) { throw new RuntimeException('The page has changed. Refresh before saving.'); }
        if (!is_array($input) || !is_string($input['title'] ?? null) || trim(sanitize_text_field($input['title'])) === '' || !is_string($input['content'] ?? null) || !is_string($input['slug'] ?? null) || !sanitize_title($input['slug'])) { throw new RuntimeException('Enter a title, a slug and valid content.'); }
        $groups = portfolio_admin_field_groups($id, ['lang']);
        $values = portfolio_admin_validate_fields($groups, $raw_fields);
        $status = !empty($input['published']) ? 'publish' : 'draft';
        if ($status !== get_post_status($id) && !current_user_can('publish_pages')) { throw new RuntimeException('You do not have permission to change publication status.'); }
        $template = $values['field_6a71ed14f9194'] ?? get_field('template', $id);
        if ($status === 'publish' && $template === 'home') {
            foreach (get_posts(['post_type'=>'page','post_status'=>'publish','numberposts'=>-1,'exclude'=>[$id]]) as $page) {
                if (get_field('template', $page->ID) === 'home' && portfolio_page_locale($page->ID) === portfolio_page_locale($id)) { throw new RuntimeException('A published Home page already exists in this language.'); }
            }
        }
        $result = wp_update_post(wp_slash(['ID'=>$id,'post_title'=>sanitize_text_field($input['title']),'post_content'=>wp_kses_post($input['content']),'post_name'=>sanitize_title($input['slug']),'post_status'=>$status]), true);
        if (is_wp_error($result)) { throw new RuntimeException($result->get_error_message()); }
        portfolio_admin_save_fields($id, $groups, $values);
        return $id;
    });
}
function portfolio_page_add_translation($root, $locale) {
    if (portfolio_page_root($root) !== $root || !current_user_can('edit_post', $root) || !current_user_can('edit_pages')) { throw new RuntimeException('You do not have permission to add a page translation.'); }
    if (!is_string($locale) || !isset(portfolio_languages()[$locale])) { throw new RuntimeException('Choose a registered language.'); }
    return portfolio_page_transaction($root, function (&$created) use ($root, $locale) {
        foreach (portfolio_page_versions($root) as $post) { if (portfolio_page_locale($post->ID) === $locale) { throw new RuntimeException('This translation already exists.'); } }
        $key = get_post_meta($root, '_portfolio_page_key', true) ?: wp_generate_uuid4();
        if (!get_post_meta($root, '_portfolio_page_key', true)) { portfolio_page_identity($root, $root, $key, portfolio_page_locale($root)); }
        $id = wp_insert_post(wp_slash(['post_type'=>'page','post_status'=>'draft','post_title'=>get_the_title($root),'post_name'=>get_post($root)->post_name . '-' . $locale]), true);
        if (is_wp_error($id)) { throw new RuntimeException($id->get_error_message()); }
        $created[] = $id;
        portfolio_page_identity($id, $root, $key, $locale);
        update_field('field_6a71ed14f9194', wp_slash(get_field('template', $root) ?: ''), $id);
        return $id;
    });
}

add_action('admin_menu', function () {
    if (!get_option('portfolio_language_ui_enabled')) { return; }
    remove_menu_page('edit.php?post_type=page');
    add_menu_page('Pages', 'Pages', 'edit_pages', 'portfolio-pages', 'portfolio_pages_page', 'dashicons-admin-page', 20);
}, 99);
add_action('admin_enqueue_scripts', function ($hook) {
    if ($hook === 'toplevel_page_portfolio-pages') { portfolio_admin_enqueue_editor(absint($_GET['entry'] ?? 0)); }
});
function portfolio_pages_page() {
    if (!current_user_can('edit_pages')) { wp_die('You do not have permission to edit pages.'); }
    echo '<div class="wrap portfolio-admin">';
    if (($_GET['view'] ?? '') === 'new') {
        portfolio_admin_header('Add page', ['All pages'=>portfolio_page_url()]);
        echo '<p>Create the first language version as a draft.</p><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><section>';
        portfolio_admin_creation_fields('portfolio_create_page');
        echo '<p><label>Template <input class="regular-text" name="portfolio[template]"></label></p>';
        submit_button('Create draft'); echo '</section></form></div>'; return;
    }
    portfolio_admin_header('Pages', ['Add page'=>add_query_arg('view','new',portfolio_page_url())]);
    $root = absint($_GET['entry'] ?? 0);
    if (!$root) {
        echo '<table class="widefat striped"><thead><tr><th>Page</th><th>Languages</th></tr></thead><tbody>';
        foreach (get_posts(['post_type'=>'page','post_status'=>['publish','draft','private','pending','future'],'numberposts'=>-1,'orderby'=>'title','order'=>'ASC']) as $post) {
            if (portfolio_page_root($post->ID) !== $post->ID || !current_user_can('edit_post',$post->ID)) { continue; }
            printf('<tr><td><a href="%s">%s</a></td><td class="portfolio-admin-languages">',esc_url(portfolio_page_url($post->ID)),esc_html($post->post_title));
            foreach (portfolio_page_versions($post->ID) as $version) { if (current_user_can('edit_post',$version->ID)) { portfolio_admin_version_link(portfolio_page_locale($version->ID),$version->post_status,portfolio_page_url($post->ID,portfolio_page_locale($version->ID))); } }
            echo '</td></tr>';
        }
        echo '</tbody></table></div>'; return;
    }
    if (portfolio_page_root($root) !== $root || !current_user_can('edit_post',$root)) { wp_die('Invalid page.'); }
    $versions = portfolio_page_versions($root);
    $by_locale = []; foreach ($versions as $version) { $by_locale[portfolio_page_locale($version->ID)] = $version; }
    $locale = is_string($_GET['language'] ?? null) ? $_GET['language'] : array_key_first(array_intersect_key(portfolio_languages(), $by_locale));
    $post = $by_locale[$locale] ?? null;
    if (portfolio_page_root($root) !== $root || !$post || !current_user_can('edit_post',$root) || !current_user_can('edit_post',$post->ID)) { wp_die('Invalid page or language.'); }
    echo '<p><a href="' . esc_url(portfolio_page_url()) . '">← All pages</a></p>';
    portfolio_admin_language_tabs(array_intersect_key(portfolio_languages(),$by_locale),array_map(static fn($version)=>portfolio_page_url($root,portfolio_page_locale($version->ID)),$by_locale),$locale);
    if (isset($_GET['saved'])) { echo '<div class="notice notice-success"><p>Language version saved.</p></div>'; }
    echo '<form data-portfolio-unsaved method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
    wp_nonce_field('portfolio_save_page_' . $post->ID);
    printf('<input type="hidden" name="action" value="portfolio_save_page"><input type="hidden" name="id" value="%d"><input type="hidden" name="token" value="%s">',$post->ID,esc_attr(portfolio_page_token($post->ID)));
    printf('<section><h2>Content — %s</h2><p><label>Title <input class="large-text" name="portfolio[title]" value="%s" required></label></p><p><label>Slug <input class="regular-text" name="portfolio[slug]" value="%s" required></label></p>',esc_html(portfolio_languages()[$locale] ?? $locale),esc_attr($post->post_title),esc_attr($post->post_name));
    printf('<p><label><input type="checkbox" name="portfolio[published]" value="1" %s> Published language version</label></p>',checked($post->post_status,'publish',false));
    wp_editor($post->post_content,'portfolio_page_content',['textarea_name'=>'portfolio[content]','media_buttons'=>false,'textarea_rows'=>8]);
    echo '</section>';
    portfolio_admin_render_fields($post->ID,portfolio_admin_field_groups($post->ID,['lang']),'page_fields');
    submit_button('Save language version'); echo '</form>';
    $available = array_diff_key(portfolio_languages(),$by_locale);
    if ($available) {
        echo '<section><h2>Add a translation</h2><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('portfolio_page_translation_' . $root);
        printf('<input type="hidden" name="action" value="portfolio_page_translation"><input type="hidden" name="root" value="%d"><select name="locale">',$root);
        foreach ($available as $key=>$label) { printf('<option value="%s">%s</option>',esc_attr($key),esc_html($label)); }
        echo '</select>'; submit_button('Create translation draft','secondary'); echo '</form></section>';
    }
    echo '</div>';
}
foreach (['portfolio_create_page','portfolio_save_page','portfolio_page_translation'] as $action) {
    add_action('admin_post_' . $action,function () use ($action) {
        try {
            if ($action === 'portfolio_create_page') { check_admin_referer($action); $id=portfolio_page_create(wp_unslash($_POST['portfolio'] ?? null)); }
            elseif ($action === 'portfolio_save_page') { $id=absint($_POST['id'] ?? 0); check_admin_referer($action . '_' . $id); portfolio_page_save($id,wp_unslash($_POST['token'] ?? ''),wp_unslash($_POST['portfolio'] ?? null),wp_unslash($_POST['page_fields'] ?? null)); }
            else { $root=absint($_POST['root'] ?? 0); check_admin_referer('portfolio_page_translation_' . $root); $id=portfolio_page_add_translation($root,wp_unslash($_POST['locale'] ?? null)); }
        } catch (Throwable $error) { wp_die(esc_html($error->getMessage()),'Page not saved',['back_link'=>true]); }
        wp_safe_redirect(add_query_arg('saved','1',portfolio_page_url(portfolio_page_root($id),portfolio_page_locale($id)))); exit;
    });
}
add_action('load-edit.php',function () { if (get_option('portfolio_language_ui_enabled') && ($_GET['post_type'] ?? '') === 'page') { wp_safe_redirect(portfolio_page_url()); exit; } });
add_action('load-post-new.php',function () { if (get_option('portfolio_language_ui_enabled') && ($_GET['post_type'] ?? '') === 'page') { wp_safe_redirect(add_query_arg('view','new',portfolio_page_url())); exit; } });
add_action('load-post.php',function () {
    $id=absint($_GET['post'] ?? 0);
    if (get_option('portfolio_language_ui_enabled') && ($_GET['action'] ?? '') === 'edit' && get_post_type($id) === 'page' && current_user_can('edit_post',$id)) { wp_safe_redirect(portfolio_page_url(portfolio_page_root($id),portfolio_page_locale($id))); exit; }
});
add_action('admin_bar_menu',function ($bar) { if (get_option('portfolio_language_ui_enabled') && current_user_can('edit_pages')) { $bar->add_node(['id'=>'new-page','parent'=>'new-content','title'=>'Page','href'=>add_query_arg('view','new',portfolio_page_url())]); } },100);
