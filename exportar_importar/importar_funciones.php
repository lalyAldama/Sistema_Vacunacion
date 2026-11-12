<?php

function importacionLanzarError(string $mensaje): void
{
    throw new RuntimeException($mensaje);
}

function importacionConsultar(mysqli $conexion, string $sql, string $types = "", array $values = []): mysqli_stmt
{
    $stmt = $conexion->prepare($sql);
    if (!$stmt) {
        importacionLanzarError("No se pudo preparar la importación: " . $conexion->error);
    }

    if ($types !== "") {
        $referencias = [];
        foreach ($values as $indice => &$valor) {
            $referencias[$indice] = &$valor;
        }
        unset($valor);
        if (!$stmt->bind_param($types, ...$referencias)) {
            $stmt->close();
            importacionLanzarError("No se pudieron preparar los datos de importación.");
        }
    }

    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        importacionLanzarError("No se pudo guardar la importación: " . $error);
    }

    return $stmt;
}

function importacionValidarPaquete(array $sobre, string $contrasena): array
{
    if (
        ($sobre["formato"] ?? null) !== "sistema-vacunacion-cifrado"
        || ($sobre["version"] ?? null) !== 1
        || ($sobre["cifrado"] ?? null) !== "AES-256-GCM"
        || ($sobre["derivacion"] ?? null) !== "PBKDF2-HMAC-SHA256"
        || ($sobre["iteraciones"] ?? null) !== 310000
    ) {
        importacionLanzarError("El archivo no tiene un formato de exportación compatible.");
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
        importacionLanzarError("El archivo está incompleto o dañado.");
    }

    $clave = hash_pbkdf2("sha256", $contrasena, $sal, 310000, 32, true);
    $comprimido = openssl_decrypt(
        $datos,
        "aes-256-gcm",
        $clave,
        OPENSSL_RAW_DATA,
        $iv,
        $tag,
        "sistema-vacunacion-unidad:v1"
    );
    if ($comprimido === false) {
        importacionLanzarError("No se pudo descifrar el paquete. Verifica la contraseña.");
    }

    $json = gzdecode($comprimido, 200 * 1024 * 1024);
    if ($json === false) {
        importacionLanzarError("El contenido comprimido del paquete no es válido.");
    }
    try {
        $paquete = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $error) {
        importacionLanzarError("El contenido del paquete no es un JSON válido.");
    }

    if (
        !is_array($paquete)
        || ($paquete["formato"] ?? null) !== "sistema-vacunacion-unidad"
        || ($paquete["version"] ?? null) !== 2
        || !is_array($paquete["unidad_origen"] ?? null)
        || !is_array($paquete["catalogos"] ?? null)
        || !is_array($paquete["datos_unidad"] ?? null)
    ) {
        importacionLanzarError("El paquete no contiene una exportación de unidad compatible.");
    }

    $uuid = $paquete["unidad_origen"]["uuid_instalacion"] ?? "";
    $idUnidadOrigen = filter_var(
        $paquete["unidad_origen"]["id_local"] ?? null,
        FILTER_VALIDATE_INT
    );
    if (
        !is_string($uuid)
        || !preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $uuid
        )
        || $idUnidadOrigen === false
        || $idUnidadOrigen < 1
        || !is_string($paquete["unidad_origen"]["nombre"] ?? null)
    ) {
        importacionLanzarError("La identidad de la unidad de origen no es válida.");
    }

    $tablasDatos = [
        "TUTOR", "PACIENTE", "PACIENTE_CENSO", "PACIENTE_COMORBILIDAD",
        "PRESENTACION", "LOTE", "APLICACION_VACUNA", "APLICACION_VPH_ANONIMA",
        "MOVIMIENTO_BIOLOGICO", "MOVIMIENTO_JERINGA", "PALOTEO_GENERAL",
        "PALOTEO_INFLUENZA", "PALOTEO_COVID", "INFORME_ANUAL_META",
        "INFORME_ANUAL_ENCABEZADO", "CENSO_CONFIGURACION", "CENSO_HISTORIAL",
        "UNIDAD"
    ];
    $tablasCatalogo = [
        "ESQUEMA", "CENSO", "BIOLOGICO", "DOSIS", "ESQUEMA_BIOLOGICO",
        "ESQUEMA_BIOLOGICO_DOSIS", "COMORBILIDAD", "JERINGA"
    ];
    foreach ($tablasDatos as $tabla) {
        if (!array_key_exists($tabla, $paquete["datos_unidad"])) {
            importacionLanzarError("El paquete no incluye la sección " . $tabla . ".");
        }
    }
    foreach ($tablasCatalogo as $tabla) {
        if (!array_key_exists($tabla, $paquete["catalogos"])) {
            importacionLanzarError("El paquete no incluye el catálogo " . $tabla . ".");
        }
    }

    $totalRegistros = 0;
    foreach ([$paquete["catalogos"], $paquete["datos_unidad"]] as $grupo) {
        foreach ($grupo as $tabla => $filas) {
            if (!is_string($tabla) || !is_array($filas) || !array_is_list($filas)) {
                importacionLanzarError("Una sección del paquete tiene una estructura inválida.");
            }
            $totalRegistros += count($filas);
            if ($totalRegistros > 500000) {
                importacionLanzarError("El paquete supera el límite de registros permitido.");
            }
            foreach ($filas as $fila) {
                if (!is_array($fila) || array_is_list($fila)) {
                    importacionLanzarError("Se encontró un registro con formato inválido en " . $tabla . ".");
                }
            }
        }
    }

    return $paquete;
}

