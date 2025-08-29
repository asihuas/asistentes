<?php
if (!defined('ABSPATH')) { exit; }

/** Add "Analytics" and "Settings" under the Agents (CPT) menu */
add_action('admin_menu', function () {
  $parent = 'edit.php?post_type=am_agent';

  add_submenu_page($parent,'Analytics','Analytics','manage_options','am_assistants_analytics','am_admin_analytics_page');
  add_submenu_page($parent,'Settings','Settings','manage_options','am_assistants_settings','am_admin_settings_page');
});

/** SETTINGS PAGE — stores API keys in options */
function am_admin_settings_page(){
  if (!current_user_can('manage_options')) return;

  if (isset($_POST['am_settings_nonce']) && wp_verify_nonce($_POST['am_settings_nonce'], 'am_save_settings')) {
    update_option('am_enable_moderation',      isset($_POST['am_enable_moderation']) ? 1 : 0);
    update_option('am_enable_suggestions',     isset($_POST['am_enable_suggestions']) ? 1 : 0);
    update_option('am_enable_feedback_fab',    isset($_POST['am_enable_feedback_fab']) ? 1 : 0);
    update_option('am_banned_words', sanitize_textarea_field($_POST['am_banned_words'] ?? ''));
    echo '<div class="updated notice"><p>Settings saved.</p></div>';
  }

  $enable_mod  = (int) get_option('am_enable_moderation', 0);
  $enable_sugs = (int) get_option('am_enable_suggestions', 1);
  $fb_fab      = (int) get_option('am_enable_feedback_fab', 1);
  $banned      = esc_textarea(get_option('am_banned_words', ''));
  ?>
  <div class="wrap">
    <h1>AM Assistants — Settings</h1>
    <form method="post">
      <?php wp_nonce_field('am_save_settings','am_settings_nonce'); ?>
      <table class="form-table" role="presentation">
        <tr><th scope="row">Moderation</th>
          <td><label><input type="checkbox" name="am_enable_moderation" <?php checked($enable_mod,1); ?>> Enable basic moderation</label></td></tr>
        <tr><th scope="row">Quick suggestions</th>
          <td><label><input type="checkbox" name="am_enable_suggestions" <?php checked($enable_sugs,1); ?>> Show 1–3 quick replies on the first turn of each conversation</label></td></tr>
        <tr><th scope="row">Feedback floating button</th>
          <td><label><input type="checkbox" name="am_enable_feedback_fab" <?php checked($fb_fab,1); ?>> Show floating 👍/👎 in chat</label></td></tr>
        <tr><th scope="row">Banned words (global)</th>
          <td><textarea name="am_banned_words" rows="6" class="large-text" placeholder="one word per line"><?php echo $banned; ?></textarea></td></tr>
      </table>
      <?php submit_button('Save Settings'); ?>
    </form>
  </div>
  <?php
}
/** ANALYTICS PAGE (with feedback viewer) **/
function am_admin_analytics_page(){
  if (!current_user_can('manage_options')) return;
  global $wpdb;
  $c = AM_DB_CONVERSATIONS; 
  $m = AM_DB_MESSAGES; 
  $f = AM_DB_FEEDBACK; // Use the feedback table instead of events
  $u = $wpdb->users;

  // Tolerant if tables not yet created
  $table_ok = function($table) use ($wpdb){
    $exists = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=%s AND TABLE_NAME=%s", DB_NAME, $table));
    return !!$exists;
  };
  if(!$table_ok($c) || !$table_ok($m) || !$table_ok($f)){
    echo '<div class="notice notice-warning"><p>Tables are not ready yet. Reactivate the plugin to run the installer.</p></div>';
    return;
  }

  $total_convs = (int)$wpdb->get_var("SELECT COUNT(*) FROM $c");
  $total_msgs  = (int)$wpdb->get_var("SELECT COUNT(*) FROM $m");
  $avg_len     = (float)$wpdb->get_var("SELECT IFNULL(AVG(cnt),0) FROM (SELECT COUNT(*) AS cnt FROM $m GROUP BY conversation_id) t");
  $fb_up = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $f WHERE value=%s",'up'));
  $fb_down = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $f WHERE value=%s",'down'));

  $recent = $wpdb->get_results("
    SELECT c.public_id, c.agent_id, c.title, c.updated_at, c.user_id,
           COALESCE(u.display_name, u.user_login) AS user_name
    FROM $c c
    LEFT JOIN $u u ON u.ID = c.user_id
    ORDER BY c.updated_at DESC LIMIT 10
  ", ARRAY_A);
  ?>
  <div class="wrap">
    <h1>AM Assistants — Analytics</h1>
    <div class="am-cards" style="display:grid;grid-template-columns:repeat(5,minmax(140px,1fr));gap:12px;">
      <div class="card"><h3>Total conversations</h3><p><strong><?php echo number_format_i18n($total_convs); ?></strong></p></div>
      <div class="card"><h3>Total messages</h3><p><strong><?php echo number_format_i18n($total_msgs); ?></strong></p></div>
      <div class="card"><h3>Avg. msgs/conversation</h3><p><strong><?php echo number_format_i18n($avg_len,2); ?></strong></p></div>
      <div class="card"><h3>👍 feedback</h3><p><strong><?php echo number_format_i18n($fb_up); ?></strong></p></div>
      <div class="card"><h3>👎 feedback</h3><p><strong><?php echo number_format_i18n($fb_down); ?></strong></p></div>
    </div>

    <h2 style="margin-top:24px;">Recent conversations</h2>
    <table class="widefat striped">
      <thead><tr><th>Conversation</th><th>Agent</th><th>User</th><th>Updated</th></tr></thead>
      <tbody>
      <?php
      if($recent){
        foreach($recent as $r){
          $name = get_the_title((int)$r['agent_id']);
          $dest = am_find_chat_page_url();
          $url  = add_query_arg(['agent_id'=>$r['agent_id'],'cid'=>$r['public_id']], $dest);
          $user = $r['user_id'] ? ($r['user_name'] ?: ('#'.$r['user_id'])) : 'Guest';
          echo '<tr>';
          echo '<td><a href="'.esc_url($url).'" target="_blank">'.esc_html($r['title'] ?: $r['public_id']).'</a></td>';
          echo '<td>'.esc_html($name ?: ('#'.$r['agent_id'])).'</td>';
          echo '<td>'.esc_html($user).'</td>';
          echo '<td>'.esc_html($r['updated_at']).'</td>';
          echo '</tr>';
        }
      } else {
        echo '<tr><td colspan="4">No conversations yet.</td></tr>';
      }
      ?>
      </tbody>
    </table>

    <h2 style="margin-top:24px;">Feedback</h2>
    <p>
      <a href="<?php echo esc_url( add_query_arg('feedback','up') ); ?>" class="button">View 👍 messages</a>
      <a href="<?php echo esc_url( add_query_arg('feedback','down') ); ?>" class="button">View 👎 messages</a>
      <a href="<?php echo esc_url( remove_query_arg('feedback') ); ?>" class="button button-secondary">Clear filter</a>
    </p>
    <?php
    $filter = isset($_GET['feedback']) ? sanitize_text_field($_GET['feedback']) : '';
    if($filter){
        $dir = ($filter==='up') ? 'up' : 'down';
        $list = $wpdb->get_results($wpdb->prepare("
          SELECT f.id AS feedback_id, f.created_at, f.value, f.text,
                 m.id AS message_id, m.content,
                 c.public_id, c.agent_id, c.user_id,
                 COALESCE(u.display_name, u.user_login) AS user_name
          FROM $f f
          LEFT JOIN $m m ON m.id = f.message_id
          LEFT JOIN $c c ON c.public_id = f.conversation_uid
          LEFT JOIN $u u ON u.ID = c.user_id
          WHERE f.value = %s
          ORDER BY f.created_at DESC
          LIMIT 200
        ", $dir), ARRAY_A);
        ?>
        <table class="widefat striped">
          <thead>
            <tr><th>Date</th><th>Agent</th><th>User</th><th>Message</th><th>Open</th></tr>
          </thead>
          <tbody>
          <?php
          if($list){
            foreach($list as $row){
              $agent = get_the_title((int)$row['agent_id']) ?: ('#'.$row['agent_id']);
              $user  = $row['user_id'] ? ($row['user_name'] ?: ('#'.$row['user_id'])) : 'Guest';
              $snippet = mb_substr( wp_strip_all_tags($row['content'] ?: $row['text']), 0, 200 );
              $open = $row['public_id'] ? add_query_arg(['agent_id'=>$row['agent_id'],'cid'=>$row['public_id']], am_find_chat_page_url()) : '#';
              echo '<tr>';
              echo '<td>'.esc_html($row['created_at']).'</td>';
              echo '<td>'.esc_html($agent).'</td>';
              echo '<td>'.esc_html($user).'</td>';
              echo '<td>'.esc_html($snippet).'</td>';
              echo '<td><a class="button" href="'.esc_url($open).'" target="_blank">Open</a></td>';
              echo '</tr>';
            }
          } else {
            echo '<tr><td colspan="5">No feedback yet.</td></tr>';
          }
          ?>
          </tbody>
        </table>
        <?php
    }
    ?>
  </div>
  <?php
}
