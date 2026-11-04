<?php
function asegurarEsquemaVph($conexion)
{
    $stmtColumna = $conexion->prepare(
        "SELECT COUNT(*) AS existe
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'APLICACION_VACUNA'
           AND COLUMN_NAME = 'clasificacion_vph'"
    );
    if (!$stmtColumna || !$stmtColumna->execute()) {
        die("No fue posible verificar la clasificación VPH de las aplicaciones.");
    }
    $columnaExiste = (int)$stmtColumna->get_result()->fetch_assoc()["existe"] > 0;
    $stmtColumna->close();

    if (!$columnaExiste && !$conexion->query(
        "ALTER TABLE APLICACION_VACUNA
         ADD COLUMN clasificacion_vph VARCHAR(40) NULL AFTER fecha_aplicacion"
    )) {
        $stmtVerificar = $conexion->prepare(
            "SELECT COUNT(*) AS existe
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'APLICACION_VACUNA'
               AND COLUMN_NAME = 'clasificacion_vph'"
        );
        if (!$stmtVerificar || !$stmtVerificar->execute()) {
            die("No fue posible preparar el almacenamiento de la clasificación VPH.");
        }
        $columnaExiste = (int)$stmtVerificar->get_result()->fetch_assoc()["existe"] > 0;
        $stmtVerificar->close();
        if (!$columnaExiste) {
            die("No fue posible agregar la clasificación VPH: " . $conexion->error);
        }
    }

    $sqlTabla = "CREATE TABLE IF NOT EXISTS APLICACION_VPH_ANONIMA (
                     id_aplicacion_vph_anonima INT NOT NULL AUTO_INCREMENT,
                     id_esquema_biologico_dosis INT NOT NULL,
                     id_lote INT NOT NULL,
                     id_unidad INT NOT NULL,
                     id_usuario INT NOT NULL,
                     fecha_aplicacion DATE NOT NULL,
                     clasificacion_vph VARCHAR(40) NOT NULL,
                     es_migrante TINYINT(1) NOT NULL,
                     creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                     PRIMARY KEY (id_aplicacion_vph_anonima),
                     KEY idx_vph_anonimo_periodo (id_unidad, fecha_aplicacion),
                     KEY idx_vph_anonimo_lote (id_lote, id_unidad, fecha_aplicacion),
                     CONSTRAINT fk_vph_anonimo_esquema_dosis
                         FOREIGN KEY (id_esquema_biologico_dosis)
                         REFERENCES ESQUEMA_BIOLOGICO_DOSIS (id_esquema_biologico_dosis),
                     CONSTRAINT fk_vph_anonimo_lote
                         FOREIGN KEY (id_lote) REFERENCES LOTE (id_lote),
                     CONSTRAINT fk_vph_anonimo_unidad
                         FOREIGN KEY (id_unidad) REFERENCES UNIDAD (id_unidad),
                     CONSTRAINT fk_vph_anonimo_usuario
                         FOREIGN KEY (id_usuario) REFERENCES USUARIO (id_usuario)
                 ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    if (!$conexion->query($sqlTabla)) {
        die("No fue posible preparar el registro confidencial de VPH: " . $conexion->error);
    }
}

function categoriasVph()
{
    return [
        "primaria_11" => "Personas de quinto año de primaria o de 11 años no escolarizadas",
        "rezagados" => "Niñas y/o adolescentes de 12 a 16 años (rezagadas)",
        "violencia_9_14" => "Niñas y/o adolescentes víctimas de violación sexual de 9 a 14 años",
        "violencia_15_19" => "Niñas y/o adolescentes víctimas de violación sexual de 15 a 19 años",
        "mujeres_vih" => "Mujeres cis y trans de 11 a 49 años que viven con VIH",
        "hombres_vih" => "Hombres cis y trans de 11 a 49 años que viven con VIH"
    ];
}

