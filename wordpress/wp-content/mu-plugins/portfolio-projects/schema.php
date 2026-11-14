<?php
if (!defined('ABSPATH')) { exit; }

function portfolio_project_type($value) {
    return in_array($value, ['commercial', 'personal'], true) ? $value : '';
}

/** Stable row names also define the future structured-data contract. */
function portfolio_project_row_fields($name) {
    $definitions = in_array($name, ['project_gallery', 'project_screenshots'], true)
        ? [['image', 'Obraz', 'image'], ['caption', 'Podpis zdjęcia (opcjonalnie)', 'text']]
        : [['title', 'Tytuł zagadnienia', 'text'], ['challenge', 'Wyzwanie', 'textarea'], ['solution', 'Rozwiązanie', 'wysiwyg'], ['effect', 'Efekt', 'textarea'], ['screenshots', 'Zrzuty ekranu (opcjonalnie)', 'portfolio_rows'], ['link_url', 'Link do materiału (opcjonalnie)', 'url'], ['link_label', 'Tekst przycisku (opcjonalnie)', 'text']];
    return array_map(function ($item) use ($name) {
        [$key, $label, $type] = $item;
        if ($key === 'screenshots') {
            return [
                'key' => 'field_portfolio_' . $name . '_screenshots', 'name' => 'screenshots',
                'label' => $label, 'type' => 'portfolio_rows',
                'sub_fields' => portfolio_project_row_fields('project_screenshots'),
                'instructions' => 'Dodaj jeden lub więcej zrzutów. Kolejność zmienisz przyciskami w nagłówku.',
            ];
        }
        return [
            'key' => 'field_portfolio_' . $name . '_' . $key, 'name' => $key,
            'label' => $label, 'type' => $type, 'required' => in_array($name, ['project_gallery', 'project_screenshots'], true) && $key === 'image',
            'return_format' => 'array', 'preview_size' => 'medium', 'library' => 'all',
            'instructions' => ['title' => 'Krótki nagłówek, np. „Wydajność strony”.', 'challenge' => 'Jaki problem należało rozwiązać?', 'solution' => 'Co zrobiłeś i dlaczego wybrałeś to rozwiązanie?', 'effect' => 'Co udało się uzyskać? Podaj konkretne wyniki, jeśli je masz.'][$key] ?? '',
            'placeholder' => $key === 'link_url' ? 'https://' : ($key === 'link_label' ? 'Zobacz materiał' : ''),
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
        $note('hero', 'Tytuł i podtytuł uzupełnij w polach „Tytuł” i „Zajawka” WordPressa. Obrazek wyróżniający to miniatura na liście projektów. Poniższa galeria pojawi się w nagłówku detalu.'),
        [
            'key' => 'field_portfolio_project_type', 'name' => 'project_type',
            'label' => 'Typ projektu', 'type' => 'select', 'required' => 1,
            'choices' => ['commercial' => 'Komercyjny', 'personal' => 'Osobisty'],
            'allow_null' => 1, 'return_format' => 'value',
        ],
        [
            'key' => 'field_portfolio_project_gallery', 'name' => 'project_gallery',
            'label' => 'Zdjęcia w galerii', 'type' => 'portfolio_rows',
            'sub_fields' => portfolio_project_row_fields('project_gallery'),
            'instructions' => 'Kolejność zdjęć zmienisz przyciskami w nagłówku. Podpisy są opcjonalne.',
        ],
        $tab('overview', '2. Opis i technologie'),
        $note('overview', 'Opis projektu uzupełnij w głównym edytorze treści WordPressa. Technologie przypisuj w polu relacji — korzystamy z istniejących wpisów technologii.'),
    ];
    if (!portfolio_technology_field()) {
        $fields[] = [
            'key' => 'field_portfolio_project_technologies', 'name' => 'project_technologies',
            'label' => 'Technologie wykorzystane w projekcie', 'type' => 'relationship', 'post_type' => ['technology'],
            'instructions' => 'Wyszukaj technologię i kliknij, aby przypisać ją do tego projektu.',
            'filters' => ['search'], 'return_format' => 'id',
        ];
    }
    $fields = array_merge($fields, [
        $tab('challenges', '3. Wyzwania i rozwiązania'),
        [
            'key' => 'field_portfolio_project_challenges', 'name' => 'project_challenges',
            'label' => 'Zagadnienia projektu', 'type' => 'portfolio_rows',
            'sub_fields' => portfolio_project_row_fields('project_challenges'),
            'instructions' => 'Jeden wiersz = jedno zagadnienie: wyzwanie, rozwiązanie i efekt. Puste zagadnienia nie pojawią się na stronie.',
        ],
        $tab('outcome', '4. Podsumowanie'),
        [
            'key' => 'field_portfolio_project_outcome', 'name' => 'project_outcome',
            'label' => 'Końcowy rezultat', 'type' => 'textarea', 'rows' => 4,
            'instructions' => 'Opcjonalne. Krótko podsumuj rezultat projektu. Pozostaw puste, aby ukryć całą sekcję.',
        ],
    ]);
    acf_add_local_field_group([
        'key' => 'group_portfolio_project', 'title' => 'Portfolio — treść podstrony projektu',
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
