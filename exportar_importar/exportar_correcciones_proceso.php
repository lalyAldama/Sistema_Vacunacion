<?php
require_once __DIR__ . "/../config/seguridad.php";

if (!isset($_SESSION["id_usuario"])) {
    header("Location: ../login.php");
    exit();
}

if (($_SESSION["nombre_rol"] ?? "") !== "Supervisor") {
    http_response_code(403);
    exit("Acceso denegado.");
}

require_once __DIR__ . "/../config/conexion.php";

// Validar token
$idUnidadDestino = filter_var($_POST["id_unidad_destino"] ?? 0, FILTER_VALIDATE_INT);
if (!$idUnidadDestino) {
    http_response_code(400);
    exit("Unidad inválida.");
}

$tokenEsperado = hash_hmac(
    "sha256",
    "exportar_correcciones|" . $idUnidadDestino,
    $_SESSION["form_guard_secret"]
);

if (($_POST["token_exportacion"] ?? "") !== $tokenEsperado) {
    http_response_code(403);
    exit("Token de seguridad inválido.");
}

$contrasena = $_POST["contrasena_paquete"] ?? "";
$confirmar = $_POST["confirmar_contrasena"] ?? "";

if ($contrasena !== $confirmar || strlen($contrasena) < 14) {
    http_response_code(400);
    exit("Contraseña inválida o no coinciden.");
}

// Obtener datos de la unidad
$stmtUnidad = $conexion->prepare("SELECT id_unidad, nombre_unidad FROM UNIDAD WHERE id_unidad = ? LIMIT 1");
if (!$stmtUnidad || !$stmtUnidad->bind_param("i", $idUnidadDestino) || !$stmtUnidad->execute()) {
    http_response_code(500);
    exit("Error al obtener unidad.");
}

$resultado = $stmtUnidad->get_result();
$unidad = $resultado->fetch_assoc();
$stmtUnidad->close();

if (!$unidad) {
    http_response_code(404);
    exit("Unidad no encontrada.");
}

// Recopilar correcciones
// Este es un ejemplo simplificado. En producción, necesitarías un mecanismo
// para rastrear qué cambios se han hecho desde la última importación.
// Por ahora, recolectamos todos los datos y el usuario debe seleccionar qué corregir.

$correcciones = [
    "TUTOR" => [],
    "PACIENTE" => [],
    "BIOLOGICO" => [],
    "LOTE" => [],
    "PRESENTACION" => [],
    "APLICACION_VACUNA" => []
];

// Obtener tutores
$stmtTutores = $conexion->query("SELECT * FROM TUTOR LIMIT 1000");
if ($stmtTutores) {
    while ($tutor = $stmtTutores->fetch_assoc()) {
        // En una implementación real, solo incluiríamos los modificados
        // Por ahora, incluimos toda la data
        $correcciones["TUTOR"][] = $tutor;
    }
    $stmtTutores->free();
}

// Obtener pacientes (muestra)
$stmtPacientes = $conexion->query("SELECT id_paciente, ap_pat, ap_mat, nombres, fecha_nacimiento, sexo FROM PACIENTE LIMIT 100");
if ($stmtPacientes) {
    while ($paciente = $stmtPacientes->fetch_assoc()) {
        $correcciones["PACIENTE"][] = $paciente;
    }
    $stmtPacientes->free();
}

// Crear paquete JSON
$paquete = [
    "formato" => "sistema-vacunacion-correcciones",
    "version" => 1,
    "fecha_exportacion" => date("c"),
    "unidad_destino" => [
        "id_unidad" => $unidad["id_unidad"],
        "nombre_unidad" => $unidad["nombre_unidad"]
    ],
    "correcciones" => $correcciones
];

$json = json_encode($paquete, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
$comprimido = gzencode($json, 9);

// Cifrar
$sal = random_bytes(16);
$iv = random_bytes(12);
$clave = hash_pbkdf2("sha256", $contrasena, $sal, 310000, 32, true);

$cifrado = openssl_encrypt(
    $comprimido,
    "aes-256-gcm",
    $clave,
    OPENSSL_RAW_DATA,
    $iv,
    $tag,
    "sistema-vacunacion-correcciones:v1"
);

if ($cifrado === false) {
    http_response_code(500);
    exit("Error al cifrar el paquete.");
}

// Crear sobre (envelope)
$sobre = [
    "formato" => "sistema-vacunacion-cifrado",
    "version" => 1,
    "cifrado" => "AES-256-GCM",
    "derivacion" => "PBKDF2-HMAC-SHA256",
    "iteraciones" => 310000,
    "sal" => base64_encode($sal),
    "iv" => base64_encode($iv),
    "tag" => base64_encode($tag),
    "datos" => base64_encode($cifrado)
];

$sobrejson = json_encode($sobre, JSON_UNESCAPED_UNICODE);

// Enviar archivo
$fecha = date("Y-m-d_H-i-s");
$nombre_archivo = "correcciones_" . $unidad["id_unidad"] . "_" . $fecha . ".json";

header("Content-Type: application/json");
header("Content-Disposition: attachment; filename=\"" . $nombre_archivo . "\"");
header("Content-Length: " . strlen($sobrejson));
header("Cache-Control: no-cache, no-store, must-revalidate");
header("Pragma: no-cache");
header("Expires: 0");

echo $sobrejson;
exit();