function asegurarClasificacionHepatitisB($conexion)
{
    $stmt = $conexion->prepare(
        "SELECT COUNT(*) AS existe
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'APLICACION_VACUNA'
           AND COLUMN_NAME = 'clasificacion_hepatitis_b'"
    );
    if (!$stmt || !$stmt->execute()) {
        die("No fue posible verificar la clasificación de Hepatitis B.");
    }
    $existe = (int)$stmt->get_result()->fetch_assoc()["existe"] > 0;
    $stmt->close();
    if ($existe) {
        return;
    }

    if (!$conexion->query(
        "ALTER TABLE APLICACION_VACUNA
         ADD COLUMN clasificacion_hepatitis_b VARCHAR(30) NULL AFTER clasificacion_vph"
    )) {
        $stmt = $conexion->prepare(
            "SELECT COUNT(*) AS existe
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'APLICACION_VACUNA'
               AND COLUMN_NAME = 'clasificacion_hepatitis_b'"
        );
        if (!$stmt || !$stmt->execute()) {
            die("No fue posible preparar el almacenamiento de la clasificación de Hepatitis B.");
        }
        $existe = (int)$stmt->get_result()->fetch_assoc()["existe"] > 0;
        $stmt->close();
        if (!$existe) {
            die("No fue posible agregar la clasificación de Hepatitis B: " . $conexion->error);
        }
    }
}

function asegurarClasificacionSr($conexion)
{
    $stmt = $conexion->prepare(
        "SELECT COUNT(*) AS existe
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'APLICACION_VACUNA'
           AND COLUMN_NAME = 'clasificacion_sr'"
    );
    if (!$stmt || !$stmt->execute()) {
        die("No fue posible verificar la clasificación de SR.");
    }
    $existe = (int)$stmt->get_result()->fetch_assoc()["existe"] > 0;
    $stmt->close();
    if ($existe) {
        return;
    }

    if (!$conexion->query(
        "ALTER TABLE APLICACION_VACUNA
         ADD COLUMN clasificacion_sr VARCHAR(24) NULL AFTER clasificacion_hepatitis_b"
    )) {
        $stmt = $conexion->prepare(
            "SELECT COUNT(*) AS existe
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'APLICACION_VACUNA'
               AND COLUMN_NAME = 'clasificacion_sr'"
        );
        if (!$stmt || !$stmt->execute()) {
            die("No fue posible preparar el almacenamiento de la clasificación de SR.");
        }
        $existe = (int)$stmt->get_result()->fetch_assoc()["existe"] > 0;
        $stmt->close();
        if (!$existe) {
            die("No fue posible agregar la clasificación de SR: " . $conexion->error);
        }
    }
}

function asegurarClasificacionVaricelaHepatitisA($conexion)
{
    $stmt = $conexion->prepare(
        "SELECT COUNT(*) AS existe
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'APLICACION_VACUNA'
           AND COLUMN_NAME = 'clasificacion_varicela_hepatitis_a'"
    );
    if (!$stmt || !$stmt->execute()) {
        die("No fue posible verificar la clasificación de Varicela y Hepatitis A.");
    }
    $existe = (int)$stmt->get_result()->fetch_assoc()["existe"] > 0;
    $stmt->close();
    if ($existe) {
        return;
    }

    if (!$conexion->query(
        "ALTER TABLE APLICACION_VACUNA
         ADD COLUMN clasificacion_varicela_hepatitis_a VARCHAR(16) NULL AFTER clasificacion_sr"
    )) {
        $stmt = $conexion->prepare(
            "SELECT COUNT(*) AS existe
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'APLICACION_VACUNA'
               AND COLUMN_NAME = 'clasificacion_varicela_hepatitis_a'"
        );
        if (!$stmt || !$stmt->execute()) {
            die("No fue posible preparar la clasificación de Varicela y Hepatitis A.");
        }
        $existe = (int)$stmt->get_result()->fetch_assoc()["existe"] > 0;
        $stmt->close();
        if (!$existe) {
            die("No fue posible agregar la clasificación de Varicela y Hepatitis A: " . $conexion->error);
        }
    }
}

function obtenerRenglonHepatitisB($clasificacion, $nombreDosis)
{
    $dosis = mb_strtolower(trim((string)$nombreDosis), "UTF-8");
    $dosis = strtr($dosis, ["á" => "a", "é" => "e", "í" => "i", "ó" => "o", "ú" => "u"]);
    $sufijo = null;
    if (str_contains($dosis, "primera") || preg_match('/(^|\D)1(\D|$)/u', $dosis)) {
        $sufijo = "1";
    } elseif (str_contains($dosis, "segunda") || preg_match('/(^|\D)2(\D|$)/u', $dosis)) {
        $sufijo = "2";
    }
    if ($sufijo === null) {
        return null;
    }

    $prefijos = [
        "sin_antecedente" => "hepb_mayor11_",
        "riesgo" => "hepb_riesgo_"
    ];
    return isset($prefijos[$clasificacion]) ? $prefijos[$clasificacion] . $sufijo : null;
}

