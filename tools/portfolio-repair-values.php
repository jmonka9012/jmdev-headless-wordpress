<?php
/** Explicit date editing is required when restoring an undated WordPress draft. */
function portfolio_repair_post_values($id, $saved, $fields, $categories, $tags) {
    $values = ['ID' => $id, 'edit_date' => true];
    foreach ($fields as $field) { $values[$field] = $saved[$field]; }
    $values['post_category'] = $categories;
    $values['tags_input'] = $tags;
    return $values;
}
