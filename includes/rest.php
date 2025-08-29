<?php
if (!defined('ABSPATH')) exit;

add_action('rest_api_init', function(){
    register_rest_route('am/v1', '/feedback', [
    'methods'  => 'POST',
    'permission_callback' => function( WP_REST_Request $req ){
      // Acepta nonce estándar de WP REST (X-WP-Nonce) o usuario logueado
      return is_user_logged_in() || wp_verify_nonce($req->get_header('x-wp-nonce'), 'wp_rest');
    },
    'callback' => 'am_rest_feedback_handler',
    'args'     => [
      'conversation_uid' => ['required' => true],
      'message_id'       => ['required' => true],
      'agent_id'         => ['required' => false],
      'value'            => ['required' => true], // 'up' | 'down'
      'text'             => ['required' => false],
    ],
  ]);
  register_rest_route('am/v1','/chat',[ 'methods'=>'POST','callback'=>'am_rest_chat','permission_callback'=>'__return_true' ]);
  register_rest_route('am/v1','/history',[ 'methods'=>'GET','callback'=>'am_rest_history','permission_callback'=>'__return_true' ]);
  register_rest_route('am/v1','/conversations',[ 'methods'=>'GET','callback'=>'am_rest_list_conversations','permission_callback'=>function(){return is_user_logged_in();} ]);
  register_rest_route('am/v1','/delete_conversation',[ 'methods'=>'POST','callback'=>'am_rest_delete_conversation','permission_callback'=>function(){return is_user_logged_in();} ]);
  register_rest_route('am/v1','/rename_conversation',[ 'methods'=>'POST','callback'=>'am_rest_rename_conversation','permission_callback'=>function(){return is_user_logged_in();} ]);
  register_rest_route('am/v1','/feedback',[ 'methods'=>'POST','callback'=>'am_rest_feedback','permission_callback'=>'__return_true' ]);
  register_rest_route('am/v1','/tts',[ 'methods'=>'POST','callback'=>'am_rest_tts','permission_callback'=>'__return_true' ]);
  register_rest_route('am/v1','/stt',[ 'methods'=>'POST','callback'=>'am_rest_stt','permission_callback'=>'__return_true' ]);
});



function am_rest_feedback_handler( WP_REST_Request $req ){
  $conv_uid  = sanitize_text_field( (string) $req['conversation_uid'] );
  $messageId = sanitize_text_field( (string) $req['message_id'] );
  $agentId   = intval( $req['agent_id'] ?? 0 );
  $value     = sanitize_text_field( (string) $req['value'] );
  if (!in_array($value, ['up','down'], true)) {
    return new WP_Error('am_bad_value', 'Invalid value for feedback', ['status'=>400]);
  }
  $text      = wp_kses_post( (string) ($req['text'] ?? '') );
  $user_id   = get_current_user_id();

  // Guarda en DB
  $saved_id = am_db_save_feedback([
    'conversation_uid' => $conv_uid,
    'message_id'       => $messageId,
    'agent_id'         => $agentId,
    'value'            => $value,
    'text'             => $text,
    'user_id'          => $user_id,
  ]);
  if (is_wp_error($saved_id)) return $saved_id;

  /**
   * Hook para Analytics / cualquier otra métrica
   */
  do_action('am_chat_feedback_saved', [
    'id'               => $saved_id,
    'conversation_uid' => $conv_uid,
    'message_id'       => $messageId,
    'agent_id'         => $agentId,
    'value'            => $value,
    'text'             => $text,
    'user_id'          => $user_id,
  ]);

  return rest_ensure_response(['ok' => true, 'id' => $saved_id]);
}


