<?php
if (!defined('ABSPATH')) exit;
global $wpdb;

// Control estricto de roles
$user = wp_get_current_user();
$roles = (array) $user->roles;
$es_gerente_o_admin = in_array('administrator', $roles) || in_array('gerente', $roles);
$es_operativo = in_array('funcionario_operativo', $roles) && !$es_gerente_o_admin;
$uid = get_current_user_id();

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

// PROCESAMIENTO TÉRMICO Y ORDENAMIENTO
$tareas_raw = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}ig_tareas $where");
$tareas = []; $gantt_data = []; $hoy = new DateTime(current_time('Y-m-d'));
$stats_operativas = []; 

if ($tareas_raw) {
    foreach ($tareas_raw as $t) {
        $fb = $t->fecha_final ?: 'today'; $fin = new DateTime($fb); 
        $diff = (int) $hoy->diff($fin)->format('%r%a');
        $p = intval($t->porcentaje ?? 0);
        
        if ($p >= 100) { $st = 'finalizada'; $orden = 5; $color = "#718096"; $txt = "Finalizada"; $css_class = "bar-gray"; }
        elseif ($diff < 0) { $st = 'vencida'; $orden = 1; $color = "#e53e3e"; $txt = "Vencida (".abs($diff)."d)"; $css_class = "bar-red"; }
        elseif ($diff <= 3) { $st = 'urgente'; $orden = 2; $color = "#ed8936"; $txt = "Urgente"; $css_class = "bar-orange"; }
        elseif ($diff <= 7) { $st = 'critica'; $orden = 3; $color = "#ecc94b"; $txt = "Crítica"; $css_class = "bar-yellow"; }
        else { $st = 'a_tiempo'; $orden = 4; $color = "#28a745"; $txt = "Al día"; $css_class = "bar-green"; }
        
        $lbl = $t->sede . ' (' . $t->grupo . ')';
        if (!isset($stats_operativas[$lbl])) { $stats_operativas[$lbl] = ['vencida'=>0, 'urgente'=>0, 'critica'=>0, 'a_tiempo'=>0, 'finalizada'=>0]; }
        $stats_operativas[$lbl][$st]++;

        if ($f_estado && $st !== $f_estado) continue;
        
        $t->estado_calc = $st; $t->orden = $orden; $t->color = $color; $t->txt = $txt;
        $t->subtareas = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$wpdb->prefix}ig_subtareas WHERE tarea_id = %d", $t->id));
        $tareas[] = $t;

        $gantt_data[] = [
            'id' => 'T'.$t->id,
            'name' => mb_strimwidth($t->tarea, 0, 30, '...'),
            'start' => $t->fecha_inicio ?: date('Y-m-d'),
            'end' => $t->fecha_final ?: date('Y-m-d', strtotime('+1 day')),
            'progress' => $p,
            'custom_class' => $css_class
        ];
    }
}

usort($tareas, function($a, $b) {
    if ($a->orden == $b->orden) { return strtotime($a->fecha_final ?? 0) - strtotime($b->fecha_final ?? 0); }
    return $a->orden - $b->orden;
});

