<?php
if (!defined('ABSPATH')) exit;

function am_openai_chat_complete($model,$messages,$temperature=null){
  $key = am_get_openai_key();
  if(!$key) return new WP_Error('no_key','OPENAI_API_KEY is empty');

  $payload = [
    'model' => ($model ?: AM_OPENAI_MODEL),
    'messages' => $messages,
    'temperature' => ($temperature===null ? AM_OPENAI_DEFAULT_TEMPERATURE : $temperature),
  ];
  $res = wp_remote_post('https://api.openai.com/v1/chat/completions', [
    'headers'=>['Authorization'=>'Bearer '.$key,'Content-Type'=>'application/json'],
    'body'=>wp_json_encode($payload),
    'timeout'=>45
  ]);
  if(is_wp_error($res)) return $res;
  $code  = (int) wp_remote_retrieve_response_code($res);
  $body  = json_decode(wp_remote_retrieve_body($res),true);
  if($code>=400) return new WP_Error('http_'.$code, 'OpenAI HTTP '.$code);
  if (!is_array($body)) return new WP_Error('bad_response','Unexpected response from OpenAI');
  return $body['choices'][0]['message']['content'] ?? '';
}

function am_build_agent_system_message($agent_id,$summary=''){
  $prompt = get_post_meta($agent_id,'am_system_prompt',true);
  $name   = get_the_title($agent_id);
  $style  = $prompt ?: ('You are '.$name.', a helpful character with a consistent voice. Be concise, empathetic, and useful.');
  if(is_user_logged_in()){
    $u = wp_get_current_user();
    $uname = $u->display_name ?: $u->user_login;
    $style .= "\nThe logged in user is {$uname}.";
    if(!empty($u->user_email)){
      $style .= " Their email is {$u->user_email}.";
    }
  }
  if($summary){ $style .= "\n\nPrevious context (summary):\n".$summary; }
  return ['role'=>'system','content'=>$style];
}

function am_summarize_conversation($agent_id,$history_pairs){
  $model = get_post_meta($agent_id,'am_model',true) ?: AM_OPENAI_MODEL;
  $prompt = [
    ['role'=>'system','content'=>'Summarize the conversation into 8-12 concise bullets (who the user is, goals, preferences, facts, agreements).'],
    ['role'=>'user','content'=>wp_json_encode($history_pairs)]
  ];
  $summary = am_openai_chat_complete($model,$prompt,0.3);
  return is_wp_error($summary) ? '' : ($summary ?: '');
}

function am_suggest_next_steps($agent_id,$summary,$last_reply){
  $model = get_post_meta($agent_id,'am_model',true) ?: AM_OPENAI_MODEL;
  $persona = get_post_meta($agent_id,'am_system_prompt',true);
  $prompt = [
    ['role'=>'system','content'=> "Return 1–3 short quick replies (<= 8 words) as a JSON array of strings, grounded on this persona:\n".$persona],
    ['role'=>'user','content'=> "Summary:\n".$summary."\n\nLast reply:\n".$last_reply."\n\nReturn JUST the JSON array."]
  ];
  $out = am_openai_chat_complete($model,$prompt,0.2);
  if(is_wp_error($out) || !$out) return [];
  $arr = json_decode(trim($out), true);
  if(is_array($arr)) return array_slice(array_map('trim',$arr),0,3);
  $lines = array_filter(array_map('trim', explode("\n", wp_strip_all_tags($out))));
  return array_slice($lines,0,3);
}
