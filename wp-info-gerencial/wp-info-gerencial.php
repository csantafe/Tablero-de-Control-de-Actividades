<?php
/**
 * Plugin Name: Información Gerencial
 * Description: Sistema PMO V17.1. Súper-Gantt interactivo con auto-cálculo de fechas y semáforo en tiempo real.
 * Version: 18.5
 * Author: Carlos Santafe
 */

if (!defined('ABSPATH')) exit;

// 1. MOTOR DE TABLAS
function ig_revisar_y_crear_tablas() {
    global $wpdb;
    $tabla_f = $wpdb->prefix . 'info_gerencial';
    $tabla_t = $wpdb->prefix . 'ig_tareas';
    $tabla_st = $wpdb->prefix . 'ig_subtareas';
    $tabla_r = $wpdb->prefix . 'ig_responsables';
    $tabla_a = $wpdb->prefix . 'ig_config_areas';
    
    $charset_collate = $wpdb->get_charset_collate();
    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

    $sql1 = "CREATE TABLE IF NOT EXISTS $tabla_f ( id mediumint(9) NOT NULL AUTO_INCREMENT, user_id bigint(20) NOT NULL, sede varchar(50) NOT NULL, grupo varchar(50) NOT NULL, fecha date NOT NULL, ingreso float NOT NULL, gasto float NOT NULL, ideas_mejora text NOT NULL, PRIMARY KEY  (id) ) $charset_collate;";
    $sql2 = "CREATE TABLE IF NOT EXISTS $tabla_t ( id mediumint(9) NOT NULL AUTO_INCREMENT, user_id bigint(20) NOT NULL, sede varchar(50) NOT NULL, grupo varchar(50) NOT NULL, tarea text NOT NULL, fecha_inicio date NOT NULL, fecha_final date NOT NULL, porcentaje int(3) DEFAULT 0, estado varchar(50) NOT NULL, origen varchar(50) NOT NULL, observacion text NOT NULL, PRIMARY KEY  (id) ) $charset_collate;";
    $sql3 = "CREATE TABLE IF NOT EXISTS $tabla_r ( id mediumint(9) NOT NULL AUTO_INCREMENT, user_id bigint(20) NOT NULL, sede varchar(50) NOT NULL, grupo varchar(50) NOT NULL, PRIMARY KEY  (id) ) $charset_collate;";
    $sql4 = "CREATE TABLE IF NOT EXISTS $tabla_a ( id mediumint(9) NOT NULL AUTO_INCREMENT, tipo varchar(20) NOT NULL, nombre varchar(100) NOT NULL, PRIMARY KEY  (id) ) $charset_collate;";
    $sql5 = "CREATE TABLE IF NOT EXISTS $tabla_st ( id mediumint(9) NOT NULL AUTO_INCREMENT, tarea_id mediumint(9) NOT NULL, user_id bigint(20) NOT NULL, subtarea text NOT NULL, fecha_inicio date NOT NULL, fecha_final date NOT NULL, porcentaje int(3) DEFAULT 0, observacion text NOT NULL, PRIMARY KEY  (id) ) $charset_collate;";
    
    dbDelta($sql1); dbDelta($sql2); dbDelta($sql3); dbDelta($sql4); dbDelta($sql5);
}
add_action('init', 'ig_revisar_y_crear_tablas');

register_activation_hook(__FILE__, 'ig_activar_plugin');
function ig_activar_plugin() {
    remove_role('gerente'); remove_role('coordinador_grupo'); remove_role('funcionario_operativo');
    add_role('gerente', 'Gerente', array('read'=>true, 'level_0'=>true, 'ver_info_gerencial'=>true, 'gestionar_areas_ig'=>true));
    add_role('coordinador_grupo', 'Coordinador', array('read'=>true, 'level_0'=>true, 'subir_info_gerencial'=>true));
    add_role('funcionario_operativo', 'Funcionario Operativo', array('read'=>true, 'level_0'=>true));
    $admin = get_role('administrator'); if($admin) $admin->add_cap('gestionar_areas_ig');
    ig_revisar_y_crear_tablas();
}

