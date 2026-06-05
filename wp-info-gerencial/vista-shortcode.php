<?php
if (!defined('ABSPATH')) exit;
global $wpdb;

// Control estricto de roles
$user = wp_get_current_user();
$roles = (array) $user->roles;
$es_gerente_o_admin = in_array('administrator', $roles) || in_array('gerente', $roles);
$es_operativo = in_array('funcionario_operativo', $roles) && !$es_gerente_o_admin;
$uid = get_current_user_id();

// 🚀 MOTOR DE EXPORTACIÓN EXCEL NATIVO CON COLORES Y VISTA GLOBAL
if (isset($_POST['ig_exportar_excel']) && !$es_operativo) {
    $e_sede = sanitize_text_field($_POST['exp_sede'] ?? '');
    $e_grupo = sanitize_text_field($_POST['exp_grupo'] ?? '');

    if (empty($e_sede) && empty($e_grupo)) {
        // 1. REPORTE GLOBAL: Todo el club (excluyendo finalizadas al 100%)
        $q_exp = "SELECT * FROM {$wpdb->prefix}ig_tareas WHERE porcentaje < 100 ORDER BY sede ASC, grupo ASC, id DESC";
        $exp_tareas = $wpdb->get_results($q_exp);
        $nombre_archivo = 'Reporte_Global_Activas_' . date('Ymd') . '.xls';
        
    } elseif (!empty($e_sede) && empty($e_grupo)) {
        // 2. REPORTE DE SEDE: Eligió Sede pero dejó el Grupo vacío
        $q_exp = "SELECT * FROM {$wpdb->prefix}ig_tareas WHERE sede = %s ORDER BY grupo ASC, id DESC";
        $exp_tareas = $wpdb->get_results($wpdb->prepare($q_exp, $e_sede));
        $nombre_archivo = 'Reporte_Sede_' . sanitize_title($e_sede) . '_' . date('Ymd') . '.xls';
        
    } else {
        // 3. REPORTE ESPECÍFICO: Sede y Grupo seleccionados
        $q_exp = "SELECT * FROM {$wpdb->prefix}ig_tareas WHERE sede = %s AND grupo = %s ORDER BY id DESC";
        $exp_tareas = $wpdb->get_results($wpdb->prepare($q_exp, $e_sede, $e_grupo));
        $nombre_archivo = 'Reporte_' . sanitize_title($e_sede) . '_' . sanitize_title($e_grupo) . '_' . date('Ymd') . '.xls';
    }

    ob_clean();
    header("Content-Type: application/vnd.ms-excel; charset=utf-8");
    header('Content-Disposition: attachment; filename="' . $nombre_archivo . '"');
    
    echo '<html xmlns:x="urn:schemas-microsoft-com:office:excel">';
    echo '<head><meta charset="utf-8"></head><body>';
    echo '<table border="1">';
    echo '<tr style="background-color:#0073aa; color:#fff; font-weight:bold;">';
    // Nueva columna agregada
    echo '<th>ID Tarea</th><th>Sede / Grupo</th><th>Tarea Principal</th><th>Subtarea</th><th>Progreso</th><th>Responsable</th><th>Fecha Limite</th><th>Notas de Avance</th>';
    echo '</tr>';

    $hoy_excel = new DateTime(current_time('Y-m-d'));

    if ($exp_tareas) {
        foreach ($exp_tareas as $et) {
            $st_raw = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$wpdb->prefix}ig_subtareas WHERE tarea_id = %d", $et->id));
            
            // Tratamiento de Bitácora para Excel
            $obs = wp_kses_post($et->observacion);
            $obs = preg_replace('/<div[^>]*>/i', '', $obs); 
            $obs = str_replace(['</div>', '<br>', '<br/>', '<br />', '</p>', "\n"], '<br style="mso-data-placement:same-cell;" />', $obs);
            $obs = strip_tags($obs, '<br>');

            // CALCULO DEL SEMÁFORO PARA EL EXCEL
            $fi_str = $et->fecha_inicio ?: current_time('Y-m-d');
            $ff_str = $et->fecha_final ?: current_time('Y-m-d');
            $inicio_ex = new DateTime($fi_str);
            $fin_ex = new DateTime($ff_str);
            
            $diff_total_ex = (int) $inicio_ex->diff($fin_ex)->format('%r%a');
            $diff_restante_ex = (int) $hoy_excel->diff($fin_ex)->format('%r%a');
            $p_ex = intval($et->porcentaje ?? 0);
            
            $bg_color = "#1960ca"; $txt_color = "#ffffff";
            if ($p_ex >= 100) { 
                $bg_color = "#1960ca"; 
            } elseif ($diff_restante_ex < 0) { 
                $bg_color = "#e53e3e"; 
            } else {
                if ($diff_total_ex == 0) {
                    $bg_color = "#ed8936";
                } else {
                    $diff_transcurrido_ex = (int) $inicio_ex->diff($hoy_excel)->format('%r%a');
                    $tiempo_consumido_ex = ($diff_transcurrido_ex <= 0) ? 0 : ($diff_transcurrido_ex / $diff_total_ex) * 100;
                    
                    if ($tiempo_consumido_ex <= 60) {
                        $bg_color = "#28a745"; 
                    } elseif ($tiempo_consumido_ex <= 85) {
                        $bg_color = "#ecc94b"; $txt_color = "#000000";
                    } elseif ($tiempo_consumido_ex < 100) {
                        $bg_color = "#ed8936"; 
                    } else {
                        $bg_color = "#e53e3e"; 
                    }
                }
            }

            // Construcción visual del área
            $area_lbl = $et->sede . ' / ' . $et->grupo;

            if ($st_raw) {
                foreach ($st_raw as $st) {
                    $usr = get_userdata($st->user_id);
                    $nom = $usr ? $usr->display_name : 'Sin asignar';
                    echo '<tr>';
                    echo "<td style='background-color:{$bg_color}; color:{$txt_color}; font-weight:bold; text-align:center;'>{$et->id}</td><td>{$area_lbl}</td><td>{$et->tarea}</td><td>{$st->subtarea}</td><td>{$st->porcentaje}%</td><td>{$nom}</td><td>{$st->fecha_final}</td>";
                    echo "<td style='vertical-align:top;'>{$obs}</td>";
                    echo '</tr>';
                }
            } else {
                echo '<tr>';
                echo "<td style='background-color:{$bg_color}; color:{$txt_color}; font-weight:bold; text-align:center;'>{$et->id}</td><td>{$area_lbl}</td><td>{$et->tarea}</td><td>Sin subtareas</td><td>{$et->porcentaje}%</td><td>---</td><td>{$et->fecha_final}</td>";
                echo "<td style='vertical-align:top;'>{$obs}</td>";
                echo '</tr>';
            }
        }
    }
    echo '</table></body></html>';
    exit;
}

// 🚀 MOTOR DE ADMINISTRACIÓN (Editar/Eliminar Tareas)
if ($es_gerente_o_admin) {
    if (isset($_POST['ig_eliminar_tarea_admin']) && isset($_POST['tarea_id'])) {
        $id_borrar = intval($_POST['tarea_id']);
        $wpdb->delete("{$wpdb->prefix}ig_tareas", ['id' => $id_borrar], ['%d']);
        $wpdb->delete("{$wpdb->prefix}ig_subtareas", ['tarea_id' => $id_borrar], ['%d']);
    }
    
    if (isset($_POST['ig_editar_tarea_admin']) && isset($_POST['tarea_id'])) {
        $id_editar = intval($_POST['tarea_id']);
        $wpdb->update(
            "{$wpdb->prefix}ig_tareas",
            [
                'sede'         => sanitize_text_field($_POST['t_sede_edit']),
                'grupo'        => sanitize_text_field($_POST['t_grupo_edit']),
                'tarea'        => sanitize_text_field($_POST['t_nombre_edit']),
                'fecha_inicio' => sanitize_text_field($_POST['t_inicio_edit']),
                'fecha_final'  => sanitize_text_field($_POST['t_fin_edit'])
            ],
            ['id' => $id_editar],
            ['%s', '%s', '%s', '%s', '%s'],
            ['%d']
        );
    }
}

