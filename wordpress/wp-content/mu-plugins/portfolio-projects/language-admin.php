<?php
if (!defined('ABSPATH')) { exit; }

function portfolio_store_meta($id, $key, $value) {
    update_post_meta($id, $key, wp_slash($value));
    $actual = get_post_meta($id, $key, true);
    if (is_scalar($value) ? (string) $actual !== (string) $value : $actual !== $value) { throw new RuntimeException('Could not save field ' . $key); }
}
function portfolio_editor_token($root) {
    $state = [get_post_meta($root, '_portfolio_shared', true)];
    foreach (portfolio_project_translations($root) as $post) {
        $state[$post->ID] = [$post->post_title, $post->post_excerpt, $post->post_content, $post->post_status, get_post_meta($post->ID, '_portfolio_local', true)];
    }
    ksort($state);
    return hash('sha256', serialize($state));
}
function portfolio_editor_fields($kind) {
    $make = static function ($name, $label, $type = 'text', $locale = '') {
        return ['key' => 'field_portfolio_editor_' . $name, 'name' => $name, 'label' => $label, 'type' => $type, 'return_format' => 'id', 'preview_size' => 'medium', 'toolbar' => 'basic', 'tabs' => 'all', 'media_upload' => 0, 'wrapper' => ['class' => $locale ? 'portfolio-locale-' . $locale : '']];
    };
    $fields = [$make('row_id', '', 'hidden')];
    if ($kind === 'image') {
        $image = $make('image', 'Image', 'image'); $image['required'] = 1; $fields[] = $image;
        foreach (portfolio_languages() as $locale => $label) {
            $fields[] = $make($locale . '_alt', 'Alt text — ' . $label, 'text', $locale);
            $fields[] = $make($locale . '_caption', 'Caption — ' . $label, 'text', $locale);
        }
    } else {
        foreach (portfolio_languages() as $locale => $label) {
            foreach (['title' => ['Challenge title', 'text'], 'challenge' => ['Challenge', 'textarea'], 'solution' => ['Solution', 'wysiwyg'], 'effect' => ['Result', 'textarea'], 'link_label' => ['Link label', 'text'], 'link_url' => ['Link for this language (empty = no link)', 'url']] as $key => [$title, $type]) {
                $fields[] = $make($locale . '_' . $key, $title . ' — ' . $label, $type, $locale);
            }
        }
        $shots = $make('screenshots', 'Resources — images', 'portfolio_rows');
        $shots['sub_fields'] = portfolio_editor_fields('image'); $fields[] = $shots;
        $fields[] = $make('link_url', 'Resources — one link (optional)', 'url');
    }
    return $fields;
}
function portfolio_editor_rows($root, $kind) {
    $shared = get_post_meta($root, '_portfolio_shared', true);
    $locals = [];
    foreach (portfolio_project_translations($root) as $post) { $locals[get_post_meta($post->ID, '_portfolio_locale', true)] = get_post_meta($post->ID, '_portfolio_local', true); }
    $translate_image = static function ($row, $usage) use ($locals) {
        foreach ($locals as $locale => $local) {
            foreach (['alt', 'caption'] as $key) { $row[$locale . '_' . $key] = $local['images'][$usage][$key] ?? ''; }
        }
        return $row;
    };
    $rows = [];
    foreach ($shared[$kind] ?? [] as $row) {
        if ($kind === 'gallery') { $rows[] = $translate_image($row, 'hero/' . $row['row_id']); continue; }
        foreach ($locals as $locale => $local) {
            foreach (['title', 'challenge', 'solution', 'effect', 'link_label', 'link_url'] as $key) { $row[$locale . '_' . $key] = $local['challenges'][$row['row_id']][$key] ?? ($key === 'link_url' ? ($row['link_url'] ?? '') : ''); }
        }
        foreach ($row['screenshots'] as &$shot) { $shot = $translate_image($shot, $row['row_id'] . '/' . $shot['row_id']); }
        unset($shot); $rows[] = $row;
    }
    return $rows;
}
function portfolio_editor_render_rows($root, $kind) {
    $field = acf_validate_field(['key' => 'field_portfolio_editor_' . $kind, 'name' => 'portfolio[' . $kind . ']', '_name' => $kind === 'gallery' ? 'project_gallery' : 'project_challenges', 'label' => $kind === 'gallery' ? 'Hero gallery' : 'Challenges and solutions', 'type' => 'portfolio_rows', 'sub_fields' => portfolio_editor_fields($kind === 'gallery' ? 'image' : 'challenge'), 'value' => portfolio_editor_rows($root, $kind)]);
    $field['_prepare'] = true; $field['_valid'] = 1;
    acf_render_field_wrap($field);
}
add_action('admin_menu', function () {
    add_menu_page('Portfolio', 'Portfolio', 'edit_posts', 'portfolio-projects', 'portfolio_editor_page', 'dashicons-portfolio', 6);
});
add_action('admin_enqueue_scripts', function ($hook) {
    if ($hook !== 'toplevel_page_portfolio-projects') { return; }
    acf_enqueue_scripts(); wp_enqueue_media(); wp_enqueue_editor();
    wp_enqueue_script('portfolio-language-editor', plugins_url('language-admin.js', __FILE__), ['acf-input'], '2.0.0', true);
    wp_enqueue_style('portfolio-language-editor', plugins_url('language-admin.css', __FILE__), [], '2.0.0');
});
function portfolio_editor_page() {
    if (!current_user_can('edit_posts')) { wp_die('You do not have permission to perform this action.'); }
    $root = absint($_GET['project'] ?? 0);
    echo '<div class="wrap portfolio-editor"><h1>Portfolio — projects</h1>';
    if (!$root) {
        echo '<table class="widefat"><thead><tr><th>Project</th><th>Languages</th></tr></thead><tbody>';
        $posts = get_posts(['post_type' => 'post', 'post_status' => ['publish', 'draft', 'private', 'pending'], 'numberposts' => -1, 'meta_key' => '_portfolio_shared', 'orderby' => 'title', 'order' => 'ASC']);
        foreach ($posts as $post) {
            if (!current_user_can('edit_post', $post->ID)) { continue; }
            $labels = array_map(static fn($translation) => strtoupper(get_post_meta($translation->ID, '_portfolio_locale', true)), portfolio_project_translations($post->ID));
            printf('<tr><td><a href="%s">%s</a></td><td>%s</td></tr>', esc_url(admin_url('admin.php?page=portfolio-projects&project=' . $post->ID)), esc_html($post->post_title), esc_html(implode(' / ', $labels)));
        }
        echo '</tbody></table>';
        if (current_user_can('manage_options')) {
            echo '<h2>Add a language to this site</h2><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="portfolio_add_language">';
            wp_nonce_field('portfolio_add_language');
            echo '<label>Code (e.g. de) <input name="locale" pattern="[a-z]{2}" maxlength="2" required></label> <label>Name <input name="label" required></label> '; submit_button('Add language', 'secondary', 'submit', false); echo '</form>';
        }
        echo '</div>'; return;
    }
    if (portfolio_project_root($root) !== $root || !current_user_can('edit_post', $root)) { wp_die('Invalid project.'); }
    $shared = get_post_meta($root, '_portfolio_shared', true);
    $translations = portfolio_project_translations($root);
    foreach ($translations as $post) { if (!current_user_can('edit_post', $post->ID)) { wp_die('You do not have permission to edit this language version.'); } }
    echo '<p><a href="' . esc_url(admin_url('admin.php?page=portfolio-projects')) . '">← All projects</a></p>';
    if (isset($_GET['saved'])) { echo '<div class="notice notice-success"><p>All project versions saved.</p></div>'; }
    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
    wp_nonce_field('portfolio_save_' . $root);
    printf('<input type="hidden" name="action" value="portfolio_save"><input type="hidden" name="root" value="%d"><input type="hidden" name="token" value="%s">', $root, esc_attr(portfolio_editor_token($root)));
    echo '<div class="portfolio-language-tabs" role="group" aria-label="Editing language">';
    foreach (portfolio_languages() as $locale => $label) { printf('<button type="button" class="button" data-portfolio-language="%s" aria-pressed="false">%s</button> ', esc_attr($locale), esc_html($label)); }
    echo '</div><h2>Shared data</h2>';
    echo '<p><label>Project type <select name="portfolio[project_type]" required><option value="">Choose a type</option>';
    foreach (['commercial' => 'Commercial', 'personal' => 'Personal'] as $value => $label) {
        printf('<option value="%s" %s>%s</option>', esc_attr($value), selected($shared['project_type'] ?? '', $value, false), esc_html($label));
    }
    echo '</select></label></p>';
    printf('<p><label>Project URL <input class="regular-text" type="url" name="portfolio[project_url]" value="%s"></label></p><p>Set the order in <a href="%s">Portfolio → Order</a>.</p>', esc_attr($shared['project_url']), esc_url(portfolio_order_url('projects')));
    echo '<p><strong>Technologies</strong></p><div class="portfolio-technology-options">';
    foreach (get_posts(['post_type' => 'technology', 'numberposts' => -1, 'orderby' => 'title', 'order' => 'ASC']) as $technology) {
        printf('<label><input type="checkbox" name="portfolio[technology_ids][]" value="%d" %s> %s</label>', $technology->ID, checked(in_array($technology->ID, $shared['technology_ids'], true), true, false), esc_html($technology->post_title));
    }
    echo '</div>';
    foreach ($translations as $post) {
        $locale = get_post_meta($post->ID, '_portfolio_locale', true); $local = get_post_meta($post->ID, '_portfolio_local', true);
        printf('<section class="portfolio-locale-%s"><h2>Content — %s</h2>', esc_attr($locale), esc_html(portfolio_languages()[$locale] ?? $locale));
        foreach (['post_title' => ['Title', $post->post_title], 'post_excerpt' => ['Subtitle / excerpt', $post->post_excerpt], 'seo_title' => ['SEO title', $local['seo']['title'] ?? ''], 'seo_description' => ['SEO description', $local['seo']['meta_description'] ?? ''], 'seo_keywords' => ['SEO keywords (one per line)', implode("\n", $local['seo']['keywords'] ?? [])], 'outcome' => ['Outcome (optional)', $local['outcome'] ?? '']] as $key => [$label, $value]) {
            printf('<p><label>%s<textarea class="large-text" rows="%d" name="portfolio[local][%s][%s]">%s</textarea></label></p>', esc_html($label), $key === 'post_title' ? 1 : 3, esc_attr($locale), esc_attr($key), esc_textarea($value));
        }
        printf('<p><label><input type="checkbox" name="portfolio[local][%s][published]" value="1" %s> Published language version</label></p>', esc_attr($locale), checked($post->post_status, 'publish', false));
        echo '<p><strong>Project overview</strong></p>';
        wp_editor($post->post_content, 'portfolio_content_' . $locale, ['textarea_name' => 'portfolio[local][' . $locale . '][post_content]', 'media_buttons' => false, 'textarea_rows' => 12]);
        echo '</section>';
    }
    echo '<div class="acf-fields">'; portfolio_editor_render_rows($root, 'gallery'); portfolio_editor_render_rows($root, 'challenges'); echo '</div>';
    submit_button('Save all languages'); echo '</form>';
    $existing = array_map(static fn($post) => get_post_meta($post->ID, '_portfolio_locale', true), $translations);
    $available = array_diff_key(portfolio_languages(), array_flip($existing));
    if ($available) {
        echo '<h2>Add a translation</h2><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('portfolio_add_translation_' . $root);
        printf('<input type="hidden" name="action" value="portfolio_add_translation"><input type="hidden" name="root" value="%d"><select name="locale">', $root);
        foreach ($available as $locale => $label) { printf('<option value="%s">%s</option>', esc_attr($locale), esc_html($label)); }
        echo '</select> '; submit_button('Create translation draft', 'secondary', 'submit', false); echo '</form>';
    }
    echo '</div>';
}

