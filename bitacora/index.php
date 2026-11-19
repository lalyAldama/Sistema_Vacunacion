<?php
require_once __DIR__ . "/../config/seguridad.php";

if (!isset($_SESSION["id_usuario"])) {
    header("Location: ../login.php");
    exit();
}

require_once __DIR__ . "/../config/conexion.php";
require_once __DIR__ . "/../config/unidad_contexto.php";

unidadExigirPersonalDeUnidad();

$contextoUnidad = unidadObtenerContexto($conexion);
$idUnidad = (int)$contextoUnidad["id_unidad"];
$vistaProductividad = ($_GET["vista"] ?? "") === "productividad";
$mesFiltro = is_string($_GET["mes"] ?? null) ? trim($_GET["mes"]) : "";
$fechaDesdeMes = null;
$fechaHastaMes = null;
$errorFiltroMes = "";
if ($mesFiltro !== "") {
    $fechaMes = DateTimeImmutable::createFromFormat("!Y-m", $mesFiltro);
    if (
        !preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $mesFiltro)
        || !$fechaMes
        || $fechaMes->format("Y-m") !== $mesFiltro
    ) {
        http_response_code(400);
        $errorFiltroMes = "Selecciona un mes válido.";
    } else {
        $fechaDesdeMes = $fechaMes->format("Y-m-01");
        $fechaHastaMes = $fechaMes->modify("+1 month")->format("Y-m-01");
    }
}
$idEnfermero = filter_var($_GET["enfermero"] ?? null, FILTER_VALIDATE_INT);
$idEnfermero = $idEnfermero !== false && $idEnfermero > 0 ? $idEnfermero : 0;
$enfermeros = [];
$totalProductividad = null;
$nombreEnfermero = "";
$productividadPorEnfermero = [];
$maxAplicacionesEnfermero = 0;

