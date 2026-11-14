<?php
if (!defined('ABSPATH')) { exit; }

function portfolio_register_technology_post_type() {
    if (!post_type_exists('technology')) {
        register_post_type('technology', require __DIR__ . '/post-types/technology.php');
    }
}

// Register before ACF loads the retained database copy.
add_action('init', 'portfolio_register_technology_post_type', 0);

add_action('admin_notices', function () {
    $screen = get_current_screen();
    if (!$screen || $screen->post_type !== 'acf-post-type') { return; }
    echo '<div class="notice notice-info"><p>Typ Technology jest definiowany w <code>mu-plugins/portfolio-projects/post-types/technology.php</code>. Rekord w ACF pozostaje jako kopia; aktywna jest definicja PHP.</p></div>';
});
