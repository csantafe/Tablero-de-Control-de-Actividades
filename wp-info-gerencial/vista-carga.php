<?php 
if (isset($_GET['carga']) && $_GET['carga'] == 'resultado') {
    $insertadas = isset($_GET['ok']) ? intval($_GET['ok']) : 0;
    $fallidas = isset($_GET['err']) ? intval($_GET['err']) : 0;
    $db_error = isset($_GET['db_error']) ? sanitize_text_field(urldecode($_GET['db_error'])) : '';

    if ($insertadas > 0 && $fallidas == 0) {
        echo '<div style="background: #d4edda; color: #155724; padding: 15px; border-radius: 5px; margin-bottom: 20px; border-left: 5px solid #28a745;">';
        echo '✅ <strong>¡Éxito total!</strong> Se cargaron ' . $insertadas . ' registros correctamente.</div>';
    } elseif ($insertadas > 0 && $fallidas > 0) {
        echo '<div style="background: #fff3cd; color: #856404; padding: 15px; border-radius: 5px; margin-bottom: 20px; border-left: 5px solid #ffc107;">';
        echo '⚠️ <strong>Carga Parcial:</strong> Se guardaron ' . $insertadas . ' registros, pero <strong>' . $fallidas . ' filas fueron rechazadas</strong>.</div>';
    } else {
        echo '<div style="background: #f8d7da; color: #721c24; padding: 15px; border-radius: 5px; margin-bottom: 20px; border-left: 5px solid #dc3545;">';
        echo '🛑 <strong>Error Crítico:</strong> Ningún registro pudo ser guardado. Revisa que las Sedes y Grupos coincidan exactamente.';
        if ($db_error) {
            echo '<br><br><strong>Error interno de la Base de Datos:</strong> <code>' . esc_html($db_error) . '</code>';
        }
        echo '</div>';
    }
}
?>

<script src="https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js"></script>

<div class="ig-contenedor-carga" style="max-width: 600px; margin: 0 auto; background: #fff; padding: 30px; border-radius: 10px; box-shadow: 0 4px 15px rgba(0,0,0,0.1); text-align: center; font-family: Arial, sans-serif;">
    <h2 style="color: #0073aa; margin-top: 0;">Subir Reporte Gerencial</h2>
    <p style="color: #666; font-size: 14px;">Sube tu archivo. El sistema leerá los datos directamente desde la celda A2.</p>

    <div style="border: 2px dashed #0073aa; border-radius: 8px; padding: 40px 20px; background: #f9fbfd; margin-bottom: 10px; position: relative;">
        <span style="font-size: 40px; display: block; margin-bottom: 10px;">📊</span>
        <label for="archivo_excel" style="background: #0073aa; color: white; padding: 10px 20px; border-radius: 5px; cursor: pointer; display: inline-block; font-weight: bold;">
            Seleccionar Archivo
        </label>
        <input type="file" id="archivo_excel" accept=".xlsx, .xls, .csv" style="display: none;">
        <p id="nombre_archivo" style="margin-top: 15px; color: #333; font-weight: bold;"></p>
        <div id="js_feedback" style="margin-top: 10px; font-weight: bold; font-size: 14px;"></div>
    </div>

    <form method="post" id="formulario_datos">
        <?php wp_nonce_field('ig_guardar_datos_excel', 'ig_nonce_carga'); ?>
        <input type="hidden" name="ig_datos_procesados" id="datos_ocultos">
        <button type="submit" id="btn_subir" disabled style="background: #28a745; color: white; border: none; padding: 12px 25px; border-radius: 5px; font-size: 16px; cursor: pointer; opacity: 0.5; width: 100%; transition: 0.3s;">
            Procesar y Guardar Información
        </button>
    </form>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const inputExcel = document.getElementById('archivo_excel');
    const inputOculto = document.getElementById('datos_ocultos');
    const btnSubir = document.getElementById('btn_subir');
    const nombreArchivo = document.getElementById('nombre_archivo');
    const feedback = document.getElementById('js_feedback');

    inputExcel.addEventListener('change', function(e) {
        const archivo = e.target.files[0];
        if (!archivo) return;

        nombreArchivo.innerText = archivo.name;
        feedback.innerHTML = '<span style="color:#0073aa;">Leyendo archivo... ⏳</span>';

        const reader = new FileReader();
        reader.onload = function(evento) {
            try {
                const data = new Uint8Array(evento.target.result);
                const workbook = XLSX.read(data, {type: 'array', cellDates: true});
                const primeraHoja = workbook.SheetNames[0];
                const hojaActiva = workbook.Sheets[primeraHoja];
                
                // MAGIA: 'range: 1' fuerza a omitir la fila 1 (A1) y leer desde A2
                const datosJSON = XLSX.utils.sheet_to_json(hojaActiva, {header: 1, range: 1, raw: false, dateNF: 'yyyy-mm-dd'});

                // Filtramos filas vacías
                const datosLimpios = datosJSON.filter(fila => fila.length > 0);

                if (datosLimpios.length === 0) {
                    feedback.innerHTML = '<span style="color:#dc3545;">❌ El archivo no contiene datos a partir de la fila 2.</span>';
                    btnSubir.disabled = true;
                    btnSubir.style.opacity = "0.5";
                    return;
                }

                feedback.innerHTML = '<span style="color:#28a745;">✅ Archivo válido. ' + datosLimpios.length + ' filas de datos listas.</span>';
                inputOculto.value = JSON.stringify(datosLimpios);
                btnSubir.disabled = false;
                btnSubir.style.opacity = "1";

            } catch (error) {
                feedback.innerHTML = '<span style="color:#dc3545;">❌ Error al procesar el archivo.</span>';
            }
        };
        reader.readAsArrayBuffer(archivo);
    });
});
</script>