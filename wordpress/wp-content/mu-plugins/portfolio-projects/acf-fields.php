<?php
if (!defined('ABSPATH')) { exit; }

function portfolio_register_acf_fields() {
    foreach (['content', 'meta', 'project', 'technology'] as $name) {
        acf_add_local_field_group(require __DIR__ . '/fields/' . $name . '.php');
    }
}

// Register shared definitions before the project schema resolves existing fields.
add_action('acf/init', 'portfolio_register_acf_fields', 5);

add_action('admin_notices', function () {
    $screen = get_current_screen();
    if (!$screen || $screen->post_type !== 'acf-field-group') { return; }
    echo '<div class="notice notice-info"><p>Grupy Content, Meta, Project i Technology są definiowane w plikach <code>mu-plugins/portfolio-projects/fields/</code>. Schemat tych grup edytuj w PHP. Rekordy w bazie pozostają jako kopia; aktywne są definicje z kodu.</p></div>';
});
