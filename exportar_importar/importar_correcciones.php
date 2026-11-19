<?php
require_once __DIR__ . "/../config/seguridad.php";

if (!isset($_SESSION["id_usuario"])) {
    header("Location: ../login.php");
    exit();
}
if (($_SESSION["nombre_rol"] ?? "") !== "Personal de unidad") {
    http_response_code(403);
    exit("Esta función está disponible solo para personal de unidad.");
}

require_once __DIR__ . "/../config/conexion.php";
require_once __DIR__ . "/../config/unidad_contexto.php";
require_once __DIR__ . "/correcciones_funciones.php";
require_once __DIR__ . "/importar_funciones.php";

$contextoUnidad = unidadObtenerContexto($conexion);
$idUnidad = (int)$contextoUnidad["id_unidad"];
$idUsuario = (int)$_SESSION["id_usuario"];
$mensaje = "";
$tipoMensaje = "";
$historial = [];

$sqlAplicadas = "CREATE TABLE IF NOT EXISTS CORRECCION_APLICADA_SUPERVISOR (
    id_cambio CHAR(36) NOT NULL,
    fecha_importacion DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id_cambio)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
$sqlMapaEntidades = "CREATE TABLE IF NOT EXISTS CORRECCION_MAPA_ENTIDAD (
    tabla VARCHAR(40) NOT NULL,
    id_central INT NOT NULL,
    id_local INT NOT NULL,
    creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (tabla, id_central),
    UNIQUE KEY uq_correccion_mapa_entidad_local (tabla, id_local)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
$sqlHistorial = "CREATE TABLE IF NOT EXISTS HISTORIAL_CORRECCIONES_SUPERVISOR (
    id_historial INT AUTO_INCREMENT PRIMARY KEY,
    fecha_importacion DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    nombre_archivo VARCHAR(255) NOT NULL,
    cantidad_registros_actualizados INT NOT NULL,
    tablas_afectadas VARCHAR(500) NOT NULL,
    usuario_importador VARCHAR(100) NOT NULL,
    detalles_importacion LONGTEXT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
