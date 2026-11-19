<?php

function correccionesAsegurarRegistroCentral(mysqli $conexion): void
{
    if (($_SESSION["nombre_rol"] ?? "") !== "Supervisor") {
        return;
    }

    $sql = "CREATE TABLE IF NOT EXISTS CORRECCION_SUPERVISOR (
        id_cambio CHAR(36) NOT NULL,
        id_secuencia BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        id_unidad INT NOT NULL,
        tabla VARCHAR(40) NOT NULL,
        datos LONGTEXT NOT NULL,
        id_usuario INT NOT NULL,
        creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id_cambio),
        UNIQUE KEY uq_correccion_supervisor_secuencia (id_secuencia),
        KEY idx_correccion_supervisor_unidad_fecha (id_unidad, creado_en)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

    if (!$conexion->query($sql)) {
        throw new RuntimeException(
            "No se pudo preparar el registro de correcciones: " . $conexion->error
        );
    }
}

function correccionesRegistrarCambio(
    mysqli $conexion,
    int $idUnidad,
    string $tabla,
    array $datos
): void {
    if (($_SESSION["nombre_rol"] ?? "") !== "Supervisor") {
        return;
    }

    $tablasPermitidas = [
        "LOTE",
        "MOVIMIENTO_BIOLOGICO",
        "MOVIMIENTO_JERINGA",
        "PALOTEO_GENERAL",
        "PALOTEO_INFLUENZA",
        "PALOTEO_COVID"
    ];
    if (!in_array($tabla, $tablasPermitidas, true)) {
        throw new InvalidArgumentException("La tabla no está permitida para correcciones.");
    }

    $json = json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    $bytes = random_bytes(16);
    $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
    $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
    $hex = bin2hex($bytes);
    $idCambio = sprintf(
        "%s-%s-%s-%s-%s",
        substr($hex, 0, 8),
        substr($hex, 8, 4),
        substr($hex, 12, 4),
        substr($hex, 16, 4),
        substr($hex, 20, 12)
    );
    $idUsuario = (int)$_SESSION["id_usuario"];

    $stmt = $conexion->prepare(
        "INSERT INTO CORRECCION_SUPERVISOR
            (id_cambio, id_unidad, tabla, datos, id_usuario)
         VALUES (?, ?, ?, ?, ?)"
    );
    if (!$stmt) {
        throw new RuntimeException("No se pudo preparar la corrección: " . $conexion->error);
    }
    $stmt->bind_param("sissi", $idCambio, $idUnidad, $tabla, $json, $idUsuario);
    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        throw new RuntimeException("No se pudo registrar la corrección: " . $error);
    }
    $stmt->close();
}

function correccionesDescifrarSobre(array $sobre, string $contrasena, string $ad): array
{
    if (
        ($sobre["formato"] ?? null) !== "sistema-vacunacion-cifrado"
        || ($sobre["version"] ?? null) !== 1
        || ($sobre["cifrado"] ?? null) !== "AES-256-GCM"
        || ($sobre["derivacion"] ?? null) !== "PBKDF2-HMAC-SHA256"
        || ($sobre["iteraciones"] ?? null) !== 310000
    ) {
        throw new RuntimeException("El archivo no tiene un formato de paquete compatible.");
    }

    $sal = base64_decode((string)($sobre["sal"] ?? ""), true);
    $iv = base64_decode((string)($sobre["iv"] ?? ""), true);
    $tag = base64_decode((string)($sobre["tag"] ?? ""), true);
    $datos = base64_decode((string)($sobre["datos"] ?? ""), true);
    if (
        $sal === false || strlen($sal) !== 16
        || $iv === false || strlen($iv) !== 12
        || $tag === false || strlen($tag) !== 16
        || $datos === false || $datos === ""
    ) {
        throw new RuntimeException("El paquete está incompleto o dañado.");
    }

    $clave = hash_pbkdf2("sha256", $contrasena, $sal, 310000, 32, true);
    $comprimido = openssl_decrypt(
        $datos,
        "aes-256-gcm",
        $clave,
        OPENSSL_RAW_DATA,
        $iv,
        $tag,
        $ad
    );
    if ($comprimido === false) {
        throw new RuntimeException("No se pudo descifrar el paquete. Verifica la contraseña.");
    }

    $json = gzdecode($comprimido, 50 * 1024 * 1024);
    if ($json === false) {
        throw new RuntimeException("El contenido comprimido del paquete no es válido.");
    }
    return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
}
