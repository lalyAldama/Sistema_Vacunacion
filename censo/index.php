<?php
session_start();

if(!isset($_SESSION["id_usuario"])) {
    header("Location: ../login.php");
    exit();
}

$rol = $_SESSION["nombre_rol"];
?>

<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <title>Censo Nominal</title>
</head>

<body>

    <h1>CENSO NOMINAL</h1>

    <a href="registrar.php">
        <button type="button">REGISTRAR NUEVO PACIENTE</button>
    </a>

    <a href="eliminar.php">
        <button type="button">ELIMINAR PACIENTE</button>
    </a>

</body>

</html>