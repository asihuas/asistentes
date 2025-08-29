<?php
if (!defined('ABSPATH')) exit;

/** Model defaults */
if (!defined('AM_OPENAI_MODEL'))               define('AM_OPENAI_MODEL', 'gpt-5-chat-latest');
if (!defined('AM_OPENAI_DEFAULT_TEMPERATURE')) define('AM_OPENAI_DEFAULT_TEMPERATURE', 0.7);
if (!defined('AM_ELEVENLABS_DEFAULT_VOICE_ID'))define('AM_ELEVENLABS_DEFAULT_VOICE_ID','21m00Tcm4TlvDq8ikWAM');

/** Table names — mantenemos pgn_ para compatibilidad con tus datos */
if (!defined('AM_DB_CONVERSATIONS')) define('AM_DB_CONVERSATIONS', 'pgn_am_conversations');
if (!defined('AM_DB_MESSAGES'))      define('AM_DB_MESSAGES',      'pgn_am_messages');
if (!defined('AM_DB_EVENTS'))        define('AM_DB_EVENTS',        'pgn_am_events');   // para feedback/analytics
if (!defined('AM_DB_FEEDBACK'))      define('AM_DB_FEEDBACK',      'pgn_am_feedback');