function importacionObtenerUnidad(mysqli $conexion, int $idUnidadOrigen, string $uuid, int $idUnidadDestino): array
{
    $stmtUnidad = importacionConsultar(
        $conexion,
        "SELECT id_unidad, nombre_unidad FROM UNIDAD WHERE id_unidad = ? LIMIT 1",
        "i",
        [$idUnidadDestino]
    );
    $unidadDestino = $stmtUnidad->get_result()->fetch_assoc();
    $stmtUnidad->close();
    if (!$unidadDestino) {
        importacionLanzarError("La unidad central seleccionada ya no existe.");
    }

    if (!$conexion->query(
        "CREATE TABLE IF NOT EXISTS IMPORTACION_REGISTRO (
            uuid_instalacion CHAR(36) NOT NULL,
            id_unidad_origen INT NOT NULL,
            id_unidad_destino INT NOT NULL,
            tabla VARCHAR(40) NOT NULL,
            id_registro_origen VARCHAR(64) NOT NULL,
            id_registro_destino VARCHAR(64) NOT NULL,
            actualizado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (
                uuid_instalacion, id_unidad_origen, id_unidad_destino,
                tabla, id_registro_origen
            ),
            KEY idx_importacion_destino (id_unidad_destino, tabla, id_registro_destino)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    )) {
        importacionLanzarError("No se pudo preparar la bitácora de importación: " . $conexion->error);
    }

    return [
        "id_origen" => $idUnidadOrigen,
        "uuid" => $uuid,
        "id_destino" => (int)$unidadDestino["id_unidad"],
        "nombre_destino" => (string)$unidadDestino["nombre_unidad"]
    ];
}

function importacionBuscarMapa(mysqli $conexion, array $contexto, string $tabla, string $idOrigen): ?string
{
    $stmt = importacionConsultar(
        $conexion,
        "SELECT id_registro_destino FROM IMPORTACION_REGISTRO
         WHERE uuid_instalacion = ? AND id_unidad_origen = ?
           AND id_unidad_destino = ? AND tabla = ? AND id_registro_origen = ?
         LIMIT 1",
        "siiss",
        [$contexto["uuid"], $contexto["id_origen"], $contexto["id_destino"], $tabla, $idOrigen]
    );
    $fila = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $fila ? (string)$fila["id_registro_destino"] : null;
}