// 2. HELPERS
function ig_normalizar($t) { return str_replace(['á','é','í','ó','ú','ñ'],['a','e','i','o','u','n'], mb_strtolower(trim($t), 'UTF-8')); }
function ig_limpiar_num($v) { $v = str_replace(['$', ' ', '.', ','], ['', '', '', '.'], (string)$v); return is_numeric($v) ? floatval($v) : 0; }
function ig_formatear_fecha($f) { if(!$f) return null; if(is_numeric($f)) return gmdate("Y-m-d", ($f - 25569) * 86400); $ts = strtotime(str_replace('/','-',$f)); return $ts ? date("Y-m-d", $ts) : null; }

// 3. PÁGINA ADMINISTRATIVA (ZONA DE PELIGRO)
add_action('admin_menu', function(){ add_menu_page('Configuración PMO', 'PMO (Áreas)', 'gestionar_areas_ig', 'ig-asignaciones', 'ig_pagina_asignaciones', 'dashicons-networking', 6); });

function ig_pagina_asignaciones() {
    global $wpdb;
    $tabla_f = $wpdb->prefix . 'info_gerencial'; $tabla_t = $wpdb->prefix . 'ig_tareas';
    $tabla_st = $wpdb->prefix . 'ig_subtareas'; $tabla_r = $wpdb->prefix . 'ig_responsables'; $tabla_a = $wpdb->prefix . 'ig_config_areas';

    if (isset($_POST['ig_reset_total_db']) && check_admin_referer('ig_reset_accion', 'ig_reset_nonce')) {
        $wpdb->query("DROP TABLE IF EXISTS $tabla_f, $tabla_t, $tabla_st, $tabla_r, $tabla_a");
        ig_revisar_y_crear_tablas();
        echo '<div class="notice notice-warning is-dismissible"><p>🔥 Base de datos reseteada para producción.</p></div>';
    }

    if (isset($_POST['ig_crear_area'])) { $wpdb->insert($tabla_a, array('tipo' => sanitize_text_field($_POST['tipo_area']), 'nombre' => sanitize_text_field($_POST['nombre_area']))); }
    if (isset($_GET['borrar_area'])) { $wpdb->delete($tabla_a, array('id' => intval($_GET['borrar_area']))); }
    
    // LÓGICA DE TRASLADOS DE EQUIPO
    if (isset($_POST['ig_asignar_area'])) {
        $n_uid = intval($_POST['user_id']); $sd = sanitize_text_field($_POST['sede']); $gr = sanitize_text_field($_POST['grupo']);
        $wpdb->delete($tabla_r, array('user_id' => $n_uid)); // Lo saca de su equipo anterior
        $wpdb->insert($tabla_r, array('user_id' => $n_uid, 'sede' => $sd, 'grupo' => $gr)); // Lo mete al nuevo
    }
    
    // LÓGICA DE DESVINCULACIÓN Y ORFANDAD
    if (isset($_GET['borrar_resp'])) { 
        $id_resp = intval($_GET['borrar_resp']);
        $uid_borrado = $wpdb->get_var($wpdb->prepare("SELECT user_id FROM $tabla_r WHERE id = %d", $id_resp));
        if ($uid_borrado) {
            $wpdb->query($wpdb->prepare("UPDATE {$wpdb->prefix}ig_subtareas SET user_id = 0 WHERE user_id = %d AND porcentaje < 100", $uid_borrado));
        }
        $wpdb->delete($tabla_r, array('id' => $id_resp)); 
    }

    // INCLUSIÓN DE TODOS LOS ROLES
    $users = get_users(array('role__in' => ['coordinador_grupo', 'gerente', 'administrator', 'funcionario_operativo']));
    
    // ORDENAMIENTO POR EQUIPOS
    $asignaciones = $wpdb->get_results("SELECT * FROM $tabla_r ORDER BY sede ASC, grupo ASC");
    $sedes_db = $wpdb->get_results("SELECT id, nombre FROM $tabla_a WHERE tipo='sede' ORDER BY nombre ASC");
    $grupos_db = $wpdb->get_results("SELECT id, nombre FROM $tabla_a WHERE tipo='grupo' ORDER BY nombre ASC");
    ?>
    <div class="wrap">
        <h1>⚙️ Arquitectura PMO y Equipos de Trabajo</h1>
        <div style="background:#fff5f5; border:1px solid #feb2b2; padding:15px; border-radius:8px; margin-bottom:20px;">
            <h3 style="color:#c53030; margin-top:0;">🛑 Zona de Peligro</h3>
            <form method="post" onsubmit="return confirm('¿Borrar todo permanentemente?');">
                <?php wp_nonce_field('ig_reset_accion', 'ig_reset_nonce'); ?>
                <button type="submit" name="ig_reset_total_db" class="button button-primary" style="background:#f56565; border-color:#e53e3e;">RESET TOTAL PARA PRODUCCIÓN</button>
            </form>
        </div>
        <div style="display:flex; gap:20px;">
            <div style="background:#fff; padding:15px; border:1px solid #ccc; flex:1;">
                <h3>1. Crear Sedes y Grupos</h3>
                <form method="post" style="display:flex; gap:10px; margin-bottom:15px;">
                    <select name="tipo_area" required><option value="sede">Sede</option><option value="grupo">Grupo</option></select>
                    <input type="text" name="nombre_area" placeholder="Nombre..." required>
                    <button type="submit" name="ig_crear_area" class="button">Crear</button>
                </form>
                <div style="font-size:12px; display:flex; gap:20px;">
                    <div><strong>Sedes:</strong><br><?php foreach($sedes_db as $s) echo esc_html($s->nombre)." <a href='?page=ig-asignaciones&borrar_area={$s->id}' style='color:red;'>[x]</a><br>"; ?></div>
                    <div><strong>Grupos:</strong><br><?php foreach($grupos_db as $g) echo esc_html($g->nombre)." <a href='?page=ig-asignaciones&borrar_area={$g->id}' style='color:red;'>[x]</a><br>"; ?></div>
                </div>
            </div>
            
            <div style="background:#f0f8ff; padding:15px; border:1px solid #b0d4f1; flex:1.5;">
                <h3>2. Asignar Personal al Equipo</h3>
                <form method="post" style="display:flex; flex-direction:column; gap:10px;">
                    <select name="user_id" required>
                        <option value="">Seleccionar Funcionario...</option>
                        <?php foreach($users as $u) {
                            $r_name = 'Admin';
                            if(in_array('coordinador_grupo', (array)$u->roles)) $r_name = 'Coordinador';
                            elseif(in_array('funcionario_operativo', (array)$u->roles)) $r_name = 'Operativo';
                            elseif(in_array('gerente', (array)$u->roles)) $r_name = 'Gerente';
                            echo "<option value='{$u->ID}'>{$u->display_name} - [Rol: {$r_name}]</option>"; 
                        } ?>
                    </select>
                    <select name="sede" required><option value="">Sede...</option><?php foreach($sedes_db as $s) echo "<option value='{$s->nombre}'>{$s->nombre}</option>"; ?></select>
                    <select name="grupo" required><option value="">Grupo...</option><?php foreach($grupos_db as $g) echo "<option value='{$g->nombre}'>{$g->nombre}</option>"; ?></select>
                    <button type="submit" name="ig_asignar_area" class="button button-primary">Vincular al Equipo</button>
                </form>
            </div>
        </div>
        
        <h3 style="margin-top:30px;">Directorio de Equipos Existentes</h3>
        <table class="wp-list-table widefat fixed striped">
            <thead><tr><th>Área (Sede - Grupo)</th><th>Funcionario</th><th>Cargo / Rol</th><th>Acción</th></tr></thead>
            <tbody>
                <?php if($asignaciones): foreach($asignaciones as $a): 
                    $u = get_userdata($a->user_id); 
                    $rol_ui = 'N/A';
                    if($u) {
                        if(in_array('coordinador_grupo', (array)$u->roles)) $rol_ui = '<strong>👑 Coordinador</strong>';
                        elseif(in_array('funcionario_operativo', (array)$u->roles)) $rol_ui = '👥 Operativo';
                        elseif(in_array('gerente', (array)$u->roles)) $rol_ui = '👔 Gerente';
                        else $rol_ui = 'Administrador';
                    }
                ?>
                <tr>
                    <td><strong><?php echo esc_html($a->sede); ?></strong> &raquo; <?php echo esc_html($a->grupo); ?></td>
                    <td><?php echo $u ? $u->display_name : '<span style="color:red;">Usuario Borrado</span>'; ?></td>
                    <td><?php echo $rol_ui; ?></td>
                    <td><a href="?page=ig-asignaciones&borrar_resp=<?php echo $a->id; ?>" style="color:red;">[Desvincular]</a></td>
                </tr>
                <?php endforeach; else: echo "<tr><td colspan='4'>No hay equipos conformados.</td></tr>"; endif; ?>
            </tbody>
        </table>
    </div>
    <?php
}

