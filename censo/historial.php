<?php

function censoHistorialAsegurarTabla(mysqli $conexion): void
{
    $sql = "CREATE TABLE IF NOT EXISTS CENSO_HISTORIAL (
                id_historial BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                tipo_reporte VARCHAR(20) NOT NULL,
                id_censo TINYINT UNSIGNED NOT NULL,
                fecha_captura DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                hash_contenido CHAR(64) NOT NULL,
                contenido LONGBLOB NOT NULL,
                PRIMARY KEY (id_historial),
                KEY idx_censo_historial_fecha (tipo_reporte, id_censo, fecha_captura),
                KEY idx_censo_historial_expiracion (fecha_captura)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

    if (!$conexion->query($sql)) {
        http_response_code(500);
        exit("No se pudo preparar el almacenamiento del historial: " . $conexion->error);
    }

    if (!$conexion->query(
        "DELETE FROM CENSO_HISTORIAL
         WHERE fecha_captura < DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 5 YEAR)"
    )) {
        http_response_code(500);
        exit("No se pudo aplicar el periodo de conservación del historial: " . $conexion->error);
    }
}

function censoHistorialGuardar(
    mysqli $conexion,
    string $tipo,
    int $idCenso,
    string $contenido
): void {
    if (!function_exists("gzcompress")) {
        http_response_code(500);
        exit("No está disponible la compresión necesaria para guardar el historial.");
    }

    $hash = hash("sha256", $contenido);
    $stmt = $conexion->prepare(
        "SELECT hash_contenido
         FROM CENSO_HISTORIAL
         WHERE tipo_reporte = ? AND id_censo = ?
         ORDER BY fecha_captura DESC, id_historial DESC
         LIMIT 1"
    );
    if (!$stmt) {
        http_response_code(500);
        exit("No se pudo consultar el historial: " . $conexion->error);
    }

    $stmt->bind_param("si", $tipo, $idCenso);
    if (!$stmt->execute()) {
        http_response_code(500);
        exit("No se pudo consultar el historial: " . $stmt->error);
    }

    $stmt->bind_result($ultimoHash);
    $tieneCaptura = $stmt->fetch();
    $stmt->close();

    if ($tieneCaptura && hash_equals((string)$ultimoHash, $hash)) {
        return;
    }

    $contenidoComprimido = gzcompress($contenido, 6);
    if ($contenidoComprimido === false) {
        http_response_code(500);
        exit("No se pudo comprimir la copia del censo.");
    }

    $stmt = $conexion->prepare(
        "INSERT INTO CENSO_HISTORIAL (tipo_reporte, id_censo, hash_contenido, contenido)
         VALUES (?, ?, ?, ?)"
    );
    if (!$stmt) {
        http_response_code(500);
        exit("No se pudo preparar el guardado del historial: " . $conexion->error);
    }

    $stmt->bind_param("sisb", $tipo, $idCenso, $hash, $contenidoComprimido);
    if (!$stmt->send_long_data(3, $contenidoComprimido) || !$stmt->execute()) {
        http_response_code(500);
        exit("No se pudo guardar la copia del censo: " . $stmt->error);
    }
    $stmt->close();

    if (!$conexion->query(
        "DELETE FROM CENSO_HISTORIAL
         WHERE fecha_captura < DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 5 YEAR)"
    )) {
        http_response_code(500);
        exit("No se pudo aplicar el periodo de conservación del historial: " . $conexion->error);
    }
}

function censoHistorialObtener(
    mysqli $conexion,
    string $tipo,
    int $idCenso,
    string $fecha
): ?array {
    $stmt = $conexion->prepare(
        "SELECT contenido, fecha_captura
         FROM CENSO_HISTORIAL
         WHERE tipo_reporte = ?
           AND id_censo = ?
           AND fecha_captura <= CONCAT(?, ' 23:59:59')
         ORDER BY fecha_captura DESC, id_historial DESC
         LIMIT 1"
    );
    if (!$stmt) {
        http_response_code(500);
        exit("No se pudo consultar la copia histórica: " . $conexion->error);
    }

    $stmt->bind_param("sis", $tipo, $idCenso, $fecha);
    if (!$stmt->execute()) {
        http_response_code(500);
        exit("No se pudo consultar la copia histórica: " . $stmt->error);
    }

    $stmt->bind_result($contenidoComprimido, $fechaCaptura);
    if (!$stmt->fetch()) {
        $stmt->close();
        return null;
    }
    $stmt->close();

    $contenido = gzuncompress($contenidoComprimido, 50 * 1024 * 1024);
    if ($contenido === false) {
        http_response_code(500);
        exit("La copia histórica está dañada y no se puede mostrar.");
    }

    return [
        "contenido" => $contenido,
        "fecha_captura" => $fechaCaptura
    ];
}

