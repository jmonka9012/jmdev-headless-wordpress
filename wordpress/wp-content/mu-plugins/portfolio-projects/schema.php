<?php
if (!defined('ABSPATH')) { exit; }

function portfolio_project_type($value) {
    return in_array($value, ['commercial', 'personal'], true) ? $value : '';
}

/** Stable row names also define the future structured-data contract. */
function portfolio_project_row_fields($name) {
    $definitions = in_array($name, ['project_gallery', 'project_screenshots'], true)
        ? [['image', 'Image', 'image'], ['caption', 'Image caption (optional)', 'text']]
        : [['title', 'Challenge title', 'text'], ['challenge', 'Challenge', 'textarea'], ['solution', 'Solution', 'wysiwyg'], ['effect', 'Result', 'textarea'], ['screenshots', 'Screenshots (optional)', 'portfolio_rows'], ['link_url', 'Resource link (optional)', 'url'], ['link_label', 'Button text (optional)', 'text']];
    return array_map(function ($item) use ($name) {
        [$key, $label, $type] = $item;
        if ($key === 'screenshots') {
            return [
                'key' => 'field_portfolio_' . $name . '_screenshots', 'name' => 'screenshots',
                'label' => $label, 'type' => 'portfolio_rows',
                'sub_fields' => portfolio_project_row_fields('project_screenshots'),
                'instructions' => 'Add one or more screenshots. Use the header buttons to reorder them.',
            ];
        }
        return [
            'key' => 'field_portfolio_' . $name . '_' . $key, 'name' => $key,
            'label' => $label, 'type' => $type, 'required' => in_array($name, ['project_gallery', 'project_screenshots'], true) && $key === 'image',
            'return_format' => 'array', 'preview_size' => 'medium', 'library' => 'all',
            'instructions' => ['title' => 'A short heading, e.g. Page performance.', 'challenge' => 'What problem needed to be solved?', 'solution' => 'What was done, and why was this approach chosen?', 'effect' => 'What was achieved? Include specific results when available.'][$key] ?? '',
            'placeholder' => $key === 'link_url' ? 'https://' : ($key === 'link_label' ? 'View resource' : ''),
            'rows' => 4, 'tabs' => 'all', 'toolbar' => 'basic', 'media_upload' => 0,
        ];
    }, $definitions);
}

/** Reuse a relationship in the groups applicable to this specific project. */
function portfolio_technology_field($post_id = 0) {
    $groups = acf_get_field_groups($post_id ? ['post_id' => $post_id] : ['post_type' => 'post']);
    $walk = function ($fields, $path = []) use (&$walk) {
        foreach ($fields ?: [] as $field) {
            $targets = (array) ($field['post_type'] ?? []);
            if (in_array($field['type'], ['relationship', 'post_object'], true)
                && (in_array('technology', $targets, true)
                    || (!$targets && in_array($field['name'], ['technologies', 'technology', 'project_technologies'], true)))) {
                $field['_portfolio_path'] = array_merge($path, [$field['key']]);
                return $field;
            }
            // Group fields can contain the existing relationship as well.
            if ($field['type'] === 'group') {
                $found = $walk(acf_get_fields($field), array_merge($path, [$field['key']]));
                if ($found) { return $found; }
            }
        }
        return null;
    };
    foreach ($groups as $group) {
        $found = $walk(acf_get_fields($group));
        if ($found) { return $found; }
    }
    return null;
}

function portfolio_register_project_fields() {
    $tab = function ($id, $label) {
        return ['key' => 'field_portfolio_tab_' . $id, 'label' => $label, 'type' => 'tab', 'placement' => 'top'];
    };
    $note = function ($id, $text) {
        return ['key' => 'field_portfolio_note_' . $id, 'label' => '', 'type' => 'message', 'message' => $text, 'new_lines' => 'wpautop', 'esc_html' => 1];
    };
    // Layout-only tabs/messages do not introduce additional stored values.
    $fields = [
        $tab('hero', '1. Hero'),
        $note('hero', 'Enter the title and subtitle in the WordPress Title and Excerpt fields. The featured image is the project-list thumbnail. The gallery below appears in the project header.'),
        [
            'key' => 'field_portfolio_project_type', 'name' => 'project_type',
            'label' => 'Project type', 'type' => 'select', 'required' => 1,
            'choices' => ['commercial' => 'Commercial', 'personal' => 'Personal'],
            'allow_null' => 1, 'return_format' => 'value',
        ],
        [
            'key' => 'field_portfolio_project_gallery', 'name' => 'project_gallery',
            'label' => 'Gallery images', 'type' => 'portfolio_rows',
            'sub_fields' => portfolio_project_row_fields('project_gallery'),
            'instructions' => 'Use the header buttons to reorder images. Captions are optional.',
        ],
        $tab('overview', '2. Overview and technologies'),
        $note('overview', 'Enter the project overview in the main WordPress content editor. Assign technologies through the relationship field using existing technology entries.'),
    ];
    if (!portfolio_technology_field()) {
        $fields[] = [
            'key' => 'field_portfolio_project_technologies', 'name' => 'project_technologies',
            'label' => 'Project technologies', 'type' => 'relationship', 'post_type' => ['technology'],
            'instructions' => 'Search for a technology and click to assign it to this project.',
            'filters' => ['search'], 'return_format' => 'id',
        ];
    }
    $fields = array_merge($fields, [
        $tab('challenges', '3. Challenges and solutions'),
        [
            'key' => 'field_portfolio_project_challenges', 'name' => 'project_challenges',
            'label' => 'Project challenges', 'type' => 'portfolio_rows',
            'sub_fields' => portfolio_project_row_fields('project_challenges'),
            'instructions' => 'Each row describes one challenge, solution and result. Empty challenges are hidden on the site.',
        ],
        $tab('outcome', '4. Outcome'),
        [
            'key' => 'field_portfolio_project_outcome', 'name' => 'project_outcome',
            'label' => 'Final outcome', 'type' => 'textarea', 'rows' => 4,
            'instructions' => 'Optional. Summarize the project outcome briefly. Leave empty to hide the section.',
        ],
    ]);
    acf_add_local_field_group([
        'key' => 'group_portfolio_project', 'title' => 'Portfolio — project details',
        'fields' => $fields, 'location' => [[['param' => 'post_type', 'operator' => '==', 'value' => 'post']]],
        'instruction_placement' => 'label', 'label_placement' => 'top',
        'show_in_rest' => 1, 'active' => true,
    ]);
}

