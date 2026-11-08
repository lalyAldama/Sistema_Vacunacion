<?php
require_once __DIR__ . "/../config/seguridad.php";

if (!isset($_SESSION["id_usuario"])) {
    http_response_code(401);
    exit("Se requiere una sesión autenticada.");
}
if (($_SESSION["nombre_rol"] ?? "") !== "Personal de unidad") {
    http_response_code(403);
    exit("Esta función está disponible para personal de unidad.");
}
if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    http_response_code(405);
    header("Allow: POST");
    exit("Método no permitido.");
}

require_once __DIR__ . "/../config/conexion.php";

$contrasenaPaquete = $_POST["contrasena_paquete"] ?? null;
$confirmarContrasena = $_POST["confirmar_contrasena"] ?? null;
$tokenRecibido = $_POST["token_exportacion"] ?? null;
if (
    !is_string($contrasenaPaquete)
    || !is_string($confirmarContrasena)
    || !is_string($tokenRecibido)
    || strlen($contrasenaPaquete) < 14
    || strlen($contrasenaPaquete) > 128
    || !hash_equals($contrasenaPaquete, $confirmarContrasena)
) {
    http_response_code(400);
    exit("La contraseña no es válida o las dos entradas no coinciden.");
}

$stmtUnidad = $conexion->prepare(
    "SELECT u.id_unidad, un.nombre_unidad
     FROM USUARIO u
     INNER JOIN UNIDAD un ON un.id_unidad = u.id_unidad
     WHERE u.id_usuario = ?"
);
if (!$stmtUnidad) {
    die("Error al consultar la unidad de origen: " . $conexion->error);
}
$idUsuarioSesion = (int)$_SESSION["id_usuario"];
$stmtUnidad->bind_param("i", $idUsuarioSesion);
if (!$stmtUnidad->execute()) {
    die("Error al consultar la unidad de origen: " . $stmtUnidad->error);
}
$unidadOrigen = $stmtUnidad->get_result()->fetch_assoc();
$stmtUnidad->close();
if (!$unidadOrigen) {
    http_response_code(400);
    exit("El usuario no tiene una unidad asignada.");
}

$tokenEsperado = hash_hmac(
    "sha256",
    "exportar_unidad|" . (int)$unidadOrigen["id_unidad"],
    $_SESSION["form_guard_secret"]
);
if (!hash_equals($tokenEsperado, $tokenRecibido)) {
    http_response_code(400);
    exit("La solicitud de exportación venció. Regresa e inténtalo de nuevo.");
}

$existeTabla = function ($nombreTabla) use ($conexion) {
    $stmt = $conexion->prepare(
        "SELECT COUNT(*) AS cantidad
         FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?"
    );
    if (!$stmt) {
        die("Error al verificar las tablas para exportar: " . $conexion->error);
    }
    $stmt->bind_param("s", $nombreTabla);
    if (!$stmt->execute()) {
        die("Error al verificar la tabla " . $nombreTabla . ": " . $stmt->error);
    }
    $existe = (int)$stmt->get_result()->fetch_assoc()["cantidad"] === 1;
    $stmt->close();
    return $existe;
};

