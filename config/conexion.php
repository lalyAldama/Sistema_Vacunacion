<?php

require_once __DIR__ . "/seguridad.php";

if (
    !defined("FORZAR_CONEXION_LOCAL")
    && in_array($_SESSION["nombre_rol"] ?? "", ["Administrador", "Supervisor"], true)
) {
    require_once __DIR__ . "/conexion_bd_central.php";
    return;
}

require_once __DIR__ . "/conexion_unidad.php";
$conexion = conexionAbrirUnidad();

?>