add_action('admin_post_portfolio_save', function () {
    global $wpdb;
    $root = absint($_POST['root'] ?? 0);
    check_admin_referer('portfolio_save_' . $root);
    if (!current_user_can('edit_post', $root) || portfolio_project_root($root) !== $root) { wp_die('You do not have permission to perform this action.'); }
    $posts = portfolio_project_translations($root);
    foreach ($posts as $post) { if (!current_user_can('edit_post', $post->ID)) { wp_die('You do not have permission to edit this translation.'); } }
    $lock = 'portfolio-project-' . $root;
    if ((int) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 0)', $lock)) !== 1) { wp_die('The project is being saved. Try again.'); }
    try {
        if (!hash_equals(portfolio_editor_token($root), (string) ($_POST['token'] ?? ''))) { throw new RuntimeException('The project has changed since this form was opened. Refresh the page before saving.'); }
        $raw = wp_unslash($_POST['portfolio'] ?? []);
        if (!is_array($raw)) { throw new RuntimeException('Invalid form.'); }
        $project_type = portfolio_project_type($raw['project_type'] ?? '');
        if (!$project_type) { throw new RuntimeException('Choose a project type: commercial or personal.'); }
        $rows = new Portfolio_Project_Rows();
        foreach (['gallery', 'challenges'] as $kind) {
            $field = ['sub_fields' => portfolio_editor_fields($kind === 'gallery' ? 'image' : 'challenge')];
            $valid = $rows->validate_value(true, $raw[$kind] ?? [], $field, '');
            if ($valid !== true) { throw new RuntimeException($valid); }
            $raw[$kind] = $rows->update_value($raw[$kind] ?? [], $root, $field);
        }
        $current_shared = get_post_meta($root, '_portfolio_shared', true);
        $shared = ['project_url' => portfolio_project_link($raw['project_url'] ?? ''), 'order' => (int) ($current_shared['order'] ?? 0), 'technology_ids' => [], 'gallery' => [], 'challenges' => []];
        $shared['project_type'] = $project_type;
        if (($raw['project_url'] ?? '') !== '' && !$shared['project_url']) { throw new RuntimeException('Invalid project URL.'); }
        foreach ((array) ($raw['technology_ids'] ?? []) as $id) {
            $id = absint($id); if (get_post_type($id) !== 'technology' || get_post_status($id) !== 'publish') { throw new RuntimeException('Invalid technology.'); }
            $shared['technology_ids'][] = $id;
        }
        $shared['technology_ids'] = array_values(array_unique($shared['technology_ids']));
        $locals = [];
        foreach ($posts as $post) {
            $locale = get_post_meta($post->ID, '_portfolio_locale', true); $input = $raw['local'][$locale] ?? null;
            if (!is_array($input) || !trim($input['post_title'] ?? '')) { throw new RuntimeException('Enter a title for every language version.'); }
            $locals[$locale] = ['images' => [], 'challenges' => [], 'outcome' => sanitize_textarea_field($input['outcome'] ?? ''), 'seo' => ['title' => sanitize_text_field($input['seo_title'] ?? ''), 'meta_description' => sanitize_textarea_field($input['seo_description'] ?? ''), 'keywords' => array_values(array_filter(array_map('sanitize_text_field', preg_split('/\R/', $input['seo_keywords'] ?? ''))))]];
        }
        $image = static function ($row, $prefix) use (&$locals) {
            $id = $row['row_id'] ?: wp_generate_uuid4();
            foreach ($locals as $locale => &$local) { $local['images'][$prefix . $id] = ['alt' => $row[$locale . '_alt'] ?? '', 'caption' => $row[$locale . '_caption'] ?? '']; } unset($local);
            return ['row_id' => $id, 'image' => (int) $row['image']];
        };
        $seen = [];
        foreach ($raw['gallery'] as $row) { $shared['gallery'][] = $image($row, 'hero/'); }
        foreach ($raw['challenges'] as $row) {
            $id = $row['row_id'] ?: wp_generate_uuid4();
            if (isset($seen[$id])) { throw new RuntimeException('Duplicate challenge ID.'); } $seen[$id] = true;
            $entry = ['row_id' => $id, 'link_url' => $row['link_url'], 'screenshots' => []];
            foreach ($row['screenshots'] as $shot) { $entry['screenshots'][] = $image($shot, $id . '/'); }
            foreach ($locals as $locale => &$local) {
                foreach (['title', 'challenge', 'solution', 'effect', 'link_label', 'link_url'] as $key) { $local['challenges'][$id][$key] = $row[$locale . '_' . $key] ?? ''; }
                if ($local['challenges'][$id]['link_url'] === $entry['link_url']) { unset($local['challenges'][$id]['link_url']); }
            } unset($local);
            $shared['challenges'][] = $entry;
        }
        if (!current_user_can('publish_posts')) {
            foreach ($posts as $post) {
                $locale = get_post_meta($post->ID, '_portfolio_locale', true);
                if ((!empty($raw['local'][$locale]['published'])) !== ($post->post_status === 'publish')) { throw new RuntimeException('You do not have permission to change publication status.'); }
            }
        }
        $wpdb->query('START TRANSACTION');
        foreach ($posts as $post) {
            $locale = get_post_meta($post->ID, '_portfolio_locale', true); $input = $raw['local'][$locale];
            $result = wp_update_post(wp_slash(['ID' => $post->ID, 'post_title' => sanitize_text_field($input['post_title']), 'post_excerpt' => wp_kses_post($input['post_excerpt']), 'post_content' => wp_kses_post($input['post_content']), 'post_status' => !empty($input['published']) ? 'publish' : 'draft']), true);
            if (is_wp_error($result)) { throw new RuntimeException($result->get_error_message()); }
            portfolio_store_meta($post->ID, '_portfolio_local', $locals[$locale]);
        }
        portfolio_store_meta($root, '_portfolio_shared', $shared);
        if ($wpdb->query('COMMIT') === false) { throw new RuntimeException('Could not commit the changes.'); }
    } catch (Throwable $error) {
        $wpdb->query('ROLLBACK'); foreach ($posts as $post) { clean_post_cache($post->ID); }
        wp_die(esc_html($error->getMessage()));
    } finally { $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock)); }
    wp_safe_redirect(admin_url('admin.php?page=portfolio-projects&project=' . $root . '&saved=1')); exit;
});

