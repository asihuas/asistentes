<?php
if (!defined('ABSPATH')) exit;

function am_install_tables(){
  global $wpdb;
  $charset = $wpdb->get_charset_collate();
  require_once ABSPATH.'wp-admin/includes/upgrade.php';

  $c = AM_DB_CONVERSATIONS;
  $m = AM_DB_MESSAGES;
  $e = AM_DB_EVENTS;

  $sql1 = "CREATE TABLE $c (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    public_id VARCHAR(40) NOT NULL,
    user_id BIGINT UNSIGNED NULL,
    agent_id BIGINT UNSIGNED NOT NULL,
    title VARCHAR(255) NULL,
    summary LONGTEXT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY public_id (public_id),
    KEY user_id (user_id),
    KEY agent_id (agent_id)
  ) $charset";

  $sql2 = "CREATE TABLE $m (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    conversation_id BIGINT UNSIGNED NOT NULL,
    role ENUM('user','assistant','system') NOT NULL,
    content LONGTEXT NOT NULL,
    tokens INT UNSIGNED NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY conversation_id (conversation_id),
    KEY created_at (created_at)
  ) $charset";

  $sql3 = "CREATE TABLE $e (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    conversation_id BIGINT UNSIGNED NULL,
    message_id BIGINT UNSIGNED NULL,
    event_type VARCHAR(64) NOT NULL,
    event_payload LONGTEXT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY conversation_id (conversation_id),
    KEY message_id (message_id),
    KEY event_type (event_type),
    KEY created_at (created_at)
  ) $charset";

  dbDelta($sql1); dbDelta($sql2); dbDelta($sql3);
}

/* CRUD helpers */
function am_get_conversation($id){
  global $wpdb; $t = AM_DB_CONVERSATIONS;
  $sql = am_db_prepare("SELECT * FROM $t WHERE id=%d", $id);
  return $sql ? $wpdb->get_row($sql) : null;
}
function am_get_conversation_by_public($uid){
  global $wpdb; $t = AM_DB_CONVERSATIONS;
  $sql = am_db_prepare("SELECT * FROM $t WHERE public_id=%s", $uid);
  return $sql ? $wpdb->get_row($sql) : null;
}
function am_create_conversation($user_id,$agent_id,$title=null){
  global $wpdb; $t = AM_DB_CONVERSATIONS;
  do { $pid = am_uuid_public_id(); $exists = $wpdb->get_var(am_db_prepare("SELECT COUNT(*) FROM $t WHERE public_id=%s",$pid)); } while($exists);
  $wpdb->insert($t,[
    'public_id'=>$pid,
    'user_id'=> $user_id ?: null,
    'agent_id'=>$agent_id,
    'title'=>$title,
    'created_at'=> current_time('mysql'),
    'updated_at'=> current_time('mysql')
  ],['%s','%d','%d','%s','%s','%s']);
  return (int)$wpdb->insert_id;
}
function am_insert_message($conversation_id,$role,$content,$tokens=null){
  global $wpdb; $t = AM_DB_MESSAGES;
  $wpdb->insert($t,[
    'conversation_id'=>$conversation_id,'role'=>$role,'content'=>$content,'tokens'=>$tokens,'created_at'=> current_time('mysql')
  ],['%d','%s','%s','%d','%s']);
  return (int)$wpdb->insert_id;
}

function am_get_messages($conversation_id,$limit=200){
  global $wpdb; $t = AM_DB_MESSAGES;
  $sql = am_db_prepare("SELECT id, role, content FROM $t WHERE conversation_id=%d ORDER BY id ASC LIMIT %d", $conversation_id, $limit);
  return $sql ? $wpdb->get_results($sql, ARRAY_A) : [];
}

function am_update_summary($conversation_id,$summary){
  global $wpdb; $t = AM_DB_CONVERSATIONS;
  $wpdb->update($t,['summary'=>$summary,'updated_at'=>current_time('mysql')],['id'=>$conversation_id],['%s','%s'],['%d']);
}
function am_touch_conversation($conversation_id){
  global $wpdb; $t = AM_DB_CONVERSATIONS;
  $wpdb->update($t,['updated_at'=>current_time('mysql')],['id'=>$conversation_id],['%s'],['%d']);
}
function am_rename_conversation_by_public($public_id,$title,$user_id){
  global $wpdb; $t = AM_DB_CONVERSATIONS;
  $row = am_get_conversation_by_public($public_id);
  if(!$row || (int)$row->user_id !== (int)$user_id) return false;
  $wpdb->update($t,['title'=>$title,'updated_at'=>current_time('mysql')],['id'=>$row->id],['%s','%s'],['%d']);
  return true;
}





function am_db_save_feedback( array $data ) {https://wa4u.ai/wp-content/uploads/2025/08/thumbs-down-filled.svg
  global $wpdb;
  $table = 'pgn_am_feedback';
  $charset = $wpdb->get_charset_collate();

  // Crea la tabla si no existe (migración perezosa)
  $sql = "CREATE TABLE IF NOT EXISTS $table (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    conversation_uid VARCHAR(64) NOT NULL,
    message_id VARCHAR(64) NOT NULL,
    agent_id BIGINT UNSIGNED NULL,
    value ENUM('up','down') NOT NULL,
    text MEDIUMTEXT NULL,
    user_id BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY conv (conversation_uid),
    KEY msg (message_id),
    KEY agent (agent_id)
  ) $charset;";
  $wpdb->query($sql);

  $ok = $wpdb->insert($table, [
    'conversation_uid' => $data['conversation_uid'] ?? '',
    'message_id'       => $data['message_id'] ?? '',
    'agent_id'         => isset($data['agent_id']) ? (int)$data['agent_id'] : null,
    'value'            => $data['value'] ?? '',
    'text'             => $data['text'] ?? '',
    'user_id'          => isset($data['user_id']) ? (int)$data['user_id'] : null,
  ], [
    '%s','%s','%d','%s','%s','%d'
  ]);

  if (!$ok) {
    return new WP_Error('am_db_insert_failed', 'Could not save feedback');
  }
  return $wpdb->insert_id;
}