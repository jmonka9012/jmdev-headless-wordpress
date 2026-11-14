<?php
define('ABSPATH', __DIR__);
function add_action(...$args) {}
function current_user_can(...$args) { return true; }
function wp_attachment_is_image($id) { return $id === 10; }
function acf_reset_validation_errors() {}
function acf_get_validation_errors() { return []; }
function acf_validate_value(...$args) { return true; }
function get_post($id) { return $GLOBALS['posts'][$id] ?? null; }
function get_post_type($id) { return get_post($id)->post_type ?? null; }
function get_post_meta($id, $key, $single = true) { return $GLOBALS['meta'][$id][$key] ?? ''; }
function get_field($key, $id) { return 'pl'; }
function get_posts($args) { return array_values(array_filter($GLOBALS['posts'],static fn($post)=>get_post_meta($post->ID,$args['meta_key']) == $args['meta_value'])); }
require dirname(__DIR__) . '/admin-ui.php';
require dirname(__DIR__) . '/admin-fields.php';
require dirname(__DIR__) . '/page-admin.php';
function check($value, $message) { if (!$value) { throw new RuntimeException($message); } }
function rejects($callback) { try { $callback(); } catch (RuntimeException $e) { return; } throw new RuntimeException('Invalid data accepted.'); }
$text=['key'=>'field_text','name'=>'text','label'=>'Text','type'=>'text'];
$date=['key'=>'field_date','name'=>'date','label'=>'Date','type'=>'date_picker'];
$image=['key'=>'field_image','name'=>'image','label'=>'Image','type'=>'image'];
$group=['key'=>'field_group','name'=>'group','label'=>'Group','type'=>'group','sub_fields'=>[$text,$date,$image]];
$value=['field_text'=>"<b>Text</b> \\ quote '",'field_date'=>'20240229','field_image'=>'10','unknown'=>'ignored'];
$clean=portfolio_admin_field_value($group,$value);
check(!isset($clean['unknown']) && $clean['field_image']===10,'Only declared fields reach ACF persistence.');
check($clean['field_text']===$value['field_text'],'Native rich text and literal backslashes are preserved for authorized authors.');
check(portfolio_admin_field_matches($group,$clean,$value),'Read-back comparison supports WordPress scalar storage.');
$wrong=$value;$wrong['field_text']='different';check(!portfolio_admin_field_matches($group,$clean,$wrong),'Partial or failed writes are detected by read-back.');
foreach (['20230229','invalid'] as $value) { rejects(fn()=>portfolio_admin_field_value($date,$value)); }
rejects(fn()=>portfolio_admin_field_value($group,[]));
rejects(fn()=>portfolio_admin_field_value($text,['invalid']));
rejects(fn()=>portfolio_admin_validate_fields([['fields'=>[$text]]],[]));
$GLOBALS['posts']=[1=>(object)['ID'=>1,'post_type'=>'page'],2=>(object)['ID'=>2,'post_type'=>'page'],3=>(object)['ID'=>3,'post_type'=>'page']];
check(portfolio_page_root(1)===1 && portfolio_page_root(2)===2,'Unlinked pages remain independent even when their templates match.');
$GLOBALS['meta']=[1=>['_portfolio_page_root_id'=>1,'_portfolio_page_key'=>'page-a'],2=>['_portfolio_page_root_id'=>1,'_portfolio_page_key'=>'page-a'],3=>['_portfolio_page_root_id'=>1,'_portfolio_page_key'=>'other']];
check(portfolio_page_root(2)===1 && portfolio_page_root(3)===3,'Page identity requires a matching explicit key.');
check(count(portfolio_page_versions(1))===2,'Unrelated pages do not enter a translation group.');
check(portfolio_page_versions(999)===[],'Unknown pages do not produce invalid editor records.');
echo "ACF field persistence and explicit page-language identity contracts passed.\n";
