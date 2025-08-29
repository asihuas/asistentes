(function () {
  // Evita doble init si el script se carga dos veces
  if (window.__AM_CHAT_INIT__) return;
  window.__AM_CHAT_INIT__ = true;
  if (typeof window.AM_AUTO_AUDIO !== 'boolean') window.AM_AUTO_AUDIO = true;

  document.querySelectorAll('.openai-chat-container').forEach((root) => {
    if (root.__AM_INIT__) return; // guard por instancia
    root.__AM_INIT__ = true;

    // --- Elementos dentro del contenedor (scopeados) ---
    // El SCROLLER ahora es el propio .openai-chat-container (root)
    const scrollerEl = root; // <- contenedor que detectamos para auto-scroll
    // Donde se agregan los mensajes; si no hay .openai-messages, usamos root
    const messagesEl = root.querySelector('.openai-messages') || root;

    // Si tu HTML usa IDs para form/input/etc, mantenemos búsqueda scopeada:
    const form    = root.querySelector('#openai-chat-form');
    const input   = root.querySelector('#openai-message-input');
    const voiceBtn= root.querySelector('#openai-voice-btn');
    if (voiceBtn) voiceBtn.setAttribute('type', 'button');

    const agentId       = parseInt(root.dataset.agentId || '0', 10);
    let convUid         = String(root.dataset.convUid || '');
    const assistantName = root.dataset.assistantName || 'Assistant';
    const voiceId       = (root.dataset.voiceId || '').trim();
    const welcome       = root.dataset.welcome || 'Hi!';
    const showFab       = root.dataset.feedbackFab === '1';
    const avatarUrl     = root.dataset.avatarUrl || '';
    const scrollIcon    = root.dataset.scrollIcon || 'https://wa4u.ai/wp-content/uploads/2025/08/nav-arrow-down.svg';

    // -----------------------
    // AUTO-SCROLL + BOTÓN FIXED
    // -----------------------
    (function setupAutoScroll() {
      if (!scrollerEl) return;

      const EPS = 64; // margen px para considerar "cerca del fondo"
      let userLocked = false;

      function isNearBottom() {
        const remaining = scrollerEl.scrollHeight - scrollerEl.clientHeight - scrollerEl.scrollTop;
        return remaining <= EPS;
      }

      function scrollToBottom(smooth = true) {
        scrollerEl.scrollTo({
          top: scrollerEl.scrollHeight,
          behavior: smooth ? 'smooth' : 'auto',
        });
      }

      // Botón flotante FIXED (uno por contenedor)
      const goEndBtn = document.createElement('button');
      goEndBtn.type = 'button';
      goEndBtn.className = 'am-scroll-bottom';
      goEndBtn.setAttribute('aria-label', 'Ir al final');
      Object.assign(goEndBtn.style, {
        position: 'fixed',
        left: '50%',
        transform: 'translatex(-50%)',
        bottom: '80px',
        display: 'none',
        width: '40px',
        height: '40px',
        borderRadius: '20px',
        border: '1px solid rgba(0,0,0,.1)',
        boxShadow: '0 6px 18px rgba(0,0,0,.18)',
        background: '#fff',
        cursor: 'pointer',
        zIndex: '9999',
        padding: '0'
      });

      const iconImg = document.createElement('img');
      iconImg.src = scrollIcon;
      iconImg.alt = 'Ir al final';
      iconImg.width = 20;
      iconImg.height = 20;
      Object.assign(iconImg.style, {
        pointerEvents: 'none',
        display: 'block',
        margin: '0 auto',
        position: 'relative',
        top: '10px'
      });
      goEndBtn.appendChild(iconImg);

      // Para diferenciar si hay múltiples instancias
      goEndBtn.dataset.cid = convUid || Math.random().toString(36).slice(2);
      document.body.appendChild(goEndBtn);

      goEndBtn.addEventListener('click', () => {
        userLocked = false;
        scrollToBottom(true);
        updateState();
      });

      function updateState() {
        userLocked = !isNearBottom();
        goEndBtn.style.display = userLocked ? 'block' : 'none';
      }

      scrollerEl.addEventListener('scroll', updateState, { passive: true });

      // Observar inserción de nodos en el contenedor de mensajes
      const mo = new MutationObserver((mutations) => {
        let added = false;
        for (const m of mutations) {
          if (m.addedNodes && m.addedNodes.length) { added = true; break; }
        }
        if (!added) return;

        if (!userLocked) {
          scrollToBottom(true);
        } else {
          goEndBtn.style.display = 'grid';
        }
      });
      mo.observe(messagesEl, { childList: true, subtree: true });

      // Helpers públicos por instancia
      root.AM_isNearBottom   = isNearBottom;
      root.AM_scrollToBottom = scrollToBottom;
      root.AM_userLocked     = () => userLocked;
      root.AM_scrollBtn      = goEndBtn;

      // Estado inicial
      updateState();
      // Ajuste inicial de posición
      scrollToBottom(false);
    })();

    // -----------------------
    // Bienvenida (después de montar auto-scroll)
    // -----------------------
    appendBubble('ai', escapeHtml(welcome), true);

    // -----------------------
    // STT using Whisper via MediaRecorder
    // -----------------------
    const sendBtn = root.querySelector('.send');
    const callBtn = root.querySelector('.am-voice-call-btn');
    const inputInner = root.querySelector('.openai-input-inner');
    const transcribingImg = root.dataset.transcribingImg || '';
    let sttOverlay = null;
    if (inputInner) {
      inputInner.style.position = 'relative';
      sttOverlay = document.createElement('div');
      sttOverlay.className = 'am-stt-loading';
      sttOverlay.style.display = 'none';
      inputInner.appendChild(sttOverlay);
    }

    if (voiceBtn && navigator.mediaDevices) {
      let mediaRecorder = null;
      let chunks = [];
      let isRecording = false;
      let micStream = null;

      function updateVoiceBtn(state) {
        voiceBtn.classList.toggle('listening', state === 'listening');
        voiceBtn.innerHTML = state === 'listening'
          ? '<img src="https://wa4u.ai/wp-content/uploads/2025/08/mic-off.svg" alt="Stop" width="20" height="20">'
          : '<img src="https://wa4u.ai/wp-content/uploads/2025/08/mic-on.svg" alt="Mic" width="20" height="20">';
        if (sendBtn) sendBtn.style.display = state === 'listening' ? 'none' : '';
        if (callBtn) callBtn.style.display = state === 'listening' ? 'none' : '';
      }

      async function startRecording() {
        if (isRecording) return;
        chunks = [];
        micStream = await navigator.mediaDevices.getUserMedia({ audio: true });
        mediaRecorder = new MediaRecorder(micStream, { mimeType: 'audio/webm' });
        mediaRecorder.ondataavailable = (e) => { if (e.data.size > 0) chunks.push(e.data); };
        mediaRecorder.onstop = async () => {
          if (micStream) {
            micStream.getTracks().forEach((t) => t.stop());
            micStream = null;
          }
          if (sttOverlay) {
            sttOverlay.innerHTML = transcribingImg
              ? `<img src="${transcribingImg}" alt="Transcribing...">`
              : 'Transcribing...';
            sttOverlay.style.display = 'flex';
          }
          if (voiceBtn) voiceBtn.style.display = 'none';
          if (sendBtn) sendBtn.style.display = 'none';
          if (callBtn) callBtn.style.display = 'none';
          const file = new File(chunks, 'audio.webm', { type: 'audio/webm' });
          const fd = new FormData();
          fd.append('file', file);
          const rawLang = root.dataset.sttLang || '';
          if (rawLang) {
            fd.append('language', rawLang.split('-')[0].toLowerCase());
          }
          try {
            const r = await fetch(window.AM_REST + 'am/v1/stt', {
              method: 'POST',
              body: fd,
              headers: { 'X-WP-Nonce': window.AM_NONCE },
              credentials: 'same-origin'
            });
            if (!r.ok) {
              const txt = await r.text();
              console.error('STT HTTP error', r.status, txt);
            } else {
              const j = await r.json();
              if (j && j.text) {
                const text = (j.text || '').trim();
                const letters = (text.match(/[\w\u00C0-\u017F]/g) || []).length;
                if (text && letters >= 4 && letters / text.length >= 0.5) {
                  const pre = input.value ? input.value + ' ' : '';
                  input.value = pre + text;
                  autosize();
                }
              }
            }
          } catch (err) {
            console.error('STT error:', err);
          }
          if (sttOverlay) sttOverlay.style.display = 'none';
          if (voiceBtn) voiceBtn.style.display = '';
          if (sendBtn) sendBtn.style.display = '';
          updateVoiceBtn('idle');
          // callBtn remains hidden until user sends a new message
          if (callBtn) callBtn.style.display = 'none';
          mediaRecorder = null;
        };
        mediaRecorder.start();
        isRecording = true;
        updateVoiceBtn('listening');
        if (sttOverlay) {
          sttOverlay.innerHTML = 'Listening...';
          sttOverlay.style.display = 'flex';
        }
      }

      function stopRecording() {
        if (!isRecording || !mediaRecorder) return;
        isRecording = false;
        mediaRecorder.stop();
      }

      voiceBtn.addEventListener('click', (e) => {
        e.preventDefault();
        if (!isRecording) startRecording();
        else stopRecording();
      });
    } else if (voiceBtn) {
      voiceBtn.style.display = 'none';
    }

    // -----------------------
    // Historial (con limpieza)
    // -----------------------
    if (convUid) {
      fetch(window.AM_REST + 'am/v1/history?conversation_uid=' + encodeURIComponent(convUid), {
        credentials: 'same-origin',
        headers: { 'X-WP-Nonce': window.AM_NONCE },
      })
      .then((r) => r.json())
      .then((j) => {
        const items = (j && j.items) ? j.items : [];
          items.forEach((it) => {
            const cleanedText = it.role === 'user'
              ? escapeHtml(String(it.content || ''))
              : sanitizeReply(String(it.content || ''));
            const wrap = appendBubble(it.role === 'user' ? 'user' : 'ai', cleanedText, it.role !== 'user');

            if (it.role !== 'user') {
              const mid = it.assistant_message_id || it.message_id || it.id;
              const row = wrap.querySelector('.am-feedback-row');
              if (row) addFeedbackButtons(row, stripHtml(cleanedText), mid);  // <- FIX: inserta dentro del row
            }
          });
      })
      .catch(() => {});
    }

    // -----------------------
    // Autosize + Enter envía
    // -----------------------
    const MAX_ROWS = 6;
    function autosize() {
      input.style.height = 'auto';
      const lh = parseFloat(getComputedStyle(input).lineHeight) || 20;
      const maxH = lh * MAX_ROWS;
      input.style.height = Math.min(input.scrollHeight, maxH) + 'px';
      input.style.overflowY = input.scrollHeight > maxH ? 'auto' : 'hidden';
    }
    input && input.addEventListener('input', autosize);
    input && autosize();
    input && input.addEventListener('keydown', (e) => {
      if (e.key === 'Enter' && !e.shiftKey) {
        e.preventDefault();
        form && form.dispatchEvent(new Event('submit'));
      }
    });

    // -----------------------
    // Enviar
    // -----------------------
    if (form && !form.__amBound) {
      form.addEventListener('submit', onSubmit);
      form.__amBound = true;
    }

    async function onSubmit(e) {
      e.preventDefault();
      const text = (input.value || '').trim();
      if (!text) return;

      if (callBtn) callBtn.style.display = '';

      appendBubble('user', escapeHtml(text));
      input.value = '';
      autosize();

      const typing = appendBubble('ai', '<div class="typing-indicator">Typing...</div>', false);

      try {
        const payload = {
          agent_id: agentId,
          message: text,
          conversation_uid: convUid || ''
        };

        console.log('Sending request:', {
            url: window.AM_REST + 'am/v1/chat',
            payload
        });

        const resp = await fetch(window.AM_REST + 'am/v1/chat', {
            method: 'POST',
            headers: { 
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-WP-Nonce': window.AM_NONCE 
            },
            credentials: 'same-origin',
            body: JSON.stringify(payload),
        });

        console.log('Response headers:', {
            contentType: resp.headers.get('content-type'),
            status: resp.status
        });

        // Log raw response for debugging
        const rawText = await resp.text();
        console.log('Raw response:', rawText);

        // Parse as JSON
        let data;
        try {
            data = JSON.parse(rawText);
        } catch (jsonError) {
            console.error('JSON Parse Error:', jsonError);
            throw new Error('Server returned invalid JSON response');
        }

        if (!resp.ok) {
          throw new Error(data?.error || `HTTP ${resp.status}`);
        }

        // Notify conversation update
        if (data && data.conversation_uid) {
          convUid = data.conversation_uid;
          
          // Update URL
          const url = new URL(window.location.href);
          url.searchParams.set('agent_id', String(agentId));
          url.searchParams.set('cid', String(convUid));
          window.history.replaceState({}, '', url.toString());
          
          // Dispatch event for history update
          window.dispatchEvent(new CustomEvent('am:conversation-updated', {
            detail: { 
              cid: convUid,
              agentId, 
              title: text.slice(0, 60),
              avatarUrl,
              assistantName 
            }
          }));
        }

        // Limpieza + typewriter
        const rawReply = String(data.reply || '');
        const replyHtml = sanitizeReply(rawReply);
        const replyText = stripHtml(replyHtml);

        const bubbleEl = typing.querySelector('.bubble');
        await typeInto(bubbleEl, escapeHtml(replyText));
        bubbleEl.innerHTML = replyHtml;

        // Chips
        const sugs = extractSuggestions(data.suggestions, rawReply);
        if (!root.__chipsShown && sugs.length) {
          root.__chipsShown = true;
          const row = document.createElement('div');
          row.className = 'am-suggestions';
          sugs.forEach((s) => {
            const b = document.createElement('button');
            b.type = 'button';
            b.className = 'am-suggestion-chip';
            b.textContent = s;
            b.addEventListener('click', () => {
              row.remove();
              input.value = s;
              autosize();
              form.dispatchEvent(new Event('submit'));
            });
            row.appendChild(b);
          });
          bubbleEl.appendChild(row);
        }

        const row = typing.querySelector('.am-feedback-row');
        if (row) addFeedbackButtons(row, replyText, data.assistant_message_id);

        // Automatically play TTS for every assistant response when enabled
        addPlayToLastAIBubble(replyText);
        const lastPlayBtn = typing.querySelector('.play-btn');
        if (lastPlayBtn && window.AM_AUTO_AUDIO) lastPlayBtn.click(); // Trigger TTS playback automatically
      } catch (err) {
        console.error('Chat error:', err);
        const b = typing.querySelector('.bubble');
        if (b) {
          b.innerHTML = `<div class="error">Error: ${escapeHtml(err.message || 'Could not get response')}</div>`;
        }
      } finally {
        if (callBtn) callBtn.style.display = '';
      }
    }

    // -----------------------
    // FAB feedback (opcional)
    // -----------------------
    if (showFab) {
      const fab = document.createElement('div');
      fab.className = 'am-fb-fab';
      fab.innerHTML = '<button class="up">👍</button><button class="down">👎</button>';
      fab.addEventListener('click', (e) => e.stopPropagation());
      document.body.appendChild(fab);
    }

    // -----------------------
    // TTS click
    // -----------------------
messagesEl.addEventListener('click', async (e) => {
  const btn = e.target.closest('.play-btn');
  if (!btn) return;

  const img = btn.querySelector('img');
  if (!btn.dataset.playIcon && img) btn.dataset.playIcon = img.src;

  // Stop playback if already playing
  if (btn.classList.contains('playing') && btn._audio) {
    try {
      btn._audio.pause();
      btn._audio.currentTime = 0;
    } catch (_) {}
    if (btn._audioUrl) {
      try { URL.revokeObjectURL(btn._audioUrl); } catch (_) {}
    }
    btn._audio = null;
    btn._audioUrl = null;
    btn.classList.remove('playing');
    btn.disabled = false;
    if (img) img.src = btn.dataset.playIcon || img.src;
    return;
  }

  if (btn.classList.contains('loading')) return;

  const text = btn.dataset.text;
  if (!text) return;

  console.log('TTS Debug - Starting playback:', {
    text: text.substring(0, 50) + '...',
    voiceId: voiceId,
    agentId: agentId
  });

  btn.disabled = true;
  btn.classList.add('loading');

  // Show loading indicator
  if (img) img.src = 'https://wa4u.ai/wp-content/uploads/2025/08/loading.svg';

  try {
    // Clean text for TTS (remove HTML entities and excess whitespace)
    const cleanText = text.replace(/&[a-zA-Z0-9#]+;/g, ' ').replace(/\s+/g, ' ').trim();
    
    if (!cleanText) {
      throw new Error('No text content to convert to speech');
    }

    // Use voice ID as-is - let the server handle any cleaning
    const payload = {
      text: cleanText,
      voice_id: voiceId || '', // Send exactly what we have
      agent_id: agentId
    };

    console.log('TTS Debug - Sending payload:', payload);
    
    const r = await fetch(window.AM_REST + 'am/v1/tts', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-WP-Nonce': window.AM_NONCE
      },
      body: JSON.stringify(payload)
    });

    console.log('TTS Debug - Response status:', r.status, r.statusText);

    if (!r.ok) {
      const errorText = await r.text();
      console.error('TTS API Error:', r.status, errorText);
      
      // Try to parse error details
      let errorMsg = `HTTP ${r.status}`;
      try {
        const errorData = JSON.parse(errorText);
        if (errorData.message) errorMsg = errorData.message;
        else if (errorData.error) errorMsg = errorData.error;
      } catch (e) {
        if (errorText.length > 0 && errorText.length < 200) {
          errorMsg = errorText;
        }
      }
      
      throw new Error(`TTS API error: ${errorMsg}`);
    }

    // Check content type
    const contentType = r.headers.get('content-type') || '';
    console.log('TTS Debug - Content-Type:', contentType);

    // Get the blob
    const blob = await r.blob();
    console.log('TTS Debug - Blob size:', blob.size, 'bytes');

    if (blob.size < 100) {
      throw new Error('Received invalid audio data (too small)');
    }

    // Create and play audio
    const audioUrl = URL.createObjectURL(blob);
    const audio = new Audio(audioUrl);
    btn._audio = audio;
    btn._audioUrl = audioUrl;

    const reset = () => {
      btn.classList.remove('playing');
      btn.classList.remove('loading');
      btn.disabled = false;
      if (img) img.src = btn.dataset.playIcon || img.src;
      if (btn._audioUrl) {
        try { URL.revokeObjectURL(btn._audioUrl); } catch (_) {}
      }
      btn._audio = null;
      btn._audioUrl = null;
      btn._reset = null;
    };
    btn._reset = reset;

    // Enhanced audio event handlers
    audio.addEventListener('loadstart', () => console.log('TTS Debug - Audio loading started'));
    audio.addEventListener('canplay', () => console.log('TTS Debug - Audio ready to play'));
    audio.addEventListener('error', (e) => {
      console.error('TTS Debug - Audio error:', {
        error: e,
        audioError: audio.error,
        networkState: audio.networkState,
        readyState: audio.readyState
      });

      reset();

      let errorMsg = 'Audio playback failed';
      if (audio.error) {
        switch(audio.error.code) {
          case MediaError.MEDIA_ERR_DECODE:
            errorMsg = 'Audio format not supported by browser';
            break;
          case MediaError.MEDIA_ERR_NETWORK:
            errorMsg = 'Network error during audio loading';
            break;
          case MediaError.MEDIA_ERR_SRC_NOT_SUPPORTED:
            errorMsg = 'Audio source not supported';
            break;
        }
      }
      alert(`Could not play audio: ${errorMsg}`);
    });

    audio.addEventListener('ended', () => {
      console.log('TTS Debug - Audio playback ended');
      reset();
    });

    console.log('TTS Debug - Starting audio playback...');
    await audio.play();
    console.log('TTS Debug - Audio playback started successfully');

    btn.classList.remove('loading');
    btn.classList.add('playing');
    btn.disabled = false;
    if (img) img.src = 'https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/icons/stop-circle.svg';
    
  } catch (err) {
    console.error('TTS Debug - Error:', err);
    if (typeof btn._reset === 'function') {
      btn._reset();
    } else {
      btn.classList.remove('playing');
      btn.classList.remove('loading');
      btn.disabled = false;
      if (img) img.src = btn.dataset.playIcon || img.src;
    }

    // Provide specific error messages
    let userMsg = 'Could not play audio. ';
    if (err.message.includes('API key')) {
      userMsg += 'API key not configured.';
    } else if (err.message.includes('voice')) {
      userMsg += 'Invalid voice ID.';
    } else if (err.message.includes('text')) {
      userMsg += 'No valid text content.';
    } else {
      userMsg += 'Please check console for details.';
    }

    alert(userMsg);
  }
});


    // ---- helpers ----
function unquoteSmart(s) {
  s = String(s || "").trim();
  s = s.replace(/^[“”"']+/, "").replace(/[“”"']+$/, "");
  s = s.replace(/,+\s*$/, "");  // quita coma final
  return s.trim();
}

// ✅ descarta tokens vacíos o basura ("```json", "[", "]", "```", etc.)
function isGoodChip(s) {
  s = String(s || "").trim().toLowerCase();
  if (!s) return false;
  if (s === "[" || s === "]" || s === "```" || s === "```json") return false;
  if (/^```/.test(s)) return false;
  if (/^\[.*\]$/.test(s) && !s.includes(",")) return false; // un único bloque entre []
  return s.replace(/[^\p{L}\p{N}]+/gu, "").length >= 2;      // al menos 2 letras/números
}

// ✅ si faltan, rellena hasta 3 con fallback y defaults
function ensureThree(list, fallbackSeedText) {
  const defaultsES = ['Cuéntame más', '¿Qué me recomiendas ahora?', 'Dame un siguiente paso'];
  const defaultsEN = ['Tell me more', 'What do you recommend next?', 'Give me one action'];
  const isEs = /[áéíóúñ¿¡]|(hola|cómo|estás|gracias|ayuda|cuéntame|siento|triste|ansioso|ansiedad|estrés|hambre|comida)/i.test(fallbackSeedText || "");
  const defaults = isEs ? defaultsES : defaultsEN;

  // usa tu fallbackChips primero
  const fb = fallbackChips(fallbackSeedText);
  const pool = [...list];
  for (const s of fb) {
    if (pool.length >= 3) break;
    if (!pool.includes(s)) pool.push(s);
  }
  for (const s of defaults) {
    if (pool.length >= 3) break;
    if (!pool.includes(s)) pool.push(s);
  }
  return pool.slice(0, 3);
}
function extractSuggestions(raw, replyOrRaw) {
  const uniqueClean = (xs) => [...new Set((xs || []).map((x) => unquoteSmart(x)))].filter(isGoodChip);

  const looksLikeJSONArray = (s) => {
    s = String(s || "");
    return /^\s*(?:```(?:json|javascript|js)?\s*)?\[\s*(?:"[^"\n]+"|'[^'\n]+')(?:\s*,\s*(?:"[^"\n]+"|'[^'\n]+'))*\s*\]\s*(?:```)?\s*$/is.test(s);
  };

  const stripFences = (s) =>
    String(s || "").replace(/^```(?:json|javascript|js)?\s*/i, "").replace(/```$/i, "").trim();

  const tryParseArray = (s) => {
    try {
      const txt = stripFences(String(s || ""));
      const j = JSON.parse(txt);
      return Array.isArray(j) ? j.map((v) => String(v)) : null;
    } catch (_) { return null; }
  };

  let out = [];

  const fromVal = (val) => {
    if (val == null) return;

    if (Array.isArray(val)) {
      // Caso típico roto: ['```json', '["a","b","c"]', '```']
      const parts = val.map((v) => String(v).trim());
      const jsonish = parts.find((p) => looksLikeJSONArray(p));
      if (jsonish) {
        const parsed = tryParseArray(jsonish) || tryParseArray(parts.join("\n"));
        if (parsed) { out.push(...parsed); return; }
      }
      // Si no hay array reconocible, quédate solo con strings “normales”
      out.push(...parts.filter((p) => !/^```/i.test(p)));
      return;
    }

    if (typeof val === "string") {
      if (looksLikeJSONArray(val)) {
        const parsed = tryParseArray(val);
        if (parsed) { out.push(...parsed); return; }
      }
      return; // texto suelto: no lo tratamos como lista
    }

    if (val && typeof val === "object") {
      const k = val.suggestions ?? val.chips ?? val.buttons;
      if (k != null) return fromVal(k);
    }
  };

  // 1) intenta con raw
  fromVal(raw);

  // 2) si nada, intenta leer del reply crudo (NO sanitizado)
  if (out.length === 0 && replyOrRaw) {
    const txt = String(replyOrRaw);
    const code = txt.match(/```(?:json|javascript|js)?\s*([\s\S]*?)```/i);
    if (code) {
      const parsed = tryParseArray(code[0]) || tryParseArray(code[1]);
      if (parsed) out = parsed;
    }
    if (out.length === 0) {
      const m = txt.match(/\[\s*"[^"\n]+"(?:\s*,\s*"[^"\n]+")+\s*\]/);
      if (m) {
        const parsed = tryParseArray(m[0]);
        if (parsed) out = parsed;
      }
    }
  }

  // 3) limpia y desduplica + quita comillas
  out = uniqueClean(out);

  // 4) fuerza EXACTAMENTE 3
  out = ensureThree(out, replyOrRaw);

  return out;
}




    function appendBubble(role, html, withPlay) {
      // Check if the user was near the bottom before adding the bubble
      const shouldStick = root.AM_isNearBottom ? root.AM_isNearBottom() : true;

      const wrap = document.createElement('div');
      wrap.className = 'openai-bubble ' + (role === 'user' ? 'user' : 'ai');

      const avatar = document.createElement('div');
      avatar.className = 'avatar';
      avatar.textContent = role === 'user' ? 'Me' : assistantName;

      if (role !== 'user' && withPlay) {
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'play-btn';
        btn.setAttribute('data-text', stripHtml(html));
        btn.setAttribute('aria-label', 'Play voice');
        btn.innerHTML =
          '<img src="https://wa4u.ai/wp-content/uploads/2025/08/play.svg" alt="Play" width="20" height="20">';
        avatar.appendChild(btn);
      }

      const bubble = document.createElement('div');
      bubble.className = 'bubble';
      bubble.innerHTML = html;

      wrap.appendChild(avatar);
      wrap.appendChild(bubble);

      // Add feedback buttons only for AI responses
      if (role === 'ai') {
        const feedbackRow = document.createElement('div');
        feedbackRow.className = 'am-feedback-row';
        wrap.appendChild(feedbackRow);
      }

      messagesEl.appendChild(wrap);

      if (shouldStick && root.AM_scrollToBottom) root.AM_scrollToBottom(true);
      return wrap;
    }

    function addFeedbackButtons(row, replyText, msgId) {
      if (!row || row.__fbMounted) return;
      row.__fbMounted = true;

      const upUrl = root.dataset.fbUp || 'https://wa4u.ai/wp-content/uploads/2025/08/thumbs-up.svg';
      const upActiveUrl = root.dataset.fbUpActive || upUrl;
      const downUrl = root.dataset.fbDown || 'https://wa4u.ai/wp-content/uploads/2025/08/thumbs-down.svg';
      const downActiveUrl = root.dataset.fbDownActive || downUrl;

      const up = document.createElement('button');
      up.type = 'button';
      up.className = 'am-fb am-fb-up';
      up.setAttribute('aria-pressed', 'false');
      const upImg = document.createElement('img');
      upImg.src = upUrl; upImg.alt = 'Like'; upImg.width = 20; upImg.height = 20;
      up.appendChild(upImg);

      const dn = document.createElement('button');
      dn.type = 'button';
      dn.className = 'am-fb am-fb-down';
      dn.setAttribute('aria-pressed', 'false');
      const dnImg = document.createElement('img');
      dnImg.src = downUrl; dnImg.alt = 'Dislike'; dnImg.width = 20; dnImg.height = 20;
      dn.appendChild(dnImg);

      row.appendChild(up);
      row.appendChild(dn);

      function setActive(which) {
        up.classList.toggle('active', which === 'up');
        dn.classList.toggle('active', which === 'down');
        up.setAttribute('aria-pressed', String(which === 'up'));
        dn.setAttribute('aria-pressed', String(which === 'down'));
        up.querySelector('img').src = which === 'up' ? upActiveUrl : upUrl;
        dn.querySelector('img').src = which === 'down' ? downActiveUrl : downUrl;
      }

      // Restaurar estado previo (si recargan la página)
      if (msgId) {
        const saved = localStorage.getItem(`feedback-${msgId}`);
        if (saved === 'up' || saved === 'down') setActive(saved);
      }

      async function send(val) {
        if (!convUid || !msgId) return false; // convUid viene del dataset; msgId del historial o respuesta nueva
        try {
          const payload = {
            conversation_uid: convUid,
            message_id: msgId,
            value: val,                  // 'up' | 'down'
            text: replyText || '',
            agent_id: agentId || 0
          };
          const r = await fetch(window.AM_REST + 'am/v1/feedback', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': window.AM_NONCE },
            credentials: 'same-origin',
            body: JSON.stringify(payload),
          });
          const j = await r.json();
          if (!r.ok) throw new Error(j?.error || 'Error');
          localStorage.setItem(`feedback-${msgId}`, val); // persistimos
          return true;
        } catch (err) {
          console.error('Feedback error:', err);
          return false;
        }
      }

      // Permitir alternar SIEMPRE; solo bloqueamos mientras viaja la request
      up.addEventListener('click', async () => {
        if (up.disabled || dn.disabled) return;
        up.disabled = dn.disabled = true;
        const ok = await send('up');
        if (ok) setActive('up');
        up.disabled = dn.disabled = false;
      });

      dn.addEventListener('click', async () => {
        if (up.disabled || dn.disabled) return;
        up.disabled = dn.disabled = true;
        const ok = await send('down');
        if (ok) setActive('down');
        up.disabled = dn.disabled = false;
      });
    }



    async function typeInto(el, html, opts = {}) {
      const delayMs = typeof opts.delayMs === 'number' ? opts.delayMs : 12;
      const charsPerTick = typeof opts.charsPerTick === 'number' ? opts.charsPerTick : 2;

      // ¿estabas al fondo al comenzar?
      const stick = root.AM_isNearBottom ? root.AM_isNearBottom() : true;

      const safe = String(html || '').replace(/\n/g, '<br>');
      const tokens = safe.split(/(<br>)/g);

      el.innerHTML = '';
      let textNode = document.createTextNode('');
      el.appendChild(textNode);

      let skip = false;
      const skipHandler = () => { skip = true; };
      el.addEventListener('click', skipHandler, { once: true });

      for (const t of tokens) {
        if (t === '<br>') {
          el.appendChild(document.createElement('br'));
          textNode = document.createTextNode('');
          el.appendChild(textNode);
          if (stick && root.AM_scrollToBottom) root.AM_scrollToBottom(false);
          continue;
        }
        let i = 0;
        while (i < t.length) {
          if (skip) {
            textNode.nodeValue += t.slice(i);
            i = t.length;
            break;
          }
          const next = t.slice(i, i + charsPerTick);
          textNode.nodeValue += next;
          i += next.length;

          await new Promise((r) => setTimeout(r, delayMs));
          if (stick && root.AM_scrollToBottom) root.AM_scrollToBottom(false);
        }
      }

      try { el.removeEventListener('click', skipHandler, { once: true }); } catch (_) {}
    }

    function addPlayToLastAIBubble(text) {
      const last = [...messagesEl.querySelectorAll('.openai-bubble.ai .avatar')].pop();
      if (!last) return;
      if (last.querySelector('.play-btn')) {
        last.querySelector('.play-btn').setAttribute('data-text', text);
        return;
      }
      const btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'play-btn';
      btn.setAttribute('data-text', text);
      btn.innerHTML =
        '<img src="https://wa4u.ai/wp-content/uploads/2025/08/play.svg" alt="Play" width="20" height="20">';
      last.appendChild(btn);
    }

    // ---------- Limpieza dura y chips fallback (sin JSON) ----------
    function sanitizeReply(text) {
      let out = String(text || '');

      // 1) Elimina TODOS los bloques fenceados con triple backtick
      out = out.replace(/```[\s\S]*?```/g, '');

      // 2) Elimina backticks sueltos
      out = out.replace(/`{1,}/g, '');

      // 3) Si hay un array JSON balanceado al final (residuo), quitarlo
      const tail = out.slice(-600);
      const range = findLastBalancedArrayRange(tail);
      if (range) {
        const globalStart = out.length - tail.length + range.start;
        const globalEnd   = out.length - tail.length + range.end + 1;
        if (globalEnd > out.length - 5) {
          out = (out.slice(0, globalStart) + out.slice(globalEnd)).trim();
        }
      }

      // 4) Compacta espacios
      out = out.replace(/\s{3,}/g, '  ').trim();

      // 5) Escapa HTML básico
      out = escapeHtml(out);

      // 6) Markdown sencillo -> HTML
      // Negritas **texto**
      out = out.replace(/\*\*(.+?)\*\*/g, '<strong>$1<\/strong>');

      // Listas con guiones o asteriscos o viñetas
      out = out.replace(/(^|\n)(?:[-*•]\s.+)(?:\n[-*•]\s.+)*/g, (m) => {
        const items = m.trim().split(/\n/).map(line => line.replace(/^[-*•]\s+/, ''));
        return '<ul><li>' + items.join('</li><li>') + '</li></ul>';
      });

      // Listas numeradas
      out = out.replace(/(^|\n)(?:\d+\.\s.+)(?:\n\d+\.\s.+)*/g, (m) => {
        const items = m.trim().split(/\n/).map(line => line.replace(/^\d+\.\s+/, ''));
        return '<ol><li>' + items.join('</li><li>') + '</li></ol>';
      });

      return out;
    }

    function findLastBalancedArrayRange(s) {
      let inStr = false, quote = '', esc = false, depth = 0, start = -1, last = null;
      for (let i = 0; i < s.length; i++) {
        const ch = s[i];
        if (esc) { esc = false; continue; }
        if (inStr) {
          if (ch === '\\') { esc = true; continue; }
          if (ch === quote) { inStr = false; quote = ''; }
          continue;
        }
        if (ch === '"' || ch === "'") { inStr = true; quote = ch; continue; }
        if (ch === '[') { if (depth === 0) start = i; depth++; continue; }
        if (ch === ']') { if (depth > 0) { depth--; if (depth === 0 && start !== -1) { last = { start, end: i }; } } }
      }
      return last;
    }

    function fallbackChips(reply) {
      const text = (reply || '').toLowerCase();
      const isEs =
        /[áéíóúñ¿¡]|(hola|cómo|estás|gracias|ayuda|cuéntame|siento|triste|ansioso|ansiedad|estrés|hambre|comida)/i.test(reply);

      if (isEs) {
        if (/triste|depre|solo|ansio|ansied|estres|estrés|preocup/i.test(text)) {
          return ['Quiero hablar de eso', '¿Qué puedo hacer ahora?', 'Dame un ejercicio rápido'];
        }
        if (/hambre|comer|comida|dieta/i.test(text)) {
          return ['Recomiéndame algo saludable', 'Ideas de snacks rápidos', 'Consejos para organizar comidas'];
        }
        return ['Cuéntame más', '¿Qué me recomiendas ahora?', 'Dame un siguiente paso'];
      } else {
        if (/sad|down|anxious|anxiety|stress|worried/i.test(text)) {
          return ["Let’s talk about it", 'What should I do now?', 'Give me a quick exercise'];
        }
        if (/hungry|food|eat|diet/i.test(text)) {
          return ['Suggest something healthy', 'Quick snack ideas', 'Tips to plan meals'];
        }
        return ['Tell me more', 'What do you recommend next?', 'Give me one action'];
      }
    }

    function escapeHtml(s) { return (s || '').replace(/[&<>]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;' }[c])); }
    function stripHtml(s) { const tmp = document.createElement('div'); tmp.innerHTML = s || ''; return tmp.textContent || tmp.innerText || ''; }
  });
})();