// Construcción del Árbol Relacional
$relaciones_raw = $wpdb->get_results("SELECT DISTINCT sede, grupo FROM {$wpdb->prefix}ig_responsables WHERE sede != '' AND grupo != '' ORDER BY sede ASC, grupo ASC");
$arbol_organizacional = [];
foreach ($relaciones_raw as $rel) {
    $arbol_organizacional[$rel->sede][] = $rel->grupo;
}

$sedes = $wpdb->get_col("SELECT nombre FROM {$wpdb->prefix}ig_config_areas WHERE tipo='sede' ORDER BY nombre ASC");
$grupos = $wpdb->get_col("SELECT nombre FROM {$wpdb->prefix}ig_config_areas WHERE tipo='grupo' ORDER BY nombre ASC");

$f_sede = $_GET['f_sede'] ?? ''; $f_grupo = $_GET['f_grupo'] ?? ''; $f_estado = $_GET['f_estado'] ?? '';
$where = "WHERE 1=1";

if ($es_operativo) {
    $mis_tareas = $wpdb->get_col($wpdb->prepare("SELECT tarea_id FROM {$wpdb->prefix}ig_subtareas WHERE user_id = %d", $uid));
    if (empty($mis_tareas)) { $where .= " AND 1=0"; } else { $where .= " AND id IN (" . implode(',', $mis_tareas) . ")"; }
} elseif (!$es_gerente_o_admin) {
    $areas = $wpdb->get_results($wpdb->prepare("SELECT sede, grupo FROM {$wpdb->prefix}ig_responsables WHERE user_id = %d", $uid));
    if ($areas) { $cs = []; foreach($areas as $a) $cs[] = $wpdb->prepare("(sede = %s AND grupo = %s)", $a->sede, $a->grupo); $where .= " AND (" . implode(" OR ", $cs) . ")"; }
    else { $where .= " AND 1=0"; }
}

if ($f_sede) $where .= $wpdb->prepare(" AND sede = %s", $f_sede);
if ($f_grupo) $where .= $wpdb->prepare(" AND grupo = %s", $f_grupo);

// PROCESAMIENTO
$tareas_raw = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}ig_tareas $where");
$tareas_activas = []; $tareas_finalizadas = []; $gantt_data = []; $hoy = new DateTime(current_time('Y-m-d'));
$stats_operativas = []; 
$kpi_tareas_vencidas = 0; $kpi_tareas_criticas = 0;

if ($tareas_raw) {
    $task_ids = array_column($tareas_raw, 'id');
    $todas_las_subtareas = [];
    if (!empty($task_ids)) {
        $ids_str = implode(',', array_map('intval', $task_ids));
        $st_raw = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}ig_subtareas WHERE tarea_id IN ($ids_str)");
        foreach ($st_raw as $st) { $todas_las_subtareas[$st->tarea_id][] = $st; }
    }

    foreach ($tareas_raw as $t) {
        $fi_str = $t->fecha_inicio ?: current_time('Y-m-d');
        $ff_str = $t->fecha_final ?: current_time('Y-m-d');
        $inicio = new DateTime($fi_str);
        $fin = new DateTime($ff_str);
        
        $diff_total = (int) $inicio->diff($fin)->format('%r%a');
        $diff_restante = (int) $hoy->diff($fin)->format('%r%a');
        $p = intval($t->porcentaje ?? 0);
        
        if ($p >= 100) { 
            $st = 'finalizada'; $orden = 5; $color = "#1960ca"; $txt = "Finalizada"; $css_class = "bar-gray"; 
        } elseif ($diff_restante < 0) { 
            $st = 'vencida'; $orden = 1; $color = "#e53e3e"; $txt = "Vencida (".abs($diff_restante)."d)"; $css_class = "bar-red"; 
            $kpi_tareas_vencidas++;
        } else {
            if ($diff_total == 0) {
                $st = 'urgente'; $orden = 2; $color = "#ed8936"; $txt = "Urgente (Exprés)"; $css_class = "bar-orange";
                $kpi_tareas_criticas++;
            } else {
                $diff_transcurrido = (int) $inicio->diff($hoy)->format('%r%a');
                $tiempo_consumido = ($diff_transcurrido <= 0) ? 0 : ($diff_transcurrido / $diff_total) * 100;
                
                if ($tiempo_consumido <= 60) {
                    $st = 'a_tiempo'; $orden = 4; $color = "#28a745"; $txt = "Al día (" . round($tiempo_consumido, 1) . "%)"; $css_class = "bar-green";
                }
                elseif ($tiempo_consumido <= 85) {
                    $st = 'critica'; $orden = 3; $color = "#ecc94b"; $txt = "Crítica (" . round($tiempo_consumido, 1) . "%)"; $css_class = "bar-yellow";
                    $kpi_tareas_criticas++;
                }
                elseif ($tiempo_consumido < 100) {
                    $st = 'urgente'; $orden = 2; $color = "#ed8936"; $txt = "Urgencia (" . round($tiempo_consumido, 1) . "%)"; $css_class = "bar-orange";
                    $kpi_tareas_criticas++;
                }
                else {
                    $st = 'vencida'; $orden = 1; $color = "#e53e3e"; $txt = "Tiempo agotado"; $css_class = "bar-red";
                    $kpi_tareas_vencidas++;
                }
            }
        }
        
        $lbl = $t->sede . ' (' . $t->grupo . ')';
        if (!isset($stats_operativas[$lbl])) { $stats_operativas[$lbl] = ['vencida'=>0, 'urgente'=>0, 'critica'=>0, 'a_tiempo'=>0, 'finalizada'=>0]; }
        $stats_operativas[$lbl][$st]++;

        if ($f_estado && $st !== $f_estado) continue;
        
        $t->estado_calc = $st; $t->orden = $orden; $t->color = $color; $t->txt = $txt;
        $t->subtareas = $todas_las_subtareas[$t->id] ?? [];
        
        if ($st === 'finalizada') {
            $tareas_finalizadas[] = $t;
        } else {
            $tareas_activas[] = $t;
            $gantt_data[] = [
                'id' => 'T'.$t->id,
                'name' => mb_strimwidth($t->tarea, 0, 30, '...'),
                'start' => $fi_str,
                'end' => $ff_str, 
                'progress' => $p,
                'custom_class' => $css_class,
                'estado_calc' => $st
            ];
        }
    }
}

$ordenador = function($a, $b) {
    if ($a->orden == $b->orden) { return strtotime($a->fecha_final ?? 0) - strtotime($b->fecha_final ?? 0); }
    return $a->orden - $b->orden;
};
usort($tareas_activas, $ordenador);
usort($tareas_finalizadas, $ordenador);

// MOTOR FINANCIERO
$datasets_finanzas = [];
$chart_labels = []; $d_ven = []; $d_urg = []; $d_cri = []; $d_ati = []; $d_fin = []; 
$kpi_total_ingresos = 0; $kpi_total_gastos = 0;