function am_rest_chat(WP_REST_Request $req) {
    // Prevent any output before headers
    ob_start();
    
    // Force JSON response
    header('Content-Type: application/json; charset=utf-8');
    
    try {
        $agent_id = (int)$req->get_param('agent_id');
        $message  = trim((string)$req->get_param('message'));
        $conv_uid = sanitize_text_field($req->get_param('conversation_uid') ?: '');
        
        if(!$agent_id || $message==='') {
            ob_end_clean();
            return new WP_REST_Response(['error' => 'Incomplete data'], 400);
        }

        // Validate nonce
        if(!wp_verify_nonce($req->get_header('X-WP-Nonce'), 'wp_rest')) {
          wp_send_json(['error' => 'Invalid nonce'], 403); 
          exit;
        }

        if(function_exists('am_moderate_text')){
          $mod = am_moderate_text($message);
          if(!$mod['ok']) {
            return new WP_REST_Response(['error'=>'Not allowed'], 400);
          }
        }

        try {
          $user_id = get_current_user_id();
          $model   = get_post_meta($agent_id,'am_model',true) ?: AM_OPENAI_MODEL;
          $temp_v  = get_post_meta($agent_id,'am_temperature',true);
          $temp    = ($temp_v==='' || $temp_v===null) ? AM_OPENAI_DEFAULT_TEMPERATURE : max(0.0, min(2.0, floatval($temp_v)));

          // Resolve/create conversation by public id (create only on first user message)
          $conv = $conv_uid ? am_get_conversation_by_public($conv_uid) : null;
          if(!$conv){
            $title = mb_substr($message, 0, 60) ?: 'New chat';
            $new_id = am_create_conversation($user_id ?: null, $agent_id, $title);
            $conv = am_get_conversation($new_id);
          }
          if((int)$conv->user_id > 0 && (int)$conv->user_id !== (int)$user_id){
            return new WP_REST_Response(['error'=>'Not authorized'], 403);
          }

          $user_msg_id = am_insert_message($conv->id,'user',$message);

          $summary = (string)($conv->summary ?: '');
          $history = am_get_messages($conv->id, 60);
          $msgs = [ am_build_agent_system_message($agent_id, $summary) ];
          foreach($history as $h){ $msgs[] = ['role'=>$h['role'],'content'=>$h['content']]; }
          $msgs[] = ['role'=>'system','content'=>"Always respond directly to the user's most recent message. Be contextual."];

          $reply = am_openai_chat_complete($model, $msgs, $temp);
          if(is_wp_error($reply)) {
            wp_send_json([
              'conversation_uid' => $conv->public_id,
              'error' => $reply->get_error_message()
            ], 500);
            exit;
          }
          if(!$reply) $reply = 'Sorry, I could not generate a reply.';
          $reply = am_filter_banned_words_all($reply, $agent_id);

          $assistant_msg_id = am_insert_message($conv->id,'assistant',$reply);
          am_touch_conversation($conv->id);

          $turns = count($history) + 1;
          if($turns % 6 === 0 || strlen($summary) < 20){
            $pairs=[]; foreach($history as $h){ $pairs[] = [$h['role'],$h['content']]; }
            $pairs[]=['assistant',$reply];
            $new_summary = am_summarize_conversation($agent_id,$pairs);
            if($new_summary){ am_update_summary($conv->id,$new_summary); }
          }

          // Quick suggestions only on first turn
          $enable_sugs = (bool) get_option('am_enable_suggestions', 1);
          $is_first_turn = (count($history) === 1);
          $sugs = ($enable_sugs && $is_first_turn) ? am_suggest_next_steps($agent_id, $summary, $reply) : [];

          am_log_event('message', ['direction'=>'user->assistant'], $conv->id, $assistant_msg_id);

          // Clean output buffer before sending response
          ob_end_clean();
          return new WP_REST_Response([
              'conversation_uid' => $conv->public_id,
              'reply' => $reply,
              'assistant_message_id' => $assistant_msg_id,
              'suggestions' => $sugs
          ], 200);

        } catch (Exception $e) {
          ob_end_clean();
          error_log('Chat API Error: ' . $e->getMessage());
          return new WP_REST_Response([
            'error' => 'Server error',
            'message' => $e->getMessage()
          ], 500);
        }

    } catch (Exception $e) {
        ob_end_clean();
        error_log('Unexpected Chat API Error: ' . $e->getMessage());
        return new WP_REST_Response(['error' => 'Unexpected server error'], 500);
    }
}

function am_rest_history(WP_REST_Request $req){
  $cuid = sanitize_text_field($req->get_param('conversation_uid') ?: '');
  if(!$cuid) return new WP_REST_Response(['error'=>'conversation_uid is required'],400);
  $conv = am_get_conversation_by_public($cuid);
  if(!$conv) return new WP_REST_Response(['error'=>'Conversation not found'],404);
  if((int)$conv->user_id > 0){
    $uid = get_current_user_id();
    if(!$uid || (int)$uid !== (int)$conv->user_id) return new WP_REST_Response(['error'=>'Not authorized'],403);
  }
  $items = am_get_messages($conv->id,200);
  return new WP_REST_Response(['items'=>$items],200);
}

function am_rest_list_conversations(WP_REST_Request $req){
  global $wpdb; $t = AM_DB_CONVERSATIONS;
  $uid = get_current_user_id();
  $rows = $wpdb->get_results($wpdb->prepare(
    "SELECT id,public_id,agent_id,title,created_at,updated_at FROM {$t} WHERE user_id=%d ORDER BY updated_at DESC", $uid
  ), ARRAY_A);
  return new WP_REST_Response(['items'=>$rows],200);
}

