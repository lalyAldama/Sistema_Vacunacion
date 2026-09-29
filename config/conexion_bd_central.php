<?php

$servidor = "localhost";
$usuario = "root";
$contrasena = "123456789";
$base_datos= "bd_central_vacunacion";

$conexion = new mysqli(
    $servidor,
    $usuario,
    $contrasena,
    $base_datos
);

if ($conexion->connect_error) {
    die ("Error de conexión:" .$conexion->connect_error);

}

$conexion->set_charset("utf8mb4");

?>