$consultasCatalogo = [
    "ESQUEMA" => "SELECT id_esquema, nombre_esquema FROM ESQUEMA ORDER BY id_esquema",
    "CENSO" => "SELECT id_censo, nombre_censo, id_esquema FROM CENSO ORDER BY id_censo",
    "BIOLOGICO" => "SELECT id_biologico, nombre_biologico FROM BIOLOGICO ORDER BY id_biologico",
    "DOSIS" => "SELECT id_dosis, nombre_dosis FROM DOSIS ORDER BY id_dosis",
    "ESQUEMA_BIOLOGICO" => "SELECT id_esquema_biologico, id_esquema, id_biologico FROM ESQUEMA_BIOLOGICO ORDER BY id_esquema_biologico",
    "ESQUEMA_BIOLOGICO_DOSIS" => "SELECT id_esquema_biologico_dosis, id_esquema_biologico, id_dosis FROM ESQUEMA_BIOLOGICO_DOSIS ORDER BY id_esquema_biologico_dosis",
    "COMORBILIDAD" => "SELECT id_comorbilidad, nombre_comorbilidad FROM COMORBILIDAD ORDER BY id_comorbilidad",
    "JERINGA" => "SELECT id_jeringa, tipo FROM JERINGA ORDER BY id_jeringa"
];
$consultasDatos = [
    "TUTOR" => "SELECT * FROM TUTOR ORDER BY id_tutor",
    "PACIENTE" => "SELECT * FROM PACIENTE ORDER BY id_paciente",
    "PACIENTE_CENSO" => "SELECT * FROM PACIENTE_CENSO ORDER BY id_paciente_censo",
    "PACIENTE_COMORBILIDAD" => "SELECT * FROM PACIENTE_COMORBILIDAD ORDER BY id_paciente_comorbilidad",
    "PRESENTACION" => "SELECT * FROM PRESENTACION ORDER BY id_presentacion",
    "LOTE" => "SELECT * FROM LOTE ORDER BY id_lote",
    "APLICACION_VACUNA" => "SELECT * FROM APLICACION_VACUNA WHERE id_unidad = " . (int)$unidadOrigen["id_unidad"] . " ORDER BY id_aplicacion_vacuna",
    "APLICACION_VPH_ANONIMA" => "SELECT id_aplicacion_vph_anonima, id_esquema_biologico_dosis, id_lote, id_unidad, id_usuario AS id_usuario_origen, fecha_aplicacion, clasificacion_vph, es_migrante, creado_en FROM APLICACION_VPH_ANONIMA WHERE id_unidad = " . (int)$unidadOrigen["id_unidad"] . " ORDER BY id_aplicacion_vph_anonima",
    "MOVIMIENTO_BIOLOGICO" => "SELECT * FROM MOVIMIENTO_BIOLOGICO WHERE id_unidad = " . (int)$unidadOrigen["id_unidad"] . " ORDER BY id_movimiento",
    "MOVIMIENTO_JERINGA" => "SELECT * FROM MOVIMIENTO_JERINGA WHERE id_unidad = " . (int)$unidadOrigen["id_unidad"] . " ORDER BY id_movimiento_jeringa",
    "PALOTEO_GENERAL" => "SELECT id_paloteo, id_unidad, anio, mes, datos_json, id_usuario AS id_usuario_origen, actualizado_en FROM PALOTEO_GENERAL WHERE id_unidad = " . (int)$unidadOrigen["id_unidad"] . " ORDER BY id_paloteo",
    "PALOTEO_INFLUENZA" => "SELECT id_paloteo, id_unidad, anio, mes, datos_json, id_usuario AS id_usuario_origen, actualizado_en FROM PALOTEO_INFLUENZA WHERE id_unidad = " . (int)$unidadOrigen["id_unidad"] . " ORDER BY id_paloteo",
    "PALOTEO_COVID" => "SELECT id_paloteo, id_unidad, anio, mes, datos_json, id_usuario AS id_usuario_origen, actualizado_en FROM PALOTEO_COVID WHERE id_unidad = " . (int)$unidadOrigen["id_unidad"] . " ORDER BY id_paloteo",
    "INFORME_ANUAL_META" => "SELECT id_unidad, anio, clave, meta, id_usuario AS id_usuario_origen, actualizado_en FROM INFORME_ANUAL_META WHERE id_unidad = " . (int)$unidadOrigen["id_unidad"] . " ORDER BY anio, clave",
    "CENSO_HISTORIAL" => "SELECT id_historial, tipo_reporte, id_censo, fecha_captura, hash_contenido, TO_BASE64(contenido) AS contenido_base64 FROM CENSO_HISTORIAL ORDER BY id_historial",
    "UNIDAD" => "SELECT id_unidad, nombre_unidad FROM UNIDAD ORDER BY id_unidad"
];

$banderasTransaccion = MYSQLI_TRANS_START_READ_ONLY
    | MYSQLI_TRANS_START_WITH_CONSISTENT_SNAPSHOT;
if (!$conexion->begin_transaction($banderasTransaccion)) {
    die("No fue posible iniciar una lectura consistente para la exportación: " . $conexion->error);
}

