<?php
if (!defined('ABSPATH')) exit;

function am_moderate_text($text){
  $enabled = (bool) get_option('am_enable_moderation', 0);
  if(!$enabled) return ['ok'=>true];
  // Aquí podrías llamar a la Moderation API si quieres; por ahora, deja pasar todo.
  return ['ok'=>true];
}