// MOTOR FINANCIERO (LÍNEAS EXCEL)
$datasets_finanzas = [];
$chart_labels = []; $d_ven = []; $d_urg = []; $d_cri = []; $d_ati = []; $d_fin = []; 

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
                $diff_i = $r->ingreso - $r->ant_i; $diff_g = $r->gasto - $r->ant_g;
                $r->pct_i = ($r->ant_i > 0) ? round(($diff_i / $r->ant_i) * 100, 1) : ($r->ingreso > 0 ? 100 : 0);
                $r->pct_g = ($r->ant_g > 0) ? round(($diff_g / $r->ant_g) * 100, 1) : ($r->gasto > 0 ? 100 : 0);
                $r->hist = $ant ? true : false; $datos_finales[$key] = $r;
                
                if($r->ingreso > 0 || $r->gasto > 0){
                    $c_act = $colores_act[$idx_color % count($colores_act)];
                    $c_ant = $colores_ant[$idx_color % count($colores_ant)];

                    $datasets_finanzas[] = [
                        'label' => $r->sede . ' (' . $r->grupo . ') - Última',
                        'data' => [$r->ingreso, $r->gasto],
                        'borderColor' => $c_act, 'backgroundColor' => $c_act, 'tension' => 0, 'pointRadius' => 6, 'pointHoverRadius' => 8
                    ];
                    
                    if ($r->hist) {
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

<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/frappe-gantt/0.6.1/frappe-gantt.css">
<script src="https://cdnjs.cloudflare.com/ajax/libs/frappe-gantt/0.6.1/frappe-gantt.min.js"></script>

<style>
    .ig-container { font-family: sans-serif; max-width: 1100px; margin: 0 auto; color: #333; }
    .ig-card { background: #fff; border: 1px solid #ddd; border-radius: 10px; padding: 20px; margin-bottom: 20px; box-shadow: 0 2px 5px rgba(0,0,0,0.05); }
    .ig-flex { display: flex; flex-wrap: wrap; gap: 10px; align-items: center; }
    .ig-input { padding: 8px; border: 1px solid #ccc; border-radius: 5px; flex: 1; min-width: 140px; font-size: 13px; }
    .ig-btn { background: #2271b1; color: #fff; border: none; padding: 8px 15px; border-radius: 5px; cursor: pointer; font-weight: bold; font-size: 12px; }
    
    /* CAMBIO VISUAL: TAREAS HORIZONTALES (LISTA) */
    .ig-task-grid { display: flex; flex-direction: column; gap: 10px; }
    
    /* MODIFICACIÓN PARA QUE LA TAREA SEA UN ACORDEÓN COMPLETO */
    .ig-task-item { border: 1px solid #e2e8f0; border-radius: 8px; background: #fff; border-left: 6px solid #ccc; position:relative; transition: background-color 0.8s ease, transform 0.2s, border-left-color 0.5s; overflow:hidden; }
    .ig-task-focused { background-color: #fffbe5 !important; transform: scale(1.01); border-color: #ecc94b; }
    .ig-task-item summary { padding: 15px; cursor: pointer; outline: none; list-style: none; display: flex; flex-wrap: wrap; align-items: center; gap: 15px; background: #fff; transition: background 0.2s; }
    .ig-task-item summary::-webkit-details-marker { display: none; } /* Ocultar flecha nativa */
    .ig-task-item summary:hover { background: #f8fafc; }
    .ig-task-item[open] summary { border-bottom: 1px solid #e2e8f0; background: #f8fafc; }
    
    .ig-progress-bar { background: #eee; height: 6px; border-radius: 3px; overflow: hidden; margin-top:4px; }
    .ig-progress-fill { height: 100%; transition: background-color 0.5s; }
    .ig-badge { font-size: 9px; padding: 2px 5px; background: #e2e8f0; border-radius: 3px; font-weight: bold; text-transform: uppercase; }
    .ig-form-box { background:#f9f9f9; padding:10px; border-radius:6px; border:1px solid #eee; margin-top:10px; }
    .ig-subtask-box { background:#fff; border-left:3px solid #0073aa; padding:10px; margin-top:8px; font-size:11px; border-radius:4px; border-right:1px solid #eee; border-top:1px solid #eee; border-bottom:1px solid #eee; }
    
    .ig-diff { font-size: 10px; padding: 2px 4px; border-radius: 4px; font-weight: bold; margin-left: 5px; display: inline-block; }
    .up { color: #155724; background: #d4edda; } .down { color: #721c24; background: #f8d7da; } .neutral { color: #383d41; background: #e2e3e5; }

    .gantt .bar-red .bar { fill: #e53e3e; } .gantt .bar-red .bar-progress { fill: #c53030; }
    .gantt .bar-orange .bar { fill: #ed8936; } .gantt .bar-orange .bar-progress { fill: #dd6b20; }
    .gantt .bar-yellow .bar { fill: #ecc94b; } .gantt .bar-yellow .bar-progress { fill: #d69e2e; }
    .gantt .bar-green .bar { fill: #48bb78; } .gantt .bar-green .bar-progress { fill: #38a169; }
    .gantt .bar-gray .bar { fill: #a0aec0; } .gantt .bar-gray .bar-progress { fill: #718096; }
    
    .ig-date-updated { color: #e53e3e; transition: color 1s ease; }

    .ig-accordion { margin-bottom: 15px; border: 1px solid #e2e8f0; border-radius: 8px; overflow: hidden; background: #fff; box-shadow: 0 1px 3px rgba(0,0,0,0.05); }
    .ig-accordion summary { background: #f8fafc; padding: 12px 15px; font-weight: bold; color: #334155; cursor: pointer; list-style: none; font-size: 14px; display: flex; align-items: center; border-bottom: 1px solid transparent; }
    .ig-accordion summary::-webkit-details-marker { display: none; }
    .ig-accordion summary::before { content: '▶'; margin-right: 10px; font-size: 10px; color: #94a3b8; transition: transform 0.2s; }
    .ig-accordion[open] summary { border-bottom: 1px solid #e2e8f0; }
    .ig-accordion[open] summary::before { transform: rotate(90deg); }
    .ig-accordion-content { padding: 15px; }
</style>

<div class="ig-container">
    <div class="ig-card">
        <form method="get" class="ig-flex">
            <strong>Filtros:</strong>
            <?php if(!$es_operativo): ?>
            <select name="f_sede" class="ig-input"><option value="">Sede...</option><?php foreach($sedes as $s) echo "<option value='$s' ".selected($f_sede,$s,false).">$s</option>"; ?></select>
            <select name="f_grupo" class="ig-input"><option value="">Grupo...</option><?php foreach($grupos as $g) echo "<option value='$g' ".selected($f_grupo,$g,false).">$g</option>"; ?></select>
            <?php endif; ?>
            <select name="f_estado" class="ig-input">
                <option value="">Prioridad...</option>
                <option value="vencida" <?php selected($f_estado,'vencida');?>>🔴 Vencida</option>
                <option value="urgente" <?php selected($f_estado,'urgente');?>>🟠 Urgente</option>
                <option value="critica" <?php selected($f_estado,'critica');?>>🟡 Crítica</option>
                <option value="a_tiempo" <?php selected($f_estado,'a_tiempo');?>>🟢 Al día</option>
                <option value="finalizada" <?php selected($f_estado,'finalizada');?>>⚪ Finalizada</option>
            </select>
            <button type="submit" class="ig-btn">Filtrar</button>
            <a href="<?php echo strtok($_SERVER["REQUEST_URI"], '?'); ?>" style="color:red; font-size:12px;">Limpiar</a>
        </form>
    </div>

    <?php if (!$es_operativo && (!empty($datos_finales) || !empty($gantt_data))): ?>
    <div class="ig-card" style="padding: 20px 20px 5px 20px; background: #f1f5f9; border: none;">
        <h2 style="margin-top:0; margin-bottom:15px; font-size:18px; color:#1e293b;">📊 Paneles de Mando Analíticos</h2>

        <?php if (!empty($datos_finales)): ?>
        <details class="ig-accordion" open>
            <summary>📈 Balance Financiero (Pendiente de Ingresos vs Gastos)</summary>
            <div class="ig-accordion-content">
                <div style="height:320px; margin-bottom:15px;"><canvas id="igChartFinanzas"></canvas></div>
                <table style="width:100%; border-collapse:collapse; font-size:13px;">
                    <thead><tr style="background:#f7fafc; border-bottom:2px solid #edf2f7;"> <th style="padding:12px; text-align:left;">Área</th><th>Ingresos (Variación)</th><th>Gastos (Variación)</th> </tr></thead>
                    <tbody>
                        <?php foreach($datos_finales as $r): ?>
                        <tr style="border-bottom:1px solid #edf2f7;">
                            <td style="padding:12px;"><strong><?php echo esc_html($r->sede); ?></strong><br><small><?php echo esc_html($r->grupo); ?></small></td>
                            <?php if ($r->hist): 
                                $ci = ($r->ingreso > $r->ant_i) ? 'up' : (($r->ingreso < $r->ant_i) ? 'down' : 'neutral'); $fi = ($r->ingreso > $r->ant_i) ? '▲' : (($r->ingreso < $r->ant_i) ? '▼' : '-');
                                $cg = ($r->gasto > $r->ant_g) ? 'down' : (($r->gasto < $r->ant_g) ? 'up' : 'neutral'); $fg = ($r->gasto > $r->ant_g) ? '▲' : (($r->gasto < $r->ant_g) ? '▼' : '-');
                            ?>
                                <td style="padding:12px;"><span style="color:#2f855a; font-weight:bold; font-size:14px;">$<?php echo number_format($r->ingreso); ?></span><br><small style="color:#718096;">Ant: $<?php echo number_format($r->ant_i); ?></small><span class="ig-diff <?php echo $ci; ?>"><?php echo $fi; ?> <?php echo abs($r->pct_i); ?>%</span></td>
                                <td style="padding:12px;"><span style="color:#c53030; font-weight:bold; font-size:14px;">$<?php echo number_format($r->gasto); ?></span><br><small style="color:#718096;">Ant: $<?php echo number_format($r->ant_g); ?></small><span class="ig-diff <?php echo $cg; ?>"><?php echo $fg; ?> <?php echo abs($r->pct_g); ?>%</span></td>
                            <?php else: ?>
                                <td style="padding:12px;"><span style="color:#2f855a; font-weight:bold; font-size:14px;">$<?php echo number_format($r->ingreso); ?></span><br><span class="ig-diff neutral">Registro Inicial</span></td>
                                <td style="padding:12px;"><span style="color:#c53030; font-weight:bold; font-size:14px;">$<?php echo number_format($r->gasto); ?></span><br><span class="ig-diff neutral">Registro Inicial</span></td>
                            <?php endif; ?>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </details>
        <?php endif; ?>

        <?php if (!empty($stats_operativas)): ?>
        <details class="ig-accordion">
            <summary>🌡️ Grafica de Tareas</summary>
            <div class="ig-accordion-content" style="height:280px;">
                <canvas id="igChartSeparado"></canvas>
            </div>
        </details>
        <?php endif; ?>

        <?php if (!empty($gantt_data)): ?>
        <details class="ig-accordion" id="ganttAccordion">
            <summary>📆 Cronograma General Interactivo</summary>
            <div class="ig-accordion-content" style="overflow-x:auto;">
                <svg id="gantt"></svg>
            </div>
        </details>
        <?php endif; ?>
    </div>
    
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            <?php if (!empty($datasets_finanzas)): ?>
            new Chart(document.getElementById('igChartFinanzas'), { 
                type: 'line', 
                data: { labels: ['Ingresos', 'Gastos'], datasets: <?php echo json_encode($datasets_finanzas); ?> },
                options: { responsive: true, maintainAspectRatio: false, interaction: { mode: 'index', intersect: false }, plugins: { tooltip: { callbacks: { label: function(ctx) { return ctx.dataset.label + ': $' + ctx.raw.toLocaleString(); } } } }, scales: { y: { beginAtZero: true } } } 
            });
            <?php endif; ?>

            <?php if (!empty($chart_labels)): ?>
            new Chart(document.getElementById('igChartSeparado'), {
                type: 'bar',
                data: {
                    labels: <?php echo json_encode($chart_labels); ?>,
                    datasets: [
                        {label: '🔴 Vencidas', data: <?php echo json_encode($d_ven); ?>, backgroundColor: '#e53e3e'},
                        {label: '🟠 Urgentes', data: <?php echo json_encode($d_urg); ?>, backgroundColor: '#ed8936'},
                        {label: '🟡 Críticas', data: <?php echo json_encode($d_cri); ?>, backgroundColor: '#ecc94b'},
                        {label: '🟢 Al día', data: <?php echo json_encode($d_ati); ?>, backgroundColor: '#28a745'},
                        {label: '⚪ Finalizadas', data: <?php echo json_encode($d_fin); ?>, backgroundColor: '#718096'}
                    ]
                },
                options: { responsive: true, maintainAspectRatio: false, scales: { x: { stacked: false }, y: { stacked: false, beginAtZero: true, ticks: { stepSize: 1 } } } }
            });
            <?php endif; ?>
        });

        <?php if (!empty($gantt_data)): ?>
        const ganttAccordion = document.getElementById('ganttAccordion');
        let isGanttLoaded = false;
        
        ganttAccordion.addEventListener('toggle', function(e) {
            if (ganttAccordion.open && !isGanttLoaded) {
                isGanttLoaded = true;
                var tasks = <?php echo json_encode($gantt_data); ?>;
                tasks.forEach(function(t) { t._orig_start = t.start; t._orig_end = t.end; });

                var gantt = new Gantt("#gantt", tasks, {
                    view_mode: 'Day', language: 'es',
                    on_date_change: function(task, start, end) {
                        var confirmar = confirm('¿Estás seguro de que deseas cambiar las fechas de la actividad:\n"' + task.name + '"?');
                        if (!confirmar) { task.start = task._orig_start; task.end = task._orig_end; gantt.refresh(tasks); return; }

                        var s_str = start.getFullYear() + '-' + String(start.getMonth() + 1).padStart(2, '0') + '-' + String(start.getDate()).padStart(2, '0');
                        var endAdjusted = new Date(end.getTime() - 1000);
                        var e_str = endAdjusted.getFullYear() + '-' + String(endAdjusted.getMonth() + 1).padStart(2, '0') + '-' + String(endAdjusted.getDate()).padStart(2, '0');
                        
                        fetch('<?php echo admin_url('admin-ajax.php'); ?>', {
                            method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                            body: new URLSearchParams({ action: 'ig_update_task_dates', task_id: task.id, start: s_str, end: e_str })
                        }).then(res => res.json()).then(res => {
                            if(res.success) {
                                task._orig_start = start; task._orig_end = end;
                                var cleanId = task.id.replace('T', '');
                                var tDate = document.getElementById('task-date-' + cleanId);
                                var tCard = document.getElementById('task-' + cleanId);
                                var tStatus = document.getElementById('task-status-' + cleanId);
                                var tProg = document.getElementById('task-prog-' + cleanId);

                                if (tDate) { tDate.innerText = e_str; tDate.classList.add('ig-date-updated'); setTimeout(function() { tDate.classList.remove('ig-date-updated'); }, 1500); }
                                if (tCard) { tCard.style.borderLeftColor = res.data.color; }
                                if (tStatus) { tStatus.innerText = res.data.texto + ' (' + task.progress + '%)'; tStatus.style.color = res.data.color; }
                                if (tProg) { tProg.style.background = res.data.color; }

                                task.custom_class = res.data.css_class; gantt.refresh(tasks);
                                alert('✅ Base de datos actualizada con éxito.');
                            } else {
                                alert('❌ Error interno. Revirtiendo...'); task.start = task._orig_start; task.end = task._orig_end; gantt.refresh(tasks);
                            }
                        }).catch(error => { alert('❌ Fallo de conexión.'); task.start = task._orig_start; task.end = task._orig_end; gantt.refresh(tasks); });
                    },
                    on_click: function(task) {
                        var cleanId = 'task-' + task.id.replace('T', '');
                        var target = document.getElementById(cleanId);
                        if (target) { 
                            // Hacer scroll y abrir el acordeón de la tarea automáticamente
                            target.scrollIntoView({ behavior: 'smooth', block: 'center' }); 
                            target.setAttribute('open', 'true');
                            target.classList.add('ig-task-focused'); 
                            setTimeout(function() { target.classList.remove('ig-task-focused'); }, 2000); 
                        }
                    }
                });
                document.getElementById('ganttAccordion').addEventListener('click', function(e) { if (e.target.closest('a')) e.preventDefault(); });
            }
        });
        <?php endif; ?>
    </script>
    <?php endif; ?>

    <?php if ($es_gerente_o_admin): ?>
    <div class="ig-card" style="border-top:4px solid #28a745;">
        <h3 style="margin-top:0;">➕ Nueva Tarea Principal</h3>
        <form method="post" class="ig-flex">
            <select name="t_sede" class="ig-input" required><option value="">Sede...</option><?php foreach($sedes as $s) echo "<option value='$s'>$s</option>"; ?></select>
            <select name="t_grupo" class="ig-input" required><option value="">Grupo...</option><?php foreach($grupos as $g) echo "<option value='$g'>$g</option>"; ?></select>
            <input type="text" name="t_nombre" class="ig-input" placeholder="Tarea..." required style="flex:2;">
            <input type="date" name="t_inicio" class="ig-input" required>
            <input type="date" name="t_fin" class="ig-input" required>
            <button type="submit" name="ig_crear_tarea_gerente" class="ig-btn" style="background:#28a745;">Asignar</button>
        </form>
    </div>
    <?php endif; ?>

    <h2>Tablero de Ejecución Operativa</h2>
    <div class="ig-task-grid">
        <?php foreach ($tareas as $t): $p = intval($t->porcentaje); $tiene_subtareas = !empty($t->subtareas); ?>
        
        <details class="ig-task-item" id="task-<?php echo $t->id; ?>" style="border-left-color:<?php echo $t->color; ?>;">
            
            <summary>
                <div style="width:60px; text-align:center;">
                    <span class="ig-badge"><?php echo $t->origen; ?></span><br><small style="color:#94a3b8; font-weight:bold;">#<?php echo $t->id; ?></small>
                </div>
                <div style="flex:2; min-width:200px; font-weight:bold; font-size:14px; color:#1e293b;">
                    <?php echo $t->tarea; ?>
                </div>
                <div style="flex:1; min-width:120px; font-size:11px; color:#64748b;">
                    📍 <?php echo $t->sede; ?><br><?php echo $t->grupo; ?>
                </div>
                <div style="flex:1; min-width:120px; font-size:11px; color:#64748b;">
                    📅 Límite:<br><strong id="task-date-<?php echo $t->id; ?>" style="color:#334155;"><?php echo $t->fecha_final; ?></strong>
                </div>
                <div style="flex:1; min-width:120px;">
                    <div style="font-size:11px; font-weight:bold; color:<?php echo $t->color; ?>;" id="task-status-<?php echo $t->id; ?>"><?php echo $t->txt; ?> (<?php echo $p; ?>%)</div>
                    <div class="ig-progress-bar"><div class="ig-progress-fill" id="task-prog-<?php echo $t->id; ?>" style="width:<?php echo $p; ?>%; background:<?php echo $t->color; ?>;"></div></div>
                </div>
                <div style="width:20px; text-align:center; color:#cbd5e1; font-size:10px;">▼</div>
            </summary>
            
            <div style="padding:15px; border-top:1px solid #e2e8f0; background:#f8fafc;">
            
                <?php if(!empty($t->observacion)): ?>
                <details style="margin-bottom:10px; border:1px solid #cbd5e1; border-radius:6px; overflow:hidden;">
                    <summary style="background:#fff; padding:8px 12px; font-size:11px; font-weight:bold; color:#475569; cursor:pointer; list-style:none;">💬 Ver Bitácora Consolidada</summary>
                    <div style="padding:12px; font-size:11px; background:#fff; border-top:1px solid #e2e8f0;"><?php echo wp_kses_post($t->observacion); ?></div>
                </details>
                <?php endif; ?>

                <?php 
                $equipo_ids = $wpdb->get_col($wpdb->prepare("SELECT user_id FROM {$wpdb->prefix}ig_responsables WHERE sede=%s AND grupo=%s", $t->sede, $t->grupo));
                $equipo = [];
                foreach($equipo_ids as $eq_id) { $usr = get_userdata($eq_id); if($usr && in_array('funcionario_operativo', (array)$usr->roles)) $equipo[] = $usr; }

                if($tiene_subtareas): ?>
                    <details style="margin-bottom:10px; border:1px solid #cbd5e1; border-radius:6px; overflow:hidden;" open>
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
                                        <form method="post" style="display:flex; gap:5px; margin-top:5px;">
                                            <input type="hidden" name="subtarea_id" value="<?php echo $st->id; ?>">
                                            <select name="nuevo_operativo" style="font-size:10px; padding:2px;" required>
                                                <option value="">Seleccionar relevo...</option>
                                                <?php foreach($equipo as $miembro) echo "<option value='{$miembro->ID}'>{$miembro->display_name}</option>"; ?>
                                            </select>
                                            <button type="submit" name="ig_reasignar_subtarea" class="ig-btn" style="padding:2px 5px; font-size:9px; background:#e53e3e;">Reasignar</button>
                                        </form>
                                    <?php else: ?>
                                        <span style="color:#64748b;">👤 <?php echo $u_op ? $u_op->display_name : 'Desconocido'; ?> | 📅 <?php echo $st->fecha_final; ?></span><br>
                                        
                                        <?php if(!$es_operativo && $st->porcentaje < 100): ?>
                                            <details style="margin:2px 0 5px 0;">
                                                <summary style="font-size:9px; color:#2271b1; cursor:pointer; list-style:none;">🔄 Cambiar responsable</summary>
                                                <form method="post" style="display:flex; gap:5px; margin-top:3px;">
                                                    <input type="hidden" name="subtarea_id" value="<?php echo $st->id; ?>">
                                                    <select name="nuevo_operativo" style="font-size:9px; padding:2px;" required>
                                                        <option value="">Seleccionar del equipo...</option>
                                                        <?php foreach($equipo as $miembro): if($miembro->ID != $st->user_id): ?>
                                                            <option value="<?php echo $miembro->ID; ?>"><?php echo $miembro->display_name; ?></option>
                                                        <?php endif; endforeach; ?>
                                                    </select>
                                                    <button type="submit" name="ig_reasignar_subtarea" class="ig-btn" style="padding:2px 5px; font-size:9px;">Aplicar</button>
                                                </form>
                                            </details>
                                        <?php endif; ?>
                                    <?php endif; ?>

                                    <div style="display:flex; justify-content:space-between; align-items:center; margin-top:5px;">
                                        <span style="font-weight:bold; color:#0073aa;"><?php echo $st->porcentaje; ?>%</span>
                                        <?php if($st->porcentaje < 100 && ($uid == $st->user_id || current_user_can('coordinador_grupo') || $es_gerente_o_admin)): ?>
                                        <form method="post" style="display:flex; gap:5px;">
                                            <input type="hidden" name="subtarea_id" value="<?php echo $st->id; ?>">
                                            <input type="hidden" name="tarea_padre_id" value="<?php echo $t->id; ?>">
                                            <input type="number" name="nuevo_porcentaje" value="<?php echo $st->porcentaje; ?>" max="100" style="width:40px; font-size:10px; padding:2px;">
                                            <input type="text" name="observacion_texto" placeholder="Avance..." style="width:80px; font-size:10px; padding:2px;">
                                            <button type="submit" name="ig_actualizar_subtarea" class="ig-btn" style="padding:2px 5px; font-size:9px;">OK</button>
                                        </form>
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
                        <details><summary style="font-size:11px; font-weight:bold; color:#0073aa; cursor:pointer; list-style:none;">👥 Delegar Nueva Subtarea</summary>
                            <form method="post" style="display:flex; flex-direction:column; gap:5px; margin-top:5px;">
                                <input type="hidden" name="tarea_padre_id" value="<?php echo $t->id; ?>">
                                <select name="st_operativo" style="font-size:11px;" required><option value="">Operativo...</option><?php foreach($equipo as $op) echo "<option value='{$op->ID}'>{$op->display_name}</option>"; ?></select>
                                <input type="text" name="st_nombre" placeholder="Instrucción..." style="font-size:11px;" required>
                                <div style="display:flex; gap:5px;"><input type="date" name="st_inicio" style="font-size:10px; flex:1;" required><input type="date" name="st_fin" style="font-size:10px; flex:1;" required></div>
                                <button type="submit" name="ig_crear_subtarea" class="ig-btn" style="background:#0073aa;">Crear Subtarea</button>
                            </form>
                        </details>
                        
                        <?php if(!$tiene_subtareas): ?>
                        <hr style="margin:8px 0; border:0; border-top:1px solid #e2e8f0;">
                        <form method="post" style="display:flex; gap:5px; align-items:center;">
                            <input type="hidden" name="tarea_id" value="<?php echo $t->id; ?>">
                            <input type="number" name="nuevo_porcentaje" value="<?php echo $p; ?>" max="100" style="width:50px; font-size:11px;">
                            <input type="text" name="observacion_texto" placeholder="Nota de avance..." style="flex:1; font-size:11px;">
                            <button type="submit" name="ig_actualizar_porcentaje" class="ig-btn" style="padding:3px 8px; font-size:11px;">Actualizar Avance</button>
                        </form>
                        <?php else: ?>
                        <div style="font-size:10px; color:#64748b; margin-top:5px; text-align:center;"><i>*El avance principal se calcula automáticamente promediando las subtareas.</i></div>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>

                    <?php if ($es_gerente_o_admin): ?>
                    <form method="post" class="ig-form-box" style="background:#f0fdf4; border:1px solid #bbf7d0;">
                        <input type="hidden" name="tarea_id" value="<?php echo $t->id; ?>">
                        <details><summary style="font-size:10px; font-weight:bold; color:#166534; cursor:pointer; list-style:none;">⚙️ Dejar Nota Gerencial</summary>
                        <textarea name="observacion_texto" rows="2" placeholder="Escribir directriz o comentario..." style="width:100%; font-size:10px; margin:5px 0;"></textarea>
                        <div style="display:flex; gap:5px;"><button type="submit" name="ig_guardar_observacion" class="ig-btn" style="flex:1; background:#16a34a; font-size:10px;">Guardar Nota</button></div></details>
                    </form>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
            
        </details>
        <?php endforeach; ?>
    </div>
</div>