if ($vistaProductividad) {
    $stmtEnfermeros = $conexion->prepare(
        "SELECT u.id_usuario, u.nombre, u.ap_pat, u.ap_mat
         FROM USUARIO u
         INNER JOIN ROL r ON r.id_rol = u.id_rol
         WHERE u.id_unidad = ?
           AND r.nombre_rol = 'Personal de unidad'
         ORDER BY u.ap_pat, u.ap_mat, u.nombre"
    );
    if (!$stmtEnfermeros) {
        die("No se pudo preparar la lista de enfermeros: " . $conexion->error);
    }
    $stmtEnfermeros->bind_param("i", $idUnidad);
    if (!$stmtEnfermeros->execute()) {
        die("No se pudo consultar la lista de enfermeros: " . $stmtEnfermeros->error);
    }
    $enfermeros = $stmtEnfermeros->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmtEnfermeros->close();

    if ($idEnfermero > 0) {
        foreach ($enfermeros as $enfermero) {
            if ((int)$enfermero["id_usuario"] === $idEnfermero) {
                $nombreEnfermero = trim(
                    $enfermero["nombre"] . " " . $enfermero["ap_pat"] . " " . $enfermero["ap_mat"]
                );
                break;
            }
        }

        if ($nombreEnfermero !== "") {
            $sqlTotal = "
                SELECT COUNT(*) AS total
                FROM (
                    SELECT av.id_usuario
                    FROM APLICACION_VACUNA av
                    WHERE av.id_unidad = ? AND av.id_usuario = ?
                        " . ($fechaDesdeMes !== null ? "AND av.fecha_aplicacion >= ? AND av.fecha_aplicacion < ?" : "") . "
                    UNION ALL
                    SELECT va.id_usuario
                    FROM APLICACION_VPH_ANONIMA va
                    WHERE va.id_unidad = ? AND va.id_usuario = ?
                        " . ($fechaDesdeMes !== null ? "AND va.fecha_aplicacion >= ? AND va.fecha_aplicacion < ?" : "") . "
                ) AS aplicaciones
            ";
            $stmtTotal = $conexion->prepare($sqlTotal);
            if (!$stmtTotal) {
                die("No se pudo preparar el total de aplicaciones: " . $conexion->error);
            }
            if ($fechaDesdeMes === null) {
                $stmtTotal->bind_param("iiii", $idUnidad, $idEnfermero, $idUnidad, $idEnfermero);
            } else {
                $stmtTotal->bind_param(
                    "iissiiss",
                    $idUnidad,
                    $idEnfermero,
                    $fechaDesdeMes,
                    $fechaHastaMes,
                    $idUnidad,
                    $idEnfermero,
                    $fechaDesdeMes,
                    $fechaHastaMes
                );
            }
            if (!$stmtTotal->execute()) {
                die("No se pudo calcular el total: " . $stmtTotal->error);
            }
            $totalProductividad = (int)$stmtTotal->get_result()->fetch_assoc()["total"];
            $stmtTotal->close();
        }
    }

    $sqlProductividad = "
        SELECT aplicaciones.id_usuario, COUNT(*) AS total
        FROM (
            SELECT av.id_usuario
            FROM APLICACION_VACUNA av
            WHERE av.id_unidad = ?
                " . ($fechaDesdeMes !== null ? "AND av.fecha_aplicacion >= ? AND av.fecha_aplicacion < ?" : "") . "
            UNION ALL
            SELECT va.id_usuario
            FROM APLICACION_VPH_ANONIMA va
            WHERE va.id_unidad = ?
                " . ($fechaDesdeMes !== null ? "AND va.fecha_aplicacion >= ? AND va.fecha_aplicacion < ?" : "") . "
        ) AS aplicaciones
        WHERE aplicaciones.id_usuario IS NOT NULL
        GROUP BY aplicaciones.id_usuario
    ";
    $stmtProductividad = $conexion->prepare($sqlProductividad);
    if (!$stmtProductividad) {
        die("No se pudo preparar la gráfica de productividad: " . $conexion->error);
    }
    if ($fechaDesdeMes === null) {
        $stmtProductividad->bind_param("ii", $idUnidad, $idUnidad);
    } else {
        $stmtProductividad->bind_param(
            "ississ",
            $idUnidad,
            $fechaDesdeMes,
            $fechaHastaMes,
            $idUnidad,
            $fechaDesdeMes,
            $fechaHastaMes
        );
    }
    if (!$stmtProductividad->execute()) {
        die("No se pudo consultar la gráfica de productividad: " . $stmtProductividad->error);
    }
    $totalesPorUsuario = [];
    foreach ($stmtProductividad->get_result()->fetch_all(MYSQLI_ASSOC) as $filaProductividad) {
        $totalesPorUsuario[(int)$filaProductividad["id_usuario"]] = (int)$filaProductividad["total"];
    }
    $stmtProductividad->close();

    foreach ($enfermeros as $enfermero) {
        $idUsuarioEnfermero = (int)$enfermero["id_usuario"];
        $productividadPorEnfermero[] = [
            "nombre" => trim(
                $enfermero["nombre"] . " " . $enfermero["ap_pat"] . " " . $enfermero["ap_mat"]
            ),
            "total" => $totalesPorUsuario[$idUsuarioEnfermero] ?? 0
        ];
    }
    usort(
        $productividadPorEnfermero,
        static fn(array $a, array $b): int => ($b["total"] <=> $a["total"])
            ?: strcmp($a["nombre"], $b["nombre"])
    );
    foreach ($productividadPorEnfermero as $filaProductividad) {
        $maxAplicacionesEnfermero = max($maxAplicacionesEnfermero, $filaProductividad["total"]);
    }
}