function portfolio_project_link($value) {
    if (!is_string($value)) { return ''; }
    $value = trim($value);
    return preg_match('#^https?://#i', $value) && filter_var($value, FILTER_VALIDATE_URL) ? esc_url_raw($value) : '';
}

function portfolio_project_image($image) {
    $id = is_array($image) ? ($image['ID'] ?? $image['id'] ?? 0) : $image;
    if (is_numeric($id) && wp_attachment_is_image((int) $id)) {
        $src = wp_get_attachment_image_src((int) $id, 'full');
        return $src ? ['id' => (int) $id, 'url' => esc_url_raw($src[0]), 'width' => $src[1], 'height' => $src[2], 'alt' => sanitize_text_field(get_post_meta((int) $id, '_wp_attachment_image_alt', true))] : null;
    }
    // Compatibility with legacy ACF image fields returning URLs.
    $url = is_array($image) ? ($image['url'] ?? '') : $image;
    return is_string($url) && preg_match('#^https?://#i', $url)
        ? ['url' => esc_url_raw($url), 'alt' => ''] : null;
}

/** A missing new collection inherits the old image; an explicit empty list stays empty. */
function portfolio_project_screenshot_rows($row) {
    return array_key_exists('screenshots', $row)
        ? (is_array($row['screenshots']) ? $row['screenshots'] : [])
        : (!empty($row['screenshot']) ? [['image' => $row['screenshot'], 'caption' => '']] : []);
}

/** Presentation only: never writes to or duplicates the existing relation. */
function portfolio_project_payload($post_id) {
    if (function_exists('portfolio_multilingual_payload')) {
        $managed = portfolio_multilingual_payload($post_id);
        if ($managed !== null) { return $managed; }
    }
    if (!function_exists('get_field')) { return ['schema_version' => 1]; }
    $gallery = [];
    foreach (get_field('project_gallery', $post_id) ?: [] as $row) {
        $image = portfolio_project_image($row['image'] ?? null);
        if ($image) { $gallery[] = ['image' => $image, 'caption' => sanitize_text_field($row['caption'] ?? '')]; }
    }
    if (!$gallery) {
        foreach (['photo_1', 'photo_2', 'photo_3'] as $name) {
            $image = portfolio_project_image(get_field($name, $post_id));
            if ($image) { $gallery[] = ['image' => $image, 'caption' => '']; }
        }
    }
    $challenges = [];
    foreach (get_field('project_challenges', $post_id) ?: [] as $row) {
        $screenshots = [];
        foreach (portfolio_project_screenshot_rows($row) as $shot) {
            $image = portfolio_project_image($shot['image'] ?? null);
            if ($image) { $screenshots[] = ['image' => $image, 'caption' => sanitize_text_field($shot['caption'] ?? '')]; }
        }
        $item = [
            'title' => sanitize_text_field($row['title'] ?? ''),
            'challenge' => sanitize_textarea_field($row['challenge'] ?? ''),
            'solution' => wp_kses_post(wpautop($row['solution'] ?? '')),
            'effect' => sanitize_textarea_field($row['effect'] ?? ''),
            'screenshots' => $screenshots,
            'link_url' => portfolio_project_link($row['link_url'] ?? ''),
            'link_label' => sanitize_text_field($row['link_label'] ?? ''),
        ];
        if (trim(wp_strip_all_tags(implode('', array_slice($item, 0, 4)))) !== '' || $item['screenshots'] || $item['link_url']) { $challenges[] = $item; }
    }
    $relation = portfolio_technology_field($post_id);
    $technologies = [];
    if ($relation) {
        $path = $relation['_portfolio_path'];
        $value = get_field(array_shift($path), $post_id, false);
        foreach ($path as $key) { $value = is_array($value) ? ($value[$key] ?? null) : null; }
        foreach (is_array($value) ? $value : ($value ? [$value] : []) as $id) {
            $id = absint(is_object($id) ? $id->ID : $id);
            if (get_post_type($id) === 'technology' && get_post_status($id) === 'publish') { $technologies[] = $id; }
        }
    }
    return [
        'schema_version' => 1, 'gallery' => $gallery, 'challenges' => $challenges,
        'project_type' => portfolio_project_type(get_field('project_type', $post_id)),
        'featured_image' => portfolio_project_image(get_post_thumbnail_id($post_id)),
        'outcome' => sanitize_textarea_field(get_field('project_outcome', $post_id) ?: ''),
        'technology_ids' => array_values(array_unique($technologies)),
    ];
}
