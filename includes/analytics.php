<?php
if (!defined('ABSPATH')) exit;

/**
 * Helper robusto: verifica si existe una tabla MySQL.
 * Usa INFORMATION_SCHEMA (exacto). Si el host lo bloquea, cae a SHOW TABLES LIKE.
 */
if (!function_exists('am_table_exists')) {
  function am_table_exists($table_name){
    global $wpdb;
    if (empty($table_name)) return false;

    // Intento 1: INFORMATION_SCHEMA (match exacto)
    $sql   = $wpdb->prepare(
      "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = %s",
      $table_name
    );
    $found = $wpdb->get_var($sql);
    if (is_numeric($found)) {
      return ((int)$found) > 0;
    }

    // Intento 2: SHOW TABLES LIKE (con escape de comodines)
    $like = $wpdb->esc_like($table_name);
    $sql  = $wpdb->prepare("SHOW TABLES LIKE %s", $like);
    $res  = $wpdb->get_var($sql);

    return (is_string($res) && $res === $table_name);
  }
}

/**
 * Excerpt seguro de HTML a texto plano, 200 chars por defecto.
 * Prefiere wp_html_excerpt; si no existe, carga formatting.php; si aun así no está,
 * hace fallback con mbstring y finalmente con substr.
 */
if (!function_exists('am_safe_excerpt_html')) {
  function am_safe_excerpt_html($html, $length = 200, $more = '…'){
    $text = is_string($html) ? $html : '';
    // Intentar usar wp_html_excerpt
    if (!function_exists('wp_html_excerpt')) {
      // Cargar desde core por si no está aún
      if (defined('ABSPATH')) {
        @require_once ABSPATH . WPINC . '/formatting.php';
      }
    }
    if (function_exists('wp_html_excerpt')) {
      return wp_html_excerpt($text, $length, $more);
    }

    // Fallback manual
    $plain = wp_strip_all_tags($text);
    if (function_exists('mb_substr') && function_exists('mb_strlen')) {
      $out = mb_substr($plain, 0, $length);
      return $out . (mb_strlen($plain) > $length ? $more : '');
    }
    $out = substr($plain, 0, $length);
    return $out . (strlen($plain) > $length ? $more : '');
  }
}

/**
 * ADMIN PAGE: Analytics (a prueba de fallos aunque falten tablas)
 */
