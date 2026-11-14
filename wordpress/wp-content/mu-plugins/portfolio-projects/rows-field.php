<?php
if (!defined('ABSPATH')) { exit; }

/** ACF Free extension, scoped to the two project collections; not the PRO Repeater. */
class Portfolio_Project_Rows extends acf_field {
    public function initialize() {
        $this->name = 'portfolio_rows';
        $this->label = 'Portfolio — repeatable sections';
        $this->category = 'layout';
        $this->defaults = ['sub_fields' => []];
        $this->supports = ['escaping_html' => true];
    }

    public function input_admin_enqueue_scripts() {
        wp_enqueue_media();
        wp_enqueue_script('portfolio-project-rows', plugins_url('rows.js', __FILE__), ['acf-input'], '1.2.1', true);
        wp_enqueue_style('portfolio-project-rows', plugins_url('rows.css', __FILE__), [], '1.2.0');
    }

    public function render_field($field) {
        $rows = is_array($field['value']) ? $field['value'] : [];
        $gallery = in_array($field['_name'] ?? '', ['project_gallery', 'screenshots'], true);
        $token = '__PORTFOLIO_ROW_' . ($field['_portfolio_depth'] ?? 0) . '__';
        printf('<div class="portfolio-rows" data-row-token="%s" data-row-label="%s">', esc_attr($token), esc_attr($gallery ? 'Image' : 'Challenge'));
        echo '<div class="portfolio-rows__toolbar"><span class="portfolio-rows__count" aria-live="polite"></span><div>';
        echo '<button type="button" class="button-link" data-portfolio-action="expand">Expand all</button> · <button type="button" class="button-link" data-portfolio-action="collapse">Collapse all</button></div></div>';
        // An explicit empty value lets removing the last row clear the collection.
        printf('<input type="hidden" name="%s" value="">', esc_attr($field['name']));
        echo '<div class="portfolio-rows__list">';
        foreach (array_values($rows) as $index => $row) { $this->render_row($field, $row, 'row_' . $index); }
        echo '</div><template class="portfolio-rows__template">';
        $this->render_row($field, [], $token);
        printf('</template><p class="portfolio-rows__empty">%s</p><button type="button" class="button button-secondary" data-portfolio-action="add">%s</button></div>', esc_html($gallery ? 'This gallery has no images. Add an image to get started.' : 'No challenges yet. Add a challenge and describe its solution.'), esc_html($gallery ? '+ Add image' : '+ Add challenge'));
    }

    private function render_row($field, $row, $index) {
        $gallery = in_array($field['_name'] ?? '', ['project_gallery', 'screenshots'], true);
        $body_id = acf_idify($field['name'] . '_' . $index . '_body');
        $title = $gallery ? ($row['caption'] ?? '') : ($row['title'] ?? '');
        $expanded = $index === 'row_0' || str_starts_with((string) $index, '__PORTFOLIO_ROW_');
        printf('<div class="portfolio-rows__row%s"><div class="portfolio-rows__header"><button type="button" class="portfolio-rows__toggle" data-portfolio-action="toggle" aria-expanded="%s" aria-controls="%s"><span class="portfolio-rows__number"></span><span class="portfolio-rows__title">%s</span><span class="portfolio-rows__chevron" aria-hidden="true">⌄</span></button><div class="portfolio-rows__actions">', $gallery ? ' portfolio-rows__row--image' : '', $expanded ? 'true' : 'false', esc_attr($body_id), esc_html($title ?: ($gallery ? 'Image without a caption' : 'New challenge')));
        foreach (['up' => 'Move up', 'down' => 'Move down', 'remove' => 'Remove row'] as $action => $label) {
            printf('<button type="button" class="button" data-portfolio-action="%s">%s</button> ', esc_attr($action), esc_html($label));
        }
        printf('</div></div><div id="%s" class="acf-fields portfolio-rows__body"%s>', esc_attr($body_id), $expanded ? '' : ' hidden');
        foreach ($field['sub_fields'] as $sub) {
            $sub = acf_validate_field($sub);
            $sub['prefix'] = $field['name'] . '[' . $index . ']';
            $sub['value'] = $sub['name'] === 'screenshots' ? portfolio_project_screenshot_rows($row) : ($row[$sub['name']] ?? '');
            $sub['_portfolio_depth'] = ($field['_portfolio_depth'] ?? 0) + 1;
            // Row identity must also be part of ACF's key and WYSIWYG editor IDs.
            $sub['key'] .= '_' . ($field['key'] ?? 'rows') . '_' . $index;
            $sub['_name'] = $sub['name'];
            $sub['name'] = $sub['prefix'] . '[' . $sub['name'] . ']';
            $sub['prefix'] = '';
            $sub['id'] = acf_idify($sub['name']);
            $sub['_valid'] = 1;
            $sub['_prepare'] = true;
            acf_render_field_wrap($sub);
        }
        echo '</div></div>';
    }

