<?php
/** Use the update API so omitted native fields retain their original values. */
function portfolio_import_write_project($operation, $local) {
    $values = ['post_type' => 'post', 'post_title' => $local['post_title'], 'post_excerpt' => $local['post_excerpt'], 'post_content' => $local['post_content']];
    if ($operation['action'] === 'create') {
        $values['post_name'] = $operation['slug']; $values['post_status'] = 'publish';
        return wp_insert_post(wp_slash($values), true);
    }
    $values['ID'] = $operation['id'];
    $values['edit_date'] = true;
    return wp_update_post(wp_slash($values), true);
}