$sqlTablasPaloteo = [
    "CREATE TABLE IF NOT EXISTS PALOTEO_GENERAL (
        id_paloteo INT AUTO_INCREMENT PRIMARY KEY,
        id_unidad INT NOT NULL,
        anio SMALLINT UNSIGNED NOT NULL,
        mes TINYINT UNSIGNED NOT NULL,
        datos_json LONGTEXT NOT NULL,
        id_usuario INT NOT NULL,
        actualizado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_paloteo_general_periodo (id_unidad, anio, mes)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS PALOTEO_INFLUENZA (
        id_paloteo INT AUTO_INCREMENT PRIMARY KEY,
        id_unidad INT NOT NULL,
        anio SMALLINT UNSIGNED NOT NULL,
        mes TINYINT UNSIGNED NOT NULL,
        datos_json LONGTEXT NOT NULL,
        id_usuario INT NOT NULL,
        actualizado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_paloteo_influenza_periodo (id_unidad, anio, mes)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS PALOTEO_COVID (
        id_paloteo INT AUTO_INCREMENT PRIMARY KEY,
        id_unidad INT NOT NULL,
        anio SMALLINT UNSIGNED NOT NULL,
        mes TINYINT UNSIGNED NOT NULL,
        datos_json LONGTEXT NOT NULL,
        id_usuario INT NOT NULL,
        actualizado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_paloteo_covid_periodo (id_unidad, anio, mes)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
];

foreach ([$sqlAplicadas, $sqlMapaEntidades, $sqlHistorial] as $sqlTabla) {
    if (!$conexion->query($sqlTabla)) {
        http_response_code(500);
        exit("No se pudieron preparar las tablas de importación: " . $conexion->error);
    }
}
foreach ($sqlTablasPaloteo as $sqlTabla) {
    if (!$conexion->query($sqlTabla)) {
        http_response_code(500);
        exit("No se pudieron preparar las tablas de paloteo: " . $conexion->error);
    }
}

function correccionesAplicarCambio(mysqli $conexion, array $cambio, int $idUnidad): string
{
    $tabla = $cambio["tabla"] ?? "";
    $datos = $cambio["datos"] ?? null;
    if (!is_array($datos)) {
        throw new RuntimeException("El paquete contiene un cambio incompleto.");
    }

    if ($tabla === "LOTE") {
        $idLoteCentral = filter_var($datos["id_lote_central"] ?? null, FILTER_VALIDATE_INT);
        $idPresentacion = filter_var($datos["id_presentacion"] ?? null, FILTER_VALIDATE_INT);
        $numeroLote = is_string($datos["numero_lote"] ?? null) ? trim($datos["numero_lote"]) : "";
        $fechaCaducidad = is_string($datos["fecha_caducidad"] ?? null) ? $datos["fecha_caducidad"] : "";
        $fechaValida = DateTimeImmutable::createFromFormat("!Y-m-d", $fechaCaducidad);
        if (
            $idLoteCentral === false || $idLoteCentral < 1
            || $idPresentacion === false || $idPresentacion < 1
            || $numeroLote === "" || mb_strlen($numeroLote, "UTF-8") > 100
            || !$fechaValida || $fechaValida->format("Y-m-d") !== $fechaCaducidad
        ) {
            throw new RuntimeException("El cambio contiene un lote no válido.");
        }

        $tablaMapa = "LOTE";
        $stmtMapa = $conexion->prepare(
            "SELECT id_local FROM CORRECCION_MAPA_ENTIDAD
             WHERE tabla = ? AND id_central = ? LIMIT 1"
        );
        $stmtMapa->bind_param("si", $tablaMapa, $idLoteCentral);
        if (!$stmtMapa->execute()) {
            throw new RuntimeException("No se pudo consultar el mapa de lotes: " . $stmtMapa->error);
        }
        $mapa = $stmtMapa->get_result()->fetch_assoc();
        $stmtMapa->close();
        if ($mapa) {
            return $tabla;
        }

        $stmtPresentacion = $conexion->prepare(
            "SELECT 1 FROM PRESENTACION WHERE id_presentacion = ? LIMIT 1"
        );
        $stmtPresentacion->bind_param("i", $idPresentacion);
        if (!$stmtPresentacion->execute() || $stmtPresentacion->get_result()->num_rows === 0) {
            $stmtPresentacion->close();
            throw new RuntimeException("El lote hace referencia a una presentación que no existe en la unidad.");
        }
        $stmtPresentacion->close();

        $stmtInsertar = $conexion->prepare(
            "INSERT INTO LOTE (id_presentacion, numero_lote, fecha_caducidad)
             VALUES (?, ?, ?)"
        );
        $stmtInsertar->bind_param("iss", $idPresentacion, $numeroLote, $fechaCaducidad);
        if (!$stmtInsertar->execute()) {
            throw new RuntimeException("No se pudo agregar el lote: " . $stmtInsertar->error);
        }
        $idLoteUnidad = (int)$conexion->insert_id;
        $stmtInsertar->close();

        correccionesGuardarMapaEntidad($conexion, $tablaMapa, $idLoteCentral, $idLoteUnidad);
        return $tabla;
    }

    if ($tabla === "MOVIMIENTO_BIOLOGICO") {
        $tipo = is_string($datos["tipo_movimiento"] ?? null) ? $datos["tipo_movimiento"] : "";
        $tiposPermitidos = ["Entrada", "Desecho", "Donación"];
        $cantidadFrascos = filter_var($datos["cantidad_frascos"] ?? null, FILTER_VALIDATE_INT);
        $cantidadDosis = filter_var($datos["cantidad_dosis"] ?? null, FILTER_VALIDATE_INT);
        $necesidad = filter_var($datos["necesidad"] ?? 0, FILTER_VALIDATE_INT);
        $fecha = is_string($datos["fecha_movimiento"] ?? null) ? $datos["fecha_movimiento"] : "";
        $fechaValida = DateTimeImmutable::createFromFormat("!Y-m-d", $fecha);
        if (
            !in_array($tipo, $tiposPermitidos, true)
            || $cantidadFrascos === false || $cantidadFrascos < 0
            || $cantidadDosis === false || $cantidadDosis < 0
            || $necesidad === false || $necesidad < 0
            || !$fechaValida || $fechaValida->format("Y-m-d") !== $fecha
        ) {
            throw new RuntimeException("El cambio contiene un movimiento de biológicos no válido.");
        }

        $idLote = 0;
        if (isset($datos["id_lote_origen"])) {
            $idLote = filter_var($datos["id_lote_origen"], FILTER_VALIDATE_INT) ?: 0;
        } elseif (isset($datos["id_lote_central"])) {
            $idLoteCentral = filter_var($datos["id_lote_central"], FILTER_VALIDATE_INT);
            if ($idLoteCentral !== false && $idLoteCentral > 0) {
                $tablaMapa = "LOTE";
                $stmtMapa = $conexion->prepare(
                    "SELECT id_local FROM CORRECCION_MAPA_ENTIDAD
                     WHERE tabla = ? AND id_central = ? LIMIT 1"
                );
                $stmtMapa->bind_param("si", $tablaMapa, $idLoteCentral);
                if (!$stmtMapa->execute()) {
                    throw new RuntimeException("No se pudo consultar la relación del lote.");
                }
                $mapa = $stmtMapa->get_result()->fetch_assoc();
                $stmtMapa->close();
                $idLote = (int)($mapa["id_local"] ?? 0);
            }
        }
        if ($idLote < 1) {
            throw new RuntimeException("No se pudo relacionar el lote del movimiento con la unidad.");
        }
        $stmtLote = $conexion->prepare("SELECT 1 FROM LOTE WHERE id_lote = ? LIMIT 1");
        $stmtLote->bind_param("i", $idLote);
        if (!$stmtLote->execute() || $stmtLote->get_result()->num_rows === 0) {
            $stmtLote->close();
            throw new RuntimeException("El lote del movimiento no existe en la unidad.");
        }
        $stmtLote->close();

        $idUnidadMovimiento = filter_var($datos["id_unidad_movimiento"] ?? $idUnidad, FILTER_VALIDATE_INT);
        if ($idUnidadMovimiento === false || $idUnidadMovimiento < 1) {
            throw new RuntimeException("El movimiento no tiene una unidad de origen válida.");
        }
        $idUnidadReceptora = filter_var($datos["id_unidad_receptora"] ?? null, FILTER_VALIDATE_INT);
        $idUnidadReceptora = $idUnidadReceptora === false ? null : $idUnidadReceptora;
        if ($idUnidadMovimiento !== $idUnidad && $idUnidadReceptora !== $idUnidad) {
            throw new RuntimeException("El movimiento no corresponde a esta unidad.");
        }
        foreach (array_unique(array_filter([$idUnidadMovimiento, $idUnidadReceptora])) as $idUnidadMovimientoRelacionado) {
            $stmtUnidad = $conexion->prepare("SELECT 1 FROM UNIDAD WHERE id_unidad = ? LIMIT 1");
            $stmtUnidad->bind_param("i", $idUnidadMovimientoRelacionado);
            if (!$stmtUnidad->execute() || $stmtUnidad->get_result()->num_rows === 0) {
                $stmtUnidad->close();
                throw new RuntimeException("El movimiento referencia una unidad que no existe.");
            }
            $stmtUnidad->close();
        }
        $observaciones = is_string($datos["observaciones"] ?? null) ? $datos["observaciones"] : "";
        if (mb_strlen($observaciones, "UTF-8") > 500) {
            throw new RuntimeException("Las observaciones del movimiento superan el límite permitido.");
        }
        $stmtInsertar = $conexion->prepare(
            "INSERT INTO MOVIMIENTO_BIOLOGICO
                (id_unidad, id_lote, tipo_movimiento, cantidad_frascos, cantidad_dosis,
                 fecha_movimiento, necesidad, id_unidad_receptora, observaciones)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $stmtInsertar->bind_param(
            "iisiisiis",
            $idUnidadMovimiento,
            $idLote,
            $tipo,
            $cantidadFrascos,
            $cantidadDosis,
            $fecha,
            $necesidad,
            $idUnidadReceptora,
            $observaciones
        );
        if (!$stmtInsertar->execute()) {
            throw new RuntimeException("No se pudo aplicar el movimiento de biológicos: " . $stmtInsertar->error);
        }
        $idMovimientoLocal = (int)$conexion->insert_id;
        $stmtInsertar->close();
        $idMovimientoCentral = filter_var($datos["id_movimiento_central"] ?? null, FILTER_VALIDATE_INT);
        if ($idMovimientoCentral === false || $idMovimientoCentral < 1) {
            throw new RuntimeException("El movimiento de biológicos no tiene una referencia central válida.");
        }
        correccionesGuardarMapaEntidad($conexion, $tabla, $idMovimientoCentral, $idMovimientoLocal);
        return $tabla;
    }

    if ($tabla === "MOVIMIENTO_JERINGA") {
        $idJeringa = filter_var($datos["id_jeringa"] ?? null, FILTER_VALIDATE_INT);
        $tipo = is_string($datos["tipo_movimiento"] ?? null) ? $datos["tipo_movimiento"] : "";
        $fecha = is_string($datos["fecha_movimiento"] ?? null) ? $datos["fecha_movimiento"] : "";
        $cantidad = filter_var($datos["cantidad_jeringas"] ?? null, FILTER_VALIDATE_INT);
        $necesidad = filter_var($datos["necesidad"] ?? 0, FILTER_VALIDATE_INT);
        $fechaValida = DateTimeImmutable::createFromFormat("!Y-m-d", $fecha);
        if (
            $idJeringa === false || $idJeringa < 1
            || !in_array($tipo, ["Entrada", "Uso", "Desecho"], true)
            || !$fechaValida || $fechaValida->format("Y-m-d") !== $fecha
            || $cantidad === false || $cantidad < 1
            || $necesidad === false || $necesidad < 0
        ) {
            throw new RuntimeException("El cambio contiene un movimiento de jeringas no válido.");
        }
        $stmtJeringa = $conexion->prepare("SELECT 1 FROM JERINGA WHERE id_jeringa = ? LIMIT 1");
        $stmtJeringa->bind_param("i", $idJeringa);
        if (!$stmtJeringa->execute() || $stmtJeringa->get_result()->num_rows === 0) {
            $stmtJeringa->close();
            throw new RuntimeException("El movimiento referencia una jeringa que no existe en la unidad.");
        }
        $stmtJeringa->close();
        $stmtInsertar = $conexion->prepare(
            "INSERT INTO MOVIMIENTO_JERINGA
                (id_jeringa, id_unidad, tipo_movimiento, fecha_movimiento, cantidad_jeringas, necesidad)
             VALUES (?, ?, ?, ?, ?, ?)"
        );
        $stmtInsertar->bind_param("iissii", $idJeringa, $idUnidad, $tipo, $fecha, $cantidad, $necesidad);
        if (!$stmtInsertar->execute()) {
            throw new RuntimeException("No se pudo aplicar el movimiento de jeringas: " . $stmtInsertar->error);
        }
        $idMovimientoLocal = (int)$conexion->insert_id;
        $stmtInsertar->close();
        $idMovimientoCentral = filter_var($datos["id_movimiento_central"] ?? null, FILTER_VALIDATE_INT);
        if ($idMovimientoCentral === false || $idMovimientoCentral < 1) {
            throw new RuntimeException("El movimiento de jeringas no tiene una referencia central válida.");
        }
        correccionesGuardarMapaEntidad($conexion, $tabla, $idMovimientoCentral, $idMovimientoLocal);
        return $tabla;
    }

    if (in_array($tabla, ["PALOTEO_GENERAL", "PALOTEO_INFLUENZA", "PALOTEO_COVID"], true)) {
        $anio = filter_var($datos["anio"] ?? null, FILTER_VALIDATE_INT);
        $mes = filter_var($datos["mes"] ?? null, FILTER_VALIDATE_INT);
        $datosJson = is_string($datos["datos_json"] ?? null) ? $datos["datos_json"] : "";
        if ($anio === false || $anio < 2000 || $anio > 2100 || $mes === false || $mes < 1 || $mes > 12) {
            throw new RuntimeException("El paquete contiene un periodo de paloteo no válido.");
        }
        json_decode($datosJson, true, 64, JSON_THROW_ON_ERROR);
        $stmtGuardar = $conexion->prepare(
            "INSERT INTO {$tabla} (id_unidad, anio, mes, datos_json, id_usuario)
             VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE datos_json = VALUES(datos_json), id_usuario = VALUES(id_usuario)"
        );
        if (!$stmtGuardar) {
            throw new RuntimeException("No se pudo preparar el paloteo: " . $conexion->error);
        }
        $idUsuario = (int)$_SESSION["id_usuario"];
        $stmtGuardar->bind_param("iiisi", $idUnidad, $anio, $mes, $datosJson, $idUsuario);
        if (!$stmtGuardar->execute()) {
            throw new RuntimeException("No se pudo aplicar el paloteo: " . $stmtGuardar->error);
        }
        $stmtGuardar->close();
        $stmtIdPaloteo = $conexion->prepare(
            "SELECT id_paloteo FROM {$tabla} WHERE id_unidad = ? AND anio = ? AND mes = ? LIMIT 1"
        );
        $stmtIdPaloteo->bind_param("iii", $idUnidad, $anio, $mes);
        if (!$stmtIdPaloteo->execute()) {
            throw new RuntimeException("No se pudo verificar el paloteo actualizado.");
        }
        $filaIdPaloteo = $stmtIdPaloteo->get_result()->fetch_assoc();
        $stmtIdPaloteo->close();
        $idPaloteoCentral = filter_var($datos["id_paloteo_central"] ?? null, FILTER_VALIDATE_INT);
        if (!$filaIdPaloteo || $idPaloteoCentral === false || $idPaloteoCentral < 1) {
            throw new RuntimeException("El paloteo no tiene una referencia central válida.");
        }
        correccionesGuardarMapaEntidad(
            $conexion,
            $tabla,
            $idPaloteoCentral,
            (int)$filaIdPaloteo["id_paloteo"]
        );
        return $tabla;
    }

    throw new RuntimeException("El paquete contiene una tabla de correcciones no permitida.");
}

function correccionesGuardarMapaEntidad(
    mysqli $conexion,
    string $tabla,
    int $idCentral,
    int $idLocal
): void {
    $stmt = $conexion->prepare(
        "INSERT INTO CORRECCION_MAPA_ENTIDAD (tabla, id_central, id_local)
         VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE id_local = VALUES(id_local)"
    );
    if (!$stmt) {
        throw new RuntimeException("No se pudo preparar el mapa de registros: " . $conexion->error);
    }
    $stmt->bind_param("sii", $tabla, $idCentral, $idLocal);
    if (!$stmt->execute()) {
        throw new RuntimeException("No se pudo guardar el mapa de registros: " . $stmt->error);
    }
    $stmt->close();
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $tokenRecibido = is_string($_POST["token_importacion"] ?? null)
        ? $_POST["token_importacion"]
        : "";
    $tokenEsperado = hash_hmac(
        "sha256",
        "importar_correcciones|" . $idUnidad,
        $_SESSION["form_guard_secret"]
    );
    $archivo = $_FILES["archivo_correcciones"] ?? null;
    $contrasena = is_string($_POST["contrasena_paquete"] ?? null)
        ? $_POST["contrasena_paquete"]
        : "";
    $transaccionIniciada = false;

    try {
        if (!hash_equals($tokenEsperado, $tokenRecibido)) {
            throw new RuntimeException("La solicitud venció. Recarga la página e inténtalo de nuevo.");
        }
        if (strlen($contrasena) < 14 || strlen($contrasena) > 128) {
            throw new RuntimeException("La contraseña debe tener entre 14 y 128 caracteres.");
        }
        if (
            !is_array($archivo)
            || ($archivo["error"] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK
            || !is_uploaded_file($archivo["tmp_name"] ?? "")
        ) {
            throw new RuntimeException("Selecciona un archivo de correcciones válido.");
        }
        if (
            strtolower(pathinfo((string)($archivo["name"] ?? ""), PATHINFO_EXTENSION)) !== "json"
            || (int)($archivo["size"] ?? 0) < 1
            || (int)$archivo["size"] > 50 * 1024 * 1024
        ) {
            throw new RuntimeException("El archivo debe ser .json y no superar 50 MB.");
        }

        $contenido = file_get_contents($archivo["tmp_name"]);
        if ($contenido === false) {
            throw new RuntimeException("No se pudo leer el archivo.");
        }
        $sobre = json_decode($contenido, true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($sobre)) {
            throw new RuntimeException("El archivo no contiene un sobre JSON válido.");
        }
        $paquete = importacionDescifrarSobre(
            $sobre,
            $contrasena,
            "sistema-vacunacion-unidad:v1"
        );
        if (
            !is_array($paquete)
            || ($paquete["formato"] ?? null) !== "sistema-vacunacion-correcciones"
            || ($paquete["version"] ?? null) !== 2
            || (int)($paquete["unidad_destino"]["id_unidad"] ?? 0) !== $idUnidad
            || !is_array($paquete["cambios"] ?? null)
        ) {
            throw new RuntimeException("El paquete no corresponde a esta unidad o no tiene un formato compatible.");
        }

        $conexion->begin_transaction();
        $transaccionIniciada = true;
        $aplicados = 0;
        $omitidos = 0;
        $tablas = [];
        foreach ($paquete["cambios"] as $cambio) {
            if (
                !is_array($cambio)
                || !is_string($cambio["id_cambio"] ?? null)
                || !preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/i', $cambio["id_cambio"])
            ) {
                throw new RuntimeException("El paquete contiene un identificador de cambio no válido.");
            }
            $idCambio = $cambio["id_cambio"];
            $stmtExiste = $conexion->prepare(
                "SELECT 1 FROM CORRECCION_APLICADA_SUPERVISOR WHERE id_cambio = ? LIMIT 1"
            );
            $stmtExiste->bind_param("s", $idCambio);
            if (!$stmtExiste->execute()) {
                throw new RuntimeException("No se pudo verificar si el cambio ya fue aplicado.");
            }
            $yaAplicado = $stmtExiste->get_result()->num_rows > 0;
            $stmtExiste->close();
            if ($yaAplicado) {
                $omitidos++;
                continue;
            }

            $tabla = correccionesAplicarCambio($conexion, $cambio, $idUnidad);
            $stmtMarcar = $conexion->prepare(
                "INSERT INTO CORRECCION_APLICADA_SUPERVISOR (id_cambio) VALUES (?)"
            );
            $stmtMarcar->bind_param("s", $idCambio);
            if (!$stmtMarcar->execute()) {
                throw new RuntimeException("No se pudo marcar el cambio como aplicado: " . $stmtMarcar->error);
            }
            $stmtMarcar->close();
            $aplicados++;
            $tablas[$tabla] = true;
        }

        $tablasAfectadas = implode(", ", array_keys($tablas));
        $usuarioImportador = (string)($_SESSION["nombre"] ?? "Personal de unidad");
        $detalles = json_encode([
            "cambios_aplicados" => $aplicados,
            "cambios_omitidos_repetidos" => $omitidos,
            "tablas_afectadas" => array_keys($tablas)
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $stmtHistorial = $conexion->prepare(
            "INSERT INTO HISTORIAL_CORRECCIONES_SUPERVISOR
                (nombre_archivo, cantidad_registros_actualizados, tablas_afectadas,
                 usuario_importador, detalles_importacion)
             VALUES (?, ?, ?, ?, ?)"
        );
        $nombreArchivo = basename((string)$archivo["name"]);
        $stmtHistorial->bind_param(
            "sisss",
            $nombreArchivo,
            $aplicados,
            $tablasAfectadas,
            $usuarioImportador,
            $detalles
        );
        if (!$stmtHistorial->execute()) {
            throw new RuntimeException("No se pudo guardar el historial de importación.");
        }
        $stmtHistorial->close();

        if (!$conexion->commit()) {
            throw new RuntimeException("No se pudo confirmar la importación.");
        }
        $transaccionIniciada = false;
        $tipoMensaje = "exito";
        $mensaje = "Correcciones aplicadas: {$aplicados}. Cambios repetidos omitidos: {$omitidos}.";
    } catch (Throwable $error) {
        if ($transaccionIniciada) {
            $conexion->rollback();
        }
        $tipoMensaje = "error";
        $mensaje = "Error al procesar correcciones: " . $error->getMessage();
    }
}

$stmtHistorial = $conexion->prepare(
    "SELECT id_historial, fecha_importacion, nombre_archivo,
            cantidad_registros_actualizados, tablas_afectadas, usuario_importador
     FROM HISTORIAL_CORRECCIONES_SUPERVISOR
     ORDER BY fecha_importacion DESC
     LIMIT 50"
);
if ($stmtHistorial && $stmtHistorial->execute()) {
    $historial = $stmtHistorial->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmtHistorial->close();
}
$tokenImportacion = hash_hmac(
    "sha256",
    "importar_correcciones|" . $idUnidad,
    $_SESSION["form_guard_secret"]
);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Importar correcciones del supervisor</title>
    <link rel="stylesheet" href="../css/tema_reportes.css">
    <style>
        :root { font-family: Arial, Helvetica, sans-serif; color: #233843; background: #f2f8fa; }
        * { box-sizing: border-box; }
        body { margin: 0; padding: 20px; }
        main { max-width: 1000px; margin: 24px auto; padding: 24px; border: 1px solid #dce9ed; border-radius: 12px; background: #fff; }
        h1 { margin-top: 0; color: #285f70; }
        h2 { color: #347f91; margin-top: 24px; border-bottom: 2px solid #e3f0f5; padding-bottom: 8px; }
        p { line-height: 1.55; }
        .unidad { padding: 12px; border-radius: 8px; background: #edf6f7; }
        form { display: grid; gap: 14px; margin-top: 22px; padding: 20px; border: 1px solid #e3f0f5; border-radius: 8px; background: #f9fafb; }
        label { display: grid; gap: 6px; font-weight: 700; }
        input { min-height: 42px; padding: 8px 10px; border: 1px solid #9bb0b7; border-radius: 6px; font: inherit; }
        button, a { display: inline-flex; min-height: 42px; align-items: center; justify-content: center; padding: 8px 14px; border: 0; border-radius: 7px; background: #347f91; color: white; font: inherit; font-weight: 700; text-decoration: none; cursor: pointer; }
        a { background: #e3f0f5; color: #285f70; }
        .acciones { display: flex; flex-wrap: wrap; gap: 10px; }
        .mensaje { padding: 12px; border-left: 4px solid #d79b36; background: #fff8e9; border-radius: 4px; margin-bottom: 20px; }
        .mensaje.exito { border-left-color: #28a745; background: #e8f5e9; color: #1b5e20; }
        .mensaje.error { border-left-color: #dc3545; background: #ffebee; color: #c62828; }
        table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        th { background: #347f91; color: white; padding: 12px; text-align: left; }
        td { padding: 12px; border-bottom: 1px solid #e3f0f5; }
        tr:hover { background: #f9fafb; }
        .fecha-small { font-size: .9em; color: #666; }
    </style>
</head>
<body>
<main>
    <h1>Importar correcciones del supervisor</h1>
    <p class="unidad">Unidad: <strong><?php echo htmlspecialchars($contextoUnidad["nombre_unidad"], ENT_QUOTES, "UTF-8"); ?></strong></p>

    <?php if ($mensaje !== ""): ?>
        <div class="mensaje <?php echo htmlspecialchars($tipoMensaje, ENT_QUOTES, "UTF-8"); ?>" role="status">
            <?php echo htmlspecialchars($mensaje, ENT_QUOTES, "UTF-8"); ?>
        </div>
    <?php endif; ?>

    <section>
        <h2>Subir paquete de correcciones</h2>
        <p>Selecciona el archivo cifrado del supervisor e ingresa la misma contraseña que se usó al generarlo.</p>
        <form method="post" enctype="multipart/form-data">
            <input type="hidden" name="token_importacion" value="<?php echo htmlspecialchars($tokenImportacion, ENT_QUOTES, "UTF-8"); ?>">
            <label for="archivo_correcciones">Archivo de correcciones (.json)
                <input id="archivo_correcciones" name="archivo_correcciones" type="file" accept=".json,application/json" required>
            </label>
            <label for="contrasena_paquete">Contraseña del paquete
                <input id="contrasena_paquete" name="contrasena_paquete" type="password" minlength="14" maxlength="128" autocomplete="current-password" required>
            </label>
            <div class="acciones">
                <button type="submit">Descifrar e importar</button>
                <a href="../menu.php">Regresar al menú</a>
            </div>
        </form>
    </section>

    <section>
        <h2>Historial de correcciones importadas</h2>
        <?php if ($historial === []): ?>
            <p>No hay correcciones importadas aún.</p>
        <?php else: ?>
            <table>
                <thead><tr><th>Fecha</th><th>Archivo</th><th>Cambios aplicados</th><th>Tablas</th><th>Usuario</th></tr></thead>
                <tbody>
                <?php foreach ($historial as $registro): ?>
                    <tr>
                        <td><span class="fecha-small"><?php echo htmlspecialchars($registro["fecha_importacion"], ENT_QUOTES, "UTF-8"); ?></span></td>
                        <td><?php echo htmlspecialchars($registro["nombre_archivo"], ENT_QUOTES, "UTF-8"); ?></td>
                        <td><?php echo (int)$registro["cantidad_registros_actualizados"]; ?></td>
                        <td><?php echo htmlspecialchars($registro["tablas_afectadas"], ENT_QUOTES, "UTF-8"); ?></td>
                        <td><?php echo htmlspecialchars($registro["usuario_importador"], ENT_QUOTES, "UTF-8"); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </section>
</main>
</body>
</html>