function ig_recalcular_promedio_tarea($tarea_id) {
    global $wpdb;
    $tabla_t = $wpdb->prefix . 'ig_tareas'; $tabla_st = $wpdb->prefix . 'ig_subtareas';
    $promedio = $wpdb->get_var($wpdb->prepare("SELECT AVG(porcentaje) FROM $tabla_st WHERE tarea_id = %d", $tarea_id));
    if ($promedio !== null) {
        $estado = ($promedio >= 100) ? 'Finalizado' : 'En ejecucion';
        $wpdb->update($tabla_t, array('porcentaje' => round($promedio), 'estado' => $estado), array('id' => $tarea_id));
    }
}

// 5. RECEPTOR INTERACTIVO DEL GANTT VÍA AJAX
add_action('wp_ajax_ig_update_task_dates', 'ig_update_task_dates_ajax');
function ig_update_task_dates_ajax() {
    global $wpdb;
    if (!is_user_logged_in()) wp_send_json_error('No autorizado');
    
    // 🔒 CANDADO DE SEGURIDAD BACKEND
    $user = wp_get_current_user();
    $roles = (array) $user->roles;
    if (!in_array('administrator', $roles) && !in_array('gerente', $roles)) {
        wp_send_json_error('Acceso denegado: Solo Gerencia puede modificar las fechas.');
        exit;
    }
    
    $task_id = intval(str_replace('T', '', $_POST['task_id']));
    $start = sanitize_text_field($_POST['start']);
    $end = sanitize_text_field($_POST['end']);
    
    if ($task_id > 0 && !empty($start) && !empty($end)) {
        $wpdb->update($wpdb->prefix . 'ig_tareas', array('fecha_inicio' => $start, 'fecha_final' => $end), array('id' => $task_id));

        $porc = (int) $wpdb->get_var($wpdb->prepare("SELECT porcentaje FROM {$wpdb->prefix}ig_tareas WHERE id=%d", $task_id));
        $inicio = new DateTime($start);
        $fin = new DateTime($end);
        $hoy = new DateTime(current_time('Y-m-d'));

        $diff_total = (int) $inicio->diff($fin)->format('%r%a');
        $diff_restante = (int) $hoy->diff($fin)->format('%r%a');

        if ($porc >= 100) {
            $color = "#1960ca"; $txt = "Finalizada"; $css_class = "bar-gray";
        } elseif ($diff_restante < 0) {
            $color = "#e53e3e"; $txt = "Vencida (" . abs($diff_restante) . "d)"; $css_class = "bar-red";
        } elseif ($diff_total == 0) {
            $color = "#ed8936"; $txt = "Urgente (Exprés)"; $css_class = "bar-orange";
        } else {
            $diff_transcurrido = (int) $inicio->diff($hoy)->format('%r%a') + 1;
            if ($diff_transcurrido < 1) $diff_transcurrido = 1;
            $tiempo_consumido = ($diff_transcurrido / ($diff_total + 1)) * 100;
            
            if ($tiempo_consumido <= 60) {
                $color = "#28a745"; $txt = "Al día (" . round($tiempo_consumido, 1) . "%)"; $css_class = "bar-green";
            } elseif ($tiempo_consumido <= 85) {
                $color = "#ecc94b"; $txt = "Crítica (" . round($tiempo_consumido, 1) . "%)"; $css_class = "bar-yellow";
            } elseif ($tiempo_consumido < 100) {
                $color = "#ed8936"; $txt = "Urgencia (" . round($tiempo_consumido, 1) . "%)"; $css_class = "bar-orange";
            } else {
                $color = "#e53e3e"; $txt = "Tiempo agotado"; $css_class = "bar-red";
            }
        }
        wp_send_json_success(array('color' => $color, 'texto' => $txt, 'css_class' => $css_class));
    }
    wp_send_json_error('Datos inválidos');
}