$sql = "
    SELECT
        av.fecha_aplicacion,
        CONCAT_WS(' ', b.nombre_biologico, d.nombre_dosis) AS vacuna,
        CONCAT_WS(' ', p.nombres, p.ap_pat, p.ap_mat) AS paciente,
        CONCAT_WS(' ', u.nombre, u.ap_pat, u.ap_mat) AS enfermero,
        'Aplicación registrada' AS tipo
    FROM APLICACION_VACUNA av
    INNER JOIN PACIENTE p
        ON p.id_paciente = av.id_paciente
    INNER JOIN ESQUEMA_BIOLOGICO_DOSIS ebd
        ON ebd.id_esquema_biologico_dosis = av.id_esquema_biologico_dosis
    INNER JOIN ESQUEMA_BIOLOGICO eb
        ON eb.id_esquema_biologico = ebd.id_esquema_biologico
    INNER JOIN BIOLOGICO b
        ON b.id_biologico = eb.id_biologico
    INNER JOIN DOSIS d
        ON d.id_dosis = ebd.id_dosis
    LEFT JOIN USUARIO u
        ON u.id_usuario = av.id_usuario
    WHERE av.id_unidad = ?
        " . ($fechaDesdeMes !== null ? "AND av.fecha_aplicacion >= ? AND av.fecha_aplicacion < ?" : "") . "

    UNION ALL

    SELECT
        va.fecha_aplicacion,
        CONCAT_WS(' ', b.nombre_biologico, d.nombre_dosis) AS vacuna,
        'Registro confidencial' AS paciente,
        CONCAT_WS(' ', u.nombre, u.ap_pat, u.ap_mat) AS enfermero,
        'VPH confidencial' AS tipo
    FROM APLICACION_VPH_ANONIMA va
    INNER JOIN ESQUEMA_BIOLOGICO_DOSIS ebd
        ON ebd.id_esquema_biologico_dosis = va.id_esquema_biologico_dosis
    INNER JOIN ESQUEMA_BIOLOGICO eb
        ON eb.id_esquema_biologico = ebd.id_esquema_biologico
    INNER JOIN BIOLOGICO b
        ON b.id_biologico = eb.id_biologico
    INNER JOIN DOSIS d
        ON d.id_dosis = ebd.id_dosis
    LEFT JOIN USUARIO u
        ON u.id_usuario = va.id_usuario
    WHERE va.id_unidad = ?
        " . ($fechaDesdeMes !== null ? "AND va.fecha_aplicacion >= ? AND va.fecha_aplicacion < ?" : "") . "

    ORDER BY fecha_aplicacion DESC
";

$stmt = $conexion->prepare($sql);

if (!$stmt) {
    die("No se pudo preparar la bitácora: " . $conexion->error);
}

if ($fechaDesdeMes === null) {
    $stmt->bind_param("ii", $idUnidad, $idUnidad);
} else {
    $stmt->bind_param(
        "ississ",
        $idUnidad,
        $fechaDesdeMes,
        $fechaHastaMes,
        $idUnidad,
        $fechaDesdeMes,
        $fechaHastaMes
    );
}

if (!$stmt->execute()) {
    die("No se pudo consultar la bitácora: " . $stmt->error);
}

$aplicaciones = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$parametrosExportacion = ["exportar" => "excel"];
if ($fechaDesdeMes !== null) {
    $parametrosExportacion["mes"] = $mesFiltro;
}
$urlExportarExcel = "index.php?" . http_build_query($parametrosExportacion);

function escapar($valor)
{
    return htmlspecialchars((string)$valor, ENT_QUOTES, "UTF-8");
}

