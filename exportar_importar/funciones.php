<?php
function asegurarEsquemaExportacionUnidad($conexion)
{
    $sqlIdentidad = "CREATE TABLE IF NOT EXISTS INSTALACION_SINCRONIZACION (
        id_instalacion TINYINT UNSIGNED NOT NULL,
        uuid_instalacion CHAR(36) NOT NULL,
        creada_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id_instalacion),
        UNIQUE KEY uq_uuid_instalacion (uuid_instalacion)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    if (!$conexion->query($sqlIdentidad)) {
        die("No fue posible preparar la identidad de sincronización de la unidad: " . $conexion->error);
    }

    $sqlEncabezados = "CREATE TABLE IF NOT EXISTS INFORME_ANUAL_ENCABEZADO (
        id_unidad INT NOT NULL,
        clave VARCHAR(8) NOT NULL,
        valor VARCHAR(150) NOT NULL,
        id_usuario INT NOT NULL,
        actualizado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
            ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id_unidad, clave)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    if (!$conexion->query($sqlEncabezados)) {
        die("No fue posible preparar el almacenamiento de los encabezados anuales: " . $conexion->error);
    }

    $consultaIdentidad = $conexion->query(
        "SELECT uuid_instalacion
         FROM INSTALACION_SINCRONIZACION
         WHERE id_instalacion = 1"
    );
    if (!$consultaIdentidad) {
        die("No fue posible consultar la identidad de sincronización: " . $conexion->error);
    }
    $identidad = $consultaIdentidad->fetch_assoc();
    $consultaIdentidad->free();
    if ($identidad) {
        if (!preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            (string)$identidad["uuid_instalacion"]
        )) {
            die("La identidad de sincronización de esta unidad no es válida.");
        }
        return (string)$identidad["uuid_instalacion"];
    }

    $bytes = random_bytes(16);
    $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
    $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
    $uuid = vsprintf("%s%s-%s-%s-%s-%s%s%s", str_split(bin2hex($bytes), 4));
    $stmtGuardar = $conexion->prepare(
        "INSERT IGNORE INTO INSTALACION_SINCRONIZACION
            (id_instalacion, uuid_instalacion)
         VALUES (1, ?)"
    );
    if (!$stmtGuardar) {
        die("No fue posible guardar la identidad de sincronización: " . $conexion->error);
    }
    $stmtGuardar->bind_param("s", $uuid);
    if (!$stmtGuardar->execute()) {
        die("No fue posible guardar la identidad de sincronización: " . $stmtGuardar->error);
    }
    $stmtGuardar->close();

    $consultaIdentidad = $conexion->query(
        "SELECT uuid_instalacion
         FROM INSTALACION_SINCRONIZACION
         WHERE id_instalacion = 1"
    );
    if (!$consultaIdentidad) {
        die("No fue posible verificar la identidad de sincronización: " . $conexion->error);
    }
    $identidad = $consultaIdentidad->fetch_assoc();
    $consultaIdentidad->free();
    if (!$identidad) {
        die("No fue posible crear la identidad de sincronización de la unidad.");
    }
    return (string)$identidad["uuid_instalacion"];
}
