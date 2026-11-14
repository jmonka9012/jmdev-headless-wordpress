<?php
if (!defined('ABSPATH')) { exit; }

function portfolio_technology_url($id = 0) {
    return add_query_arg(array_filter(['page'=>'portfolio-technologies','entry'=>$id]),admin_url('admin.php'));
}
function portfolio_technology_token($id) {
    $meta=get_post_meta($id);ksort($meta);
    return hash('sha256',serialize([get_post($id)->to_array(),$meta]));
}
function portfolio_technology_save($id,$token,$input,$raw_fields) {
    if (!current_user_can('edit_posts') || ($id && (get_post_type($id)!=='technology' || !current_user_can('edit_post',$id)))) { throw new RuntimeException('You do not have permission to edit this technology.'); }
    if (!is_array($input) || !is_string($input['title']??null) || sanitize_text_field($input['title'])==='' || !is_string($input['content']??null)) { throw new RuntimeException('Enter a title and valid content.'); }
    $image=portfolio_admin_image_id($input['icon']??'');
    $key=$input['key']??null;
    if (!$id && (!is_string($key)||!wp_is_uuid($key,4))) { throw new RuntimeException('Invalid creation form.'); }
    global $wpdb;
    $lock='portfolio-technology-'.$id;
    if ((int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 0)',$lock))!==1) { throw new RuntimeException('This technology is being saved. Try again.'); }
    $created=0;
    try {
        if ($id) {
            clean_post_cache($id);
            if (!is_string($token)||!hash_equals(portfolio_technology_token($id),$token)) { throw new RuntimeException('The technology has changed. Refresh before saving.'); }
        } else {
            $existing=get_posts(['post_type'=>'technology','post_status'=>['publish','draft','private','pending','future','trash'],'numberposts'=>1,'meta_key'=>'_portfolio_technology_key','meta_value'=>$key]);
            if ($existing) {
                if (!current_user_can('edit_post',$existing[0]->ID)||$existing[0]->post_status==='trash') { throw new RuntimeException('This creation form has already been used.'); }
                return $existing[0]->ID;
            }
        }
        $groups=$id?portfolio_admin_field_groups($id,['order']):[['fields'=>[acf_get_field('field_portfolio_technology_category')]]];
        $values=portfolio_admin_validate_fields($groups,$raw_fields);
        $status=$id&&!empty($input['published'])?'publish':'draft';
        if ($id&&$status!==get_post_status($id)&&!current_user_can('publish_posts')) { throw new RuntimeException('You do not have permission to change publication status.'); }
        if ($wpdb->query('START TRANSACTION')===false) { throw new RuntimeException('Could not start saving.'); }
        $data=['post_title'=>sanitize_text_field($input['title']),'post_content'=>wp_kses_post($input['content']),'post_status'=>$status];
        $result=$id?wp_update_post(wp_slash($data+['ID'=>$id]),true):wp_insert_post(wp_slash($data+['post_type'=>'technology']),true);
        if (is_wp_error($result)) { throw new RuntimeException($result->get_error_message()); }
        if (!$id) { $created=$id=$result;portfolio_store_meta($id,'_portfolio_technology_key',$key); }
        portfolio_admin_save_fields($id,$groups,$values);
        if ($image) { set_post_thumbnail($id,$image); } else { delete_post_thumbnail($id); }
        if ((int)get_post_thumbnail_id($id)!==$image) { throw new RuntimeException('Could not save the technology icon.'); }
        if ($wpdb->query('COMMIT')===false) { throw new RuntimeException('Could not commit the technology.'); }
        return $id;
    } catch (Throwable $error) {
        $wpdb->query('ROLLBACK');if($id)clean_post_cache($id);if($created)clean_post_cache($created);acf_get_store('values')->reset();throw $error;
    } finally { $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock)); }
}
add_action('admin_menu',function(){
    if(!get_option('portfolio_language_ui_enabled'))return;
    remove_menu_page('edit.php?post_type=technology');
    add_menu_page('Technologies','Technologies','edit_posts','portfolio-technologies','portfolio_technologies_page','dashicons-editor-code',25);
},99);
add_action('admin_enqueue_scripts',function($hook){if($hook==='toplevel_page_portfolio-technologies')portfolio_admin_enqueue_editor(absint($_GET['entry']??0));});
function portfolio_technologies_page(){
    if(!current_user_can('edit_posts'))wp_die('You do not have permission to edit technologies.');
    $id=absint($_GET['entry']??0);$new=($_GET['view']??'')==='new';
    echo '<div class="wrap portfolio-admin">';
    portfolio_admin_header($new?'Add technology':'Technologies',['Add technology'=>add_query_arg('view','new',portfolio_technology_url()),'Order'=>portfolio_order_url('technologies','frontend')]);
    if(!$id&&!$new){
        echo '<table class="widefat striped"><thead><tr><th>Technology</th><th>Category</th><th>Status</th></tr></thead><tbody>';
        foreach(get_posts(['post_type'=>'technology','post_status'=>['publish','draft','private','pending','future'],'numberposts'=>-1,'orderby'=>'title','order'=>'ASC'])as$post){
            if(!current_user_can('edit_post',$post->ID))continue;
            $category=get_post_meta($post->ID,'technology_category',true);
            printf('<tr><td><a href="%s">%s</a></td><td>%s</td><td>%s</td></tr>',esc_url(portfolio_technology_url($post->ID)),esc_html($post->post_title),esc_html(portfolio_technology_category_definitions()[$category]??'Other'),esc_html(get_post_status_object($post->post_status)->label));
        }
        echo '</tbody></table></div>';return;
    }
    $post=$id?get_post($id):null;
    if($id&&(!$post||$post->post_type!=='technology'||!current_user_can('edit_post',$id)))wp_die('Invalid technology.');
    echo '<p><a href="'.esc_url(portfolio_technology_url()).'">← All technologies</a></p>';
    if(isset($_GET['saved']))echo '<div class="notice notice-success"><p>Technology saved.</p></div>';
    echo '<form data-portfolio-unsaved method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
    wp_nonce_field('portfolio_save_technology_'.$id);
    printf('<input type="hidden" name="action" value="portfolio_save_technology"><input type="hidden" name="id" value="%d"><input type="hidden" name="token" value="%s"><input type="hidden" name="portfolio[key]" value="%s">',$id,esc_attr($id?portfolio_technology_token($id):''),esc_attr(wp_generate_uuid4()));
    printf('<section><h2>Technology details</h2><p><label>Title <input class="large-text" name="portfolio[title]" value="%s" required></label></p>',esc_attr($post->post_title??''));
    if($id)printf('<p><label><input type="checkbox" name="portfolio[published]" value="1" %s> Published</label></p>',checked($post->post_status,'publish',false));
    portfolio_admin_image('icon','Icon',$id?get_post_thumbnail_id($id):0);
    wp_editor($post->post_content??'','portfolio_technology_content',['textarea_name'=>'portfolio[content]','media_buttons'=>false,'textarea_rows'=>6]);
    echo '</section>';
    $groups=$id?portfolio_admin_field_groups($id,['order']):[['title'=>'Category','fields'=>[acf_get_field('field_portfolio_technology_category')]]];
    portfolio_admin_render_fields($id,$groups,'page_fields');
    submit_button($id?'Save technology':'Create draft');echo '</form></div>';
}
add_action('admin_post_portfolio_save_technology',function(){
    $id=absint($_POST['id']??0);check_admin_referer('portfolio_save_technology_'.$id);
    try{$id=portfolio_technology_save($id,wp_unslash($_POST['token']??''),wp_unslash($_POST['portfolio']??null),wp_unslash($_POST['page_fields']??null));}
    catch(Throwable$error){wp_die(esc_html($error->getMessage()),'Technology not saved',['back_link'=>true]);}
    wp_safe_redirect(add_query_arg('saved','1',portfolio_technology_url($id)));exit;
});
add_action('load-edit.php',function(){if(get_option('portfolio_language_ui_enabled')&&($_GET['post_type']??'')==='technology'){wp_safe_redirect(portfolio_technology_url());exit;}});
add_action('load-post-new.php',function(){if(get_option('portfolio_language_ui_enabled')&&($_GET['post_type']??'')==='technology'){wp_safe_redirect(add_query_arg('view','new',portfolio_technology_url()));exit;}});
add_action('load-post.php',function(){$id=absint($_GET['post']??0);if(get_option('portfolio_language_ui_enabled')&&($_GET['action']??'')==='edit'&&get_post_type($id)==='technology'&&current_user_can('edit_post',$id)){wp_safe_redirect(portfolio_technology_url($id));exit;}});
add_action('admin_bar_menu',function($bar){if(get_option('portfolio_language_ui_enabled')&&current_user_can('edit_posts'))$bar->add_node(['id'=>'new-technology','parent'=>'new-content','title'=>'Technology','href'=>add_query_arg('view','new',portfolio_technology_url())]);},100);
