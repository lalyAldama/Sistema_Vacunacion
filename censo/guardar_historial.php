<?php
require_once __DIR__ . "/../config/seguridad.php";

if (!isset($_SESSION["id_usuario"])) {
    http_response_code(401);
    exit("Se requiere una sesión autenticada.");
}

require_once __DIR__ . "/../config/conexion.php";
require_once __DIR__ . "/../config/unidad_contexto.php";
$contextoUnidad = unidadObtenerContexto($conexion);
$idUnidad = $contextoUnidad["id_unidad"];
require_once __DIR__ . "/historial.php";

header("Content-Type: application/json; charset=utf-8");

$tipo = isset($_POST["tipo"]) && is_string($_POST["tipo"]) ? $_POST["tipo"] : "";
$idCenso = filter_var($_POST["id_censo"] ?? null, FILTER_VALIDATE_INT);
$contenido = $_POST["contenido"] ?? "";
$token = $_POST["token"] ?? "";
$tipoValido = is_int($idCenso) && (
    ($tipo === "general" && $idCenso > 0)
    || ($tipo === "influenza" && $idCenso === 0)
);
if ($tipoValido && $tipo === "general") {
    $stmtCenso = $conexion->prepare("SELECT id_censo FROM CENSO WHERE id_censo = ? LIMIT 1");
    if (!$stmtCenso) {
        http_response_code(500);
        echo json_encode(["success" => false, "error" => "No se pudo validar el censo."]);
        exit;
    }
    $stmtCenso->bind_param("i", $idCenso);
    if (!$stmtCenso->execute()) {
        http_response_code(500);
        echo json_encode(["success" => false, "error" => "No se pudo validar el censo."]);
        exit;
    }
    $tipoValido = $stmtCenso->get_result()->fetch_assoc() !== null;
    $stmtCenso->close();
}

if (!$tipoValido) {
    http_response_code(400);
    echo json_encode(["success" => false, "error" => "El censo indicado no es válido."]);
    exit;
}

$tokenEsperado = hash_hmac(
    "sha256",
    "censo_historial|" . $tipo . "|" . (string)$idCenso,
    $_SESSION["form_guard_secret"]
);

if (
    !is_string($contenido)
    || $contenido === ""
    || strlen($contenido) > 50 * 1024 * 1024
    || !is_string($token)
    || !hash_equals($tokenEsperado, $token)
) {
    http_response_code(400);
    echo json_encode(["success" => false, "error" => "La solicitud de historial no es válida."]);
    exit;
}

censoHistorialAsegurarTabla($conexion);
unidadAsegurarUnidadEnPacienteCenso($conexion);
censoHistorialGuardar($conexion, $tipo, $idCenso, $contenido, $idUnidad);

echo json_encode(["success" => true]);