function censoHistorialPrimero(mysqli $conexion, string $tipo, int $idCenso): ?string
{
    $stmt = $conexion->prepare(
        "SELECT fecha_captura
         FROM CENSO_HISTORIAL
         WHERE tipo_reporte = ? AND id_censo = ?
         ORDER BY fecha_captura ASC, id_historial ASC
         LIMIT 1"
    );
    if (!$stmt) {
        http_response_code(500);
        exit("No se pudo consultar el inicio del historial: " . $conexion->error);
    }

    $stmt->bind_param("si", $tipo, $idCenso);
    if (!$stmt->execute()) {
        http_response_code(500);
        exit("No se pudo consultar el inicio del historial: " . $stmt->error);
    }

    $stmt->bind_result($fecha);
    $tieneCaptura = $stmt->fetch();
    $stmt->close();

    return $tieneCaptura ? $fecha : null;
}

function censoHistorialFechaValida(?string $fecha): bool
{
    if ($fecha === null || !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $fecha, $partes)) {
        return false;
    }

    return checkdate((int)$partes[2], (int)$partes[3], (int)$partes[1])
        && $fecha <= date("Y-m-d");
}

function censoHistorialMostrar(
    mysqli $conexion,
    string $tipo,
    int $idCenso,
    string $titulo,
    string $ruta,
    ?string $fecha,
    bool $incluirCenso = false
): void {
    $copia = null;
    $fechaValida = censoHistorialFechaValida($fecha);

    if ($fecha !== null && $fechaValida) {
        $copia = censoHistorialObtener($conexion, $tipo, $idCenso, $fecha);
    }

    $primeraCaptura = censoHistorialPrimero($conexion, $tipo, $idCenso);
    $escape = static function ($valor): string {
        return htmlspecialchars((string)$valor, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8");
    };

    header("Content-Type: text/html; charset=utf-8");
    ?>
    <!DOCTYPE html>
    <html lang="es">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Historial: <?php echo $escape($titulo); ?></title>
        <link rel="stylesheet" href="css/censo.css">
        <style>
            .consulta-historica { margin: 18px 0; }
            .consulta-historica form { display:flex; gap:10px; align-items:end; flex-wrap:wrap; }
            .copia-historica { width:100%; min-height:75vh; border:1px solid #777; background:#fff; }
            .mensaje-historico { margin:14px 0; padding:12px; background:#fff8e1; border:1px solid #d6b656; }
        </style>
    </head>
    <body>
    <main class="pagina">
        <div class="barra-superior">
            <h1>CONSULTA HISTÓRICA: <?php echo $escape($titulo); ?></h1>
            <a class="btn btn-censo" href="<?php echo $escape($ruta . ($incluirCenso ? "?censo=" . $idCenso : "")); ?>">VOLVER AL CENSO</a>
        </div>
        <section class="panel-filtros consulta-historica">
            <form method="get" action="<?php echo $escape($ruta); ?>">
                <?php if ($incluirCenso): ?>
                    <input type="hidden" name="censo" value="<?php echo $escape($idCenso); ?>">
                <?php endif; ?>
                <label for="fecha-historica">Consultar el censo al cierre del día:</label>
                <input id="fecha-historica" type="date" name="historico_fecha"
                    max="<?php echo date("Y-m-d"); ?>"
                    value="<?php echo $escape($fecha ?? ""); ?>" required>
                <button class="btn btn-azul" type="submit">CONSULTAR</button>
            </form>
            <p>Se muestra la última copia registrada en o antes de la fecha elegida.</p>
        </section>
        <?php if ($fecha !== null && !$fechaValida): ?>
            <div class="mensaje-historico" role="alert">La fecha seleccionada no es válida o es posterior a hoy.</div>
        <?php elseif ($fecha !== null && $copia === null): ?>
            <div class="mensaje-historico" role="status">
                No hay una copia disponible para esa fecha.
                <?php if ($primeraCaptura !== null): ?>
                    El historial de este censo comienza el <?php echo $escape(date("d/m/Y H:i", strtotime($primeraCaptura))); ?>.
                <?php else: ?>
                    Aún no se ha guardado la primera copia de este censo.
                <?php endif; ?>
            </div>
        <?php elseif ($copia !== null): ?>
            <div class="mensaje-historico" role="status">
                Última copia registrada al cierre del <?php echo $escape(date("d/m/Y", strtotime($fecha))); ?>:
                <?php echo $escape(date("d/m/Y H:i:s", strtotime($copia["fecha_captura"]))); ?>.
            </div>
            <iframe class="copia-historica" title="Copia histórica del censo"
                sandbox="" srcdoc="<?php echo $escape($copia["contenido"]); ?>"></iframe>
        <?php endif; ?>
    </main>
    </body>
    </html>
    <?php
}