function am_rest_delete_conversation(WP_REST_Request $req){
  $cuid = sanitize_text_field($req->get_param('conversation_uid') ?: '');
  if(!$cuid) return new WP_REST_Response(['error'=>'conversation_uid is required'],400);
  global $wpdb; $c = AM_DB_CONVERSATIONS; $m = AM_DB_MESSAGES;
  $uid = get_current_user_id();
  $conv = am_get_conversation_by_public($cuid);
  if(!$conv || (int)$conv->user_id !== (int)$uid) return new WP_REST_Response(['error'=>'Not authorized'],403);
  $wpdb->delete($m,['conversation_id'=>$conv->id],['%d']);
  $wpdb->delete($c,['id'=>$conv->id],['%d']);
  am_log_event('delete', [], (int)$conv->id);
  return new WP_REST_Response(['ok'=>true],200);
}

function am_rest_rename_conversation(WP_REST_Request $req){
  $cuid = sanitize_text_field($req->get_param('conversation_uid') ?: '');
  $title= sanitize_text_field($req->get_param('title') ?: '');
  if(!$cuid || $title==='') return new WP_REST_Response(['error'=>'conversation_uid and title are required'],400);
  $ok = am_rename_conversation_by_public($cuid,$title,get_current_user_id());
  if(!$ok) return new WP_REST_Response(['error'=>'Not authorized or not found'],403);
  am_log_event('rename', ['title'=>$title]);
  return new WP_REST_Response(['ok'=>true],200);
}

function am_rest_feedback(WP_REST_Request $req){
  $cuid = sanitize_text_field($req->get_param('conversation_uid') ?: '');
  $mid  = (int) $req->get_param('message_id');
  $val  = sanitize_text_field($req->get_param('value')); // 'up'|'down'
  $text = (string) $req->get_param('text'); // assistant message text (for analytics)
  if(!$cuid || !$mid || !in_array($val,['up','down'],true))
    return new WP_REST_Response(['error'=>'missing fields'],400);

  $conv = am_get_conversation_by_public($cuid);
  if(!$conv) return new WP_REST_Response(['error'=>'not found'],404);

  $type = ($val==='up' ? 'feedback_up' : 'feedback_down');
  am_log_event($type, ['message_id'=>$mid,'text'=>$text], $conv->id, $mid);
  return new WP_REST_Response(['ok'=>true],200);
}

