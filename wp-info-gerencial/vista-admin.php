<?php
global $wpdb;
$tabla = $wpdb->prefix . 'info_gerencial';
$usuario_actual = wp_get_current_user();
$es_gerente = in_array('gerente', (array) $usuario_actual->roles);

// Lógica de Filtros para la consulta SQL
$where = "WHERE 1=1";
if (!$es_gerente) {
    // Si NO es gerente, solo ve sus propios datos
    $where .= $wpdb->prepare(" AND user_id = %d", get_current_user_id());
} else {
    // Si es gerente, aplicamos filtros si existen
    if (!empty($_GET['filtro_grupo'])) {
        $where .= $wpdb->prepare(" AND grupo = %s", sanitize_text_field($_GET['filtro_grupo']));
    }
    if (!empty($_GET['filtro_fecha'])) {
        $where .= $wpdb->prepare(" AND fecha = %s", sanitize_text_field($_GET['filtro_fecha']));
    }
}

// Obtener datos
$resultados = $wpdb->get_results("SELECT * FROM $tabla $where ORDER BY fecha DESC");

// Preparar totales para el gráfico
$total_ingreso = 0;
$total_gasto = 0;
foreach ($resultados as $fila) {
    $total_ingreso += $fila->ingreso;
    $total_gasto += $fila->gasto;
}
?>

<div class="wrap">
    <h1>Información Gerencial</h1>

    <div style="background:#fff; padding:20px; margin-bottom:20px; border:1px solid #ccc;">
        <h3>Cargar Datos (CSV)</h3>
        <p>El archivo CSV debe tener las columnas (sin cabeceras): <strong>Grupo, Fecha (YYYY-MM-DD), Ingreso, Gasto</strong>.</p>
        <form method="post" enctype="multipart/form-data">
            <input type="file" name="archivo_csv" accept=".csv" required>
            <input type="submit" name="ig_subir_csv" class="button button-primary" value="Subir Archivo">
        </form>
    </div>

    <?php if ($es_gerente): ?>
    <div style="background:#fff; padding:20px; margin-bottom:20px; border:1px solid #ccc;">
        <h3>Filtros de Gerencia</h3>
        <form method="get">
            <input type="hidden" name="page" value="ig-dashboard">
            <label>Grupo: <input type="text" name="filtro_grupo" value="<?php echo isset($_GET['filtro_grupo']) ? esc_attr($_GET['filtro_grupo']) : ''; ?>"></label>
            <label>Fecha: <input type="date" name="filtro_fecha" value="<?php echo isset($_GET['filtro_fecha']) ? esc_attr($_GET['filtro_fecha']) : ''; ?>"></label>
            <input type="submit" class="button" value="Filtrar">
            <a href="?page=ig-dashboard" class="button">Limpiar Filtros</a>
        </form>
    </div>
    <?php endif; ?>

    <div style="background:#fff; padding:20px; margin-bottom:20px; border:1px solid #ccc; max-width: 600px;">
        <h3>Comparativa de Ingresos vs Gastos</h3>
        <button id="btn-cambiar-grafico" class="button">Ver como Torta / Barras</button>
        <canvas id="graficoGerencial" width="400" height="200"></canvas>
        
        <script>
            const datosGrafico = {
                ingresos: <?php echo esc_js($total_ingreso); ?>,
                gastos: <?php echo esc_js($total_gasto); ?>
            };
        </script>
    </div>

    <table class="wp-list-table widefat fixed striped">
        <thead>
            <tr>
                <th>Fecha</th>
                <th>Grupo</th>
                <th>Ingreso</th>
                <th>Gasto</th>
                <th>Observación del Gerente</th>
                <?php if ($es_gerente): ?><th>Añadir Observación</th><?php endif; ?>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($resultados as $fila): ?>
            <tr>
                <td><?php echo esc_html($fila->fecha); ?></td>
                <td><?php echo esc_html($fila->grupo); ?></td>
                <td>$<?php echo esc_html(number_format($fila->ingreso, 2)); ?></td>
                <td>$<?php echo esc_html(number_format($fila->gasto, 2)); ?></td>
                <td><?php echo esc_html($fila->observacion); ?></td>
                
                <?php if ($es_gerente): ?>
                <td>
                    <form method="post" style="display:flex; gap:5px;">
                        <input type="hidden" name="registro_id" value="<?php echo esc_attr($fila->id); ?>">
                        <input type="text" name="observacion_texto" placeholder="Escribe aquí..." required>
                        <input type="submit" name="ig_guardar_observacion" class="button" value="Guardar">
                    </form>
                </td>
                <?php endif; ?>
            </tr>
            <?php endline; ?>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php if ($es_gerente): ?>
    <div style="background:#f0f8ff; padding:20px; margin-bottom:20px; border:1px solid #b0d4f1; border-radius: 5px;">
        <h3>Análisis Inteligente (Impulsado por Gemini AI)</h3>
        <p>Genera un resumen financiero automatizado con base en los datos filtrados en pantalla.</p>
        
        <form method="post">
            <input type="hidden" name="analisis_ingresos" value="<?php echo esc_attr($total_ingreso); ?>">
            <input type="hidden" name="analisis_gastos" value="<?php echo esc_attr($total_gasto); ?>">
            <input type="submit" name="ig_solicitar_analisis" class="button button-primary" value="Generar Análisis Financiero">
        </form>

        <?php
        // Si el gerente hizo clic en el botón, llamamos a la función
        if (isset($_POST['ig_solicitar_analisis'])) {
            $ingresos_analizar = floatval($_POST['analisis_ingresos']);
            $gastos_analizar = floatval($_POST['analisis_gastos']);
            
            echo '<div style="margin-top: 15px; padding: 15px; background: #fff; border-left: 4px solid #2271b1;">';
            echo '<strong>Respuesta de la IA:</strong><br><br>';
            // Llamamos a la función y usamos wpautop para mantener los párrafos bonitos
            echo wpautop(esc_html(ig_analizar_datos_con_ia($ingresos_analizar, $gastos_analizar)));
            echo '</div>';
        }
        ?>
    </div>
    <?php endif; ?>