function importacionGuardarMapa(
    mysqli $conexion,
    array $contexto,
    string $tabla,
    string $idOrigen,
    string $idDestino
): void {
    $stmt = importacionConsultar(
        $conexion,
        "INSERT INTO IMPORTACION_REGISTRO
            (uuid_instalacion, id_unidad_origen, id_unidad_destino,
             tabla, id_registro_origen, id_registro_destino)
         VALUES (?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE id_registro_destino = VALUES(id_registro_destino)",
        "siisss",
        [
            $contexto["uuid"], $contexto["id_origen"], $contexto["id_destino"],
            $tabla, $idOrigen, $idDestino
        ]
    );
    $stmt->close();
}

function importacionValidarCatalogo(mysqli $conexion, array $paquete): void
{
    $especificacion = [
        "ESQUEMA" => ["id_esquema", ["id_esquema", "nombre_esquema"]],
        "CENSO" => ["id_censo", ["id_censo", "nombre_censo", "id_esquema"]],
        "BIOLOGICO" => ["id_biologico", ["id_biologico", "nombre_biologico"]],
        "DOSIS" => ["id_dosis", ["id_dosis", "nombre_dosis"]],
        "ESQUEMA_BIOLOGICO" => [
            "id_esquema_biologico",
            ["id_esquema_biologico", "id_esquema", "id_biologico"]
        ],
        "ESQUEMA_BIOLOGICO_DOSIS" => [
            "id_esquema_biologico_dosis",
            ["id_esquema_biologico_dosis", "id_esquema_biologico", "id_dosis"]
        ],
        "COMORBILIDAD" => ["id_comorbilidad", ["id_comorbilidad", "nombre_comorbilidad"]],
        "JERINGA" => ["id_jeringa", ["id_jeringa", "tipo"]]
    ];

    foreach ($especificacion as $tabla => [$clave, $columnas]) {
        foreach ($paquete["catalogos"][$tabla] as $fila) {
            foreach ($columnas as $columna) {
                if (!array_key_exists($columna, $fila)) {
                    importacionLanzarError("El catálogo " . $tabla . " contiene columnas incompletas.");
                }
            }
            $valores = array_map(static function ($columna) use ($fila) {
                return (string)$fila[$columna];
            }, $columnas);
            $stmt = importacionConsultar(
                $conexion,
                "SELECT " . implode(", ", $columnas) . " FROM " . $tabla . " WHERE " . $clave . " = ? LIMIT 1",
                "s",
                [(string)$fila[$clave]]
            );
            $existente = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($existente) {
                foreach ($columnas as $indice => $columna) {
                    if ((string)$existente[$columna] !== $valores[$indice]) {
                        importacionLanzarError(
                            "El catálogo " . $tabla . " tiene una diferencia para el ID "
                            . (string)$fila[$clave] . ". No se modificó ningún dato."
                        );
                    }
                }
                continue;
            }

            $marcadores = implode(", ", array_fill(0, count($columnas), "?"));
            $stmt = importacionConsultar(
                $conexion,
                "INSERT INTO " . $tabla . " (" . implode(", ", $columnas) . ")
                 VALUES (" . $marcadores . ")",
                str_repeat("s", count($valores)),
                $valores
            );
            $stmt->close();
        }
    }
}

function importacionValidarPresentaciones(mysqli $conexion, array $paquete): void
{
    $columnas = [
        "id_presentacion", "id_biologico", "nombre_presentacion",
        "dosis_por_frasco", "ml_por_dosis"
    ];
    foreach ($paquete["datos_unidad"]["PRESENTACION"] as $fila) {
        foreach ($columnas as $columna) {
            if (!array_key_exists($columna, $fila)) {
                importacionLanzarError("El catálogo de presentaciones está incompleto.");
            }
        }
        $stmt = importacionConsultar(
            $conexion,
            "SELECT " . implode(", ", $columnas) . " FROM PRESENTACION WHERE id_presentacion = ? LIMIT 1",
            "s",
            [(string)$fila["id_presentacion"]]
        );
        $existente = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($existente) {
            foreach ($columnas as $columna) {
                if ((string)$existente[$columna] !== (string)$fila[$columna]) {
                    importacionLanzarError(
                        "La presentación " . (string)$fila["id_presentacion"]
                        . " tiene datos distintos en la base central."
                    );
                }
            }
            continue;
        }
        $stmt = importacionConsultar(
            $conexion,
            "INSERT INTO PRESENTACION
                (id_presentacion, id_biologico, nombre_presentacion, dosis_por_frasco, ml_por_dosis)
             VALUES (?, ?, ?, ?, ?)",
            "sssss",
            array_map(static fn($columna) => (string)$fila[$columna], $columnas)
        );
        $stmt->close();
    }
}

function importacionAsegurarCatalogosGlobales(mysqli $conexion, array $paquete): void
{
    importacionValidarCatalogo($conexion, $paquete);
    importacionValidarPresentaciones($conexion, $paquete);

    $unidadesOrigen = $paquete["datos_unidad"]["UNIDAD"];
    $origenEncontrado = false;
    foreach ($unidadesOrigen as $unidad) {
        if (
            (int)($unidad["id_unidad"] ?? 0) === (int)$paquete["unidad_origen"]["id_local"]
            && (string)($unidad["nombre_unidad"] ?? "") === (string)$paquete["unidad_origen"]["nombre"]
        ) {
            $origenEncontrado = true;
            continue;
        }
        if (!isset($unidad["id_unidad"], $unidad["nombre_unidad"])) {
            importacionLanzarError("La lista de unidades del paquete está incompleta.");
        }
        $stmt = importacionConsultar(
            $conexion,
            "SELECT id_unidad FROM UNIDAD WHERE nombre_unidad = ? LIMIT 1",
            "s",
            [(string)$unidad["nombre_unidad"]]
        );
        $encontrada = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$encontrada) {
            importacionLanzarError(
                "La unidad de referencia «" . (string)$unidad["nombre_unidad"]
                . "» no existe en la base central."
            );
        }
    }
    if (!$origenEncontrado) {
        importacionLanzarError("No coincide la unidad de origen con la lista de unidades exportada.");
    }
}

function importacionMapaUnidad(mysqli $conexion, array $paquete, array $contexto, $idUnidadPaquete): int
{
    if ((int)$idUnidadPaquete === $contexto["id_origen"]) {
        return $contexto["id_destino"];
    }

    foreach ($paquete["datos_unidad"]["UNIDAD"] as $unidad) {
        if ((int)($unidad["id_unidad"] ?? 0) !== (int)$idUnidadPaquete) {
            continue;
        }
        $stmt = importacionConsultar(
            $conexion,
            "SELECT id_unidad FROM UNIDAD WHERE nombre_unidad = ? LIMIT 1",
            "s",
            [(string)$unidad["nombre_unidad"]]
        );
        $fila = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($fila) {
            return (int)$fila["id_unidad"];
        }
    }

    importacionLanzarError("El paquete referencia una unidad que no se pudo resolver.");
}

function importacionObtenerIdRelacionado(
    mysqli $conexion,
    array $contexto,
    string $tabla,
    $idOrigen
): string {
    if ($idOrigen === null || $idOrigen === "") {
        importacionLanzarError("Un registro del paquete tiene una relación obligatoria vacía.");
    }
    $id = importacionBuscarMapa($conexion, $contexto, $tabla, (string)$idOrigen);
    if ($id === null) {
        importacionLanzarError(
            "No se encontró el registro relacionado " . $tabla . " con ID " . (string)$idOrigen . "."
        );
    }
    return $id;
}

function importacionGuardarEntidad(
    mysqli $conexion,
    array $contexto,
    string $tabla,
    string $idOrigen,
    ?string $idDestino,
    string $insertSql,
    array $insertValores,
    ?string $updateSql,
    array $updateValores,
    ?string $origenSql,
    array $origenValores
): string {
    if ($idDestino !== null) {
        if ($updateSql !== null) {
            $stmt = importacionConsultar(
                $conexion,
                $updateSql,
                str_repeat("s", count($updateValores)),
                $updateValores
            );
            $stmt->close();
        }
        importacionGuardarMapa($conexion, $contexto, $tabla, $idOrigen, $idDestino);
        return $idDestino;
    }

    $stmt = importacionConsultar(
        $conexion,
        $insertSql,
        str_repeat("s", count($insertValores)),
        $insertValores
    );
    $idDestino = (string)$conexion->insert_id;
    $stmt->close();

    if ($origenSql !== null) {
        $origenValores = array_map(
            static fn($valor) => $valor === "{target}" ? $idDestino : $valor,
            $origenValores
        );
        $stmt = importacionConsultar(
            $conexion,
            $origenSql,
            str_repeat("s", count($origenValores)),
            $origenValores
        );
        $stmt->close();
    }

    importacionGuardarMapa($conexion, $contexto, $tabla, $idOrigen, $idDestino);
    return $idDestino;
}

