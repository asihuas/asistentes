<?php
if (!defined('ABSPATH')) { exit; }

function am_render_assistant_header(){
  $agent_id = isset($_GET['agent_id']) ? (int)$_GET['agent_id'] : 0;
  if(!$agent_id) return '<div class="error">Missing URL param <code>?agent_id=ID</code>.</div>';
  $name   = esc_html(get_the_title($agent_id) ?: 'Assistant');
  $avatar = get_post_meta($agent_id,'am_avatar_url',true);
  $subtitle = get_post_meta($agent_id,'am_subtitle',true) ?: 'Character ready to chat';
  $complement = get_post_meta($agent_id,'am_complement',true) ?: '';
  ob_start(); ?>
  <div class="am-assistant-header-only">
    <?php if($avatar): ?><img class="assistant-avatar" src="<?php echo esc_url($avatar); ?>" alt="<?php echo $name; ?>"><?php endif; ?>
    <div>
      <h2 class="assistant-name"><?php echo $name; ?></h2>
      <p class="assistant-description"><?php echo esc_html($subtitle); ?></p>
      <?php if($complement): ?><p class="assistant-complement"><?php echo esc_html($complement); ?></p><?php endif; ?>
    </div>
  </div>
  <?php return ob_get_clean();
}
add_shortcode('am_assistant_header','am_render_assistant_header');
add_shortcode('am-assistant-header','am_render_assistant_header'); // alias