// 6. ACCIONES MANUALES DEL TABLERO
add_action('init', 'ig_procesar_acciones_pmo');
function ig_procesar_acciones_pmo() {
    global $wpdb; 
    $tabla_t = $wpdb->prefix . 'ig_tareas'; $tabla_st = $wpdb->prefix . 'ig_subtareas'; $tabla_r = $wpdb->prefix . 'ig_responsables';
    $fecha_ahora = current_time('d/m/Y H:i');

    if (isset($_POST['ig_crear_tarea_gerente'])) {
        $sd = sanitize_text_field($_POST['t_sede']); 
        $gr = sanitize_text_field($_POST['t_grupo']);
        $nombre_t = sanitize_textarea_field($_POST['t_nombre']);
        $fin_t = sanitize_text_field($_POST['t_fin']);
        
        $rid = $wpdb->get_var($wpdb->prepare("SELECT user_id FROM $tabla_r WHERE sede=%s AND grupo=%s", $sd, $gr)) ?: get_current_user_id();
        
        $wpdb->insert($tabla_t, array('user_id'=>$rid, 'sede'=>$sd, 'grupo'=>$gr, 'tarea'=>$nombre_t, 'fecha_inicio'=>sanitize_text_field($_POST['t_inicio']), 'fecha_final'=>$fin_t, 'estado'=>'Sin iniciar', 'origen'=>'Gerencia', 'porcentaje'=>0, 'observacion'=>''));
        
        // 📧 NOTIFICACIÓN: Tarea a Grupo
        $resp_ids = $wpdb->get_col($wpdb->prepare("SELECT user_id FROM $tabla_r WHERE sede=%s AND grupo=%s", $sd, $gr));
        if (!empty($resp_ids)) {
            $correos = [];
            foreach ($resp_ids as $req_id) {
                $usr_req = get_userdata($req_id);
                if ($usr_req) $correos[] = $usr_req->user_email;
            }
            if (!empty($correos)) {
                $asunto = "Nueva Tarea Asignada: " . $nombre_t;
                $msj = "Hola,\n\nLa gerencia ha asignado una nueva tarea principal a tu grupo ($gr - $sd).\n\n📋 Tarea: $nombre_t\n📅 Fecha Límite: $fin_t\n\nPor favor, ingresa al tablero del Club para revisar los detalles.\n";
                wp_mail($correos, $asunto, $msj, array('Content-Type: text/plain; charset=UTF-8'));
            }
        }
        
        wp_redirect($_SERVER['REQUEST_URI']); exit;
    }

    if (isset($_POST['ig_crear_subtarea'])) {
        $tarea_id = intval($_POST['tarea_padre_id']);
        $id_op = intval($_POST['st_operativo']);
        $nombre_st = sanitize_textarea_field($_POST['st_nombre']);
        $fin_st = sanitize_text_field($_POST['st_fin']);
        
        $wpdb->insert($tabla_st, array('tarea_id'=>$tarea_id, 'user_id'=>$id_op, 'subtarea'=>$nombre_st, 'fecha_inicio'=>sanitize_text_field($_POST['st_inicio']), 'fecha_final'=>$fin_st, 'porcentaje'=>0, 'observacion'=>''));
        ig_recalcular_promedio_tarea($tarea_id); 
        
        // 📧 NOTIFICACIÓN: Subtarea a Operativo
        $usr_op = get_userdata($id_op);
        if ($usr_op) {
            $asunto = "Nueva Subtarea Operativa: " . $nombre_st;
            $msj = "Hola " . $usr_op->display_name . ",\n\nTu coordinador te ha delegado una nueva subtarea en el tablero.\n\n📝 Subtarea: $nombre_st\n📅 Fecha Límite: $fin_st\n\nPor favor, ingresa al sistema para trabajarla y reportar avances.\n";
            wp_mail($usr_op->user_email, $asunto, $msj, array('Content-Type: text/plain; charset=UTF-8'));
        }
        
        wp_redirect($_SERVER['REQUEST_URI']); exit;
    }

// NUEVO: REASIGNAR SUBTAREA HUÉRFANA O CAMBIAR RESPONSABLE
if (isset($_POST['ig_reasignar_subtarea'])) {
    $st_id = intval($_POST['subtarea_id']);
    $nuevo_uid = intval($_POST['nuevo_operativo']);
    
    // 1. Actualizamos la base de datos con el nuevo usuario
    global $wpdb;
    $wpdb->update($tabla_st, array('user_id' => $nuevo_uid), array('id' => $st_id));
    
    // 2. 📧 NOTIFICACIÓN: Lógica para enviar correo al nuevo responsable
    $usr_op = get_userdata($nuevo_uid);
    if ($usr_op) {
        // Buscamos el nombre y la fecha de la subtarea en la base de datos para el correo
        $st_row = $wpdb->get_row($wpdb->prepare("SELECT subtarea, fecha_final FROM $tabla_st WHERE id=%d", $st_id));
        if ($st_row) {
            $asunto = "Asignación de Subtarea Operativa: " . $st_row->subtarea;
            
            $msj = "Hola " . $usr_op->display_name . ",\n\n";
            $msj .= "Se te ha asignado como responsable de una subtarea en el tablero.\n\n";
            $msj .= "📝 Subtarea: " . $st_row->subtarea . "\n";
            $msj .= "📅 Fecha Límite: " . $st_row->fecha_final . "\n\n";
            $msj .= "Por favor, ingresa al sistema para trabajarla y reportar avances.\n";
            
            // Usamos la función nativa de WordPress para enviar el correo
            wp_mail($usr_op->user_email, $asunto, $msj, array('Content-Type: text/plain; charset=UTF-8'));
        }
    }
    
    // 3. Recargamos la página para reflejar los cambios
    wp_redirect($_SERVER['REQUEST_URI']); 
    exit;
}

    if (isset($_POST['ig_actualizar_subtarea'])) {
        $st_id = intval($_POST['subtarea_id']); $tarea_id = intval($_POST['tarea_padre_id']); $obs = sanitize_textarea_field($_POST['observacion_texto'] ?? '');
        $reg_st = $wpdb->get_row($wpdb->prepare("SELECT observacion FROM $tabla_st WHERE id=%d", $st_id));
        $datos = array('porcentaje'=>min(intval($_POST['nuevo_porcentaje']), 100));
        if(!empty($obs)) { 
            $linea = "<strong>($fecha_ahora) ".wp_get_current_user()->display_name." (Subtarea):</strong> $obs"; 
            $datos['observacion'] = empty($reg_st->observacion) ? $linea : $reg_st->observacion."<br><br>".$linea; 
            $reg_t = $wpdb->get_row($wpdb->prepare("SELECT observacion FROM $tabla_t WHERE id=%d", $tarea_id));
            $wpdb->update($tabla_t, array('observacion'=>empty($reg_t->observacion)?$linea:$reg_t->observacion."<br><br>".$linea), array('id'=>$tarea_id));
        }
        $wpdb->update($tabla_st, $datos, array('id'=>$st_id)); ig_recalcular_promedio_tarea($tarea_id); wp_redirect($_SERVER['REQUEST_URI']); exit;
    }

    if (isset($_POST['ig_actualizar_porcentaje'])) {
        $id = intval($_POST['tarea_id']); $obs = sanitize_textarea_field($_POST['observacion_texto'] ?? '');
        $reg = $wpdb->get_row($wpdb->prepare("SELECT observacion FROM $tabla_t WHERE id=%d", $id));
        $datos = array('porcentaje'=>min(intval($_POST['nuevo_porcentaje']), 100), 'estado'=>'En ejecucion');
        if(!empty($obs)) { $linea = "<strong>($fecha_ahora) ".wp_get_current_user()->display_name.":</strong> $obs"; $datos['observacion'] = empty($reg->observacion)?$linea:$reg->observacion."<br><br>".$linea; }
        $wpdb->update($tabla_t, $datos, array('id'=>$id)); wp_redirect($_SERVER['REQUEST_URI']); exit;
    }

    if (isset($_POST['ig_guardar_observacion'])) {
        $id = intval($_POST['tarea_id']); $obs = sanitize_textarea_field($_POST['observacion_texto']);
        $reg = $wpdb->get_row($wpdb->prepare("SELECT observacion FROM $tabla_t WHERE id=%d", $id));
        $linea = "<strong>($fecha_ahora) ".wp_get_current_user()->display_name.":</strong> $obs";
        $wpdb->update($tabla_t, array('observacion'=>empty($reg->observacion)?$linea:$reg->observacion."<br><br>".$linea), array('id'=>$id));
        wp_redirect($_SERVER['REQUEST_URI']); exit;
    }
}

