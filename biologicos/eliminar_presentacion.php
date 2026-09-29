<?php
require_once "../config/conexion.php";

// Obtener el ID de la presentación
$id_presentacion = $_GET['id'];

// Eliminar presentación
$sql = "DELETE FROM PRESENTACION
        WHERE id_presentacion = ?";

$stmt = $conexion->prepare($sql);

$stmt->bind_param(
    "i",
    $id_presentacion
);

if ($stmt->execute()) {
    header("Location: index.php");
    exit;
} else {
    echo "Error al eliminar la presentación: " . $stmt->error;
}

$stmt->close();
?>