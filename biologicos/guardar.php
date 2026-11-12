<?php
require_once __DIR__ . "/../config/seguridad_administrador.php";
require_once "../config/conexion.php";

//Nuevo biologico

if(isset($_POST['guardar'])) {
    $nombre_biologico= $_POST['nombre_biologico'];
    $sql = "INSERT INTO BIOLOGICO (nombre_biologico)
            VALUES (?)";
    $stmt= $conexion->prepare($sql);
    $stmt->bind_param (
        "s",
        $nombre_biologico
    );

    if ($stmt->execute()) {
        header("Location: index.php");
        exit;
    } else {
        echo "Error al guardar biologico: ". $stmt->error;
    }

    $stmt->close();
}
?>

<!DOCTYPE html>
<html lang="es">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Nuevo Biologico</title>
        <link rel="stylesheet" href="../css/estilos.css">
        <link rel="stylesheet" href="../css/gestion_admin.css">
    </head>

    <body class="gestion-admin">
        <main class="gestion-contenedor">
        <header class="gestion-encabezado">
            <div class="gestion-encabezado-texto">
                <span class="gestion-etiqueta">ADMINISTRACIÓN · BIOLÓGICOS</span>
                <h1>Nuevo biológico</h1>
                <p>Registra un biológico en el catálogo del sistema.</p>
            </div>
            <a class="gestion-volver" href="index.php">Volver al catálogo</a>
        </header>
        <form class="gestion-formulario" action="guardar.php" method="POST">
            <label>Nombre del biológico</label>
            <input type="text" name="nombre_biologico" required>
            <br><br>

            <button type="submit" name="guardar">
                Guardar biológico
            </button>

            <a class="btn gestion-btn-secundario" href="index.php">Cancelar</a>
        </form>
        </main>
    </body>
</html>