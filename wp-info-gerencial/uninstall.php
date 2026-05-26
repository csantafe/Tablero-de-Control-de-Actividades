<?php
// Si WordPress no llama a este archivo directamente, abortar.
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

global $wpdb;

// Lista de todas las tablas creadas por el plugin
$tablas = [
    $wpdb->prefix . 'info_gerencial',
    $wpdb->prefix . 'ig_tareas',
    $wpdb->prefix . 'ig_responsables',
    $wpdb->prefix . 'ig_config_areas'
];

// Borrar cada tabla de la base de datos
foreach ($tablas as $tabla) {
    $wpdb->query("DROP TABLE IF EXISTS {$tabla}");
}