if (!$es_operativo) {
    $reporte_raw = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}info_gerencial $where ORDER BY fecha DESC, id DESC");
    $datos_finales = [];
    if ($reporte_raw) {
        $idx_color = 0;
        $colores_act = ['#2563eb', '#dc2626', '#16a34a', '#d97706', '#9333ea', '#0d9488'];
        $colores_ant = ['#93c5fd', '#fca5a5', '#86efac', '#fcd34d', '#d8b4fe', '#5eead4'];

        foreach ($reporte_raw as $r) {
            $key = $r->sede . '_' . $r->grupo;
            if (!isset($datos_finales[$key])) {
                $ant = $wpdb->get_row($wpdb->prepare("SELECT ingreso, gasto FROM {$wpdb->prefix}info_gerencial WHERE sede=%s AND grupo=%s AND id < %d AND (ingreso > 0 OR gasto > 0) ORDER BY id DESC LIMIT 1", $r->sede, $r->grupo, $r->id));
                $r->ant_i = $ant ? $ant->ingreso : 0; $r->ant_g = $ant ? $ant->gasto : 0;
                $datos_finales[$key] = $r;
                
                $kpi_total_ingresos += $r->ingreso;
                $kpi_total_gastos += $r->gasto;
                
                if($r->ingreso > 0 || $r->gasto > 0){
                    $c_act = $colores_act[$idx_color % count($colores_act)];
                    $c_ant = $colores_ant[$idx_color % count($colores_ant)];

                    $datasets_finanzas[] = [
                        'label' => $r->sede . ' (' . $r->grupo . ') - Última',
                        'data' => [$r->ingreso, $r->gasto],
                        'borderColor' => $c_act, 'backgroundColor' => $c_act, 'tension' => 0, 'pointRadius' => 6, 'pointHoverRadius' => 8
                    ];
                    
                    if ($ant) {
                        $datasets_finanzas[] = [
                            'label' => $r->sede . ' (' . $r->grupo . ') - Ant',
                            'data' => [$r->ant_i, $r->ant_g],
                            'borderColor' => $c_ant, 'backgroundColor' => $c_ant, 'borderDash' => [5, 5], 'tension' => 0, 'pointRadius' => 6, 'pointHoverRadius' => 8
                        ];
                    }
                    $idx_color++;
                }
            }
        }
    }

    if(!empty($stats_operativas)) {
        $chart_labels = array_keys($stats_operativas);
        foreach($chart_labels as $l) {
            $d_ven[] = $stats_operativas[$l]['vencida'];
            $d_urg[] = $stats_operativas[$l]['urgente'];
            $d_cri[] = $stats_operativas[$l]['critica'];
            $d_ati[] = $stats_operativas[$l]['a_tiempo'];
            $d_fin[] = $stats_operativas[$l]['finalizada'];
        }
    }
}
?>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/frappe-gantt/0.6.1/frappe-gantt.css">
<script src="https://cdnjs.cloudflare.com/ajax/libs/frappe-gantt/0.6.1/frappe-gantt.min.js"></script>

<style>
    .ig-container { 
        box-sizing: border-box; 
        overflow: hidden; 
        width: 100%; 
    }    
    .ig-bi-layout { 
        display: flex; 
        flex-direction: row; 
        gap: 25px; 
        align-items: flex-start; 
        margin-top: 15px; 
        width: 100%; 
        max-width: 100%; 
    }
    .ig-sidebar { 
        width: 250px; 
        flex-shrink: 0; 
        position: sticky; 
        top: 20px; 
    }
    .ig-main-board { 
        flex-grow: 1; 
        min-width: 0; 
        width: calc(100% - 275px); 
        overflow: hidden; 
        display: flex; 
        flex-direction: column; 
    }    
    .ig-kpi-row { 
        display: flex; 
        flex-wrap: wrap; 
        gap: 15px; 
    }
    .ig-kpi-card { 
        flex: 1 1 200px; 
        background: #fff; 
        border: 1px solid #e2e8f0; 
        border-radius: 8px; 
        padding: 15px; 
        box-shadow: 0 1px 3px rgba(0,0,0,0.05); 
    }
    .ig-kpi-title { 
        font-size: 11px; 
        color: #64748b; 
        font-weight: bold; 
        text-transform: uppercase; 
        margin-bottom: 5px; 
    }
    .ig-kpi-value { 
        font-size: 24px; 
        font-weight: 900; 
        color: #1e293b; 
        line-height: 1; 
    }    
    .ig-charts-row { 
        display: flex; 
        flex-direction: column; 
        gap: 20px; 
        width: 100%; 
    }
    .ig-chart-box { 
        background: #fff; 
        border: 1px solid #e2e8f0; 
        border-radius: 8px; 
        padding: 15px; 
        box-shadow: 0 1px 3px rgba(0,0,0,0.05); 
        min-width: 0; 
        width: 100%; 
        box-sizing: border-box; 
        overflow: hidden; 
    }    
    .gantt-container { 
        overflow-x: auto; 
        overflow-y: hidden; 
        -webkit-overflow-scrolling: touch; 
        width: 100%; 
        max-width: 100%; 
        display: block; 
    }
    .ig-tree-container { 
        background: #fff; 
        padding: 15px; 
        border-radius: 8px; 
        border: 1px solid #e2e8f0; 
    }
    .ig-tree-title { 
        margin-top: 0; 
        font-size: 14px; 
        margin-bottom: 12px; 
        color: #1e293b; 
        border-bottom: 2px solid #f1f5f9; 
        padding-bottom: 6px; 
    }    
    .ig-tree-container ul, .ig-tree-container li { 
        list-style: none !important; 
        padding: 0; 
        margin: 0; 
    }
    .ig-tree-branch { 
        list-style: none; 
        padding-left: 0; 
        margin: 0; 
    }
    .ig-tree-branch details { 
        margin-bottom: 6px; 
    }
    .ig-tree-branch summary { 
        font-weight: bold; 
        font-size: 13px; 
        color: #334155; 
        cursor: pointer; 
        padding: 4px; 
        border-radius: 4px; 
        transition: background 0.2s; 
        list-style: none !important; 
    }
    .ig-tree-branch summary::-webkit-details-marker { 
        display: none; 
    }
    .ig-tree-branch summary:hover { 
        background: #f1f5f9; 
    }
    .ig-tree-node { 
        list-style: none !important; 
        padding-left: 15px !important; 
        margin-top: 4px !important; 
        border-left: 1px dashed #cbd5e1; 
    }
    .ig-tree-link { 
        text-decoration: none; 
        color: #64748b; 
        font-size: 12px; 
        display: block; 
        padding: 4px 6px; 
        border-radius: 4px; 
        transition: all 0.2s; 
    }
    .ig-tree-link:hover { 
        background: #f1f5f9; 
        color: #1e293b; 
        padding-left: 10px; 
    }
    .ig-tree-link.active { 
        background: #e2e8f0; 
        color: #1e293b; 
        font-weight: bold; 
    }
    .ig-tabs-nav { 
        display: flex; 
        gap: 5px; 
        border-bottom: 2px solid #e2e8f0; 
        margin-bottom: 20px; 
        background: #fff; 
        padding: 5px 5px 0 5px; 
        border-radius: 8px 8px 0 0; 
        flex-wrap: wrap; 
    }
    .ig-tab-btn { 
        background: none; 
        border: none; 
        padding: 12px 20px; 
        font-size: 13px; 
        font-weight: bold; 
        color: #64748b; 
        cursor: pointer; 
        border-bottom: 3px solid transparent; 
        transition: all 0.2s; 
        border-radius: 4px 4px 0 0; 
    }
    .ig-tab-btn:hover { 
        color: #1e293b; 
        background: #f8fafc; 
    }
    .ig-tab-btn.active { 
        color: #2271b1; 
        border-bottom-color: #2271b1; 
        background: #f1f5f9; 
    }    
    .ig-tab-content { 
        display: none; 
        width: 100%; 
        min-width: 0; 
        overflow: hidden; 
    } 
    .ig-tab-content.active { 
        display: block; 
        animation: fadeIn 0.3s ease; 
    }
    @keyframes fadeIn { 
        from { 
            opacity: 0; 
            transform: translateY(5px); 
        } to { 
            opacity: 1; 
            transform: translateY(0); 
        } 
    }
    .ig-gantt-accordion summary { 
        list-style: none; 
        outline: none; 
    }
    .ig-gantt-accordion summary::-webkit-details-marker { 
        display: none; 
    }
    .ig-gantt-summary { 
        display: flex; 
        justify-content: space-between; 
        align-items: center; 
        padding: 12px 15px; 
        background: #f8fafc; 
        border: 1px solid #e2e8f0; 
        border-radius: 8px; 
        cursor: pointer; 
        margin-bottom: 15px; 
        color: #1e293b; 
        font-weight: bold; 
        font-size: 13px; 
    } 
    .ig-gantt-accordion[open] 
        .ig-gantt-summary { 
            border-bottom-left-radius: 0; 
            border-bottom-right-radius: 0; 
            margin-bottom: 0; 
            border-bottom: none; 
        }
    .ig-gantt-toggle { 
        font-size: 10px; 
        color: #2271b1; 
    }
    .ig-gantt-accordion .ig-chart-box { 
        border-top-left-radius: 0; 
        border-top-right-radius: 0; 
        border-top: 1px solid #e2e8f0; 
    }
    .ig-gantt-h3 { 
        display: none; 
    }
    .ig-flex { 
        display: flex; 
        flex-wrap: wrap; 
        gap: 10px; 
        align-items: center; 
    }
    .ig-input { 
        padding: 8px; 
        border: 1px solid #ccc; 
        border-radius: 5px; 
        flex: 1; 
        min-width: 140px; 
        font-size: 13px; 
    }
    .ig-btn { 
        background: #2271b1; 
        color: #fff; 
        border: none; 
        padding: 8px 15px; 
        border-radius: 5px; 
        cursor: pointer; 
        font-weight: bold; 
        font-size: 12px;
    }
    .ig-task-grid { 
        display: flex; 
        flex-direction: column; 
        gap: 10px; 
    }
    .ig-task-item { 
        border: 1px solid #e2e8f0; 
        border-radius: 8px;
        background: #fff; 
        border-left: 6px solid #ccc; 
        position:relative; 
        overflow:hidden; 
        transition: all 0.5s ease; 
    }
    .ig-task-focused { 
        background-color: #fffbe5 !important; 
        transform: scale(1.02); 
        border-left-color: #ecc94b !important; 
        box-shadow: 0 0 15px rgba(236,201,75,0.4); 
    }
    .ig-task-item summary { 
        padding: 15px; 
        cursor: pointer; 
        outline: none; 
        list-style: none; 
        display: flex; 
        flex-wrap: wrap; 
        align-items: center; 
        gap: 15px; 
        background: #fff; 
    }
    .ig-task-item summary::-webkit-details-marker { 
        display: none; 
    }
    .ig-task-item[open] summary { 
        border-bottom: 1px solid #e2e8f0; 
        background: #f8fafc; 
    }
    .ig-progress-bar { 
        background: #eee; 
        height: 6px; 
        border-radius: 3px; 
        overflow: hidden; 
        margin-top:4px; 
    }
    .ig-progress-fill { 
        height: 100%; 
    }
    .ig-badge { 
        font-size: 9px; 
        padding: 2px 5px; 
        background: #e2e8f0; 
        border-radius: 3px; 
        font-weight: bold; 
        text-transform: uppercase; 
    }
    .ig-subtask-box { 
        background:#fff; 
        border-left:3px solid #0073aa; 
        padding:10px; 
        margin-top:8px; 
        font-size:11px; 
        border-radius:4px; 
        border-right:1px solid #eee; 
        border-top:1px solid #eee; 
        border-bottom:1px solid #eee; 
    }
    @media (max-width: 1024px) {
        .ig-bi-layout { flex-direction: column; gap: 15px; } 
        .ig-sidebar { position: relative; top: 0; width: 100%; }
        .ig-main-board { width: 100%; }
    }
