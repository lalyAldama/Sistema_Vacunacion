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
require_once __DIR__ . "/correcciones_funciones.php";

$idUnidadDestino = filter_var($_POST["id_unidad_destino"] ?? null, FILTER_VALIDATE_INT);
if ($idUnidadDestino === false || $idUnidadDestino < 1) {
    http_response_code(400);
    exit("Unidad inválida.");
}

$tokenEsperado = hash_hmac(
    "sha256",
    "exportar_correcciones|" . $idUnidadDestino,
    $_SESSION["form_guard_secret"]
);
$tokenRecibido = is_string($_POST["token_exportacion"] ?? null)
    ? $_POST["token_exportacion"]
    : "";
if (!hash_equals($tokenEsperado, $tokenRecibido)) {
    http_response_code(403);
    exit("Token de seguridad inválido.");
}

$contrasena = is_string($_POST["contrasena_paquete"] ?? null)
    ? $_POST["contrasena_paquete"]
    : "";
$confirmar = is_string($_POST["confirmar_contrasena"] ?? null)
    ? $_POST["confirmar_contrasena"]
    : "";
if ($contrasena !== $confirmar || strlen($contrasena) < 14 || strlen($contrasena) > 128) {
    http_response_code(400);
    exit("La contraseña debe tener entre 14 y 128 caracteres y ambas entradas deben coincidir.");
}

$stmtUnidad = $conexion->prepare(
    "SELECT id_unidad, nombre_unidad FROM UNIDAD WHERE id_unidad = ? LIMIT 1"
);
if (!$stmtUnidad) {
    http_response_code(500);
    exit("No se pudo preparar la consulta de unidad.");
}
$stmtUnidad->bind_param("i", $idUnidadDestino);
if (!$stmtUnidad->execute()) {
    http_response_code(500);
    exit("No se pudo consultar la unidad destino.");
}
$unidad = $stmtUnidad->get_result()->fetch_assoc();
$stmtUnidad->close();
if (!$unidad) {
    http_response_code(404);
    exit("Unidad no encontrada.");
}

try {
    correccionesAsegurarRegistroCentral($conexion);

    $stmtCambios = $conexion->prepare(
        "SELECT id_cambio, tabla, datos
         FROM CORRECCION_SUPERVISOR
         WHERE id_unidad = ?
         ORDER BY id_secuencia"
    );
    if (!$stmtCambios) {
        throw new RuntimeException("No se pudo preparar la consulta de cambios: " . $conexion->error);
    }
    $stmtCambios->bind_param("i", $idUnidadDestino);
    if (!$stmtCambios->execute()) {
        throw new RuntimeException("No se pudieron consultar los cambios: " . $stmtCambios->error);
    }
    $resultadoCambios = $stmtCambios->get_result();
    $cambios = [];
    $lotesIncluidos = [];
    while ($fila = $resultadoCambios->fetch_assoc()) {
        $datos = json_decode($fila["datos"], true, 64, JSON_THROW_ON_ERROR);
        if ($fila["tabla"] === "LOTE") {
            $idLoteCentral = (int)($datos["id_lote_central"] ?? 0);
            if ($idLoteCentral > 0) {
                $lotesIncluidos[$idLoteCentral] = true;
            }
        }
        $cambios[] = [
            "id_cambio" => (string)$fila["id_cambio"],
            "tabla" => (string)$fila["tabla"],
            "datos" => $datos
        ];
    }
    $stmtCambios->close();

    if ($cambios === []) {
        throw new RuntimeException("No hay cambios registrados para esta unidad. Guarda los cambios desde sus módulos y vuelve a exportar.");
    }

    foreach ($cambios as &$cambio) {
        if ($cambio["tabla"] !== "MOVIMIENTO_BIOLOGICO") {
            continue;
        }
        $idLoteCentral = filter_var(
            $cambio["datos"]["id_lote_central"] ?? null,
            FILTER_VALIDATE_INT
        );
        if ($idLoteCentral === false || $idLoteCentral < 1) {
            throw new RuntimeException("Un movimiento registrado no tiene un lote válido.");
        }

        $stmtLote = $conexion->prepare(
            "SELECT id_lote_origen
             FROM LOTE
             WHERE id_lote = ? AND id_unidad_origen = ?
             LIMIT 1"
        );
        if (!$stmtLote) {
            throw new RuntimeException("No se pudo preparar la validación del lote.");
        }
        $stmtLote->bind_param("ii", $idLoteCentral, $idUnidadDestino);
        if (!$stmtLote->execute()) {
            throw new RuntimeException("No se pudo validar el lote del movimiento.");
        }
        $loteOrigen = $stmtLote->get_result()->fetch_assoc();
        $stmtLote->close();

        if ($loteOrigen && $loteOrigen["id_lote_origen"] !== null) {
            $cambio["datos"]["id_lote_origen"] = (int)$loteOrigen["id_lote_origen"];
            unset($cambio["datos"]["id_lote_central"]);
        } elseif (!isset($lotesIncluidos[$idLoteCentral])) {
            throw new RuntimeException("Un movimiento usa un lote central que no se puede relacionar con la unidad.");
        }
    }
    unset($cambio);

    $paquete = [
        "formato" => "sistema-vacunacion-correcciones",
        "version" => 2,
        "generado_en_utc" => gmdate("Y-m-d\\TH:i:s\\Z"),
        "unidad_destino" => [
            "id_unidad" => (int)$unidad["id_unidad"],
            "nombre" => (string)$unidad["nombre_unidad"]
        ],
        "cambios" => $cambios
    ];
    $json = json_encode($paquete, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    $comprimido = gzencode($json, 6);
    if ($comprimido === false) {
        throw new RuntimeException("No fue posible comprimir el paquete de correcciones.");
    }

    $sal = random_bytes(16);
    $iv = random_bytes(12);
    $clave = hash_pbkdf2("sha256", $contrasena, $sal, 310000, 32, true);
    $tag = "";
    $cifrado = openssl_encrypt(
        $comprimido,
        "aes-256-gcm",
        $clave,
        OPENSSL_RAW_DATA,
        $iv,
        $tag,
        "sistema-vacunacion-unidad:v1",
        16
    );
    if ($cifrado === false || strlen($tag) !== 16) {
        throw new RuntimeException("No fue posible cifrar el paquete de correcciones.");
    }

    $sobre = json_encode([
        "formato" => "sistema-vacunacion-cifrado",
        "version" => 1,
        "cifrado" => "AES-256-GCM",
        "derivacion" => "PBKDF2-HMAC-SHA256",
        "iteraciones" => 310000,
        "sal" => base64_encode($sal),
        "iv" => base64_encode($iv),
        "tag" => base64_encode($tag),
        "datos" => base64_encode($cifrado)
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
} catch (Throwable $error) {
    http_response_code(400);
    exit(htmlspecialchars($error->getMessage(), ENT_QUOTES, "UTF-8"));
}

$nombreArchivo = "correcciones_" . (int)$unidad["id_unidad"] . "_" . gmdate("Ymd_His") . ".json";
header("Content-Type: application/json; charset=utf-8");
header("Content-Disposition: attachment; filename=\"" . $nombreArchivo . "\"");
header("Content-Length: " . strlen($sobre));
header("Cache-Control: no-store, private");
header("X-Content-Type-Options: nosniff");
echo $sobre;
exit();
