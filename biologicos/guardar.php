<?php
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
        <title>Nuevo Biologico</title>
    </head>

    <body>
        <h1>Nuevo Biologico</h1>
        <form action="guardar.php" method="POST">
            <label>Nombre del biológico</label>
            <input type="text" name="nombre_biologico" required>
            <br><br>

            <button type="submit" name="guardar">
                Guardar biológico
            </button>

            <a href="index.php">
            <button type="button">
                Cancelar
            </button>
            </a>
        </form>
    </body>
</html>