// 7. CARGA EXCEL
add_action('init', 'ig_procesar_carga_frontend');
function ig_procesar_carga_frontend() {
    if (isset($_POST['ig_datos_procesados']) && wp_verify_nonce($_POST['ig_nonce_carga'], 'ig_guardar_datos_excel')) {
        global $wpdb; $datos = json_decode(stripslashes($_POST['ig_datos_procesados']), true);
        if (!is_array($datos)) return;
        foreach ($datos as $f) {
            if (empty($f[0]) || empty($f[1])) continue;
            $sd = sanitize_text_field($f[0]); $gr = sanitize_text_field($f[1]);
            $rid = $wpdb->get_var($wpdb->prepare("SELECT user_id FROM {$wpdb->prefix}ig_responsables WHERE sede=%s AND grupo=%s", $sd, $gr)) ?: get_current_user_id();
            $fecha = ig_formatear_fecha($f[2]) ?: current_time('Y-m-d'); $ing = ig_limpiar_num($f[3]); $gas = ig_limpiar_num($f[4]);
            if ($ing > 0 || $gas > 0 || !empty(trim($f[7] ?? ''))) {
                $id_ex = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}info_gerencial WHERE sede=%s AND grupo=%s AND fecha=%s", $sd, $gr, $fecha));
                if ($id_ex) {
                    $act = $wpdb->get_row($wpdb->prepare("SELECT ingreso, gasto FROM {$wpdb->prefix}info_gerencial WHERE id=%d", $id_ex));
                    $wpdb->update($wpdb->prefix.'info_gerencial', array('ingreso'=>($ing>0?$ing:$act->ingreso), 'gasto'=>($gas>0?$gas:$act->gasto), 'ideas_mejora'=>sanitize_textarea_field($f[7]??'')), array('id'=>$id_ex));
                } else { $wpdb->insert($wpdb->prefix.'info_gerencial', array('user_id'=>$rid, 'sede'=>$sd, 'grupo'=>$gr, 'fecha'=>$fecha, 'ingreso'=>$ing, 'gasto'=>$gas, 'ideas_mejora'=>sanitize_textarea_field($f[7]??''))); }
            }
            if (!empty(trim($f[5] ?? ''))) { $wpdb->insert($wpdb->prefix.'ig_tareas', array('user_id'=>$rid, 'sede'=>$sd, 'grupo'=>$gr, 'tarea'=>sanitize_textarea_field($f[5]), 'fecha_inicio'=>ig_formatear_fecha($f[8]??''), 'fecha_final'=>ig_formatear_fecha($f[9]??''), 'porcentaje'=>0, 'estado'=>'Sin iniciar', 'origen'=>'Carga Excel', 'observacion'=>'')); }
        }
        wp_redirect(add_query_arg('carga', 'ok', remove_query_arg('ig_datos_procesados'))); exit;
    }
}

