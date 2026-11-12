<?php
require_once __DIR__ . "/../config/seguridad_administrador.php";
require_once __DIR__ . "/../config/conexion.php";
require_once __DIR__ . "/../config/conexion_unidad.php";

$fuente = $_GET["fuente"] ?? "";
if (!in_array($fuente, ["central", "unidad"], true)) {
    http_response_code(400);
    exit("La fuente del usuario no es válida.");
}
$idUsuario = filter_var($_GET["id"] ?? null, FILTER_VALIDATE_INT);
if ($idUsuario === false || $idUsuario < 1) {
    http_response_code(400);
    exit("El usuario indicado no es válido.");
}

$conexionCentral = $conexion;
$conexionUnidad = conexionAbrirUnidad();
$conexionUsuario = $fuente === "central" ? $conexionCentral : $conexionUnidad;
$rolesPermitidos = $fuente === "central"
    ? ["Administrador", "Supervisor"]
    : ["Personal de unidad"];
$stmtEliminar = $conexionUsuario->prepare(
    "DELETE u FROM USUARIO u
     INNER JOIN ROL r ON r.id_rol = u.id_rol
     WHERE u.id_usuario = ? AND r.nombre_rol IN (?, ?)"
);
if (!$stmtEliminar) {
    die("No se pudo preparar la eliminación del usuario: " . $conexionUsuario->error);
}
$stmtEliminar->bind_param("iss", $idUsuario, $rolesPermitidos[0], $rolesPermitidos[1]);
if (!$stmtEliminar->execute()) {
    die("No se pudo eliminar el usuario: " . $stmtEliminar->error);
}
$eliminado = $stmtEliminar->affected_rows === 1;
$stmtEliminar->close();
$conexionUnidad->close();
if (!$eliminado) {
    http_response_code(404);
    exit("El usuario no se encontró en la base indicada.");
}

header("Location: index.php");
exit();