function importacionEjecutar(
    mysqli $conexion,
    array $paquete,
    array $contexto,
    int $idUsuarioCentral
): array {
    $resumen = [];
    importacionAsegurarCatalogosGlobales($conexion, $paquete);

    foreach ($paquete["datos_unidad"]["TUTOR"] as $fila) {
        $idOrigen = (string)($fila["id_tutor"] ?? "");
        if ($idOrigen === "") {
            importacionLanzarError("El paquete contiene un tutor sin identificador.");
        }
        $idDestino = importacionBuscarMapa($conexion, $contexto, "TUTOR", $idOrigen);
        $columnas = [
            "ap_pat", "ap_mat", "nombres", "fecha_nacimiento",
            "telefono_tutor", "sexo", "curp", "calle_tutor",
            "num_tutor", "colonia_tutor"
        ];
        $valores = array_map(static fn($columna) => $fila[$columna] ?? null, $columnas);
        $actualizar = null;
        $valoresActualizar = [];
        if ($idDestino !== null) {
            $asignaciones = implode(", ", array_map(static fn($columna) => $columna . " = ?", $columnas));
            $actualizar = "UPDATE TUTOR SET " . $asignaciones . " WHERE id_tutor = ?";
            $valoresActualizar = array_merge($valores, [$idDestino]);
        }
        importacionGuardarEntidad(
            $conexion,
            $contexto,
            "TUTOR",
            $idOrigen,
            $idDestino,
            "INSERT INTO TUTOR (" . implode(", ", $columnas) . ") VALUES ("
                . implode(", ", array_fill(0, count($columnas), "?")) . ")",
            $valores,
            $actualizar,
            $valoresActualizar,
            "UPDATE TUTOR SET id_tutor_origen = ?, id_unidad_origen = ? WHERE id_tutor = ?",
            ["{target}", (string)$contexto["id_destino"], "{target}"]
        );
    }
    $resumen["TUTOR"] = count($paquete["datos_unidad"]["TUTOR"]);

    foreach ($paquete["datos_unidad"]["PACIENTE"] as $fila) {
        $idOrigen = (string)($fila["id_paciente"] ?? "");
        if ($idOrigen === "") {
            importacionLanzarError("El paquete contiene un paciente sin identificador.");
        }
        $idDestino = importacionBuscarMapa($conexion, $contexto, "PACIENTE", $idOrigen);
        $idTutorDestino = null;
        if (!empty($fila["id_tutor"])) {
            $idTutorDestino = importacionObtenerIdRelacionado(
                $conexion,
                $contexto,
                "TUTOR",
                $fila["id_tutor"]
            );
        }
        $columnas = [
            "ap_pat", "ap_mat", "nombres", "fecha_nacimiento", "sexo", "curp",
            "derechoabiencia", "poblacion_indigena_afromexicana", "estatus_migratorio",
            "calle", "num", "colonia", "telefono_paciente", "embarazo",
            "fecha_ultima_menstruacion", "personal_salud", "jornalero_agricola",
            "id_tutor", "parentesco"
        ];
        $valores = array_map(static fn($columna) => $columna === "id_tutor"
            ? $idTutorDestino
            : ($fila[$columna] ?? null), $columnas);
        if ($idDestino === null) {
            $uuid = $conexion->query("SELECT UUID()")->fetch_row()[0];
            $columns = array_merge(["id_paciente", "id_paciente_origen", "id_unidad_origen"], $columnas);
            $insertValues = array_merge([$uuid, $uuid, (string)$contexto["id_destino"]], $valores);
            $stmt = importacionConsultar(
                $conexion,
                "INSERT INTO PACIENTE (" . implode(", ", $columns) . ") VALUES ("
                    . implode(", ", array_fill(0, count($columns), "?")) . ")",
                str_repeat("s", count($insertValues)),
                $insertValues
            );
            $stmt->close();
            $idDestino = (string)$uuid;
        } else {
            $asignaciones = implode(", ", array_map(static fn($columna) => $columna . " = ?", $columnas));
            $stmt = importacionConsultar(
                $conexion,
                "UPDATE PACIENTE SET " . $asignaciones . " WHERE id_paciente = ?",
                str_repeat("s", count($valores) + 1),
                array_merge($valores, [$idDestino])
            );
            $stmt->close();
        }
        importacionGuardarMapa($conexion, $contexto, "PACIENTE", $idOrigen, $idDestino);
    }
    $resumen["PACIENTE"] = count($paquete["datos_unidad"]["PACIENTE"]);

    foreach ($paquete["datos_unidad"]["PACIENTE_CENSO"] as $fila) {
        $idPaciente = importacionObtenerIdRelacionado(
            $conexion,
            $contexto,
            "PACIENTE",
            $fila["id_paciente"] ?? null
        );
        $unidad = importacionMapaUnidad(
            $conexion,
            $paquete,
            $contexto,
            $fila["id_unidad"] ?? $contexto["id_origen"]
        );
        $stmt = importacionConsultar(
            $conexion,
            "INSERT INTO PACIENTE_CENSO (id_paciente, id_censo, id_unidad)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE id_unidad = VALUES(id_unidad)",
            "sii",
            [$idPaciente, (int)($fila["id_censo"] ?? 0), $unidad]
        );
        $stmt->close();
    }
    $resumen["PACIENTE_CENSO"] = count($paquete["datos_unidad"]["PACIENTE_CENSO"]);

    foreach ($paquete["datos_unidad"]["PACIENTE_COMORBILIDAD"] as $fila) {
        $idPaciente = importacionObtenerIdRelacionado(
            $conexion,
            $contexto,
            "PACIENTE",
            $fila["id_paciente"] ?? null
        );
        $stmt = importacionConsultar(
            $conexion,
            "INSERT IGNORE INTO PACIENTE_COMORBILIDAD (id_paciente, id_comorbilidad)
             VALUES (?, ?)",
            "si",
            [$idPaciente, (int)($fila["id_comorbilidad"] ?? 0)]
        );
        $stmt->close();
    }
    $resumen["PACIENTE_COMORBILIDAD"] = count($paquete["datos_unidad"]["PACIENTE_COMORBILIDAD"]);

    foreach ($paquete["datos_unidad"]["LOTE"] as $fila) {
        $idOrigen = (string)($fila["id_lote"] ?? "");
        if ($idOrigen === "") {
            importacionLanzarError("El paquete contiene un lote sin identificador.");
        }
        $idDestino = importacionBuscarMapa($conexion, $contexto, "LOTE", $idOrigen);
        $columnas = ["id_presentacion", "numero_lote", "fecha_caducidad"];
        $valores = array_map(static fn($columna) => $fila[$columna] ?? null, $columnas);
        $updateSql = null;
        $updateValues = [];
        if ($idDestino !== null) {
            $updateSql = "UPDATE LOTE SET id_presentacion = ?, numero_lote = ?,
                          fecha_caducidad = ? WHERE id_lote = ?";
            $updateValues = array_merge($valores, [$idDestino]);
        }
        $idDestino = importacionGuardarEntidad(
            $conexion,
            $contexto,
            "LOTE",
            $idOrigen,
            $idDestino,
            "INSERT INTO LOTE (id_presentacion, numero_lote, fecha_caducidad) VALUES (?, ?, ?)",
            $valores,
            $updateSql,
            $updateValues,
            "UPDATE LOTE SET id_lote_origen = ?, id_unidad_origen = ? WHERE id_lote = ?",
            ["{target}", (string)$contexto["id_destino"], "{target}"]
        );
    }
    $resumen["LOTE"] = count($paquete["datos_unidad"]["LOTE"]);

    foreach ($paquete["datos_unidad"]["APLICACION_VACUNA"] as $fila) {
        $idOrigen = (string)($fila["id_aplicacion_vacuna"] ?? "");
        if ($idOrigen === "") {
            importacionLanzarError("El paquete contiene una aplicación sin identificador.");
        }
        $idDestino = importacionBuscarMapa($conexion, $contexto, "APLICACION_VACUNA", $idOrigen);
        $idPaciente = importacionObtenerIdRelacionado($conexion, $contexto, "PACIENTE", $fila["id_paciente"] ?? null);
        $idLote = importacionObtenerIdRelacionado($conexion, $contexto, "LOTE", $fila["id_lote"] ?? null);
        $columnas = [
            "id_paciente", "id_esquema_biologico_dosis", "id_lote", "id_unidad",
            "id_biologico_indicado", "fecha_aplicacion", "clasificacion_vph",
            "clasificacion_hepatitis_b", "clasificacion_sr",
            "clasificacion_varicela_hepatitis_a", "dosis_cero", "observaciones"
        ];
        $valores = [
            $idPaciente,
            $fila["id_esquema_biologico_dosis"] ?? null,
            $idLote,
            $contexto["id_destino"],
            $fila["id_biologico_indicado"] ?? null,
            $fila["fecha_aplicacion"] ?? null,
            $fila["clasificacion_vph"] ?? null,
            $fila["clasificacion_hepatitis_b"] ?? null,
            $fila["clasificacion_sr"] ?? null,
            $fila["clasificacion_varicela_hepatitis_a"] ?? null,
            $fila["dosis_cero"] ?? null,
            $fila["observaciones"] ?? null
        ];
        $sqlInsert = "INSERT INTO APLICACION_VACUNA (" . implode(", ", $columnas)
            . ") VALUES (" . implode(", ", array_fill(0, count($columnas), "?")) . ")";
        $sqlUpdate = null;
        $updateValues = [];
        if ($idDestino !== null) {
            $sqlUpdate = "UPDATE APLICACION_VACUNA SET "
                . implode(", ", array_map(static fn($columna) => $columna . " = ?", $columnas))
                . " WHERE id_aplicacion_vacuna = ?";
            $updateValues = array_merge($valores, [$idDestino]);
        }
        $idDestino = importacionGuardarEntidad(
            $conexion,
            $contexto,
            "APLICACION_VACUNA",
            $idOrigen,
            $idDestino,
            $sqlInsert,
            $valores,
            $sqlUpdate,
            $updateValues,
            "UPDATE APLICACION_VACUNA SET id_aplicacion_origen = ?, id_unidad_origen = ?
             WHERE id_aplicacion_vacuna = ?",
            ["{target}", (string)$contexto["id_destino"], "{target}"]
        );
    }
    $resumen["APLICACION_VACUNA"] = count($paquete["datos_unidad"]["APLICACION_VACUNA"]);

    foreach ($paquete["datos_unidad"]["MOVIMIENTO_BIOLOGICO"] as $fila) {
        $idOrigen = (string)($fila["id_movimiento"] ?? "");
        if ($idOrigen === "") {
            importacionLanzarError("El paquete contiene un movimiento de biológico sin identificador.");
        }
        $idDestino = importacionBuscarMapa($conexion, $contexto, "MOVIMIENTO_BIOLOGICO", $idOrigen);
        $idLote = importacionObtenerIdRelacionado($conexion, $contexto, "LOTE", $fila["id_lote"] ?? null);
        $idUnidadMovimiento = importacionMapaUnidad(
            $conexion,
            $paquete,
            $contexto,
            $fila["id_unidad"] ?? $contexto["id_origen"]
        );
        $idUnidadReceptora = empty($fila["id_unidad_receptora"])
            ? null
            : importacionMapaUnidad($conexion, $paquete, $contexto, $fila["id_unidad_receptora"]);
        $columnas = [
            "id_unidad", "id_lote", "tipo_movimiento", "cantidad_frascos",
            "cantidad_dosis", "fecha_movimiento", "necesidad", "observaciones",
            "id_unidad_receptora"
        ];
        $valores = [
            $idUnidadMovimiento, $idLote, $fila["tipo_movimiento"] ?? null,
            $fila["cantidad_frascos"] ?? null, $fila["cantidad_dosis"] ?? null,
            $fila["fecha_movimiento"] ?? null, $fila["necesidad"] ?? null,
            $fila["observaciones"] ?? null, $idUnidadReceptora
        ];
        $sqlUpdate = null;
        $updateValues = [];
        if ($idDestino !== null) {
            $sqlUpdate = "UPDATE MOVIMIENTO_BIOLOGICO SET "
                . implode(", ", array_map(static fn($columna) => $columna . " = ?", $columnas))
                . " WHERE id_movimiento = ?";
            $updateValues = array_merge($valores, [$idDestino]);
        }
        importacionGuardarEntidad(
            $conexion,
            $contexto,
            "MOVIMIENTO_BIOLOGICO",
            $idOrigen,
            $idDestino,
            "INSERT INTO MOVIMIENTO_BIOLOGICO (" . implode(", ", $columnas) . ")
             VALUES (" . implode(", ", array_fill(0, count($columnas), "?")) . ")",
            $valores,
            $sqlUpdate,
            $updateValues,
            "UPDATE MOVIMIENTO_BIOLOGICO SET id_movimiento_origen = ?, id_unidad_origen = ?
             WHERE id_movimiento = ?",
            ["{target}", (string)$contexto["id_destino"], "{target}"]
        );
    }
    $resumen["MOVIMIENTO_BIOLOGICO"] = count($paquete["datos_unidad"]["MOVIMIENTO_BIOLOGICO"]);

    foreach ($paquete["datos_unidad"]["MOVIMIENTO_JERINGA"] as $fila) {
        $idOrigen = (string)($fila["id_movimiento_jeringa"] ?? "");
        if ($idOrigen === "") {
            importacionLanzarError("El paquete contiene un movimiento de jeringa sin identificador.");
        }
        $idDestino = importacionBuscarMapa($conexion, $contexto, "MOVIMIENTO_JERINGA", $idOrigen);
        $idUnidadMovimiento = importacionMapaUnidad(
            $conexion,
            $paquete,
            $contexto,
            $fila["id_unidad"] ?? $contexto["id_origen"]
        );
        $columnas = [
            "id_jeringa", "id_unidad", "tipo_movimiento", "fecha_movimiento",
            "cantidad_jeringas", "necesidad"
        ];
        $valores = [
            $fila["id_jeringa"] ?? null, $idUnidadMovimiento,
            $fila["tipo_movimiento"] ?? null, $fila["fecha_movimiento"] ?? null,
            $fila["cantidad_jeringas"] ?? null, $fila["necesidad"] ?? null
        ];
        $sqlUpdate = null;
        $updateValues = [];
        if ($idDestino !== null) {
            $sqlUpdate = "UPDATE MOVIMIENTO_JERINGA SET "
                . implode(", ", array_map(static fn($columna) => $columna . " = ?", $columnas))
                . " WHERE id_movimiento_jeringa = ?";
            $updateValues = array_merge($valores, [$idDestino]);
        }
        importacionGuardarEntidad(
            $conexion,
            $contexto,
            "MOVIMIENTO_JERINGA",
            $idOrigen,
            $idDestino,
            "INSERT INTO MOVIMIENTO_JERINGA (" . implode(", ", $columnas) . ")
             VALUES (" . implode(", ", array_fill(0, count($columnas), "?")) . ")",
            $valores,
            $sqlUpdate,
            $updateValues,
            "UPDATE MOVIMIENTO_JERINGA SET id_movimiento_origen = ?, id_unidad_origen = ?
             WHERE id_movimiento_jeringa = ?",
            ["{target}", (string)$contexto["id_destino"], "{target}"]
        );
    }
    $resumen["MOVIMIENTO_JERINGA"] = count($paquete["datos_unidad"]["MOVIMIENTO_JERINGA"]);

    foreach (["APLICACION_VPH_ANONIMA", "PALOTEO_GENERAL", "PALOTEO_INFLUENZA", "PALOTEO_COVID"] as $tabla) {
        foreach ($paquete["datos_unidad"][$tabla] as $fila) {
            $idOrigen = (string)($fila["id_aplicacion_vph_anonima"] ?? $fila["id_paloteo"] ?? "");
            if ($idOrigen === "") {
                importacionLanzarError("El paquete contiene un registro sin identificador en " . $tabla . ".");
            }
            $idDestino = importacionBuscarMapa($conexion, $contexto, $tabla, $idOrigen);
            if ($tabla === "APLICACION_VPH_ANONIMA") {
                $columnas = [
                    "id_esquema_biologico_dosis", "id_lote", "id_unidad", "id_usuario",
                    "fecha_aplicacion", "clasificacion_vph", "es_migrante", "creado_en"
                ];
                $valores = [
                    $fila["id_esquema_biologico_dosis"] ?? null,
                    importacionObtenerIdRelacionado($conexion, $contexto, "LOTE", $fila["id_lote"] ?? null),
                    $contexto["id_destino"], $idUsuarioCentral,
                    $fila["fecha_aplicacion"] ?? null, $fila["clasificacion_vph"] ?? null,
                    $fila["es_migrante"] ?? null, $fila["creado_en"] ?? null
                ];
                $pk = "id_aplicacion_vph_anonima";
            } else {
                $columnas = ["id_unidad", "anio", "mes", "datos_json", "id_usuario", "actualizado_en"];
                $valores = [
                    $contexto["id_destino"], $fila["anio"] ?? null, $fila["mes"] ?? null,
                    $fila["datos_json"] ?? null, $idUsuarioCentral, $fila["actualizado_en"] ?? null
                ];
                $pk = "id_paloteo";
            }
            $sqlInsert = "INSERT INTO " . $tabla . " (" . implode(", ", $columnas) . ")
                VALUES (" . implode(", ", array_fill(0, count($columnas), "?")) . ")";
            $sqlUpdate = null;
            $updateValues = [];
            if ($idDestino !== null) {
                $sqlUpdate = "UPDATE " . $tabla . " SET "
                    . implode(", ", array_map(static fn($columna) => $columna . " = ?", $columnas))
                    . " WHERE " . $pk . " = ?";
                $updateValues = array_merge($valores, [$idDestino]);
            } elseif ($tabla !== "APLICACION_VPH_ANONIMA") {
                $stmtExistente = importacionConsultar(
                    $conexion,
                    "SELECT " . $pk . " FROM " . $tabla . "
                     WHERE id_unidad = ? AND anio = ? AND mes = ? LIMIT 1",
                    "iii",
                    [$contexto["id_destino"], (int)($fila["anio"] ?? 0), (int)($fila["mes"] ?? 0)]
                );
                $existente = $stmtExistente->get_result()->fetch_assoc();
                $stmtExistente->close();
                if ($existente) {
                    importacionLanzarError(
                        "Ya existe un paloteo para ese periodo que no pertenece a esta instalación. "
                        . "No se modificó."
                    );
                }
            }

            $idDestino = importacionGuardarEntidad(
                $conexion,
                $contexto,
                $tabla,
                $idOrigen,
                $idDestino,
                $sqlInsert,
                $valores,
                $sqlUpdate,
                $updateValues,
                null,
                []
            );
        }
        $resumen[$tabla] = count($paquete["datos_unidad"][$tabla]);
    }

    foreach (["INFORME_ANUAL_META", "INFORME_ANUAL_ENCABEZADO"] as $tabla) {
        foreach ($paquete["datos_unidad"][$tabla] as $fila) {
            $idOrigen = $tabla === "INFORME_ANUAL_META"
                ? (string)($fila["anio"] ?? "") . ":" . (string)($fila["clave"] ?? "")
                : (string)($fila["clave"] ?? "");
            if ($idOrigen === ":" || $idOrigen === "") {
                importacionLanzarError("El paquete contiene un dato de informe sin clave.");
            }
            $idDestino = importacionBuscarMapa($conexion, $contexto, $tabla, $idOrigen);
            if ($tabla === "INFORME_ANUAL_META") {
                $sql = "INSERT INTO INFORME_ANUAL_META
                            (id_unidad, anio, clave, meta, id_usuario, actualizado_en)
                        VALUES (?, ?, ?, ?, ?, ?)";
                $valores = [
                    $contexto["id_destino"], $fila["anio"] ?? null, $fila["clave"] ?? null,
                    $fila["meta"] ?? null, $idUsuarioCentral, $fila["actualizado_en"] ?? null
                ];
                $where = "id_unidad = ? AND anio = ? AND clave = ?";
                $whereValues = [$contexto["id_destino"], $fila["anio"] ?? null, $fila["clave"] ?? null];
                $pk = "clave";
            } else {
                $sql = "INSERT INTO INFORME_ANUAL_ENCABEZADO
                            (id_unidad, clave, valor, id_usuario, actualizado_en)
                        VALUES (?, ?, ?, ?, ?)";
                $valores = [
                    $contexto["id_destino"], $fila["clave"] ?? null, $fila["valor"] ?? null,
                    $idUsuarioCentral, $fila["actualizado_en"] ?? null
                ];
                $where = "id_unidad = ? AND clave = ?";
                $whereValues = [$contexto["id_destino"], $fila["clave"] ?? null];
                $pk = "clave";
            }
            if ($idDestino === null) {
                $stmtExiste = importacionConsultar(
                    $conexion,
                    "SELECT " . $pk . " FROM " . $tabla . " WHERE " . $where . " LIMIT 1",
                    str_repeat("s", count($whereValues)),
                    array_map(static fn($valor) => (string)$valor, $whereValues)
                );
                $existe = $stmtExiste->get_result()->fetch_assoc();
                $stmtExiste->close();
                if ($existe) {
                    importacionLanzarError(
                        "Ya existe una clave de " . $tabla . " para esta unidad central. No se modificó."
                    );
                }
            } else {
                $set = $tabla === "INFORME_ANUAL_META"
                    ? "meta = ?, id_usuario = ?, actualizado_en = ?"
                    : "valor = ?, id_usuario = ?, actualizado_en = ?";
                $valoresActualizar = $tabla === "INFORME_ANUAL_META"
                    ? [$fila["meta"] ?? null, $idUsuarioCentral, $fila["actualizado_en"] ?? null, ...$whereValues]
                    : [$fila["valor"] ?? null, $idUsuarioCentral, $fila["actualizado_en"] ?? null, ...$whereValues];
                $stmtActualizar = importacionConsultar(
                    $conexion,
                    "UPDATE " . $tabla . " SET " . $set . " WHERE " . $where,
                    str_repeat("s", count($valoresActualizar)),
                    array_map(static fn($valor) => (string)$valor, $valoresActualizar)
                );
                $stmtActualizar->close();
            }
            importacionGuardarMapa(
                $conexion,
                $contexto,
                $tabla,
                $idOrigen,
                (string)($idDestino ?? $idOrigen)
            );
            if ($idDestino === null) {
                $stmtInsert = importacionConsultar(
                    $conexion,
                    $sql,
                    str_repeat("s", count($valores)),
                    array_map(static fn($valor) => (string)$valor, $valores)
                );
                $stmtInsert->close();
            }
        }
        $resumen[$tabla] = count($paquete["datos_unidad"][$tabla]);
    }

    foreach ($paquete["datos_unidad"]["CENSO_CONFIGURACION"] as $fila) {
        $columnas = [
            "edad_minima", "edad_maxima", "sexo", "embarazo",
            "poblacion_indigena_afromexicana", "migrante",
            "personal_salud", "jornalero_agricola"
        ];
        $idCenso = (int)($fila["id_censo"] ?? 0);
        if ($idCenso < 1) {
            importacionLanzarError("Una configuración de censo no tiene un ID válido.");
        }
        $valores = array_map(static fn($columna) => $fila[$columna] ?? null, $columnas);
        $stmtExistente = importacionConsultar(
            $conexion,
            "SELECT " . implode(", ", $columnas) . " FROM CENSO_CONFIGURACION WHERE id_censo = ? LIMIT 1",
            "i",
            [$idCenso]
        );
        $existente = $stmtExistente->get_result()->fetch_assoc();
        $stmtExistente->close();
        if ($existente) {
            if (array_map("strval", array_values($existente)) !== array_map("strval", $valores)) {
                importacionLanzarError(
                    "La configuración central del censo " . $idCenso
                    . " difiere de la exportada. No se modificó."
                );
            }
        } else {
            $stmtInsert = importacionConsultar(
                $conexion,
                "INSERT INTO CENSO_CONFIGURACION (id_censo, " . implode(", ", $columnas)
                    . ") VALUES (" . implode(", ", array_fill(0, count($columnas) + 1, "?")) . ")",
                str_repeat("s", count($columnas) + 1),
                array_merge([$idCenso], $valores)
            );
            $stmtInsert->close();
        }
    }
    $resumen["CENSO_CONFIGURACION"] = count($paquete["datos_unidad"]["CENSO_CONFIGURACION"]);

    foreach ($paquete["datos_unidad"]["CENSO_HISTORIAL"] as $fila) {
        $idOrigen = (string)($fila["id_historial"] ?? "");
        if ($idOrigen === "") {
            importacionLanzarError("El paquete contiene un historial sin identificador.");
        }
        $idDestino = importacionBuscarMapa($conexion, $contexto, "CENSO_HISTORIAL", $idOrigen);
        if ($idDestino !== null) {
            continue;
        }
        $contenido = base64_decode((string)($fila["contenido_base64"] ?? ""), true);
        if ($contenido === false) {
            importacionLanzarError("Un archivo del historial incluido en el paquete está dañado.");
        }
        $stmt = importacionConsultar(
            $conexion,
            "INSERT INTO CENSO_HISTORIAL
                (tipo_reporte, id_censo, id_unidad, fecha_captura, hash_contenido, contenido)
             VALUES (?, ?, ?, ?, ?, ?)",
            "siisss",
            [
                (string)($fila["tipo_reporte"] ?? ""),
                (string)($fila["id_censo"] ?? ""),
                (string)$contexto["id_destino"],
                (string)($fila["fecha_captura"] ?? ""),
                (string)($fila["hash_contenido"] ?? ""),
                $contenido
            ]
        );
        $idDestino = (string)$conexion->insert_id;
        $stmt->close();
        importacionGuardarMapa($conexion, $contexto, "CENSO_HISTORIAL", $idOrigen, $idDestino);
    }
    $resumen["CENSO_HISTORIAL"] = count($paquete["datos_unidad"]["CENSO_HISTORIAL"]);

    return $resumen;
}
