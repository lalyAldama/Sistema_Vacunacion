<?php

$servidor = "localhost";
$usuario = "root";
$contrasena = "123456789";
$base_datos= "bd_central_vacunacion";

try {
    $conexion = new mysqli(
        $servidor,
        $usuario,
        $contrasena,
        $base_datos
    );
} catch (mysqli_sql_exception $errorConexion) {
    if (defined("CENTRAL_CONNECTION_OPTIONAL") && CENTRAL_CONNECTION_OPTIONAL) {
        $conexionCentralDisponible = false;
        return;
    }
    throw $errorConexion;
}

if ($conexion->connect_error) {
    if (defined("CENTRAL_CONNECTION_OPTIONAL") && CENTRAL_CONNECTION_OPTIONAL) {
        $conexionCentralDisponible = false;
        return;
    }
    die ("Error de conexión:" .$conexion->connect_error);
}

$conexion->set_charset("utf8mb4");
$conexionCentralDisponible = true;

?>