</style>

<div class="ig-container ig-bi-layout">
    
    <aside class="ig-sidebar">
        <div class="ig-tree-container">
            <h3 class="ig-tree-title">🏢 Estructura Organizacional</h3>
            <ul class="ig-tree-branch">
                <li style="margin-bottom: 10px;">
                    <a href="<?php echo strtok($_SERVER["REQUEST_URI"], '?'); ?>" class="ig-tree-link <?php echo (empty($f_sede) && empty($f_grupo)) ? 'active' : ''; ?>" style="font-weight: bold; color: #2271b1;">
                        🌐 Vista Global (Todo el Club)
                    </a>
                </li>
                <?php foreach ($arbol_organizacional as $s => $grupos_de_sede): 
                    $is_sede_activa = ($f_sede === $s);
                ?>
                    <li>
                        <details <?php echo ($is_sede_activa || empty($f_sede)) ? 'open' : ''; ?>>
                            <summary>📍 <?php echo esc_html($s); ?></summary>
                            <ul class="ig-tree-node">
                                <?php foreach ($grupos_de_sede as $g): 
                                    $is_grupo_activo = ($f_sede === $s && $f_grupo === $g);
                                ?>
                                    <li>
                                        <a href="?f_sede=<?php echo urlencode($s); ?>&f_grupo=<?php echo urlencode($g); ?>" class="ig-tree-link <?php echo $is_grupo_activo ? 'active' : ''; ?>">
                                            👥 <?php echo esc_html($g); ?>
                                        </a>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        </details>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    </aside>

    <main class="ig-main-board">
        
        <nav class="ig-tabs-nav">
            <?php if (!$es_operativo): ?>
            <button class="ig-tab-btn active" onclick="window.ig_switchTab('tab-graficos', this)">📊 Vista Analítica Principal</button>
            <?php endif; ?>
            <button class="ig-tab-btn <?php echo $es_operativo ? 'active' : ''; ?>" onclick="window.ig_switchTab('tab-tareas', this)">📋 Cronograma y Gestión Operativa</button>
            <?php if (!$es_operativo): ?>
            <button class="ig-tab-btn" onclick="window.ig_switchTab('tab-finalizadas', this)">✅ Tareas Finalizadas</button>
            <?php endif; ?>
        </nav>

        <?php if (!$es_operativo): ?>
        <div id="tab-graficos" class="ig-tab-content active">
            <div class="ig-kpi-row" style="margin-bottom: 20px;">
                <div class="ig-kpi-card" style="border-left-color:#2563eb;">
                    <div class="ig-kpi-title">Ingresos Filtrados</div>
                    <div class="ig-kpi-value" style="color:#2563eb;">$<?php echo number_format($kpi_total_ingresos/1000000, 1); ?>M</div>
                </div>
                <div class="ig-kpi-card" style="border-left-color:#dc2626;">
                    <div class="ig-kpi-title">Gastos Filtrados</div>
                    <div class="ig-kpi-value" style="color:#dc2626;">$<?php echo number_format($kpi_total_gastos/1000000, 1); ?>M</div>
                </div>
                <div class="ig-kpi-card" style="border-left-color:#e53e3e;">
                    <div class="ig-kpi-title">Tareas Vencidas</div>
                    <div class="ig-kpi-value" style="color:#e53e3e;"><?php echo $kpi_tareas_vencidas; ?></div>
                </div>
                <div class="ig-kpi-card" style="border-left-color:#ed8936;">
                    <div class="ig-kpi-title">Tareas Críticas y Urgentes</div>
                    <div class="ig-kpi-value" style="color:#ed8936;"><?php echo $kpi_tareas_criticas; ?></div>
                </div>
            </div>

            <div class="ig-charts-row">
                <div class="ig-chart-box">
                    <h3 style="margin-top:0; font-size:13px; color:#475569;">📈 Balance Financiero (Histórico vs Último)</h3>
                    <div style="position: relative; height: 250px; width: 100%;"><canvas id="igChartFinanzas"></canvas></div>
                </div>
                <div class="ig-chart-box">
                    <h3 style="margin-top:0; font-size:13px; color:#475569;">🌡️ Volumetría Operativa (Semáforo de Carga)</h3>
                    <div style="position: relative; height: 250px; width: 100%;"><canvas id="igChartSeparado"></canvas></div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <div id="tab-tareas" class="ig-tab-content <?php echo $es_operativo ? 'active' : ''; ?>">
            
            <?php if (!$es_operativo): ?>
            <details class="ig-card" style="border-top:4px solid #0073aa; padding: 15px; margin-bottom: 15px;">
                <summary style="margin-top:0; font-size: 14px; font-weight: bold; color: #0073aa; cursor: pointer; list-style: none; outline: none;">
                    📥 Generar Reporte de Avance (Excel)
                </summary>
                <form method="post" class="ig-flex" style="margin-top: 15px;">
                    <select name="exp_sede" id="ig-exp-sede" class="ig-input">
                        <option value="">Seleccione Sede...</option>
                        <?php foreach(array_keys($arbol_organizacional) as $s) echo "<option value='".esc_attr($s)."'>".esc_html($s)."</option>"; ?>
                    </select>
                    <select name="exp_grupo" id="ig-exp-grupo" class="ig-input">
                        <option value="">Seleccione Grupo...</option>
                    </select>
                    <button type="submit" name="ig_exportar_excel" class="ig-btn" style="background:#0073aa;">Descargar Reporte</button>
                </form>
                <script>
                    document.getElementById('ig-exp-sede').addEventListener('change', function() {
                        var arbol = <?php echo json_encode($arbol_organizacional); ?>;
                        var selectGrupo = document.getElementById('ig-exp-grupo');
                        var sedeSel = this.value;
                        selectGrupo.innerHTML = '<option value="">Seleccione Grupo...</option>';
                        if(sedeSel && arbol[sedeSel]) {
                            arbol[sedeSel].forEach(function(g) {
                                var opt = document.createElement('option'); opt.value = g; opt.textContent = g; selectGrupo.appendChild(opt);
                            });
                        }
                    });
                </script>
            </details>
            <?php endif; ?>

            <?php if ($es_gerente_o_admin): ?>
            <div class="ig-card ig-flex" style="padding: 10px 15px; margin-bottom: 15px; background: #f8fafc; border: 1px solid #cbd5e1; border-left: 4px solid #3b82f6;">
                <span style="font-weight:bold; font-size:13px; color:#1e293b;">🔍 Filtrar Vista:</span>
                <input type="text" id="ig-search-task" class="ig-input" placeholder="Buscar por palabra clave..." onkeyup="window.ig_filtrarTareas()">
                <select id="ig-filter-status" class="ig-input" onchange="window.ig_filtrarTareas()">
                    <option value="">Todas las prioridades</option>
                    <option value="vencida">🔴 Vencidas</option>
                    <option value="urgente">🟠 Urgentes (Exprés)</option>
                    <option value="critica">🟡 Críticas</option>
                    <option value="a_tiempo">🟢 Al día</option>
                </select>
            </div>

            <details class="ig-card" style="border-top:4px solid #28a745; padding: 15px; margin-bottom: 15px;">
                <summary style="margin-top:0; font-size: 14px; font-weight: bold; color: #166534; cursor: pointer; list-style: none; outline: none;">
                    ➕ Asignar Nueva Tarea Principal (Toca para desplegar)
                </summary>
                <form method="post" class="ig-flex" style="margin-top: 15px;">
                    <select name="t_sede" id="ig-form-sede" class="ig-input" required>
                        <option value="">Sede...</option>
                        <?php foreach(array_keys($arbol_organizacional) as $s) echo "<option value='".esc_attr($s)."'>".esc_html($s)."</option>"; ?>
                    </select>
                    <select name="t_grupo" id="ig-form-grupo" class="ig-input" required>
                        <option value="">Grupo...</option>
                    </select>
                    <input type="text" name="t_nombre" class="ig-input" placeholder="Descripción de la tarea..." required style="flex:2;">
                    <input type="date" name="t_inicio" class="ig-input" required title="Fecha de Inicio">
                    <input type="date" name="t_fin" class="ig-input" required title="Fecha Límite">
                    <button type="submit" name="ig_crear_tarea_gerente" class="ig-btn" style="background:#28a745;">Crear Actividad</button>
                </form>
                <script>
                    document.getElementById('ig-form-sede').addEventListener('change', function() {
                        var arbol = <?php echo json_encode($arbol_organizacional); ?>;
                        var selectGrupo = document.getElementById('ig-form-grupo');
                        var sedeSeleccionada = this.value;
                        selectGrupo.innerHTML = '<option value="">Grupo...</option>';
                        if(sedeSeleccionada && arbol[sedeSeleccionada]) {
                            arbol[sedeSeleccionada].forEach(function(grupo) {
                                var opt = document.createElement('option'); opt.value = grupo; opt.textContent = grupo; selectGrupo.appendChild(opt);
                            });
                        }
                    });
                </script>
            </details>
            <?php endif; ?>

            <?php if (!empty($gantt_data)): ?>
            <details id="gantt-accordion" class="ig-gantt-accordion" <?php echo wp_is_mobile() ? '' : 'open'; ?>>
                <summary class="ig-gantt-summary">
                    <span>📊 Cronograma Estratégico (Gantt)</span>
                    <span class="ig-gantt-toggle">▼ Mostrar / Ocultar</span>
                </summary>
                <div class="ig-chart-box" style="margin-bottom: 20px;">
                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px;">
                        <h3 class="ig-gantt-h3" style="margin-top:0; margin-bottom:0; font-size:13px; color:#475569;">📆 Línea de Tiempo</h3>
                        <select id="ig-gantt-view" class="ig-input" onchange="window.ig_cambiarVistaGantt()" style="width:auto; min-width:120px; padding:4px; font-size:11px; font-weight:bold; background:#e0f2fe; color:#0369a1; border-color:#bae6fd;">
                            <option value="Day">🔎 Vista Diaria</option>
                            <option value="Week" selected>📅 Vista Semanal</option>
                            <option value="Month">🗓️ Vista Mensual</option>
                        </select>
                    </div>
                    <svg id="gantt" class="<?php echo (!$es_gerente_o_admin && !current_user_can('coordinador_grupo')) ? 'ig-gantt-readonly' : ''; ?>"></svg>
                </div>
            </details>
            <?php endif; ?>

            <h2 id="ig-contador-activas" style="font-size: 16px; color:#1e293b; margin-bottom: 10px;">📋 Desglose Operativo (<?php echo count($tareas_activas); ?> Actividades Visibles)</h2>
            <div class="ig-task-grid" id="ig-contenedor-tareas">
                <?php foreach ($tareas_activas as $t): $p = intval($t->porcentaje); $tiene_subtareas = !empty($t->subtareas); ?>
                <details class="ig-task-item" id="task-<?php echo $t->id; ?>" data-estado="<?php echo $t->estado_calc; ?>" style="border-left-color:<?php echo $t->color; ?>;">
                    <summary>
                        <div style="width:60px; text-align:center;"><span class="ig-badge"><?php echo $t->origen; ?></span><br><small style="color:#94a3b8; font-weight:bold;">#<?php echo $t->id; ?></small></div>
                        <div style="flex:2; min-width:200px; font-weight:bold; font-size:14px; color:#1e293b;"><?php echo $t->tarea; ?></div>
                        <div style="flex:1; min-width:120px; font-size:11px; color:#64748b;">📍 <?php echo $t->sede; ?><br><?php echo $t->grupo; ?></div>
                        <div style="flex:1; min-width:120px; font-size:11px; color:#64748b;">📅 Límite:<br><strong id="task-date-<?php echo $t->id; ?>" style="color:#334155;"><?php echo $t->fecha_final; ?></strong></div>
                        <div style="flex:1; min-width:120px;"><div style="font-size:11px; font-weight:bold; color:<?php echo $t->color; ?>;" id="task-status-<?php echo $t->id; ?>"><?php echo $t->txt; ?></div><div class="ig-progress-bar"><div class="ig-progress-fill" id="task-prog-<?php echo $t->id; ?>" style="width:<?php echo $p; ?>%; background:<?php echo $t->color; ?>;"></div></div></div>
                        <div style="width:20px; text-align:center; color:#cbd5e1; font-size:10px;">▼</div>
                    </summary>
                    <div style="padding:15px; border-top:1px solid #e2e8f0; background:#f8fafc;">
                        
                        <?php if ($es_gerente_o_admin): ?>
                        <div class="ig-form-box" style="background:#fff3cd; border:1px solid #ffeeba; margin-bottom: 15px;">
                            <details>
                                <summary style="font-size:11px; font-weight:bold; color:#856404; cursor:pointer; list-style:none;">⚙️ Administrar Tarea Base (Editar / Eliminar)</summary>
                                <div style="margin-top: 10px; padding-top: 10px; border-top: 1px dashed #ffeeba;">
                                    <form method="post" style="display:flex; flex-direction:column; gap:8px;">
                                        <input type="hidden" name="tarea_id" value="<?php echo $t->id; ?>">
                                        <div class="ig-flex">
                                            <select name="t_sede_edit" class="ig-input" required>
                                                <?php foreach($sedes as $s) echo "<option value='".esc_attr($s)."' ".selected($t->sede, $s, false).">".esc_html($s)."</option>"; ?>
                                            </select>
                                            <select name="t_grupo_edit" class="ig-input" required>
                                                <?php foreach($grupos as $g) echo "<option value='".esc_attr($g)."' ".selected($t->grupo, $g, false).">".esc_html($g)."</option>"; ?>
                                            </select>
                                        </div>
                                        <input type="text" name="t_nombre_edit" value="<?php echo esc_attr($t->tarea); ?>" class="ig-input" required>
                                        
                                        <div class="ig-flex">
                                            <input type="date" name="t_inicio_edit" value="<?php echo esc_attr($t->fecha_inicio); ?>" class="ig-input" required title="Fecha de Inicio">
                                            <input type="date" name="t_fin_edit" value="<?php echo esc_attr($t->fecha_final); ?>" class="ig-input" required title="Fecha Límite">
                                        </div>

                                        <div class="ig-flex">
                                            <button type="submit" name="ig_editar_tarea_admin" class="ig-btn" style="flex:2; background:#e0a800; color:#212529;">Actualizar Datos</button>
                                            <button type="submit" name="ig_eliminar_tarea_admin" class="ig-btn" style="flex:1; background:#c82333;" onclick="return confirm('🚨 ¿ESTÁS SEGURO? Esta acción borrará la tarea y todas sus subtareas permanentemente.');">🗑️ Eliminar</button>
                                        </div>
                                    </form>
                                </div>
                            </details>
                        </div>
                        <?php endif; ?>

                        <?php if(!empty($t->observacion)): ?>
                        <details style="margin-bottom:10px; border:1px solid #cbd5e1; border-radius:6px; overflow:hidden;">
                            <summary style="background:#fff; padding:8px 12px; font-size:11px; font-weight:bold; color:#475569; cursor:pointer; list-style:none;">💬 Ver Bitácora Consolidada</summary>
                            <div style="padding:12px; font-size:11px; background:#fff; border-top:1px solid #e2e8f0;"><?php echo wp_kses_post($t->observacion); ?></div>
                        </details>
                        <?php endif; ?>
                        <?php 
                        $equipo_ids = $wpdb->get_col($wpdb->prepare("SELECT user_id FROM {$wpdb->prefix}ig_responsables WHERE sede=%s AND grupo=%s", $t->sede, $t->grupo));
                        $equipo = [];
                        foreach($equipo_ids as $eq_id) { $usr = get_userdata($eq_id); if($usr) $equipo[] = $usr; }
                        if($tiene_subtareas): ?>
                            <details style="margin-bottom:10px; border:1px solid #cbd5e1; border-radius:6px; overflow:hidden;">
                                <summary style="background:#fff; padding:8px 12px; font-size:11px; font-weight:bold; color:#475569; cursor:pointer; list-style:none;">📋 Ver Subtareas Asignadas (<?php echo count($t->subtareas); ?>)</summary>
                                <div style="background:#f1f5f9; padding:10px; border-top:1px solid #e2e8f0;">
                                    <?php foreach($t->subtareas as $st): 
                                        if($es_operativo && $st->user_id != $uid) continue; 
                                        $u_op = $st->user_id > 0 ? get_userdata($st->user_id) : false;
                                        $es_huerfana = ($st->user_id == 0);
                                    ?>
                                        <div class="ig-subtask-box" <?php if($es_huerfana) echo 'style="border-left-color:#e53e3e; background:#fff5f5;"'; ?>>
                                            <strong><?php echo esc_html($st->subtarea); ?></strong><br>
                                            <?php if($es_huerfana && !$es_operativo): ?>
                                                <span style="color:#e53e3e; font-weight:bold;">⚠️ Requiere Reasignación</span>
                                                <form method="post" style="display:flex; gap:5px; margin-top:5px;"><input type="hidden" name="subtarea_id" value="<?php echo $st->id; ?>"><select name="nuevo_operativo" style="font-size:10px; padding:2px;" required><option value="">Seleccionar relevo...</option><?php foreach($equipo as $miembro) echo "<option value='{$miembro->ID}'>{$miembro->display_name}</option>"; ?></select><button type="submit" name="ig_reasignar_subtarea" class="ig-btn" style="padding:2px 5px; font-size:9px; background:#e53e3e;">Reasignar</button></form>
                                            <?php else: ?>
                                                <span style="color:#64748b;">👤 <?php echo $u_op ? $u_op->display_name : 'Desconocido'; ?> | 📅 <?php echo $st->fecha_final; ?></span><br>
                                                <?php if(!$es_operativo && $st->porcentaje < 100): ?>
                                                    <details style="margin:2px 0 5px 0;"><summary style="font-size:9px; color:#2271b1; cursor:pointer; list-style:none;">🔄 Cambiar responsable</summary><form method="post" style="display:flex; gap:5px; margin-top:3px;"><input type="hidden" name="subtarea_id" value="<?php echo $st->id; ?>"><select name="nuevo_operativo" style="font-size:9px; padding:2px;" required><option value="">Seleccionar del equipo...</option><?php foreach($equipo as $miembro): if($miembro->ID != $st->user_id): ?><option value="<?php echo $miembro->ID; ?>"><?php echo $miembro->display_name; ?></option><?php endif; endforeach; ?></select><button type="submit" name="ig_reasignar_subtarea" class="ig-btn" style="padding:2px 5px; font-size:9px;">Aplicar</button></form></details>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                            <div style="display:flex; justify-content:space-between; align-items:center; margin-top:5px;">
                                                <span style="font-weight:bold; color:#0073aa;"><?php echo $st->porcentaje; ?>%</span>
                                                <?php if($st->porcentaje < 100 && ($uid == $st->user_id || current_user_can('coordinador_grupo') || $es_gerente_o_admin)): ?>
                                                <form method="post" style="display:flex; gap:5px;"><input type="hidden" name="subtarea_id" value="<?php echo $st->id; ?>"><input type="hidden" name="tarea_padre_id" value="<?php echo $t->id; ?>"><input type="number" name="nuevo_porcentaje" value="<?php echo $st->porcentaje; ?>" max="100" style="width:40px; font-size:10px; padding:2px;"><input type="text" name="observacion_texto" placeholder="Avance..." style="width:80px; font-size:10px; padding:2px;"><button type="submit" name="ig_actualizar_subtarea" class="ig-btn" style="padding:2px 5px; font-size:9px;">OK</button></form>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </details>
                        <?php endif; ?>
                        <?php if (!$es_operativo): ?>
                            <?php if ($p < 100): ?>
                            <div class="ig-form-box" style="background:#fff; border:1px solid #e2e8f0;">
                                <details><summary style="font-size:11px; font-weight:bold; color:#0073aa; cursor:pointer; list-style:none;">👥 Delegar Nueva Subtarea</summary><form method="post" style="display:flex; flex-direction:column; gap:5px; margin-top:5px;"><input type="hidden" name="tarea_padre_id" value="<?php echo $t->id; ?>"><select name="st_operativo" style="font-size:11px;" required><option value="">Funcionario...</option><?php foreach($equipo as $op) echo "<option value='{$op->ID}'>{$op->display_name}</option>"; ?></select><input type="text" name="st_nombre" placeholder="Instrucción..." style="font-size:11px;" required><div style="display:flex; gap:5px;"><input type="date" name="st_inicio" style="font-size:10px; flex:1;" required><input type="date" name="st_fin" style="font-size:10px; flex:1;" required></div><button type="submit" name="ig_crear_subtarea" class="ig-btn" style="background:#0073aa;">Crear Subtarea</button></form></details>
                                <?php if(!$tiene_subtareas): ?>
                                <hr style="margin:8px 0; border:0; border-top:1px solid #e2e8f0;"><form method="post" style="display:flex; gap:5px; align-items:center;"><input type="hidden" name="tarea_id" value="<?php echo $t->id; ?>"><input type="number" name="nuevo_porcentaje" value="<?php echo $p; ?>" max="100" style="width:50px; font-size:11px;"><input type="text" name="observacion_texto" placeholder="Nota de avance..." style="flex:1; font-size:11px;"><button type="submit" name="ig_actualizar_porcentaje" class="ig-btn" style="padding:3px 8px; font-size:11px;">Actualizar Avance</button></form>
                                <?php else: ?>
                                <div style="font-size:10px; color:#64748b; margin-top:5px; text-align:center;"><i>*El avance principal se calcula automáticamente promediando las subtareas.</i></div>
                                <?php endif; ?>
                            </div>
                            <?php endif; ?>
                            <?php if ($es_gerente_o_admin): ?>
                            <form method="post" class="ig-form-box" style="background:#f0fdf4; border:1px solid #bbf7d0;"><input type="hidden" name="tarea_id" value="<?php echo $t->id; ?>"><details><summary style="font-size:10px; font-weight:bold; color:#166534; cursor:pointer; list-style:none;">⚙️ Dejar Nota Gerencial</summary><textarea name="observacion_texto" rows="2" placeholder="Escribir directriz o comentario..." style="width:100%; font-size:10px; margin:5px 0;"></textarea><div style="display:flex; gap:5px;"><button type="submit" name="ig_guardar_observacion" class="ig-btn" style="flex:1; background:#16a34a; font-size:10px;">Guardar Nota</button></div></details></form>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </details>
                <?php endforeach; ?>
            </div>
        </div>

        <?php if (!$es_operativo): ?>
        <div id="tab-finalizadas" class="ig-tab-content">
            <h2 style="font-size: 16px; color:#1e293b; margin-bottom: 10px;">✅ Histórico de Éxito (<?php echo count($tareas_finalizadas); ?> Actividades Finalizadas)</h2>
            <div class="ig-task-grid">
                <?php if (empty($tareas_finalizadas)): ?>
                    <div style="padding: 20px; text-align: center; color: #64748b; background: #f8fafc; border-radius: 8px; border: 1px dashed #cbd5e1;">Aún no hay actividades finalizadas en esta selección.</div>
                <?php endif; ?>
                
                <?php foreach ($tareas_finalizadas as $t): $p = intval($t->porcentaje); $tiene_subtareas = !empty($t->subtareas); ?>
                <details class="ig-task-item" id="task-<?php echo $t->id; ?>" style="border-left-color:<?php echo $t->color; ?>; opacity: 0.85;">
                    <summary>
                        <div style="width:60px; text-align:center;"><span class="ig-badge"><?php echo $t->origen; ?></span><br><small style="color:#94a3b8; font-weight:bold;">#<?php echo $t->id; ?></small></div>
                        <div style="flex:2; min-width:200px; font-weight:bold; font-size:14px; color:#1e293b;"><?php echo $t->tarea; ?></div>
                        <div style="flex:1; min-width:120px; font-size:11px; color:#64748b;">📍 <?php echo $t->sede; ?><br><?php echo $t->grupo; ?></div>
                        <div style="flex:1; min-width:120px; font-size:11px; color:#64748b;">📅 Finalizó:<br><strong style="color:#334155;"><?php echo $t->fecha_final; ?></strong></div>
                        <div style="flex:1; min-width:120px;"><div style="font-size:11px; font-weight:bold; color:<?php echo $t->color; ?>;"><?php echo $t->txt; ?></div><div class="ig-progress-bar"><div class="ig-progress-fill" style="width:<?php echo $p; ?>%; background:<?php echo $t->color; ?>;"></div></div></div>
                        <div style="width:20px; text-align:center; color:#cbd5e1; font-size:10px;">▼</div>
                    </summary>
                    <div style="padding:15px; border-top:1px solid #e2e8f0; background:#f8fafc;">
                        
                        <?php if ($es_gerente_o_admin): ?>
                        <div class="ig-form-box" style="background:#fff3cd; border:1px solid #ffeeba; margin-bottom: 15px;">
                            <details>
                                <summary style="font-size:11px; font-weight:bold; color:#856404; cursor:pointer; list-style:none;">⚙️ Administrar Tarea Base (Editar / Eliminar)</summary>
                                <div style="margin-top: 10px; padding-top: 10px; border-top: 1px dashed #ffeeba;">
                                    <form method="post" style="display:flex; flex-direction:column; gap:8px;">
                                        <input type="hidden" name="tarea_id" value="<?php echo $t->id; ?>">
                                        <div class="ig-flex">
                                            <select name="t_sede_edit" class="ig-input" required>
                                                <?php foreach($sedes as $s) echo "<option value='".esc_attr($s)."' ".selected($t->sede, $s, false).">".esc_html($s)."</option>"; ?>
                                            </select>
                                            <select name="t_grupo_edit" class="ig-input" required>
                                                <?php foreach($grupos as $g) echo "<option value='".esc_attr($g)."' ".selected($t->grupo, $g, false).">".esc_html($g)."</option>"; ?>
                                            </select>
                                        </div>
                                        <input type="text" name="t_nombre_edit" value="<?php echo esc_attr($t->tarea); ?>" class="ig-input" required>
                                        
                                        <div class="ig-flex">
                                            <input type="date" name="t_inicio_edit" value="<?php echo esc_attr($t->fecha_inicio); ?>" class="ig-input" required title="Fecha de Inicio">
                                            <input type="date" name="t_fin_edit" value="<?php echo esc_attr($t->fecha_final); ?>" class="ig-input" required title="Fecha Límite">
                                        </div>

                                        <div class="ig-flex">
                                            <button type="submit" name="ig_editar_tarea_admin" class="ig-btn" style="flex:2; background:#e0a800; color:#212529;">Actualizar Datos</button>
                                            <button type="submit" name="ig_eliminar_tarea_admin" class="ig-btn" style="flex:1; background:#c82333;" onclick="return confirm('🚨 ¿ESTÁS SEGURO? Esta acción borrará la tarea y todas sus subtareas permanentemente.');">🗑️ Eliminar</button>
                                        </div>
                                    </form>
                                </div>
                            </details>
                        </div>
                        <?php endif; ?>

                        <?php if(!empty($t->observacion)): ?>
                        <details style="margin-bottom:10px; border:1px solid #cbd5e1; border-radius:6px; overflow:hidden;">
                            <summary style="background:#fff; padding:8px 12px; font-size:11px; font-weight:bold; color:#475569; cursor:pointer; list-style:none;">💬 Ver Bitácora Consolidada</summary>
                            <div style="padding:12px; font-size:11px; background:#fff; border-top:1px solid #e2e8f0;"><?php echo wp_kses_post($t->observacion); ?></div>
                        </details>
                        <?php endif; ?>
                        <?php 
                        $equipo_ids = $wpdb->get_col($wpdb->prepare("SELECT user_id FROM {$wpdb->prefix}ig_responsables WHERE sede=%s AND grupo=%s", $t->sede, $t->grupo));
                        $equipo = [];
                        foreach($equipo_ids as $eq_id) { $usr = get_userdata($eq_id); if($usr) $equipo[] = $usr; }
                        if($tiene_subtareas): ?>
                            <details style="margin-bottom:10px; border:1px solid #cbd5e1; border-radius:6px; overflow:hidden;">
                                <summary style="background:#fff; padding:8px 12px; font-size:11px; font-weight:bold; color:#475569; cursor:pointer; list-style:none;">📋 Ver Subtareas Asignadas (<?php echo count($t->subtareas); ?>)</summary>
                                <div style="background:#f1f5f9; padding:10px; border-top:1px solid #e2e8f0;">
                                    <?php foreach($t->subtareas as $st): 
                                        if($es_operativo && $st->user_id != $uid) continue; 
                                        $u_op = $st->user_id > 0 ? get_userdata($st->user_id) : false;
                                    ?>
                                        <div class="ig-subtask-box" style="border-left-color:#718096;">
                                            <strong><?php echo esc_html($st->subtarea); ?></strong><br>
                                            <span style="color:#64748b;">👤 <?php echo $u_op ? $u_op->display_name : 'Desconocido'; ?> | 📅 <?php echo $st->fecha_final; ?></span><br>
                                            <div style="display:flex; justify-content:space-between; align-items:center; margin-top:5px;">
                                                <span style="font-weight:bold; color:#718096;"><?php echo $st->porcentaje; ?>%</span>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </details>
                        <?php endif; ?>
                    </div>
                </details>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <script>
            window.ig_cambiarVistaGantt = function() {
                if (window.ig_miGantt) {
                    var vista = document.getElementById('ig-gantt-view').value;
                    window.ig_miGantt.change_view_mode(vista);
                }
            };

            window.ig_filtrarTareas = function() {
                var textoBusqueda = document.getElementById('ig-search-task') ? document.getElementById('ig-search-task').value.toLowerCase() : '';
                var estadoSeleccionado = document.getElementById('ig-filter-status') ? document.getElementById('ig-filter-status').value : '';
                
                var tarjetas = document.querySelectorAll('#ig-contenedor-tareas .ig-task-item');
                var contadorVisibles = 0;
                
                tarjetas.forEach(function(tarjeta) {
                    var contenido = tarjeta.innerText.toLowerCase(); 
                    var estadoTarjeta = tarjeta.getAttribute('data-estado');
                    
                    var coincideTexto = (contenido.indexOf(textoBusqueda) > -1);
                    var coincideEstado = (estadoSeleccionado === '' || estadoTarjeta === estadoSeleccionado);
                    
                    if (coincideTexto && coincideEstado) {
                        tarjeta.style.display = '';
                        contadorVisibles++;
                    } else {
                        tarjeta.style.display = 'none';
                    }
                });
                
                var h2 = document.getElementById('ig-contador-activas');
                if(h2) h2.innerText = "📋 Desglose Operativo (" + contadorVisibles + " Actividades Visibles)";

                if (window.ig_miGantt && window.ig_gantt_data_original) {
                    var ganttFiltrado = window.ig_gantt_data_original.filter(function(t) {
                        var coincideTextoGantt = (t.name.toLowerCase().indexOf(textoBusqueda) > -1);
                        var coincideEstadoGantt = (estadoSeleccionado === '' || t.estado_calc === estadoSeleccionado);
                        return coincideTextoGantt && coincideEstadoGantt;
                    });
                    
                    try {
                        window.ig_miGantt.refresh(ganttFiltrado);
                    } catch(e) { console.warn('Gantt se quedó sin tareas.'); }
                }
            };

            window.ig_switchTab = function(tabId, btnElement) {
                try {
                    var tabs = document.querySelectorAll(".ig-tab-content");
                    tabs.forEach(function(el) { el.classList.remove("active"); });
                    
                    var btns = document.querySelectorAll(".ig-tab-btn");
                    btns.forEach(function(el) { el.classList.remove("active"); });
                    
                    var targetTab = document.getElementById(tabId);
                    if(targetTab) targetTab.classList.add("active");
                    if(btnElement) btnElement.classList.add("active");
                    
                    if (tabId === 'tab-tareas') {
                        setTimeout(function() { window.ig_iniciarGantt(); }, 100); 
                    }
                } catch(e) { console.error("Error UI:", e); }
            };

            window.ig_iniciarGantt = function() {
                if (typeof Gantt === 'undefined' || window.ig_miGantt) return;
                
                if (!window.ig_gantt_data_original) {
                    window.ig_gantt_data_original = <?php echo json_encode($gantt_data) ?: '[]'; ?>;
                    window.ig_gantt_data_original.forEach(function(t) { t._orig_start = t.start; t._orig_end = t.end; });
                }
                
                var textoBusqueda = document.getElementById('ig-search-task') ? document.getElementById('ig-search-task').value.toLowerCase() : '';
                var estadoSeleccionado = document.getElementById('ig-filter-status') ? document.getElementById('ig-filter-status').value : '';
                
                var tData = window.ig_gantt_data_original.filter(function(t) {
                    var coincideTexto = (t.name.toLowerCase().indexOf(textoBusqueda) > -1);
                    var coincideEstado = (estadoSeleccionado === '' || t.estado_calc === estadoSeleccionado);
                    return coincideTexto && coincideEstado;
                });

                if(tData && tData.length > 0) {
                    try {
                        var modoVista = document.getElementById('ig-gantt-view') ? document.getElementById('ig-gantt-view').value : 'Week';

                        window.ig_miGantt = new Gantt("#gantt", tData, {
                            view_mode: modoVista, 
                            language: 'es',
                            on_date_change: function(task, start, end) {
                                <?php if (!$es_gerente_o_admin): ?>
                                    alert('⛔ Solo el Gerente está autorizado para modificar las fechas del cronograma.');
                                    task.start = task._orig_start; task.end = task._orig_end; window.ig_miGantt.refresh(tData); return;
                                <?php endif; ?>

                                var confirmar = confirm('¿Estás seguro de que deseas cambiar las fechas de:\n"' + task.name + '"?');
                                if (!confirmar) { task.start = task._orig_start; task.end = task._orig_end; window.ig_miGantt.refresh(tData); return; }

                                var s_str = start.getFullYear() + '-' + String(start.getMonth() + 1).padStart(2, '0') + '-' + String(start.getDate()).padStart(2, '0');
                                var endAdj = new Date(end.getTime() - 1000);
                                var e_str = endAdj.getFullYear() + '-' + String(endAdj.getMonth() + 1).padStart(2, '0') + '-' + String(endAdj.getDate()).padStart(2, '0');
                                
                                fetch('<?php echo admin_url('admin-ajax.php'); ?>', {
                                    method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                                    body: new URLSearchParams({ action: 'ig_update_task_dates', task_id: task.id, start: s_str, end: e_str })
                                }).then(r => r.json()).then(res => {
                                    if(res.success) {
                                        task._orig_start = start; task._orig_end = end;
                                        var cleanId = task.id.replace('T', '');
                                        var tStatus = document.getElementById('task-status-' + cleanId);
                                        if (tStatus) { tStatus.innerText = res.data.texto; tStatus.style.color = res.data.color; }
                                        task.custom_class = res.data.css_class; window.ig_miGantt.refresh(tData);
                                    } else { task.start = task._orig_start; task.end = task._orig_end; window.ig_miGantt.refresh(tData); }
                                }).catch(e => { task.start = task._orig_start; task.end = task._orig_end; window.ig_miGantt.refresh(tData); });
                            },
                            on_click: function(task) {
                                var cleanId = 'task-' + task.id.replace('T', '');
                                var target = document.getElementById(cleanId);
                                if (target) { 
                                    target.scrollIntoView({ behavior: 'smooth', block: 'center' }); 
                                    target.setAttribute('open', 'true');
                                    target.classList.add('ig-task-focused'); 
                                    setTimeout(function() { target.classList.remove('ig-task-focused'); }, 2000); 
                                }
                            }
                        });

                        var gtCont = document.querySelector('.gantt-container');
                        var barras = document.querySelectorAll('.gantt .bar-wrapper'); 
                        if (gtCont && barras.length > 0) {
                            var minLeft = Infinity;
                            var pBar = null;
                            barras.forEach(function(b) {
                                var rect = b.getBoundingClientRect();
                                if (rect.left < minLeft) { minLeft = rect.left; pBar = b; }
                            });
                            if (pBar) {
                                gtCont.scrollLeft += (pBar.getBoundingClientRect().left - gtCont.getBoundingClientRect().left - 20);
                            }
                        }
                    } catch (e) { console.error("Error Gantt:", e); }
                }
            };

            window.ig_iniciarGraficos = function() {
                try {
                    if (typeof Chart === 'undefined') return;
                    <?php if (!empty($datasets_finanzas)): ?>
                    if(document.getElementById('igChartFinanzas')) {
                        new Chart(document.getElementById('igChartFinanzas'), { 
                            type: 'line', 
                            data: { labels: ['Ingresos', 'Gastos'], datasets: <?php echo json_encode($datasets_finanzas); ?> },
                            options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: true, position: 'bottom' } } } 
                        });
                    }
                    <?php endif; ?>

                    <?php if (!empty($chart_labels)): ?>
                    if(document.getElementById('igChartSeparado')) {
                        new Chart(document.getElementById('igChartSeparado'), {
                            type: 'bar',
                            data: {
                                labels: <?php echo json_encode($chart_labels); ?>,
                                datasets: [
                                    {label: '🔴', data: <?php echo json_encode($d_ven); ?>, backgroundColor: '#e53e3e'},
                                    {label: '🟠', data: <?php echo json_encode($d_urg); ?>, backgroundColor: '#ed8936'},
                                    {label: '🟡', data: <?php echo json_encode($d_cri); ?>, backgroundColor: '#ecc94b'},
                                    {label: '🟢', data: <?php echo json_encode($d_ati); ?>, backgroundColor: '#28a745'},
                                    {label: '⚪', data: <?php echo json_encode($d_fin); ?>, backgroundColor: '#718096'}
                                ]
                            },
                            options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } } }
                        });
                    }
                    <?php endif; ?>
                } catch (e) { console.error("Error ChartJS:", e); }
            };

            window.addEventListener('load', function() {
                setTimeout(window.ig_iniciarGraficos, 200); 
            });
        </script>
    </main>
</div>