add_shortcode('am_chat', function(){
  $agent_id = isset($_GET['agent_id']) ? (int)$_GET['agent_id'] : 0;
  $conv_uid = isset($_GET['cid']) ? sanitize_text_field($_GET['cid']) : '';
  if(!$agent_id) return '<div class="error">Missing URL param <code>?agent_id=ID</code>.</div>';

  $name     = get_the_title($agent_id);
  $welcome  = get_post_meta($agent_id,'am_welcome',true) ?: 'Hi! How can I help today?';
  $voice    = get_post_meta($agent_id,'am_voice_id',true) ?: '';
  $avatar   = get_post_meta($agent_id,'am_avatar_url',true);
  $subtitle = get_post_meta($agent_id,'am_subtitle',true) ?: 'Character ready to chat';
  $complement = get_post_meta($agent_id,'am_complement',true) ?: '';
  $enable_fab = (int) get_option('am_enable_feedback_fab', 1);

  wp_enqueue_style('am-chat-css', AM_CA_PLUGIN_URL.'assets/css/am-chat.css', [], AM_CA_VERSION);
  wp_enqueue_script('am-chat-js', AM_CA_PLUGIN_URL.'assets/js/chat.js', [], AM_CA_VERSION, true);

  $rest_path  = wp_parse_url( rest_url(), PHP_URL_PATH );
  $rest_nonce = wp_create_nonce('wp_rest');
  wp_add_inline_script('am-chat-js',
    'window.AM_REST='.wp_json_encode(trailingslashit($rest_path)).
    ';window.AM_NONCE='.wp_json_encode($rest_nonce).';',
    'before'
  );

  $uid = wp_generate_uuid4();
  ob_start(); ?>
  <div id="amc-<?php echo esc_attr($uid); ?>" class="openai-chat-container"
       data-agent-id="<?php echo esc_attr($agent_id); ?>"
       data-conv-uid="<?php echo esc_attr($conv_uid); ?>"
       data-assistant-name="<?php echo esc_attr($name); ?>"
      data-voice-id="<?php echo esc_attr($voice); ?>"
      data-fb-up="<?php echo esc_url( get_option('am_fb_up_url', 'https://wa4u.ai/wp-content/uploads/2025/08/thumbs-up.svg') ); ?>"
      data-fb-up-active="<?php echo esc_url( get_option('am_fb_up_active_url', 'https://wa4u.ai/wp-content/uploads/2025/08/thumb-up-fill.svg') ); ?>"
      data-fb-down="<?php echo esc_url( get_option('am_fb_down_url', 'https://wa4u.ai/wp-content/uploads/2025/08/thumbs-down.svg') ); ?>"
      data-fb-down-active="<?php echo esc_url( get_option('am_fb_down_active_url', 'https://wa4u.ai/wp-content/uploads/2025/08/thumb-down-fill.svg') ); ?>"
       data-welcome="<?php echo esc_attr($welcome); ?>"
       data-subtitle="<?php echo esc_attr($subtitle); ?>"
       data-complement="<?php echo esc_attr($complement); ?>"
       data-feedback-fab="<?php echo $enable_fab ? '1' : '0'; ?>"
       data-avatar-url="<?php echo esc_url($avatar); ?>">

    <div class="assistant-header">
      <?php if($avatar): ?><img class="assistant-avatar" src="<?php echo esc_url($avatar); ?>" alt="<?php echo esc_attr($name); ?>"><?php endif; ?>
      <div class="assistant-meta">
        <h2 class="assistant-name"><?php echo esc_html($name); ?></h2>
        <p class="assistant-description"><?php echo esc_html($subtitle); ?></p>
        <?php if($complement): ?><p class="assistant-complement"><?php echo esc_html($complement); ?></p><?php endif; ?>
      </div>
    </div>
    <div id="openai-messages" class="openai-messages"></div>
    <form id="openai-chat-form" class="openai-chat-form" autocomplete="off" onsubmit="return false;">
      <div class="openai-input-group">
        <div class="openai-input-inner">
          <textarea id="openai-message-input" name="message" rows="1" required placeholder="Type your message…"></textarea>
          <button type="button" id="openai-voice-btn" class="voice-btn" aria-label="Start voice input"><img src="https://wa4u.ai/wp-content/uploads/2025/08/mic-on.svg" alt="Mic"></button>
          <button class="send" type="submit"><img src="https://wa4u.ai/wp-content/uploads/2025/08/send.svg" alt="Send"></button>
        </div>
        <button type="button" id="am-voice-call-btn-<?php echo esc_attr($uid); ?>" class="am-voice-call-btn"><svg width="50" height="50" viewBox="0 0 50 50" fill="none" xmlns="http://www.w3.org/2000/svg">
<rect x="0.5" y="0.5" width="49" height="49" rx="24.5" stroke="#3A354E"/>
<path d="M32.2957 28.5096L27.305 29.484C23.9339 27.7792 21.8516 25.8209 20.6397 22.7683L21.5728 17.7253L19.809 13H15.2633C13.8969 13 12.8208 14.1377 13.0249 15.4991C13.5344 18.8976 15.0366 25.0596 19.4278 29.484C24.0392 34.1303 30.6809 36.1465 34.3363 36.9479C35.7479 37.2574 37 36.1479 37 34.6924V30.3158L32.2957 28.5096Z" stroke="#3A354E" stroke-linecap="round" stroke-linejoin="round"/>
</svg>
</button>
      </div>
      <input type="hidden" name="agent_id" value="<?php echo esc_attr($agent_id); ?>">
    </form>

    <div id="am-voice-call-<?php echo esc_attr($uid); ?>" class="am-voice-call-overlay" style="display:none;">
      <?php if($avatar): ?>
        <img class="assistant-avatar" src="<?php echo esc_url($avatar); ?>" alt="<?php echo esc_attr($name); ?>">
      <?php endif; ?>
      <div class="am-voice-call-controls">
        <button type="button" class="am-voice-call-mute" aria-label="Mute Microphone">
          <svg class="am-mic-on" viewBox="0 0 24 24" width="32" height="32" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M12 1a3 3 0 0 0-3 3v7a3 3 0 0 0 6 0V4a3 3 0 0 0-3-3z"/>
            <path d="M19 10v2a7 7 0 0 1-14 0v-2"/>
            <line x1="12" y1="19" x2="12" y2="23"/>
            <line x1="8" y1="23" x2="16" y2="23"/>
          </svg>
          <svg class="am-mic-off" viewBox="0 0 24 24" width="32" height="32" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:none;">
            <line x1="1" y1="1" x2="23" y2="23"/>
            <path d="M9 9v3a3 3 0 0 0 5.12 2.19"/>
            <path d="M15 9V4a3 3 0 0 0-5.56-1.55"/>
            <path d="M17 16.95A7 7 0 0 1 5 13v-2"/>
            <line x1="12" y1="19" x2="12" y2="23"/>
            <line x1="8" y1="23" x2="16" y2="23"/>
          </svg>
        </button>
        <button type="button" class="am-voice-call-end" aria-label="End Call">
          <svg viewBox="0 0 24 24" width="32" height="32" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M3 5h2a2 2 0 011.8 1.1l1.7 3.4a2 2 0 01-.5 2.4l-1.2 1.2a16 16 0 007.2 7.2l1.2-1.2a2 2 0 012.4-.5l3.4 1.7A2 2 0 0119 21h-2c-7.7 0-14-6.3-14-14V5z"/>
            <line x1="15" y1="7" x2="21" y2="13" />
            <line x1="21" y1="7" x2="15" y2="13" />
          </svg>
        </button>
      </div>
    </div>

    <script>
    (function(){
      const btnId   = 'am-voice-call-btn-<?php echo esc_attr($uid); ?>';
      const ovlId   = 'am-voice-call-<?php echo esc_attr($uid); ?>';
      const agentId = <?php echo (int) $agent_id; ?>;
      const voiceId = <?php echo wp_json_encode( $voice ); ?>;
      const REST  = (window.AM_REST  || <?php echo wp_json_encode(trailingslashit($rest_path)); ?>);
      const NONCE = (window.AM_NONCE || <?php echo wp_json_encode($rest_nonce); ?>);

      const btn     = document.getElementById(btnId);
      const overlay = document.getElementById(ovlId);
      const endBtn  = overlay.querySelector('.am-voice-call-end');
      const muteBtn = overlay.querySelector('.am-voice-call-mute');

      let convUid = new URLSearchParams(location.search).get('cid') || '';
      let busy = false;
      let mediaRecorder = null;
      let currentAudio = null;

      let micCtx = null;
      let micAnalyser = null;
      let micData = null;
      let micStream = null;
      let micVizInterval = null;
      let micMuted = false;
      const avatarImg = overlay.querySelector('.assistant-avatar');

      async function initMicMonitor(){
        try {
          const stream = await navigator.mediaDevices.getUserMedia({ audio: { echoCancellation:true, noiseSuppression:true, autoGainControl:true } });
          micStream = stream;
          const AudioCtx = window.AudioContext || window.webkitAudioContext;
          micCtx = new AudioCtx();
          const source = micCtx.createMediaStreamSource(stream);
          micAnalyser = micCtx.createAnalyser();
          micAnalyser.fftSize = 2048;
          source.connect(micAnalyser);
          micData = new Uint8Array(micAnalyser.fftSize);
        } catch(err){
          console.warn('Mic monitor init failed', err);
        }
      }
      function micLevel(){
        if (!micAnalyser) return 0;
        micAnalyser.getByteTimeDomainData(micData);
        let sum = 0;
        for (let i=0;i<micData.length;i++){
          const v = (micData[i]-128)/128;
          sum += v*v;
        }
        return Math.sqrt(sum/micData.length);
      }
      function startMicViz(){
        if (!micAnalyser || !avatarImg) return;
        if (micVizInterval) clearInterval(micVizInterval);
        micVizInterval = setInterval(()=>{
          const lvl = micLevel();
          const scale = 1 + Math.min(0.3, lvl*2);
          avatarImg.style.transform = `scale(${scale.toFixed(2)})`;
        },100);
      }

      function setState(s){ /* no visible state */ }
      function sanitizeReply(text) {
        let out = String(text || '').replace(/[&<>]/g, (c) => ({ '&':'&amp;','<':'&lt;','>':'&gt;' }[c]));
        out = out.replace(/&lt;(\/?(?:ul|ol|li|br|strong))&gt;/gi, '<$1>');
        out = out.replace(/(^|\n)\s*(?:[-*•]\s.+)(?:\n\s*[-*•]\s.+)*/g, (m) => {
          const items = m.trim().split(/\n/).map(line => line.replace(/^\s*[-*•]\s+/, ''));
          return '<ul><li>' + items.join('</li><li>') + '</li></ul>';
        });
        out = out.replace(/(^|\n)\s*(?:\d+\.\s.+)(?:\n\s*\d+\.\s.+)*/g, (m) => {
          const items = m.trim().split(/\n/).map(line => line.replace(/^\s*\d+\.\s+/, ''));
          return '<ol><li>' + items.join('</li><li>') + '</li></ol>';
        });
        out = out.replace(/\n/g, '<br>');
        return out;
      }
      function logMessage(role, text){
        if (role !== 'user' && role !== 'ai') return;
        const container = document.querySelector('.openai-chat-container .openai-messages') || document.querySelector('.openai-messages');
        if (!container) return;
        const wrap = document.createElement('div');
        wrap.className = 'openai-bubble ' + (role === 'user' ? 'user' : 'ai');
        const avatar = document.createElement('div');
        avatar.className = 'avatar';
        avatar.textContent = role === 'user' ? 'Me' : (document.querySelector('.openai-chat-container')?.dataset?.assistantName || 'Assistant');
        const bubble = document.createElement('div');
        bubble.className = 'bubble';
        bubble.innerHTML = text;
        wrap.appendChild(avatar);
        wrap.appendChild(bubble);
        container.appendChild(wrap);
        const root = document.querySelector('.openai-chat-container');
        if (root && root.AM_scrollToBottom) root.AM_scrollToBottom(true);
      }

      async function ttsPlay(text){
        if (micVizInterval){ clearInterval(micVizInterval); micVizInterval=null; }
        const r = await fetch(REST + 'am/v1/tts', {
          method: 'POST',
          headers: { 'Content-Type':'application/json','X-WP-Nonce': NONCE },
          body: JSON.stringify({ text, voice_id: voiceId, agent_id: agentId })
        });
        const blob = await r.blob();
        if (!blob || !blob.size) return;
        const url = URL.createObjectURL(blob);
        const audio = new Audio(url);
        currentAudio = audio;

        if (!micAnalyser) await initMicMonitor();
        let micInterval;
        if (micAnalyser) {
          let over = 0;
          micInterval = setInterval(()=>{
            const lvl = micLevel();
            if (lvl > 0.2) {
              over++;
              if (over >= 8 && !audio.paused) {
                audio.pause();
                audio.currentTime = 0;
                currentAudio = null;
                clearInterval(micInterval);
                setState('Listening');
              }
            } else {
              over = 0;
            }
            if (audio.paused) clearInterval(micInterval);
          }, 100);
        }

        audio.addEventListener('ended', () => {
          URL.revokeObjectURL(url);
          if (micInterval) clearInterval(micInterval);
          if (overlay.style.display === 'block') startMicViz();
          currentAudio = null;
        });

        await audio.play().catch(()=>{ /* autoplay blocked */ });
      }

      async function askAssistant(text){
        if (busy) return;
        busy = true;
        setState('Thinking...');
        logMessage('user', text);

        try {
          const r = await fetch(REST + 'am/v1/chat', {
            method:'POST',
            headers:{ 'Content-Type':'application/json','X-WP-Nonce': NONCE },
            body: JSON.stringify({ agent_id: agentId, message: text, conversation_uid: convUid })
          });
          const data = await r.json();
          if (data && data.conversation_uid) {
            convUid = data.conversation_uid;
            window.dispatchEvent(new CustomEvent('am:conversation-updated', {
              detail: { cid: convUid, agentId, title: text.slice(0,60), avatarUrl: '' }
            }));
            const url = new URL(location.href);
            url.searchParams.set('agent_id', String(agentId));
            url.searchParams.set('cid', String(convUid));
            history.replaceState({}, '', url.toString());
          }
          const reply = sanitizeReply(String((data && data.reply) || '...'));
          logMessage('ai', reply);
          if (window.AM_AUTO_AUDIO) {
            setState('Speaking...');
            await ttsPlay(reply);
            setState('Listening');
          } else {
            setState('Idle');
          }
        } catch (err) {
          logMessage('system', err && err.message ? err.message : 'Unknown');
          setState('Idle');
        }
        busy = false;
      }

      async function startRecognition(){
        if (!navigator.mediaDevices || !window.MediaRecorder) {
          logMessage('system', 'MediaRecorder not supported in this browser.');
          return;
        }
        try {
          const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
          if (!micAnalyser) await initMicMonitor();
          startMicViz();
          mediaRecorder = new MediaRecorder(stream);
          const lang = document.querySelector('.openai-chat-container')?.dataset?.sttLang || 'en-US';
          mediaRecorder.ondataavailable = async (e) => {
            if (!e.data || e.data.size === 0) return;
            const fd = new FormData();
            fd.append('file', e.data, 'chunk.webm');
            fd.append('language', lang);
            try {
              const r = await fetch(REST + 'am/v1/stt', {
                method: 'POST',
                body: fd,
                headers: { 'X-WP-Nonce': NONCE }
              });
              const j = await r.json();
              if (j && j.text) {
                setState('Processing');
                askAssistant(j.text.trim());
              }
            } catch(err){
              logMessage('system', 'STT error');
            }
          };
          mediaRecorder.start(4000);
          setState('Listening');
        } catch(_) {
          logMessage('system', 'Could not start STT.');
        }
      }

      btn.addEventListener('click', function(){
        overlay.style.display = 'block';
        btn.style.display = 'none';
        setState('Initializing…');
        startRecognition();
      });

      muteBtn.addEventListener('click', function(){
        micMuted = !micMuted;
        const onIcon = muteBtn.querySelector('.am-mic-on');
        const offIcon = muteBtn.querySelector('.am-mic-off');
        if (onIcon && offIcon){
          onIcon.style.display = micMuted ? 'none' : 'block';
          offIcon.style.display = micMuted ? 'block' : 'none';
        }
        muteBtn.classList.toggle('muted', micMuted);
        muteBtn.setAttribute('aria-label', micMuted ? 'Unmute Microphone' : 'Mute Microphone');
        if (mediaRecorder && mediaRecorder.state === 'recording' && micMuted){
          try { mediaRecorder.pause(); } catch(_) {}
        } else if (mediaRecorder && mediaRecorder.state === 'paused' && !micMuted){
          try { mediaRecorder.resume(); } catch(_) {}
        }
        if (mediaRecorder && mediaRecorder.stream){
          mediaRecorder.stream.getTracks().forEach(t=> t.enabled = !micMuted);
        }
        if (micStream){ micStream.getTracks().forEach(t=> t.enabled = !micMuted); }
        if (micMuted){
          if (micVizInterval){ clearInterval(micVizInterval); micVizInterval=null; }
          if (avatarImg) avatarImg.style.transform='scale(1)';
        } else {
          startMicViz();
        }
      });

      endBtn.addEventListener('click', function(){
        overlay.style.display = 'none';
        btn.style.display = 'inline-block';
        setState('Idle');
        try { mediaRecorder && mediaRecorder.stop(); } catch(_) {}
        if (micVizInterval){ clearInterval(micVizInterval); micVizInterval=null; }
        if (avatarImg) avatarImg.style.transform='scale(1)';
        if (micStream){ micStream.getTracks().forEach(t=>t.stop()); micStream=null; }
        if (mediaRecorder && mediaRecorder.stream){ mediaRecorder.stream.getTracks().forEach(t=> t.stop()); }
        mediaRecorder = null;
        if (micCtx){ try{ micCtx.close(); } catch(_){} micCtx=null; micAnalyser=null; }
        if (currentAudio) { currentAudio.pause(); currentAudio.currentTime = 0; currentAudio = null; }
        micMuted = false;
        if (muteBtn){
          muteBtn.classList.remove('muted');
          muteBtn.setAttribute('aria-label','Mute Microphone');
          const onIcon = muteBtn.querySelector('.am-mic-on');
          const offIcon = muteBtn.querySelector('.am-mic-off');
          if (onIcon && offIcon){ onIcon.style.display='block'; offIcon.style.display='none'; }
        }
      });
    })();
    </script>
    <style>
      .am-voice-call-overlay {
        position: fixed; top:0; left:0; width:100vw; height:100vh; background:rgba(0,0,0,.85);
        z-index:99999; display:flex; flex-direction:column; align-items:center; justify-content:center; gap:32px;
        color:#fff; text-align:center;
      }
      .am-voice-call-overlay .assistant-avatar {
        width:160px; height:160px; border-radius:50%; transition:transform .2s;
      }
      .am-voice-call-btn { padding:12px 24px; font-size:18px; border-radius:8px; color:#fff; border:none; cursor:pointer; }
      .am-voice-call-controls { display:flex; gap:16px; }
      .am-voice-call-mute { width:64px; height:64px; border-radius:50%; background:#555; border:none; display:flex; align-items:center; justify-content:center; cursor:pointer; }
      .am-voice-call-mute svg { stroke:#fff; }
      .am-voice-call-mute.muted { background:#777; }
      .am-voice-call-end { width:64px; height:64px; border-radius:50%; background:#d32f2f; border:none; display:flex; align-items:center; justify-content:center; cursor:pointer; }
      .am-voice-call-end svg { stroke:#fff; }
    </style>
  </div>
  <?php return ob_get_clean();
});

// Conversations list shortcode: [am_conversations]
function am_render_conversations_shortcode(){
  if(!is_user_logged_in()) return '<p>You must be logged in to view your conversations.</p>';

  global $wpdb;
  $conv_table = AM_DB_CONVERSATIONS;
  $user_id = get_current_user_id();

  // Fetch agents visited (unique)
  $agents = $wpdb->get_results($wpdb->prepare("
    SELECT DISTINCT c.agent_id, p.post_title AS agent_name, pm.meta_value AS avatar_url
    FROM $conv_table c
    LEFT JOIN {$wpdb->posts} p ON p.ID = c.agent_id
    LEFT JOIN {$wpdb->postmeta} pm ON pm.post_id = c.agent_id AND pm.meta_key = 'am_avatar_url'
    WHERE c.user_id = %d
    ORDER BY p.post_title ASC
  ", $user_id), ARRAY_A);

  // Fetch conversations grouped by last modified date
  $conversations = $wpdb->get_results($wpdb->prepare("
    SELECT c.public_id, c.agent_id, c.title, c.updated_at, p.post_title AS agent_name, pm.meta_value AS avatar_url
    FROM $conv_table c
    LEFT JOIN {$wpdb->posts} p ON p.ID = c.agent_id
    LEFT JOIN {$wpdb->postmeta} pm ON pm.post_id = c.agent_id AND pm.meta_key = 'am_avatar_url'
    WHERE c.user_id = %d
    ORDER BY c.updated_at DESC
  ", $user_id), ARRAY_A);

  // Group conversations by date
  $grouped_conversations = [];
  foreach ($conversations as $conv) {
    $date_key = date('Y-m-d', strtotime($conv['updated_at']));
    $grouped_conversations[$date_key][] = $conv;
  }


      wp_enqueue_script('am-conv-js', AM_CA_PLUGIN_URL.'assets/js/conversations.js', [], AM_CA_VERSION, true);
      $rest_path  = trailingslashit( wp_parse_url( rest_url(), PHP_URL_PATH ) );
        $rest_nonce = wp_create_nonce('wp_rest');

      wp_add_inline_script(
        'am-conv-js',
        'window.AM_REST='.wp_json_encode(trailingslashit($rest_path)).';window.AM_NONCE='.wp_json_encode($rest_nonce).';',
        'before'
      );

  ob_start(); ?>
<div class="am-assistant-chats-container">

    <!-- Agents visited -->
    <ul class="am-agent-list" style="margin-bottom: 30px;">
      <?php foreach ($agents as $agent): ?>
        <li class="am-agent-item">
          <a href="<?php echo esc_url(add_query_arg('agent_id', $agent['agent_id'], am_find_chat_page_url())); ?>">
            <?php if (!empty($agent['avatar_url'])): ?>
              <img class="am-agent-avatar" src="<?php echo esc_url($agent['avatar_url']); ?>" alt="<?php echo esc_attr($agent['agent_name']); ?>" />
            <?php endif; ?>
            <span class="am-agent-name"><?php echo esc_html($agent['agent_name']); ?></span>
          </a>
        </li>
      <?php endforeach; ?>
    </ul>

    <!-- Conversations grouped by date -->
    <?php if (empty($grouped_conversations)): ?>
      <p>No conversations yet.</p>
    <?php else: ?>
      <?php foreach ($grouped_conversations as $date => $convs): ?>
        <h5><?php echo esc_html(am_format_date_group($date)); ?></h5>
        <ul class="am-chat-list">
          <?php foreach ($convs as $conv): ?>
            <li class="am-chat-item" data-conv-uid="<?php echo esc_attr($conv['public_id']); ?>" data-agent-id="<?php echo esc_attr($conv['agent_id']); ?>">
              <?php if (!empty($conv['avatar_url'])): ?>
                <img class="am-chat-avatar" src="<?php echo esc_url($conv['avatar_url']); ?>" alt="<?php echo esc_attr($conv['agent_name']); ?>" />
              <?php endif; ?>
              <span class="am-chat-name">
                <a href="<?php echo esc_url(add_query_arg(['agent_id' => $conv['agent_id'], 'cid' => $conv['public_id']], am_find_chat_page_url())); ?>">
                  <?php echo esc_html($conv['title'] ?: 'Untitled Conversation'); ?>
                </a>
              </span>
              <div class="am-chat-menu-container">
                <button type="button" class="am-chat-menu-btn" aria-label="Open menu">⋮</button>
                <div class="am-chat-menu">
                  <button type="button" class="am-rename-btn" aria-label="Rename chat">Rename</button>
                  <button type="button" class="am-delete-btn" aria-label="Delete chat">Delete</button>
                </div>
              </div>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
  <style>
    .am-agent-list, .am-chat-list { list-style: none; padding: 0; margin: 0; }
    .am-agent-item, .am-chat-item { display: flex; align-items: center; margin-bottom: 10px; }
    .am-agent-item a { display: flex; align-items: center; text-decoration: none; color: inherit; width: 100%; }
    .am-agent-avatar, .am-chat-avatar { width: 40px; height: 40px; border-radius: 50%; object-fit: cover; margin-right: 10px; }
    .am-agent-name, .am-chat-name { font-weight: bold; margin-right: auto; }
    .am-chat-menu-container { position: relative; }
    .am-chat-menu-btn { background: none; border: none; cursor: pointer; font-size: 16px; }
    .am-chat-menu { display: none; position: absolute; top: 100%; right: 0; background: #fff; border: 1px solid #ddd; box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1); z-index: 10; }
    .am-chat-menu.open { display: block; }
    .am-chat-menu button { display: block; width: 100%; padding: 5px 10px; text-align: left; background: none; border: none; cursor: pointer; }
    .am-chat-menu button:hover { background: #f0f0f0; }
  </style>
  <?php
  return ob_get_clean();
}
add_shortcode('am_conversations', 'am_render_conversations_shortcode');

// Helper function to format date groups
function am_format_date_group($date) {
  $today = date('Y-m-d');
  $yesterday = date('Y-m-d', strtotime('-1 day'));
  if ($date === $today) return 'Today';
  if ($date === $yesterday) return 'Yesterday';
  $days_ago = (strtotime($today) - strtotime($date)) / 86400;
  if ($days_ago <= 7) return $days_ago . ' days ago';
  return date('F j, Y', strtotime($date));
}

// Shortcode to toggle automatic audio playback of chat responses
function am_audio_toggle_button(){
  ob_start(); ?>
  <button type="button" id="am-audio-toggle" class="am-audio-toggle" aria-label="Desactivar audio"></button>
  <div id="am-audio-banner" >Voice mode activated</div>
  <script>
  (function(){
    const btn = document.getElementById('am-audio-toggle');
    const banner = document.getElementById('am-audio-banner');
    window.AM_AUTO_AUDIO = (typeof window.AM_AUTO_AUDIO === 'boolean') ? window.AM_AUTO_AUDIO : true;
    const ICON_ON = `<img src="https://wa4u.ai/wp-content/uploads/2025/08/VOLUMEN-ON-1.svg"  alt="audio on">`;
    const ICON_OFF = `<img src="https://wa4u.ai/wp-content/uploads/2025/08/VOLUMEN-OFF-1.svg"  alt="audio off">`;
    function updateBtn(){
      btn.innerHTML = window.AM_AUTO_AUDIO ? ICON_ON : ICON_OFF;
      btn.setAttribute('aria-label', window.AM_AUTO_AUDIO ? 'Desactivar audio' : 'Activar audio');
    }
    btn.addEventListener('click', () => {
      window.AM_AUTO_AUDIO = !window.AM_AUTO_AUDIO;
      updateBtn();
      if(window.AM_AUTO_AUDIO){
        banner.textContent = 'Voice mode activated';
        banner.style.display = 'block';
        setTimeout(()=>{ banner.style.display = 'none'; }, 2000);
      }
    });
    updateBtn();
  })();
  </script>
  <?php
  return ob_get_clean();
}
add_shortcode('am_audio_toggle','am_audio_toggle_button');
add_shortcode('am-audio-toggle','am_audio_toggle_button');