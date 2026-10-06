<?php
session_start();

if(!isset($_SESSION["id_usuario"])) {
    header("Location: ../login.php");
    exit();
}

require_once "../config/conexion.php";

if(!isset($_GET["id"]) || empty($_GET["id"])) {
    header("Location: index.php");
    exit();
}

$id_paciente = $_GET["id"];

$sql= "SELECT id_paciente, ap_pat, ap_mat, nombres, fecha_nacimiento, sexo, curp
        FROM PACIENTE WHERE id_paciente = ? ";
$stmt = $conexion->prepare($sql);

if(!$stmt) {
    die("Error al preparar consulta:  " . $conexion->error);
}

$stmt->bind_param("s", $id_paciente);
$stmt->execute();
$resultado= $stmt->get_result();

if($resultado->num_rows === 0) {
    die("Paciente no encontrado");
}

$paciente= $resultado->fetch_assoc();
$stmt->close();

//lotes registrados

$sqlLotes= "SELECT l.id_lote, l.numero_lote, l.fecha_caducidad, b.nombre_biologico, p.nombre_presentacion
            FROM LOTE l
            INNER JOIN PRESENTACION p
            ON l.id_presentacion = p.id_presentacion
            INNER JOIN BIOLOGICO b
            ON p.id_biologico = b.id_biologico 
            ORDER BY b.nombre_biologico, l.fecha_caducidad";
$resultadoLotes = $conexion->query($sqlLotes);
?>

<!DOCTYPE html>
<html lang="es">
    <head>
        <meta charset="UTF-8">
        <title>REGISTRAR APLICACION</title>
    </head>
    <body>
        <h1>REGISTRAR APLICACIÓN DE VACUNA</h1>
        <h2>DATOS DEL PACIENTE</h2>

        <p>
            <strong>NOMBRE: </strong>
            <?php
            echo htmlspecialchars($paciente["ap_pat"] . "". $paciente["ap_mat"] . "" . $paciente["nombres"]); ?>
        </p>

        <p>
            <strong>FECHA DE NACIMIENTO: </strong>
            <?php echo htmlspecialchars($paciente["fecha_nacimiento"]) ; ?>
        </p>

        <p>
            <strong>SEXO: </strong>
            <?php echo htmlspecialchars($paciente["sexo"]); ?>
        </p>

        <p>
            <strong>CURP: </strong>
            <?php echo htmlspecialchars($paciente["curp"] ?? "") ?>
        </p>

        <hr>
        <h2>DATOS DE LA APLICACION</h2>
        <p>

        </p>

        <br>
        <a href="index.php">
            <button type="button">REGRESAR</button>
        </a>
    </body>
</html>