function am_rest_tts( WP_REST_Request $req ){
  $text = trim( (string) $req->get_param('text') );
  $voice = trim( (string) $req->get_param('voice_id') );
  $agent_id = (int) $req->get_param('agent_id');
  
  // Debug logging
  error_log('TTS Debug - Request: ' . json_encode([
    'text_length' => strlen($text),
    'voice_id' => $voice,
    'agent_id' => $agent_id
  ]));

  if (!$text) {
    return new WP_Error('missing_text', 'Text is required', ['status' => 400]);
  }

  // Clean voice ID - remove URL parts if present
  $original_voice = $voice;
  if (preg_match('~/text-to-speech/([^/?#]+)~i', $voice, $m)) {
    $voice = $m[1];
  }
  $voice = preg_replace('/[^A-Za-z0-9_-]/', '', $voice);

  // Fallback to default voice if empty
  if (!$voice && defined('AM_ELEVENLABS_DEFAULT_VOICE_ID')) {
    $voice = AM_ELEVENLABS_DEFAULT_VOICE_ID;
  }

  // If still no voice, try to get from agent
  if (!$voice && $agent_id) {
    $agent_voice = get_post_meta($agent_id, 'am_voice_id', true);
    if ($agent_voice) {
      $voice = trim($agent_voice);
      // Clean again if needed
      if (preg_match('~/text-to-speech/([^/?#]+)~i', $voice, $m)) {
        $voice = $m[1];
      }
      $voice = preg_replace('/[^A-Za-z0-9_-]/', '', $voice);
    }
  }

  error_log('TTS Debug - Voice processing: ' . json_encode([
    'original' => $original_voice,
    'cleaned' => $voice,
    'from_agent' => $agent_id ? get_post_meta($agent_id, 'am_voice_id', true) : 'N/A'
  ]));

  if (!$voice) {
    return new WP_Error('no_voice', 'No voice ID provided or configured', ['status' => 400]);
  }

  // Get API key
  $api_key = am_get_eleven_key();
  if (!$api_key) {
    error_log('TTS Debug - No API key found');
    return new WP_Error('no_api_key', 'ElevenLabs API key not configured', ['status' => 500]);
  }

  error_log('TTS Debug - API key found, length: ' . strlen($api_key));

  // Prepare request
  $url = "https://api.elevenlabs.io/v1/text-to-speech/{$voice}";
  $body_data = [
    'text' => $text,
    'model_id' => 'eleven_multilingual_v2',
    'voice_settings' => [
      'stability' => 0.5,
      'similarity_boost' => 0.75
    ]
  ];

  error_log('TTS Debug - Calling ElevenLabs API: ' . $url);
  
  // Call ElevenLabs API
  $response = wp_remote_post($url, [
    'headers' => [
      'Accept' => 'audio/mpeg',
      'Content-Type' => 'application/json',
      'xi-api-key' => $api_key
    ],
    'body' => wp_json_encode($body_data),
    'timeout' => 30,
    'stream' => false
  ]);

  if (is_wp_error($response)) {
    error_log('TTS Debug - WordPress HTTP error: ' . $response->get_error_message());
    return new WP_Error('api_error', 'HTTP request failed: ' . $response->get_error_message(), ['status' => 500]);
  }

  $code = wp_remote_retrieve_response_code($response);
  $body = wp_remote_retrieve_body($response);
  $headers = wp_remote_retrieve_headers($response);
  
  error_log('TTS Debug - API Response: ' . json_encode([
    'status' => $code,
    'body_length' => strlen($body),
    'content_type' => $headers['content-type'] ?? 'unknown'
  ]));

  // Check if the response is an error message
  if ($code !== 200) {
    // Try to parse the error message from the API
    $error_data = json_decode($body, true);
    $error_message = 'ElevenLabs API error (HTTP ' . $code . ')';
    
    if (is_array($error_data)) {
      if (isset($error_data['detail'])) {
        $detail = $error_data['detail'];
        if (is_array($detail)) {
          $error_message = implode(', ', $detail);
        } else {
          $error_message = (string) $detail;
        }
      } elseif (isset($error_data['message'])) {
        $error_message = (string) $error_data['message'];
      }
    } else if (strlen($body) > 0 && strlen($body) < 500) {
      $error_message = $body;
    }
    
    error_log('TTS Debug - API Error: ' . $error_message);
    return new WP_Error('api_error', $error_message, ['status' => $code]);
  }

  if (!$body || strlen($body) < 100) {
    error_log('TTS Debug - Invalid response body');
    return new WP_Error('empty_response', 'Invalid audio data received', ['status' => 500]);
  }

  error_log('TTS Debug - Success! Audio size: ' . strlen($body) . ' bytes');

  // For binary responses, we need to bypass WordPress JSON encoding
  // Set headers directly and output the binary data
  header('Content-Type: audio/mpeg');
  header('Content-Length: ' . strlen($body));
  header('Cache-Control: no-cache');
  header('Accept-Ranges: bytes');
  
  // Output binary data directly and exit to avoid WordPress processing
  echo $body;
  exit;
}

function am_rest_stt( WP_REST_Request $req ){
  $files = $req->get_file_params();
  if (empty($files['file']) || empty($files['file']['tmp_name'])) {
    return new WP_Error('no_audio', 'No audio file provided', ['status' => 400]);
  }

  $api_key = am_get_openai_key();
  if (!$api_key) {
    return new WP_Error('no_key', 'OPENAI_API_KEY is empty', ['status' => 500]);
  }

  $file      = $files['file'];
  $filename  = $file['name'] ?: 'audio.webm';
  $mime      = $file['type'] ?: 'audio/webm';
  $mime      = preg_replace('/;.*/', '', $mime); // strip codec info if present
  if ($mime === '' || $mime === 'application/octet-stream') {
    $mime = 'audio/webm';
  }
  $language  = (string) ($req->get_param('language') ?? '');

  $curl_file = function_exists('curl_file_create')
    ? curl_file_create($file['tmp_name'], $mime, $filename)
    : '@' . $file['tmp_name'];

  $body = [
    'file'  => $curl_file,
    'model' => 'whisper-1',
    'temperature' => 0,
  ];
  if (preg_match('/^[a-z]{2}$/i', $language)) {
    $body['language'] = strtolower(substr($language, 0, 2));
  }

  $ch = curl_init('https://api.openai.com/v1/audio/transcriptions');
  curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 60,
    CURLOPT_HTTPHEADER     => [ 'Authorization: Bearer ' . $api_key ],
    CURLOPT_POSTFIELDS     => $body,
  ]);

  $raw = curl_exec($ch);
  $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
  if ($raw === false) {
    $err = curl_error($ch);
    curl_close($ch);
    return new WP_Error('stt_failed', $err ?: 'cURL error', ['status' => 500]);
  }
  curl_close($ch);
  $data = json_decode($raw, true);
  if ($code >= 400 || !is_array($data)) {
    $msg = is_array($data) && isset($data['error']['message']) ? (string) $data['error']['message'] : 'STT request failed';
    return new WP_Error('stt_failed', $msg, ['status' => $code ?: 500]);
  }

  return rest_ensure_response([ 'text' => $data['text'] ?? '' ]);
}