    public function update_value($value, $post_id, $field) {
        $result = [];
        foreach (is_array($value) ? $value : [] as $row) {
            if (!is_array($row)) { continue; }
            $clean = [];
            foreach ($field['sub_fields'] as $sub) {
                $raw = $sub['name'] === 'screenshots' ? portfolio_project_screenshot_rows($row) : ($row[$sub['name']] ?? '');
                if ($sub['type'] === 'portfolio_rows') {
                    $clean[$sub['name']] = $this->update_value($raw, $post_id, $sub);
                } elseif ($sub['type'] === 'image') {
                    $id = absint(is_array($raw) ? ($raw['ID'] ?? $raw['id'] ?? 0) : $raw);
                    $clean[$sub['name']] = wp_attachment_is_image($id) ? $id : 0;
                } else {
                    $raw = is_scalar($raw) ? (string) $raw : '';
                    $clean[$sub['name']] = $sub['type'] === 'url' ? portfolio_project_link($raw) : ($sub['type'] === 'wysiwyg' ? wp_kses_post($raw)
                        : ($sub['type'] === 'textarea' ? sanitize_textarea_field($raw) : sanitize_text_field($raw)));
                }
            }
            if (array_filter($clean, function ($item) { return is_array($item) ? !empty($item) : (is_int($item) ? $item > 0 : trim(wp_strip_all_tags($item)) !== ''); })) { $result[] = $clean; }
        }
        return $result;
    }

    public function validate_value($valid, $value, $field, $input) {
        if ($valid !== true) { return $valid; }
        if ($value !== '' && $value !== null && $value !== false && !is_array($value)) { return 'Expected a list of rows.'; }
        foreach (is_array($value) ? $value : [] as $row) {
            if (!is_array($row)) { return 'Invalid row.'; }
            foreach ($field['sub_fields'] as $sub) {
                $item = $sub['name'] === 'screenshots' ? portfolio_project_screenshot_rows($row) : ($row[$sub['name']] ?? '');
                if ($sub['type'] === 'portfolio_rows') {
                    $nested = $this->validate_value(true, $item, $sub, $input);
                    if ($nested !== true) { return $nested; }
                } elseif ($sub['type'] === 'image') {
                    $id = is_array($item) ? ($item['ID'] ?? $item['id'] ?? 0) : $item;
                    if (($id && (!is_numeric($id) || !wp_attachment_is_image(absint($id)))) || (!empty($sub['required']) && !$id)) { return 'Select a valid image from the media library.'; }
                } elseif (!is_scalar($item)) { return 'Invalid text value.'; }
                elseif ($sub['type'] === 'url' && trim((string) $item) !== '' && !portfolio_project_link($item)) { return 'Enter a complete URL starting with https:// or http://.'; }
            }
        }
        return true;
    }

    public function format_value($value, $post_id, $field, $escape_html = false) {
        return $this->update_value($value, $post_id, $field);
    }

    public function get_rest_schema(array $field) {
        $properties = [];
        foreach ($field['sub_fields'] as $sub) {
            $properties[$sub['name']] = $sub['type'] === 'portfolio_rows'
                ? $this->get_rest_schema($sub)
                : ['type' => $sub['type'] === 'image' ? 'integer' : 'string'];
        }
        return ['type' => 'array', 'items' => ['type' => 'object', 'properties' => $properties, 'additionalProperties' => false]];
    }

    public function validate_rest_value($valid, $value, $field) {
        if ($valid !== true) { return $valid; }
        $result = $this->validate_value($valid, $value, $field, '');
        return $result === true ? true : new WP_Error('portfolio_invalid_rows', $result, ['status' => 400]);
    }
}