if (!$vistaProductividad && ($_GET["exportar"] ?? "") === "excel") {
    if ($errorFiltroMes !== "") {
        http_response_code(400);
        exit($errorFiltroMes);
    }

    $referenciasColumnas = ["A", "B", "C", "D", "E"];
    $filasExcel = [
        ["Fecha de aplicación", "Vacuna y dosis", "Paciente", "Enfermero", "Tipo de registro"]
    ];
    foreach ($aplicaciones as $aplicacion) {
        $filasExcel[] = [
            $aplicacion["fecha_aplicacion"],
            $aplicacion["vacuna"],
            $aplicacion["paciente"],
            $aplicacion["enfermero"] !== "" ? $aplicacion["enfermero"] : "Sin usuario atribuido",
            $aplicacion["tipo"]
        ];
    }

    $filasXml = "";
    foreach ($filasExcel as $indiceFila => $filaExcel) {
        $numeroFila = $indiceFila + 1;
        $celdasXml = "";
        foreach ($filaExcel as $indiceColumna => $valorCelda) {
            $referenciaCelda = $referenciasColumnas[$indiceColumna] . $numeroFila;
            $valorEscapado = htmlspecialchars(
                (string)$valorCelda,
                ENT_XML1 | ENT_QUOTES,
                "UTF-8"
            );
            $celdasXml .= '<c r="' . $referenciaCelda . '" t="inlineStr"><is><t xml:space="preserve">'
                . $valorEscapado . '</t></is></c>';
        }
        $filasXml .= '<row r="' . $numeroFila . '">' . $celdasXml . '</row>';
    }

    $rangoFiltro = "A1:E" . count($filasExcel);
    $hojaXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<sheetData>' . $filasXml . '</sheetData>'
        . '<autoFilter ref="' . $rangoFiltro . '"/>'
        . '</worksheet>';
    $archivosExcel = [
        "[Content_Types].xml" => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '</Types>',
        "_rels/.rels" => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>',
        "xl/workbook.xml" => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
            . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets><sheet name="Bitácora" sheetId="1" r:id="rId1"/></sheets>'
            . '</workbook>',
        "xl/_rels/workbook.xml.rels" => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            . '</Relationships>',
        "xl/worksheets/sheet1.xml" => $hojaXml
    ];

    $archivoTemporal = tempnam(sys_get_temp_dir(), "bitacora_");
    if ($archivoTemporal === false) {
        http_response_code(500);
        exit("No se pudo preparar el archivo Excel de la bitácora.");
    }
    $libroExcel = new ZipArchive();
    if ($libroExcel->open($archivoTemporal, ZipArchive::OVERWRITE) !== true) {
        unlink($archivoTemporal);
        http_response_code(500);
        exit("No se pudo crear el archivo Excel de la bitácora.");
    }
    foreach ($archivosExcel as $ruta => $contenido) {
        if (!$libroExcel->addFromString($ruta, $contenido)) {
            $libroExcel->close();
            unlink($archivoTemporal);
            http_response_code(500);
            exit("No se pudo guardar el contenido del archivo Excel.");
        }
    }
    if (!$libroExcel->close()) {
        unlink($archivoTemporal);
        http_response_code(500);
        exit("No se pudo finalizar el archivo Excel de la bitácora.");
    }

    $sufijoArchivo = $fechaDesdeMes !== null
        ? str_replace("-", "", $mesFiltro)
        : "completa";
    $nombreArchivo = "Bitacora_Aplicaciones_" . $sufijoArchivo . ".xlsx";
    header("Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet");
    header("Content-Disposition: attachment; filename=\"" . $nombreArchivo . "\"");
    header("Content-Length: " . filesize($archivoTemporal));
    header("Cache-Control: no-store, private");
    header("X-Content-Type-Options: nosniff");
    readfile($archivoTemporal);
    unlink($archivoTemporal);
    exit();
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bitácora de aplicaciones</title>
    <link rel="stylesheet" href="../css/estilos.css">
    <style>
        .grafica-productividad { display: grid; gap: 10px; max-width: 900px; }
        .barra-productividad { display: grid; grid-template-columns: minmax(140px, 240px) minmax(100px, 1fr) 48px; gap: 12px; align-items: center; }
        .nombre-productividad { overflow-wrap: anywhere; }
        .pista-productividad { height: 16px; overflow: hidden; border-radius: 999px; background: #e5edf1; }
        .barra-productividad-valor { display: block; height: 100%; border-radius: inherit; background: #347f91; }
        @media (max-width: 600px) {
            .barra-productividad { grid-template-columns: minmax(90px, 130px) minmax(50px, 1fr) 40px; gap: 8px; }
        }
    </style>
</head>
<body>

    <header class="encabezado-pagina">
        <h1>Bitácora de aplicaciones</h1>
        <p>
            Aplicaciones registradas en la unidad:
            <strong><?php echo escapar($contextoUnidad["nombre_unidad"]); ?></strong>
        </p>
        <div class="acciones">
            <?php if ($vistaProductividad): ?>
                <a class="btn btn-secundario" href="index.php">Bitácora general</a>
            <?php else: ?>
                <a class="btn" href="index.php?vista=productividad">Productividad</a>
            <?php endif; ?>
        </div>
    </header>

    <main class="panel">
        <?php if ($vistaProductividad): ?>
            <?php if ($errorFiltroMes !== ""): ?>
                <p class="alerta alerta-info" role="alert"><?php echo escapar($errorFiltroMes); ?></p>
            <?php endif; ?>
            <h2>Productividad por enfermero</h2>
            <form method="get" action="index.php" class="fila-formulario">
                <input type="hidden" name="vista" value="productividad">
                <div class="campo">
                    <label for="enfermero">Enfermero (opcional)</label>
                    <select name="enfermero" id="enfermero">
                        <option value="">Todos (solo gráfica comparativa)</option>
                        <?php foreach ($enfermeros as $enfermero): ?>
                            <?php $nombreCompleto = trim($enfermero["nombre"] . " " . $enfermero["ap_pat"] . " " . $enfermero["ap_mat"]); ?>
                            <option value="<?php echo (int)$enfermero["id_usuario"]; ?>"
                                <?php echo (int)$enfermero["id_usuario"] === $idEnfermero ? "selected" : ""; ?>>
                                <?php echo escapar($nombreCompleto); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="campo">
                    <label for="mes">Filtrar por mes</label>
                    <input type="month" id="mes" name="mes" value="<?php echo escapar($mesFiltro); ?>">
                </div>
                <div class="campo acciones">
                    <button type="submit">Consultar</button>
                </div>
            </form>

            <?php if ($nombreEnfermero !== ""): ?>
                <section class="seccion" aria-live="polite">
                    <h3><?php echo escapar($nombreEnfermero); ?></h3>
                    <p>Total de vacunas aplicadas: <strong><?php echo $totalProductividad; ?></strong></p>
                </section>
            <?php elseif ($idEnfermero > 0): ?>
                <p class="alerta alerta-info">No se encontró ese enfermero en esta unidad.</p>
            <?php endif; ?>
            <section class="seccion" aria-live="polite">
                <h3>
                    Aplicaciones por enfermero
                    <?php echo $mesFiltro !== "" && $errorFiltroMes === ""
                        ? "en " . escapar($mesFiltro)
                        : " (todos los meses)"; ?>
                </h3>
                <?php if ($maxAplicacionesEnfermero === 0): ?>
                    <p>No hay aplicaciones atribuidas a enfermeros en este periodo.</p>
                <?php else: ?>
                    <div class="grafica-productividad" role="list" aria-label="Aplicaciones por enfermero">
                        <?php foreach ($productividadPorEnfermero as $filaProductividad): ?>
                            <?php
                            $anchoBarra = $maxAplicacionesEnfermero > 0
                                ? (int)round($filaProductividad["total"] * 100 / $maxAplicacionesEnfermero)
                                : 0;
                            ?>
                            <div class="barra-productividad" role="listitem">
                                <span class="nombre-productividad"><?php echo escapar($filaProductividad["nombre"]); ?></span>
                                <span class="pista-productividad" aria-hidden="true">
                                    <span class="barra-productividad-valor" style="width: <?php echo $anchoBarra; ?>%"></span>
                                </span>
                                <strong><?php echo (int)$filaProductividad["total"]; ?></strong>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>
        <?php else: ?>
        <?php if ($errorFiltroMes !== ""): ?>
            <p class="alerta alerta-info" role="alert"><?php echo escapar($errorFiltroMes); ?></p>
        <?php endif; ?>
        <form method="get" action="index.php" class="fila-formulario">
            <div class="campo">
                <label for="mes">Filtrar por mes</label>
                <input type="month" id="mes" name="mes" value="<?php echo escapar($mesFiltro); ?>">
            </div>
            <div class="campo acciones">
                <button type="submit">Filtrar</button>
                <a class="btn btn-secundario" href="index.php">Mostrar todos</a>
                <a class="btn" href="<?php echo escapar($urlExportarExcel); ?>">Descargar Excel</a>
            </div>
        </form>
        <div class="tabla-contenedor">
            <table>
                <thead>
                    <tr>
                        <th>Fecha de aplicación</th>
                        <th>Vacuna y dosis</th>
                        <th>Paciente</th>
                        <th>Enfermero</th>
                        <th>Tipo de registro</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($aplicaciones) === 0): ?>
                        <tr>
                            <td colspan="5">Todavía no hay aplicaciones registradas.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($aplicaciones as $aplicacion): ?>
                            <tr>
                                <td><?php echo escapar($aplicacion["fecha_aplicacion"]); ?></td>
                                <td><?php echo escapar($aplicacion["vacuna"]); ?></td>
                                <td><?php echo escapar($aplicacion["paciente"]); ?></td>
                                <td>
                                    <?php
                                    echo escapar(
                                        $aplicacion["enfermero"] !== ""
                                            ? $aplicacion["enfermero"]
                                            : "Sin usuario atribuido"
                                    );
                                    ?>
                                </td>
                                <td><?php echo escapar($aplicacion["tipo"]); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>

    </main>

</body>
</html>
