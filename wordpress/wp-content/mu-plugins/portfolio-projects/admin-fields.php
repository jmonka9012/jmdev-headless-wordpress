<?php
if (!defined('ABSPATH')) { exit; }

function portfolio_admin_field_groups($id, $exclude = []) {
    $groups = [];
    foreach (acf_get_field_groups(['post_id' => $id]) as $group) {
        $group['fields'] = array_values(array_filter(acf_get_fields($group) ?: [], static fn($field) => !in_array($field['name'], $exclude, true)));
        if ($group['fields']) { $groups[] = $group; }
    }
    return $groups;
}

function portfolio_admin_render_fields($id, $groups, $prefix) {
    foreach ($groups as $group) {
        echo '<section><h2>' . esc_html($group['title']) . '</h2><div class="acf-fields">';
        foreach ($group['fields'] as $field) {
            $field['prefix'] = $prefix;
            $field['value'] = acf_get_value($id, $field);
            acf_render_field_wrap($field);
        }
        echo '</div></section>';
    }
}

function portfolio_admin_field_value($field, $value) {
    if ($field['type'] === 'group') {
        if (!is_array($value)) { throw new RuntimeException('Invalid field group: ' . $field['label']); }
        $result = [];
        foreach ($field['sub_fields'] as $sub) {
            if (!empty($sub['disabled']) || !empty($sub['readonly']) || in_array($sub['type'], ['tab', 'message', 'accordion'], true)) { continue; }
            if (!array_key_exists($sub['key'], $value)) {
                if (!empty($sub['conditional_logic'])) { continue; }
                throw new RuntimeException('Missing field: ' . $sub['label'] . '. Refresh the form before saving.');
            }
            $result[$sub['key']] = portfolio_admin_field_value($sub, $value[$sub['key']]);
        }
        return $result;
    }
    if ($field['type'] === 'image') { return portfolio_admin_image_id($value); }
    if (!is_string($value)) { throw new RuntimeException('Expected text for field: ' . $field['label']); }
    if ($field['type'] === 'date_picker' && $value !== '') {
        $date = DateTimeImmutable::createFromFormat('!Ymd', $value);
        if (!$date || $date->format('Ymd') !== $value) { throw new RuntimeException('Invalid date for field: ' . $field['label']); }
    }
    if ($field['type'] === 'select' && $value !== '' && !array_key_exists($value, $field['choices'])) { throw new RuntimeException('Invalid selection for field: ' . $field['label']); }
    return current_user_can('unfiltered_html') ? $value : wp_kses_post($value);
}

function portfolio_admin_validate_fields($groups, $raw) {
    if (!is_array($raw)) { throw new RuntimeException('Invalid content fields.'); }
    acf_reset_validation_errors();
    $values = [];
    foreach ($groups as $group) {
        foreach ($group['fields'] as $field) {
            if (!empty($field['disabled']) || !empty($field['readonly']) || in_array($field['type'], ['tab', 'message', 'accordion'], true)) { continue; }
            if (!array_key_exists($field['key'], $raw)) {
                if (!empty($field['conditional_logic'])) { continue; }
                throw new RuntimeException('Missing field: ' . $field['label'] . '. Refresh the form before saving.');
            }
            $values[$field['key']] = portfolio_admin_field_value($field, $raw[$field['key']]);
            acf_validate_value($values[$field['key']], $field, 'page_fields[' . $field['key'] . ']');
        }
    }
    $errors = acf_get_validation_errors();
    if ($errors) { throw new RuntimeException($errors[0]['message']); }
    return $values;
}

function portfolio_admin_field_matches($field, $expected, $actual) {
    if ($field['type'] !== 'group') { return is_scalar($actual) || $actual === null ? (string) $expected === (string) $actual : false; }
    if (!is_array($actual)) { return false; }
    foreach ($field['sub_fields'] as $sub) {
        if (array_key_exists($sub['key'], $expected) && !portfolio_admin_field_matches($sub, $expected[$sub['key']], $actual[$sub['key']] ?? null)) { return false; }
    }
    return true;
}

function portfolio_admin_save_fields($id, $groups, $values) {
    foreach ($groups as $group) {
        foreach ($group['fields'] as $field) {
            if (!array_key_exists($field['key'], $values)) { continue; }
            update_field($field['key'], wp_slash($values[$field['key']]), $id);
            if (!portfolio_admin_field_matches($field, $values[$field['key']], acf_get_value($id, $field))) { throw new RuntimeException('Could not save field: ' . $field['label']); }
        }
    }
}