function obtenerExistenciaVph($conexion, $idLote, $idUnidad)
{
    $sql = "SELECT p.dosis_por_frasco,
                   COALESCE((
                       SELECT SUM(
                           CASE
                               WHEN mb.tipo_movimiento = 'Entrada'
                                    AND mb.id_unidad = ? THEN mb.cantidad_frascos
                               WHEN mb.tipo_movimiento = 'Donación'
                                    AND mb.id_unidad_receptora = ? THEN mb.cantidad_frascos
                               WHEN mb.tipo_movimiento = 'Donación'
                                    AND mb.id_unidad = ? THEN -mb.cantidad_frascos
                               WHEN mb.tipo_movimiento = 'Desecho'
                                    AND mb.id_unidad = ?
                                   THEN -mb.cantidad_dosis / p.dosis_por_frasco
                               ELSE 0
                           END
                       )
                       FROM MOVIMIENTO_BIOLOGICO mb
                       WHERE mb.id_lote = l.id_lote
                   ), 0)
                   - (
                       COALESCE((
                           SELECT COUNT(*)
                           FROM APLICACION_VACUNA av
                           WHERE av.id_lote = l.id_lote AND av.id_unidad = ?
                       ), 0)
                       + COALESCE((
                           SELECT COUNT(*)
                           FROM APLICACION_VPH_ANONIMA aa
                           WHERE aa.id_lote = l.id_lote AND aa.id_unidad = ?
                       ), 0)
                   ) / p.dosis_por_frasco AS disponible
            FROM LOTE l
            INNER JOIN PRESENTACION p ON p.id_presentacion = l.id_presentacion
            WHERE l.id_lote = ?";
    $stmt = $conexion->prepare($sql);
    if (!$stmt) {
        die("Error al preparar la verificación de existencias VPH: " . $conexion->error);
    }
    $stmt->bind_param(
        "iiiiiii",
        $idUnidad,
        $idUnidad,
        $idUnidad,
        $idUnidad,
        $idUnidad,
        $idUnidad,
        $idLote
    );
    if (!$stmt->execute()) {
        die("Error al verificar las existencias del lote VPH: " . $stmt->error);
    }
    $existencia = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $existencia;
}

function obtenerRenglonVph($clasificacion, $nombreDosis, $edadMeses = null)
{
    $dosis = mb_strtolower(trim((string)$nombreDosis), "UTF-8");
    $dosis = strtr($dosis, ["á" => "a", "é" => "e", "í" => "i", "ó" => "o", "ú" => "u"]);
    $renglonesFijos = [
        "primaria_11" => "vph_ninas",
        "rezagados" => "vph_rezagados",
        "violencia_9_14" => "vph_violencia_9_14",
        "violencia_15_19" => "vph_violencia_15_19"
    ];
    if (isset($renglonesFijos[$clasificacion])) {
        return str_contains($dosis, "unica") ? $renglonesFijos[$clasificacion] : null;
    }
    if ($clasificacion === "violencia" && $edadMeses !== null && str_contains($dosis, "unica")) {
        if ($edadMeses >= 108 && $edadMeses < 180) {
            return "vph_violencia_9_14";
        }
        if ($edadMeses >= 180 && $edadMeses < 240) {
            return "vph_violencia_15_19";
        }
        return null;
    }

    $sufijo = null;
    if (str_contains($dosis, "primera") || preg_match('/(^|\D)1(\D|$)/u', $dosis)) {
        $sufijo = "1";
    } elseif (str_contains($dosis, "segunda") || preg_match('/(^|\D)2(\D|$)/u', $dosis)) {
        $sufijo = "2";
    } elseif (str_contains($dosis, "tercera") || preg_match('/(^|\D)3(\D|$)/u', $dosis)) {
        $sufijo = "3";
    }
    if ($sufijo === null) {
        return null;
    }

    if ($clasificacion === "mujeres_vih") {
        return "vph_vih_" . $sufijo;
    }
    if ($clasificacion === "hombres_vih") {
        return "vph_vih_" . $sufijo;
    }
    return null;
}