if (!function_exists('am_admin_analytics_page')) {
  function am_admin_analytics_page(){
    if (!current_user_can('manage_options')) return;

    global $wpdb;

    // Nombres de tablas con fallback si las constantes no existen
    $c = defined('AM_DB_CONVERSATIONS') ? AM_DB_CONVERSATIONS : 'pgn_am_conversations';
    $m = defined('AM_DB_MESSAGES')      ? AM_DB_MESSAGES      : 'pgn_am_messages';
    $e = defined('AM_DB_EVENTS')        ? AM_DB_EVENTS        : 'pgn_am_events';

    // Comprobar existencia de tablas
    $has_c = am_table_exists($c);
    $has_m = am_table_exists($m);
    $has_e = am_table_exists($e);

    // Totales (0 si falta la tabla)
    $total_msgs = 0;
    if ($has_m) {
      $total_msgs = (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$m}`");
      if (!is_numeric($total_msgs)) $total_msgs = 0;
    }

    $fb_up = 0; $fb_down = 0;
    if ($has_e) {
      $sql_up   = $wpdb->prepare("SELECT COUNT(*) FROM `{$e}` WHERE event_type = %s", 'feedback_up');
      $sql_down = $wpdb->prepare("SELECT COUNT(*) FROM `{$e}` WHERE event_type = %s", 'feedback_down');
      $v = $wpdb->get_var($sql_up);
      $fb_up = is_null($v) ? 0 : (int)$v;
      $v = $wpdb->get_var($sql_down);
      $fb_down = is_null($v) ? 0 : (int)$v;
    }

    // Conversaciones recientes (array vacío si falta tabla)
    $recent = [];
    if ($has_c) {
      $recent = $wpdb->get_results("
        SELECT c.id, c.public_id, c.title, c.agent_id, c.user_id, c.updated_at
        FROM `{$c}` c
        ORDER BY c.updated_at DESC
        LIMIT 20
      ", ARRAY_A) ?: [];
    }

    // Mensajes de feedback (últimos 50 de cada tipo)
    $pos_msgs = $neg_msgs = [];
    if ($has_e) {
      $sql_pos = $wpdb->prepare("
        SELECT ev.id, ev.created_at, ev.event_payload
        FROM `{$e}` ev
        WHERE ev.event_type = %s
        ORDER BY ev.id DESC
        LIMIT 50
      ", 'feedback_up');
      $sql_neg = $wpdb->prepare("
        SELECT ev.id, ev.created_at, ev.event_payload
        FROM `{$e}` ev
        WHERE ev.event_type = %s
        ORDER BY ev.id DESC
        LIMIT 50
      ", 'feedback_down');

      $pos_msgs = $wpdb->get_results($sql_pos, ARRAY_A) ?: [];
      $neg_msgs = $wpdb->get_results($sql_neg, ARRAY_A) ?: [];
    }

    echo '<div class="wrap"><h1>AM Assistants — Analytics</h1>';

    // Aviso si falta alguna tabla
    if (!$has_c || !$has_m || !$has_e) {
      echo '<div class="notice notice-warning"><p>';
      echo 'Algunas tablas de analítica aún no están presentes. Activa el plugin para crearlas o inicia un chat para generar datos.';
      echo '</p></div>';
    }

    // Overview
    echo '<h2 class="title">Overview</h2>';
    echo '<ul>';
    echo '<li>Total de mensajes: <strong>' . esc_html($total_msgs) . '</strong></li>';
    echo '<li>Feedback 👍: <strong>' . esc_html($fb_up) . '</strong> &nbsp; ';
    echo 'Feedback 👎: <strong>' . esc_html($fb_down) . '</strong></li>';
    echo '</ul>';

    // Conversaciones recientes
    echo '<h2 class="title">Conversaciones recientes</h2>';
    echo '<table class="widefat striped"><thead><tr><th>Conversación</th><th>Agente</th><th>Usuario</th><th>Actualizado</th></tr></thead><tbody>';
    if (empty($recent)) {
      echo '<tr><td colspan="4">Sin conversaciones.</td></tr>';
    } else {
      foreach ($recent as $r){
        $agent = '';
        if (!empty($r['agent_id'])) {
          $agent_title = get_the_title((int)$r['agent_id']);
          $agent = $agent_title ? $agent_title : ('#' . (int)$r['agent_id']);
        } else {
          $agent = '#0';
        }

        $user_disp = 'Guest';
        if (!empty($r['user_id'])) {
          $user = get_userdata((int)$r['user_id']);
          if ($user && !is_wp_error($user)) {
            $user_disp = $user->user_login . ' (#' . (int)$user->ID . ')';
          }
        }

        $title = !empty($r['title']) ? $r['title'] : (!empty($r['public_id']) ? $r['public_id'] : ('#' . (int)$r['id']));

        echo '<tr>';
        echo '<td>' . esc_html($title) . '</td>';
        echo '<td>' . esc_html($agent) . '</td>';
        echo '<td>' . esc_html($user_disp) . '</td>';
        echo '<td>' . esc_html((string)$r['updated_at']) . '</td>';
        echo '</tr>';
      }
    }
    echo '</tbody></table>';

    // Helper para renderizar listas de feedback
    $print_fb_list = function($rows, $label){
      echo '<h2 class="title">' . esc_html($label) . '</h2>';
      echo '<table class="widefat striped"><thead><tr><th>Fecha</th><th>Mensaje</th></tr></thead><tbody>';
      if (empty($rows)) {
        echo '<tr><td colspan="2">Ninguno.</td></tr>';
      } else {
        foreach ($rows as $row){
          $payload = json_decode((string)$row['event_payload'], true);
          $msg_raw = '';
          if (is_array($payload)) {
            if (isset($payload['text'])) {
              $msg_raw = (string)$payload['text'];
            } elseif (isset($payload['message'])) {
              $msg_raw = (string)$payload['message'];
            }
          }
          $msg = am_safe_excerpt_html($msg_raw, 200, '…');
          echo '<tr><td>' . esc_html((string)$row['created_at']) . '</td><td>' . esc_html($msg) . '</td></tr>';
        }
      }
      echo '</tbody></table>';
    };

    $print_fb_list($pos_msgs, 'Mensajes con feedback positivo');
    $print_fb_list($neg_msgs, 'Mensajes con feedback negativo');

    echo '</div>';
  }
}
