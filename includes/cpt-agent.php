<?php
if (!defined('ABSPATH')) exit;

add_action('init', function(){
  register_post_type('am_agent', [
    'label' => 'Agents',
    'public' => false,
    'show_ui' => true,
    'supports' => ['title','thumbnail'],
    'menu_icon' => 'dashicons-buddicons-buddypress-logo'
  ]);
});

add_action('add_meta_boxes', function(){
  add_meta_box('am_agent_meta','Agent Configuration','am_agent_meta_box_cb','am_agent','normal','high');
});

function am_agent_meta_box_cb($post){
  $welcome = get_post_meta($post->ID,'am_welcome',true);
  $prompt  = get_post_meta($post->ID,'am_system_prompt',true);
  $model   = get_post_meta($post->ID,'am_model',true) ?: AM_OPENAI_MODEL;
  $voice   = get_post_meta($post->ID,'am_voice_id',true);
  $avatar  = get_post_meta($post->ID,'am_avatar_url',true);
  $temp    = get_post_meta($post->ID,'am_temperature',true);
  $subtitle= get_post_meta($post->ID,'am_subtitle',true); // “Character ready to chat” per-agent
  $complement = get_post_meta($post->ID,'am_complement',true);
  $banA    = get_post_meta($post->ID,'am_banned_words_agent',true);
  if ($temp === '' || $temp === null) $temp = AM_OPENAI_DEFAULT_TEMPERATURE;
  wp_nonce_field('am_agent_meta_save','am_agent_meta_nonce'); ?>
  <style>.am-field{margin:12px 0} .am-field textarea{width:100%;min-height:130px}</style>
  <div class="am-field"><label><strong>Welcome</strong><br>
    <input type="text" name="am_welcome" value="<?php echo esc_attr($welcome); ?>" style="width:100%"></label></div>
  <div class="am-field"><label><strong>System Prompt (persona)</strong><br>
    <textarea name="am_system_prompt" placeholder="Define how this character talks and behaves."><?php echo esc_textarea($prompt); ?></textarea></label></div>
  <div class="am-field"><label><strong>Agent subtitle (under the name)</strong><br>
    <input type="text" name="am_subtitle" value="<?php echo esc_attr($subtitle); ?>" style="width:100%" placeholder="Character ready to chat"></label></div>
  <div class="am-field"><label><strong>Complement (below subtitle)</strong><br>
    <input type="text" name="am_complement" value="<?php echo esc_attr($complement); ?>" style="width:100%"></label></div>
  <div class="am-field"><label><strong>Model</strong> <small>(default gpt-5-chat-latest)</small><br>
    <input type="text" name="am_model" value="<?php echo esc_attr($model); ?>"></label></div>
  <div class="am-field"><label><strong>Temperature</strong> (0.0–2.0)<br>
    <input type="number" step="0.01" min="0" max="2" name="am_temperature" value="<?php echo esc_attr($temp); ?>" style="width:120px"></label></div>
  <div class="am-field"><label><strong>Voice ID (ElevenLabs)</strong><br>
    <input type="text" name="am_voice_id" value="<?php echo esc_attr($voice); ?>" style="width:100%"></label></div>
  <div class="am-field"><label><strong>Avatar URL</strong><br>
    <input type="text" name="am_avatar_url" value="<?php echo esc_attr($avatar); ?>" style="width:100%"></label></div>
  <div class="am-field"><label><strong>Banned words (per-agent)</strong><br>
    <textarea name="am_banned_words_agent" placeholder="one word per line"><?php echo esc_textarea($banA); ?></textarea></label></div>
<?php }

add_action('save_post_am_agent', function($post_id){
  if (!isset($_POST['am_agent_meta_nonce']) || !wp_verify_nonce($_POST['am_agent_meta_nonce'],'am_agent_meta_save')) return;
  update_post_meta($post_id,'am_welcome',sanitize_text_field($_POST['am_welcome'] ?? ''));
  update_post_meta($post_id,'am_system_prompt',wp_kses_post($_POST['am_system_prompt'] ?? ''));
  update_post_meta($post_id,'am_model',sanitize_text_field($_POST['am_model'] ?? ''));
  $t = isset($_POST['am_temperature']) ? floatval($_POST['am_temperature']) : AM_OPENAI_DEFAULT_TEMPERATURE;
  $t = max(0, min(2, $t));
  update_post_meta($post_id,'am_temperature',$t);
  update_post_meta($post_id,'am_voice_id',sanitize_text_field($_POST['am_voice_id'] ?? ''));
  update_post_meta($post_id,'am_avatar_url',esc_url_raw($_POST['am_avatar_url'] ?? ''));
  update_post_meta($post_id,'am_subtitle',sanitize_text_field($_POST['am_subtitle'] ?? ''));
  update_post_meta($post_id,'am_complement',sanitize_text_field($_POST['am_complement'] ?? ''));
  update_post_meta($post_id,'am_banned_words_agent',sanitize_textarea_field($_POST['am_banned_words_agent'] ?? ''));
});