// Switch the old editing surface off only after the post-import verification enables it.
add_action('load-post.php', function () {
    if (!get_option('portfolio_language_ui_enabled')) { return; }
    $id = absint($_GET['post'] ?? 0); $root = portfolio_project_root($id);
    if ($root && ($_GET['action'] ?? '') === 'edit' && current_user_can('edit_post', $id)) { wp_safe_redirect(admin_url('admin.php?page=portfolio-projects&project=' . $root)); exit; }
});
add_action('pre_get_posts', function ($query) {
    if (!is_admin() || !$query->is_main_query() || !get_option('portfolio_language_ui_enabled')) { return; }
    global $pagenow;
    if ($pagenow === 'edit.php' && ($query->get('post_type') ?: 'post') === 'post') {
        $meta = $query->get('meta_query') ?: [];
        $meta[] = ['relation' => 'OR', ['key' => '_portfolio_root_id', 'compare' => 'NOT EXISTS'], ['key' => '_portfolio_locale', 'value' => 'pl']];
        $query->set('meta_query', $meta);
    }
});

add_filter('acf/prepare_field', function ($field) {
    if (!get_option('portfolio_language_ui_enabled') || !$field || ($field['_name'] ?? $field['name'] ?? '') !== 'lang') { return $field; }
    $screen = function_exists('get_current_screen') ? get_current_screen() : null;
    $id = absint($_GET['post'] ?? 0);
    return $screen && $screen->base === 'post' && get_post_meta($id, '_portfolio_locale', true) ? false : $field;
});

