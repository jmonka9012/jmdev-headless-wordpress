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
    echo '<div class="notice notice-info"><p>The Content, Meta, Project and Technology groups are defined in <code>mu-plugins/portfolio-projects/schema/fields/</code>. Edit these schemas in PHP. Database records remain as backups; the PHP definitions are active.</p></div>';
});
