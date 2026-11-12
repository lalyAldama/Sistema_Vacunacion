<?php

function conexionAbrirUnidad(): mysqli
{
    $servidor = "localhost";
    $usuario = "root";
    $contrasena = "123456789";
    $baseDatos = "bd_unidad_vacunacion";

    $conexion = new mysqli(
        $servidor,
        $usuario,
        $contrasena,
        $baseDatos
    );

    if ($conexion->connect_error) {
        die("Error de conexión: " . $conexion->connect_error);
    }

    if (!$conexion->set_charset("utf8mb4")) {
        die("No fue posible configurar la conexión de la unidad: " . $conexion->error);
    }

    return $conexion;
}