add_action('wp_enqueue_scripts', function(){ wp_enqueue_script('chart-js', 'https://cdn.jsdelivr.net/npm/chart.js', array(), null, true); });
add_shortcode('tablero_gerencial', function(){ if(!is_user_logged_in()) return '🛑 Inicia sesión.'; ob_start(); include plugin_dir_path(__FILE__).'vista-shortcode.php'; return ob_get_clean(); });
add_shortcode('carga_excel_gerencial', function(){ if(!is_user_logged_in()) return '🛑 Inicia sesión.'; ob_start(); include plugin_dir_path(__FILE__).'vista-carga.php'; return ob_get_clean(); });
add_shortcode('login_gerencial', function(){ if(is_user_logged_in()) return '✅ Sesión iniciada.'; ob_start(); wp_login_form(); return ob_get_clean(); });

// ==========================================
// 6. ENCOLAMIENTO OFICIAL DE ESTILOS CSS
// ==========================================
add_action('wp_enqueue_scripts', 'ig_cargar_estilos_pmo');
function ig_cargar_estilos_pmo() {
    wp_register_style('ig-estilos-tablero', plugin_dir_url(__FILE__) . 'css/estilos-pmo.css', array(), '1.0.0');
    wp_enqueue_style('ig-estilos-tablero');
}
