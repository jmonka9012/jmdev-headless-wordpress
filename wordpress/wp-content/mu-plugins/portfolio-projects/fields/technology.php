<?php
if (!defined('ABSPATH')) { exit; }

return [
    'key' => 'group_6a7898cc8e4be',
    'title' => 'Technology',
    'fields' => [
        [
            'key' => 'field_6a7898ee9f68d',
            'label' => 'Order',
            'name' => 'order',
            'aria-label' => '',
            'type' => 'text',
            'instructions' => '',
            'required' => 0,
            'conditional_logic' => 0,
            'wrapper' => [
                'width' => '',
                'class' => '',
                'id' => '',
            ],
            'default_value' => '',
            'maxlength' => '',
            'allow_in_bindings' => 0,
            'placeholder' => '',
            'prepend' => '',
            'append' => '',
        ],
    ],
    'location' => [
        [
            [
                'param' => 'post_type',
                'operator' => '==',
                'value' => 'technology',
            ],
        ],
    ],
    'menu_order' => 0,
    'position' => 'normal',
    'style' => 'default',
    'label_placement' => 'top',
    'instruction_placement' => 'label',
    'hide_on_screen' => '',
    'active' => true,
    'description' => '',
    'show_in_rest' => 1,
    'display_title' => '',
    'allow_ai_access' => false,
    'ai_description' => '',
];