add_action('admin_post_portfolio_add_language', function () {
    if (!current_user_can('manage_options')) { wp_die('You do not have permission to perform this action.'); }
    check_admin_referer('portfolio_add_language');
    $locale = sanitize_key(wp_unslash($_POST['locale'] ?? ''));
    $label = sanitize_text_field(wp_unslash($_POST['label'] ?? ''));
    if (!preg_match('/^[a-z]{2}$/D', $locale) || !$label) { wp_die('Enter a two-letter language code and a name.'); }
    $languages = portfolio_languages();
    if (isset($languages[$locale])) { wp_die('This language already exists.'); }
    $languages[$locale] = $label; update_option('portfolio_languages', $languages, false);
    wp_safe_redirect(admin_url('admin.php?page=portfolio-projects')); exit;
});
add_action('admin_post_portfolio_add_translation', function () {
    global $wpdb;
    $root = absint($_POST['root'] ?? 0);
    check_admin_referer('portfolio_add_translation_' . $root);
    if (!current_user_can('edit_post', $root) || !current_user_can('edit_posts') || portfolio_project_root($root) !== $root) { wp_die('You do not have permission to perform this action.'); }
    $locale = sanitize_key(wp_unslash($_POST['locale'] ?? ''));
    if (!isset(portfolio_languages()[$locale])) { wp_die('Unknown language.'); }
    $lock = 'portfolio-project-' . $root;
    if ((int) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 0)', $lock)) !== 1) { wp_die('The project is being saved. Try again.'); }
    try {
        foreach (portfolio_project_translations($root) as $post) { if (get_post_meta($post->ID, '_portfolio_locale', true) === $locale) { throw new RuntimeException('This translation already exists.'); } }
        $wpdb->query('START TRANSACTION');
        $id = wp_insert_post(wp_slash(['post_type' => 'post', 'post_status' => 'draft', 'post_title' => get_the_title($root), 'post_name' => get_post($root)->post_name . '-' . $locale]), true);
        if (is_wp_error($id)) { throw new RuntimeException($id->get_error_message()); }
        portfolio_store_meta($id, '_portfolio_root_id', $root);
        portfolio_store_meta($id, '_portfolio_project_key', get_post_meta($root, '_portfolio_project_key', true));
        portfolio_store_meta($id, '_portfolio_locale', $locale);
        portfolio_store_meta($id, '_portfolio_public_slug', get_post_meta($root, '_portfolio_public_slug', true));
        portfolio_store_meta($id, '_portfolio_local', ['images' => [], 'challenges' => [], 'seo' => [], 'outcome' => '']);
        $wpdb->query('COMMIT');
    } catch (Throwable $error) { $wpdb->query('ROLLBACK'); wp_die(esc_html($error->getMessage())); }
    finally { $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock)); }
    wp_safe_redirect(admin_url('admin.php?page=portfolio-projects&project=' . $root)); exit;
});
