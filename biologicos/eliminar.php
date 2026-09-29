<?php
require_once "../config/conexion.php";

// Obtener el ID del biológico
$id_biologico = $_GET['id'];

// Eliminar biológico
$sql = "DELETE FROM BIOLOGICO
        WHERE id_biologico = ?";

$stmt = $conexion->prepare($sql);

$stmt->bind_param(
    "i",
    $id_biologico
);

if ($stmt->execute()) {
    header("Location: index.php");
    exit;
} else {
    echo "Error al eliminar el biológico: " . $stmt->error;
}

$stmt->close();
?>