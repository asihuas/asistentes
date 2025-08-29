<?php
if (!defined('ABSPATH')) exit;

/** Keys ONLY from constants/env (no Settings fields) */
function am_get_openai_key(){
  if (defined('OPENAI_API_KEY') && OPENAI_API_KEY) return OPENAI_API_KEY;
  $env = getenv('OPENAI_API_KEY'); return $env ? trim($env) : '';
}
function am_get_eleven_key(){
  if (defined('ELEVENLABS_API_KEY') && ELEVENLABS_API_KEY) return ELEVENLABS_API_KEY;
  $env = getenv('ELEVENLABS_API_KEY'); return $env ? trim($env) : '';
}

/** Safe prepare wrapper (evita preg_match sobre null) */
function am_db_prepare($query, ...$args){
  global $wpdb;
  if (!is_string($query) || $query==='') return false;
  if (strpos($query, '%') === false) return $query;
  return $wpdb->prepare($query, ...$args);
}

/** Find chat page with [am_chat] */
function am_find_chat_page_url(){
  static $cached = null; if($cached!==null) return $cached;
  $pages = get_posts(['post_type'=>'page','post_status'=>'publish','numberposts'=>50,'s'=>'[am_chat']);
  foreach($pages as $p){
    if(stripos($p->post_content,'[am_chat')!==false){ $cached=get_permalink($p); return $cached; }
  }
  return $cached = home_url('/assistant/');
}

/** UUID-like base36 public id */
function am_uuid_public_id($len=26){
  $bytes = wp_generate_uuid4().wp_generate_uuid4();
  $b = preg_replace('/[^a-f0-9]/i','',$bytes);
  $num = base_convert(substr($b,0,32),16,36).base_convert(substr($b,32,32),16,36);
  return substr($num,0,$len);
}

/** Banned words helpers */
function am_get_banned_words_global(){
  $raw = (string) get_option('am_banned_words',''); if(!$raw) return [];
  $lines = preg_split('/\r\n|\r|\n/', $raw);
  $out=[]; foreach($lines as $w){ $w=trim($w); if($w!=='') $out[]=$w; }
  return array_values(array_unique($out));
}
function am_get_banned_words_agent($agent_id){
  $raw = (string) get_post_meta($agent_id,'am_banned_words_agent',true); if(!$raw) return [];
  $lines = preg_split('/\r\n|\r|\n/', $raw);
  $out=[]; foreach($lines as $w){ $w=trim($w); if($w!=='') $out[]=$w; }
  return array_values(array_unique($out));
}
function am_filter_banned_words_all($text,$agent_id){
  $words = array_merge(am_get_banned_words_global(), am_get_banned_words_agent($agent_id));
  if(!$words) return $text;
  foreach($words as $w){
    $pattern = '/\b'.preg_quote($w,'/').'\b/iu';
    $text = preg_replace($pattern, '***', $text);
  }
  return $text;
}

/** Event logger (feedback, message, delete, rename) */
function am_log_event($type,$payload=[], $conversation_id=null, $message_id=null){
  global $wpdb; $e = AM_DB_EVENTS;
  $wpdb->insert($e,[
    'conversation_id'=>$conversation_id ?: null,
    'message_id'=>$message_id ?: null,
    'event_type'=>$type,
    'event_payload'=> wp_json_encode($payload),
    'created_at'=> current_time('mysql')
  ],['%d','%d','%s','%s','%s']);
  return (int)$wpdb->insert_id;
}