$tablasOpcionales = [
    "APLICACION_VPH_ANONIMA",
    "PALOTEO_GENERAL",
    "PALOTEO_INFLUENZA",
    "PALOTEO_COVID",
    "INFORME_ANUAL_META",
    "CENSO_HISTORIAL"
];
$datosCatalogos = [];
$datosUnidad = [];
foreach ($consultasCatalogo as $tabla => $consulta) {
    if (!$existeTabla($tabla)) {
        http_response_code(500);
        exit("Falta la tabla de catálogo requerida para exportar: " . $tabla . ".");
    }
    $resultado = $conexion->query($consulta);
    if (!$resultado) {
        die("Error al exportar el catálogo " . $tabla . ": " . $conexion->error);
    }
    $datosCatalogos[$tabla] = $resultado->fetch_all(MYSQLI_ASSOC);
    $resultado->free();
}
foreach ($consultasDatos as $tabla => $consulta) {
    if (!$existeTabla($tabla)) {
        if (in_array($tabla, $tablasOpcionales, true)) {
            $datosUnidad[$tabla] = [];
            continue;
        }
        http_response_code(500);
        exit("Falta una tabla requerida para exportar: " . $tabla . ".");
    }
    $resultado = $conexion->query($consulta);
    if (!$resultado) {
        die("Error al exportar los datos de " . $tabla . ": " . $conexion->error);
    }
    $datosUnidad[$tabla] = $resultado->fetch_all(MYSQLI_ASSOC);
    $resultado->free();
}
if (!$conexion->commit()) {
    $conexion->rollback();
    die("No fue posible finalizar la lectura de la exportación: " . $conexion->error);
}

$registrosIncluidos = [];
foreach ($datosCatalogos as $tabla => $filas) {
    $registrosIncluidos[$tabla] = count($filas);
}
foreach ($datosUnidad as $tabla => $filas) {
    $registrosIncluidos[$tabla] = count($filas);
}
$paquete = [
    "formato" => "sistema-vacunacion-unidad",
    "version" => 1,
    "generado_en_utc" => gmdate("Y-m-d\TH:i:s\Z"),
    "unidad_origen" => [
        "id_local" => (int)$unidadOrigen["id_unidad"],
        "nombre" => (string)$unidadOrigen["nombre_unidad"]
    ],
    "registros_incluidos" => $registrosIncluidos,
    "catalogos" => $datosCatalogos,
    "datos_unidad" => $datosUnidad
];
$jsonPaquete = json_encode(
    $paquete,
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
);
$datosComprimidos = gzencode($jsonPaquete, 6);
if ($datosComprimidos === false) {
    http_response_code(500);
    exit("No fue posible comprimir el paquete de exportación.");
}

$iteracionesKdf = 310000;
$sal = random_bytes(16);
$iv = random_bytes(12);
$clave = hash_pbkdf2("sha256", $contrasenaPaquete, $sal, $iteracionesKdf, 32, true);
$tag = "";
$datosCifrados = openssl_encrypt(
    $datosComprimidos,
    "aes-256-gcm",
    $clave,
    OPENSSL_RAW_DATA,
    $iv,
    $tag,
    "sistema-vacunacion-unidad:v1",
    16
);
if ($datosCifrados === false || strlen($tag) !== 16) {
    http_response_code(500);
    exit("No fue posible cifrar el paquete de exportación.");
}
$sobre = json_encode([
    "formato" => "sistema-vacunacion-cifrado",
    "version" => 1,
    "cifrado" => "AES-256-GCM",
    "derivacion" => "PBKDF2-HMAC-SHA256",
    "iteraciones" => $iteracionesKdf,
    "sal" => base64_encode($sal),
    "iv" => base64_encode($iv),
    "tag" => base64_encode($tag),
    "datos" => base64_encode($datosCifrados)
], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

$nombreArchivo = "unidad_" . (int)$unidadOrigen["id_unidad"]
    . "_exportacion_" . gmdate("Ymd_His") . ".vax";
header("Content-Type: application/vnd.sistema-vacunacion.exportacion+json");
header("Content-Disposition: attachment; filename=\"" . $nombreArchivo . "\"");
header("Content-Length: " . strlen($sobre));
header("Cache-Control: no-store, private");
header("X-Content-Type-Options: nosniff");
echo $sobre;
