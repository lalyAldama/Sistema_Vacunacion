<?php
require_once __DIR__ . "/../config/seguridad.php";

if (!isset($_SESSION["id_usuario"])) {
    header("Location: ../login.php");
    exit();
}

require_once __DIR__ . "/../config/conexion.php";

function hCovid($value)
{
    return htmlspecialchars((string)$value, ENT_QUOTES, "UTF-8");
}

function textoCovid($value)
{
    $value = mb_strtolower(trim((string)$value), "UTF-8");
    $value = strtr($value, [
        "á" => "a", "é" => "e", "í" => "i", "ó" => "o", "ú" => "u",
        "ü" => "u", "ñ" => "n"
    ]);

    return trim(preg_replace('/[^a-z0-9]+/', " ", $value));
}

function ajusteCovid($ajustes, $id, $campo)
{
    $valor = $ajustes[$id][$campo] ?? 0;
    return is_numeric($valor) ? (int)$valor : 0;
}

function categoriaRiesgoCovid($comorbilidades, $categorias)
{
    if (str_contains($comorbilidades, "vih") || str_contains($comorbilidades, "sida")) {
        return "vih";
    }

    foreach ($categorias as $clave => $categoria) {
        foreach ($categoria["coincidencias"] as $coincidencia) {
            if (str_contains($comorbilidades, $coincidencia)) {
                return $clave;
            }
        }
    }

    return null;
}

$mesesTemporada = [10, 11, 12, 1, 2, 3];
$mesActual = (int)date("n");
$mesPredeterminado = in_array($mesActual, $mesesTemporada, true) ? $mesActual : 3;
$anioPredeterminado = (int)date("Y");
$periodo = $_SERVER["REQUEST_METHOD"] === "POST" ? $_POST : $_GET;
$mesValor = $periodo["mes"] ?? $mesPredeterminado;
$anioValor = $periodo["anio"] ?? $anioPredeterminado;
$mes = (is_string($mesValor) || is_int($mesValor))
    ? filter_var($mesValor, FILTER_VALIDATE_INT)
    : false;
$anio = (is_string($anioValor) || is_int($anioValor))
    ? filter_var($anioValor, FILTER_VALIDATE_INT)
    : false;
if (
    $mes === false
    || !in_array($mes, $mesesTemporada, true)
    || $anio === false
    || $anio < 2000
    || $anio > 2100
) {
    http_response_code(400);
    exit("El paloteo de COVID-19 solo está disponible de octubre a marzo.");
}

$fechaInicio = new DateTimeImmutable(sprintf("%04d-%02d-01", $anio, $mes));
$fechaFin = $fechaInicio->modify("+1 month");
$temporadaInicio = $mes >= 10 ? $anio : $anio - 1;
$nombreMeses = [
    1 => "ENERO", 2 => "FEBRERO", 3 => "MARZO", 4 => "ABRIL",
    5 => "MAYO", 6 => "JUNIO", 7 => "JULIO", 8 => "AGOSTO",
    9 => "SEPTIEMBRE", 10 => "OCTUBRE", 11 => "NOVIEMBRE", 12 => "DICIEMBRE"
];
$inicioSql = $fechaInicio->format("Y-m-d");
$finSql = $fechaFin->format("Y-m-d");

$stmtUsuario = $conexion->prepare(
    "SELECT u.id_unidad, un.nombre_unidad, u.nombre, u.ap_pat, u.ap_mat
     FROM USUARIO u LEFT JOIN UNIDAD un ON un.id_unidad = u.id_unidad
     WHERE u.id_usuario = ?"
);
if (!$stmtUsuario) {
    die("Error al consultar la unidad del usuario: " . $conexion->error);
}
$idUsuario = (int)$_SESSION["id_usuario"];
$stmtUsuario->bind_param("i", $idUsuario);
if (!$stmtUsuario->execute()) {
    die("Error al consultar la unidad del usuario: " . $stmtUsuario->error);
}
$usuario = $stmtUsuario->get_result()->fetch_assoc();
$stmtUsuario->close();
if (!$usuario || empty($usuario["id_unidad"])) {
    die("El usuario no tiene una unidad de salud asignada.");
}

$idUnidad = (int)$usuario["id_unidad"];
$nombreUnidad = (string)($usuario["nombre_unidad"] ?? "");
$nombreResponsable = trim(
    ($usuario["nombre"] ?? "") . " " . ($usuario["ap_pat"] ?? "") . " " . ($usuario["ap_mat"] ?? "")
);

$sqlTabla = "CREATE TABLE IF NOT EXISTS PALOTEO_COVID (
                 id_paloteo INT NOT NULL AUTO_INCREMENT,
                 id_unidad INT NOT NULL,
                 anio SMALLINT UNSIGNED NOT NULL,
                 mes TINYINT UNSIGNED NOT NULL,
                 datos_json LONGTEXT NOT NULL,
                 id_usuario INT NOT NULL,
                 actualizado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                     ON UPDATE CURRENT_TIMESTAMP,
                 PRIMARY KEY (id_paloteo),
                 UNIQUE KEY uq_paloteo_covid_periodo (id_unidad, anio, mes)
             ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
if (!$conexion->query($sqlTabla)) {
    die("No fue posible preparar el almacenamiento del paloteo de COVID-19: " . $conexion->error);
}

$stmtDatos = $conexion->prepare(
    "SELECT datos_json FROM PALOTEO_COVID WHERE id_unidad = ? AND anio = ? AND mes = ?"
);
if (!$stmtDatos) {
    die("Error al consultar el paloteo de COVID-19 guardado: " . $conexion->error);
}
$stmtDatos->bind_param("iii", $idUnidad, $anio, $mes);
if (!$stmtDatos->execute()) {
    die("Error al leer el paloteo de COVID-19 guardado: " . $stmtDatos->error);
}
$filaDatos = $stmtDatos->get_result()->fetch_assoc();
$stmtDatos->close();
$datosGuardados = ["ajustes" => [], "encabezado" => []];
if ($filaDatos) {
    $datosDecodificados = json_decode($filaDatos["datos_json"], true);
    if (!is_array($datosDecodificados) || json_last_error() !== JSON_ERROR_NONE) {
        die("Los datos guardados del paloteo de COVID-19 no se pudieron leer.");
    }
    $datosGuardados["ajustes"] = is_array($datosDecodificados["ajustes"] ?? null)
        ? $datosDecodificados["ajustes"]
        : [];
    $datosGuardados["encabezado"] = is_array($datosDecodificados["encabezado"] ?? null)
        ? $datosDecodificados["encabezado"]
        : [];
}

$camposEncabezado = [
    "entidad" => "Entidad federativa",
    "jurisdiccion" => "Jurisdicción/Distrito de Salud/Delegación",
    "municipio" => "Municipio",
    "localidad" => "Localidad",
    "ageb" => "AGEB",
    "sector" => "Sector",
    "responsable" => "Nombre del vacunador(a)"
];
$encabezado = array_merge([
    "entidad" => "CHIHUAHUA",
    "jurisdiccion" => "DISTRITO 3 PARRAL",
    "municipio" => "",
    "localidad" => "",
    "ageb" => "",
    "sector" => "",
    "responsable" => $nombreResponsable
], array_intersect_key($datosGuardados["encabezado"], $camposEncabezado));
$ajustes = $datosGuardados["ajustes"];
$mensajeError = "";

$productos = [
    "pfizer" => "Pfizer",
    "moderna" => "Moderna",
    "otra" => "Otra"
];
$edadesRiesgo = [
    ["6_18m", "6 a 18 meses", 6, 19],
    ["19_59m", "19 a 59 meses", 19, 60],
    ["5_11", "5 a 11 años", 60, 144],
    ["12_59", "12 a 59 años", 144, 720]
];
$categoriasRiesgo = [
    "pulmonar" => [
        "etiqueta" => "Personas que viven con enfermedad pulmonar crónica, incluye EPOC, tuberculosis y asma",
        "coincidencias" => ["pulmonar", "epoc", "asma", "tuberculosis"]
    ],
    "cancer" => [
        "etiqueta" => "Personas que viven con cáncer",
        "coincidencias" => ["cancer", "neoplasia", "tumor maligno"]
    ],
    "discapacidad" => [
        "etiqueta" => "Personas que viven con discapacidades (incluyendo parálisis cerebral, malformaciones congénitas, discapacidades de aprendizaje, limitaciones en el autocuidado o en las actividades de la vida diaria, trastornos del neurodesarrollo, lesiones de médula espinal)",
        "coincidencias" => ["discapacidad", "paralisis cerebral", "aprendizaje", "autocuidado", "neurodesarrollo", "medula espinal"]
    ],
    "fibrosis" => [
        "etiqueta" => "Personas que viven con fibrosis quística",
        "coincidencias" => ["fibrosis quistica"]
    ],
    "cardiovascular" => [
        "etiqueta" => "Personas que viven con enfermedad cardiovascular incluyendo hipertensión arterial",
        "coincidencias" => ["enfermedad cardiovascular", "hipertension arterial"]
    ],
    "obesidad" => [
        "etiqueta" => "Personas que viven con obesidad mórbida",
        "coincidencias" => ["obesidad"]
    ],
    "diabetes" => [
        "etiqueta" => "Personas que viven con diabetes mellitus tipo 1 y 2",
        "coincidencias" => ["diabetes"]
    ],
    "cardiacas" => [
        "etiqueta" => "Personas que viven con afecciones cardiacas (insuficiencia cardiaca, enfermedad arterial coronaria, miocardiopatías, cardiopatía congénita)",
        "coincidencias" => ["cardiopatia", "cardiaca", "insuficiencia cardiaca", "arterial coronaria", "miocardiopatia"]
    ],
    "inmunosupresion" => [
        "etiqueta" => "Personas que viven con inmunosupresión adquirida por enfermedad o tratamiento, excepto VIH/SIDA",
        "coincidencias" => ["inmunosupresion", "inmunodeficiencia"]
    ],
    "vih" => [
        "etiqueta" => "Personas que viven con VIH/SIDA",
        "coincidencias" => ["vih", "sida"]
    ],
    "renal" => [
        "etiqueta" => "Personas que viven con insuficiencia renal",
        "coincidencias" => ["renal", "insuficiencia de rinon"]
    ],
    "trasplante" => [
        "etiqueta" => "Personas que viven con trasplante de órganos sólidos o células madre sanguíneas",
        "coincidencias" => ["trasplante", "trasplantado"]
    ]
];

$gruposCovid = [
    [
        "poblacion" => "Población blanco",
        "filas" => [
            ["embarazadas", "Embarazadas", ""],
            ["60_plus", "Personas de 60 años y más", ""],
            ["personal_salud", "Personal de Salud en Unidades Médicas", ""]
        ]
    ],
    [
        "poblacion" => "Población de riesgo de 6 meses a 59 años",
        "filas" => []
    ]
];
foreach ($categoriasRiesgo as $clave => $categoria) {
    foreach ($edadesRiesgo as [$claveEdad, $etiquetaEdad]) {
        $gruposCovid[1]["filas"][] = [
            "riesgo_{$clave}_{$claveEdad}",
            $categoria["etiqueta"],
            $etiquetaEdad,
            $clave
        ];
    }
}

$conteos = [];
foreach ($gruposCovid as $grupo) {
    foreach ($grupo["filas"] as $fila) {
        $conteos[$fila[0]] = array_fill_keys(array_keys($productos), 0);
    }
}

$sqlAplicaciones = "SELECT av.fecha_aplicacion, p.fecha_nacimiento, p.embarazo,
                           p.personal_salud, d.nombre_dosis,
                           COALESCE(b_aplicado.nombre_biologico, b.nombre_biologico) AS nombre_biologico,
                           (SELECT GROUP_CONCAT(c.nombre_comorbilidad SEPARATOR ' | ')
                            FROM PACIENTE_COMORBILIDAD pc
                            INNER JOIN COMORBILIDAD c ON c.id_comorbilidad = pc.id_comorbilidad
                            WHERE pc.id_paciente = p.id_paciente) AS comorbilidades
                    FROM APLICACION_VACUNA av
                    INNER JOIN PACIENTE p ON p.id_paciente = av.id_paciente
                    INNER JOIN ESQUEMA_BIOLOGICO_DOSIS ebd
                        ON ebd.id_esquema_biologico_dosis = av.id_esquema_biologico_dosis
                    INNER JOIN ESQUEMA_BIOLOGICO eb
                        ON eb.id_esquema_biologico = ebd.id_esquema_biologico
                    INNER JOIN BIOLOGICO b ON b.id_biologico = eb.id_biologico
                    INNER JOIN DOSIS d ON d.id_dosis = ebd.id_dosis
                    LEFT JOIN LOTE l ON l.id_lote = av.id_lote
                    LEFT JOIN PRESENTACION pr ON pr.id_presentacion = l.id_presentacion
                    LEFT JOIN BIOLOGICO b_aplicado ON b_aplicado.id_biologico = pr.id_biologico
                    WHERE av.id_unidad = ? AND av.fecha_aplicacion >= ? AND av.fecha_aplicacion < ?
                      AND (LOWER(b.nombre_biologico) LIKE '%covid%'
                           OR LOWER(b.nombre_biologico) LIKE '%sars%'
                           OR LOWER(b_aplicado.nombre_biologico) LIKE '%covid%'
                           OR LOWER(b_aplicado.nombre_biologico) LIKE '%sars%')
                    ORDER BY av.fecha_aplicacion, d.nombre_dosis";
$stmtAplicaciones = $conexion->prepare($sqlAplicaciones);
if (!$stmtAplicaciones) {
    die("Error al preparar el conteo de COVID-19: " . $conexion->error);
}
$stmtAplicaciones->bind_param("iss", $idUnidad, $inicioSql, $finSql);
if (!$stmtAplicaciones->execute()) {
    die("Error al consultar las aplicaciones de COVID-19: " . $stmtAplicaciones->error);
}
$resultadoAplicaciones = $stmtAplicaciones->get_result();
$aplicacionesSinClasificar = 0;
while ($aplicacion = $resultadoAplicaciones->fetch_assoc()) {
    $fechaAplicacion = DateTimeImmutable::createFromFormat(
        "!Y-m-d",
        (string)$aplicacion["fecha_aplicacion"]
    );
    $fechaNacimiento = DateTimeImmutable::createFromFormat(
        "!Y-m-d",
        (string)$aplicacion["fecha_nacimiento"]
    );
    if (!$fechaAplicacion || !$fechaNacimiento || $fechaNacimiento > $fechaAplicacion) {
        die("Se encontró una fecha inválida en una aplicación de COVID-19 o expediente.");
    }

    $edad = $fechaNacimiento->diff($fechaAplicacion);
    $edadMeses = $edad->y * 12 + $edad->m;
    $nombreBiologico = textoCovid($aplicacion["nombre_biologico"]);
    $producto = str_contains($nombreBiologico, "pfizer")
        || str_contains($nombreBiologico, "comirnaty")
        ? "pfizer"
        : (str_contains($nombreBiologico, "moderna")
            || str_contains($nombreBiologico, "spikevax")
            ? "moderna"
            : "otra");
    $idFila = null;

    if ((int)$aplicacion["embarazo"] === 1) {
        $idFila = "embarazadas";
    } elseif ($edadMeses >= 720) {
        $idFila = "60_plus";
    } elseif ((int)$aplicacion["personal_salud"] === 1) {
        $idFila = "personal_salud";
    } elseif ($edadMeses >= 6 && $edadMeses < 720) {
        foreach ($edadesRiesgo as [$claveEdad, $_etiqueta, $minimo, $maximo]) {
            if ($edadMeses >= $minimo && $edadMeses < $maximo) {
                $comorbilidades = textoCovid($aplicacion["comorbilidades"] ?? "");
                $claveRiesgo = categoriaRiesgoCovid($comorbilidades, $categoriasRiesgo);
                if ($claveRiesgo !== null) {
                    $idFila = "riesgo_{$claveRiesgo}_{$claveEdad}";
                }
                break;
            }
        }
    }

    if ($idFila === null || !isset($conteos[$idFila])) {
        $aplicacionesSinClasificar++;
        continue;
    }
    $conteos[$idFila][$producto]++;
}
$stmtAplicaciones->close();

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $ajustesRecibidos = $_POST["ajustes"] ?? [];
    $encabezadoRecibido = $_POST["encabezado"] ?? [];
    if (!is_array($ajustesRecibidos) || !is_array($encabezadoRecibido)) {
        $mensajeError = "No se recibieron datos válidos para guardar el paloteo.";
    } else {
        $ajustesValidados = [];
        foreach ($ajustesRecibidos as $idFila => $valores) {
            if (!isset($conteos[$idFila]) || !is_array($valores)) {
                $mensajeError = "El formulario contiene un renglón que ya no existe.";
                break;
            }
            foreach ($productos as $producto => $_etiqueta) {
                $valor = $valores[$producto] ?? "0";
                if (
                    (!is_string($valor) && !is_int($valor))
                    || !preg_match('/^-?\d{1,6}$/', (string)$valor)
                ) {
                    $mensajeError = "Los ajustes deben ser números enteros entre -999999 y 999999.";
                    break 2;
                }
                $ajuste = (int)$valor;
                if ($conteos[$idFila][$producto] + $ajuste < 0) {
                    $mensajeError = "Un ajuste no puede dejar un conteo final menor que cero.";
                    break 2;
                }
                $ajustesValidados[$idFila][$producto] = $ajuste;
            }
        }

        $encabezadoValidado = [];
        foreach ($camposEncabezado as $campo => $_etiqueta) {
            $valor = $encabezadoRecibido[$campo] ?? "";
            if (!is_string($valor) || mb_strlen($valor, "UTF-8") > 150) {
                $mensajeError = "Los datos del encabezado deben tener un máximo de 150 caracteres.";
                break;
            }
            $encabezadoValidado[$campo] = trim($valor);
        }

        if ($mensajeError === "") {
            $datosJson = json_encode(
                ["ajustes" => $ajustesValidados, "encabezado" => $encabezadoValidado],
                JSON_UNESCAPED_UNICODE
            );
            if ($datosJson === false) {
                $mensajeError = "No fue posible preparar los datos para guardarlos.";
            } else {
                $stmtGuardar = $conexion->prepare(
                    "INSERT INTO PALOTEO_COVID (id_unidad, anio, mes, datos_json, id_usuario)
                     VALUES (?, ?, ?, ?, ?)
                     ON DUPLICATE KEY UPDATE datos_json = VALUES(datos_json), id_usuario = VALUES(id_usuario)"
                );
                if (!$stmtGuardar) {
                    die("Error al preparar el guardado del paloteo: " . $conexion->error);
                }
                $stmtGuardar->bind_param("iiisi", $idUnidad, $anio, $mes, $datosJson, $idUsuario);
                if (!$stmtGuardar->execute()) {
                    die("Error al guardar el paloteo: " . $stmtGuardar->error);
                }
                $stmtGuardar->close();
                header("Location: paloteo_covid.php?mes={$mes}&anio={$anio}&guardado=1");
                exit();
            }
        }
        $ajustes = $ajustesRecibidos;
        if (isset($encabezadoValidado["entidad"])) {
            $encabezado = array_merge($encabezado, $encabezadoValidado);
        } else {
            foreach ($camposEncabezado as $campo => $_etiqueta) {
                if (is_string($encabezadoRecibido[$campo] ?? null)) {
                    $encabezado[$campo] = trim($encabezadoRecibido[$campo]);
                }
            }
        }
    }
}

$totalPorProducto = array_fill_keys(array_keys($productos), 0);
$totalGeneral = 0;
$totalFilasPoblacion = [];
foreach ($gruposCovid as $grupo) {
    $totalFilasPoblacion[$grupo["poblacion"]] = count($grupo["filas"]);
    foreach ($grupo["filas"] as [$idFila]) {
        foreach ($productos as $producto => $_etiqueta) {
            $totalPorProducto[$producto] += $conteos[$idFila][$producto]
                + ajusteCovid($ajustes, $idFila, $producto);
        }
    }
}
$totalGeneral = array_sum($totalPorProducto);
$guardado = ($_GET["guardado"] ?? "") === "1";
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Paloteo de COVID-19</title>
    <style>
        :root { font-family: Arial, Helvetica, sans-serif; color: #172b3a; background: #eef3f6; }
        * { box-sizing: border-box; }
        body { margin: 0; padding: 18px; }
        .pagina { max-width: 1200px; margin: auto; }
        .barra, .hoja { background: #fff; border: 1px solid #c9d5dd; border-radius: 8px; }
        .barra { display: flex; flex-wrap: wrap; align-items: end; gap: 10px; padding: 14px; margin-bottom: 14px; }
        .periodo { display: flex; flex-wrap: wrap; align-items: end; gap: 10px; }
        .barra label { display: grid; gap: 4px; font-size: 13px; font-weight: 700; }
        select, input, button { font: inherit; }
        select, .barra button, .boton { padding: 8px 12px; border: 1px solid #aebdc7; border-radius: 4px; }
        .periodo select { width: 135px; }
        button, .boton { font-weight: 700; cursor: pointer; text-decoration: none; }
        .primario { background: #176b54; color: #fff; }
        .guardar { display: inline-flex; align-items: center; gap: 8px; }
        .guardar svg { width: 17px; height: 17px; fill: none; stroke: currentColor; stroke-linecap: round; stroke-linejoin: round; stroke-width: 1.8; }
        .secundario { background: #e7eef2; color: #18384c; }
        .boton-icono { display: inline-flex; align-items: center; justify-content: center; width: 40px; height: 40px; padding: 8px; }
        .boton-icono svg { width: 20px; height: 20px; }
        .estado { width: 100%; margin: 0; color: #176b54; font-weight: 700; }
        .error { width: 100%; margin: 0; padding: 10px; color: #8a1f11; background: #fff0ed; }
        .hoja { padding: 14px; }
        .institucional { text-align: center; font-size: 12px; font-weight: 700; line-height: 1.35; }
        h1 { margin: 8px 0 12px; text-align: center; font-size: 17px; }
        .encabezado { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 8px 12px; margin-bottom: 14px; }
        .dato { display: flex; align-items: end; gap: 4px; min-width: 0; font-size: 10px; font-weight: 700; }
        .dato label { flex: 0 0 auto; }
        .dato input { width: 100%; min-width: 0; padding: 3px; border: 0; border-bottom: 1px solid #607988; }
        .dato-largo { grid-column: span 2; }
        .tabla-contenedor { overflow: auto; }
        table { width: 100%; min-width: 780px; border-collapse: collapse; table-layout: fixed; font-size: 10px; }
        th, td { border: 1px solid #8296a2; padding: 4px; text-align: center; }
        thead th { background: #e5edf1; color: #17364b; font-size: 9px; }
        .poblacion { width: 16%; font-weight: 700; }
        .grupo { width: 42%; text-align: left; }
        .edad { width: 14%; }
        .conteo { width: 7%; }
        tbody td.poblacion, tbody td.grupo { vertical-align: middle; }
        tbody tr:nth-child(even) { background: #f7fafb; }
        .ajuste { width: 42px; padding: 2px; text-align: center; }
        .conteo-auto { color: #314d5e; font-weight: 700; }
        .mas { color: #768894; }
        .conteo-impresion { display: none; }
        tfoot th { background: #dce7ec; font-size: 10px; }
        .nota { margin: 10px 0 0; color: #526977; font-size: 11px; }
        @media (max-width: 700px) {
            body { padding: 8px; }
            .encabezado { grid-template-columns: 1fr 1fr; }
            .dato-largo { grid-column: span 2; }
        }
        @media print {
            @page { size: A3 portrait; margin: 7mm; }
            body { padding: 0; background: #fff; }
            .pagina, .hoja { width: 100%; max-width: none; margin: 0; border: 0; border-radius: 0; }
            .barra, .nota, .ajuste, .mas, .boton-menu-principal { display: none !important; }
            .hoja { padding: 0; }
            .institucional { font-size: 8pt; line-height: 1.15; }
            h1 { margin: 2mm 0; font-size: 11pt; }
            .encabezado { gap: 2mm 3mm; margin-bottom: 2mm; }
            .dato { font-size: 7pt; }
            table { min-width: 0; font-size: 7pt; }
            th, td { padding: 1mm; }
            thead th { font-size: 7pt; }
            .conteo-auto { display: none; }
            .conteo-impresion { display: inline; }
            tr { break-inside: avoid; }
        }
    </style>
    <link rel="stylesheet" href="../css/tema_reportes.css?v=20261105-3">
</head>
<body>
<main class="pagina">
    <header class="encabezado-reporte">
        <div class="encabezado-reporte__contenido">
            <span class="encabezado-reporte__seccion">Paloteo de vacunación</span>
            <h1>Aplicación de COVID-19</h1>
            <p>Temporada invernal <?php echo $temporadaInicio . "-" . ($temporadaInicio + 1); ?> · <?php echo hCovid($nombreMeses[$mes] . " " . $anio); ?></p>
        </div>
    </header>
    <div class="barra">
        <form class="periodo" method="get" action="paloteo_covid.php">
            <label for="mes">Mes
                <select name="mes" id="mes">
                    <?php foreach ($mesesTemporada as $numeroMes): ?>
                        <option value="<?php echo $numeroMes; ?>" <?php echo $mes === $numeroMes ? "selected" : ""; ?>>
                            <?php echo hCovid($nombreMeses[$numeroMes]); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label for="anio">Año
                <select name="anio" id="anio">
                    <?php for ($opcionAnio = 2000; $opcionAnio <= $anioPredeterminado + 1; $opcionAnio++): ?>
                        <option value="<?php echo $opcionAnio; ?>" <?php echo $anio === $opcionAnio ? "selected" : ""; ?>>
                            <?php echo $opcionAnio; ?>
                        </option>
                    <?php endfor; ?>
                </select>
            </label>
            <button class="primario boton-icono" type="submit" aria-label="Consultar periodo" title="Consultar periodo">
                <svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="10.8" cy="10.8" r="6.8"></circle><path d="m16 16 5 5"></path>
                </svg>
            </button>
        </form>
        <button class="primario guardar boton-icono" type="submit" form="formulario-paloteo-covid" aria-label="Guardar paloteo COVID-19" title="Guardar paloteo COVID-19">
            <svg aria-hidden="true" viewBox="0 0 24 24" focusable="false">
                <path d="M5 3h12l4 4v14H3V3h2Z"/>
                <path d="M7 3v6h10V3M7 21v-8h10v8"/>
            </svg>
        </button>
        <button class="secundario boton-icono" type="button" onclick="window.print()" aria-label="Imprimir o guardar como PDF" title="Imprimir o guardar como PDF">
            <svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M7 8V3h10v5M7 17H5a2 2 0 0 1-2-2v-4a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2v4a2 2 0 0 1-2 2h-2"></path><path d="M7 14h10v7H7zM18 11h.01"></path>
            </svg>
        </button>
        <?php if ($guardado): ?>
            <p class="estado" role="status">Paloteo de COVID-19 guardado para <?php echo hCovid($nombreMeses[$mes] . " " . $anio); ?>.</p>
        <?php endif; ?>
        <?php if ($mensajeError !== ""): ?>
            <p class="error" role="alert"><?php echo hCovid($mensajeError); ?></p>
        <?php endif; ?>
    </div>

    <form id="formulario-paloteo-covid" method="post" action="paloteo_covid.php?mes=<?php echo $mes; ?>&amp;anio=<?php echo $anio; ?>">
        <input type="hidden" name="mes" value="<?php echo $mes; ?>">
        <input type="hidden" name="anio" value="<?php echo $anio; ?>">
        <section class="hoja">
            <div class="institucional">
                SUBSECRETARÍA DE POLÍTICAS DE SALUD Y BIENESTAR POBLACIONAL<br>
                CENTRO NACIONAL PARA LA SALUD DE LA INFANCIA Y LA ADOLESCENCIA<br>
                PROGRAMA DE VACUNACIÓN UNIVERSAL
            </div>
            <h1>HOJA DE PALOTEO DE DOSIS APLICADAS DE VACUNA CONTRA LA COVID-19<br>
                TEMPORADA INVERNAL <?php echo $temporadaInicio . "-" . ($temporadaInicio + 1); ?></h1>
            <div class="encabezado">
                <?php foreach ($camposEncabezado as $campo => $etiqueta): ?>
                    <div class="dato <?php echo in_array($campo, ["jurisdiccion", "responsable"], true) ? "dato-largo" : ""; ?>">
                        <label for="encabezado-<?php echo hCovid($campo); ?>"><?php echo hCovid($etiqueta); ?>:</label>
                        <input id="encabezado-<?php echo hCovid($campo); ?>"
                               name="encabezado[<?php echo hCovid($campo); ?>]"
                               value="<?php echo hCovid($encabezado[$campo] ?? ""); ?>">
                    </div>
                <?php endforeach; ?>
                <div class="dato dato-largo">
                    <label>Institución de salud:</label>
                    <span>SERVICIOS DE SALUD DE CHIHUAHUA</span>
                </div>
                <div class="dato dato-largo">
                    <label>Unidad de salud:</label>
                    <span><?php echo hCovid($nombreUnidad); ?></span>
                </div>
                <div class="dato">
                    <label>Fecha:</label>
                    <span><?php echo hCovid($nombreMeses[$mes] . " " . $anio); ?></span>
                </div>
            </div>

            <div class="tabla-contenedor">
                <table>
                    <thead>
                        <tr>
                            <th class="poblacion" rowspan="2">POBLACIÓN</th>
                            <th class="grupo" colspan="2" rowspan="2">DOSIS / GRUPO POBLACIONAL</th>
                            <th colspan="3">COVID-19</th>
                            <th class="conteo" rowspan="2">TOTAL</th>
                        </tr>
                        <tr>
                            <?php foreach ($productos as $etiqueta): ?>
                                <th class="conteo"><?php echo hCovid($etiqueta); ?></th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($gruposCovid as $grupo): ?>
                            <?php $primeraFilaPoblacion = true; ?>
                            <?php $grupoAnterior = null; ?>
                            <?php foreach ($grupo["filas"] as $indiceFila => [$idFila, $etiquetaFila, $etiquetaEdad]): ?>
                                <?php
                                $cambioGrupo = $etiquetaFila !== $grupoAnterior;
                                $grupoAnterior = $etiquetaFila;
                                $filasGrupo = 1;
                                if ($cambioGrupo && $etiquetaEdad !== "") {
                                    for (
                                        $indiceSiguiente = $indiceFila + 1;
                                        isset($grupo["filas"][$indiceSiguiente])
                                            && $grupo["filas"][$indiceSiguiente][1] === $etiquetaFila;
                                        $indiceSiguiente++
                                    ) {
                                        $filasGrupo++;
                                    }
                                }
                                $totalFila = 0;
                                foreach ($productos as $producto => $_etiquetaProducto) {
                                    $totalFila += $conteos[$idFila][$producto]
                                        + ajusteCovid($ajustes, $idFila, $producto);
                                }
                                ?>
                                <tr>
                                    <?php if ($primeraFilaPoblacion): ?>
                                        <td class="poblacion" rowspan="<?php echo $totalFilasPoblacion[$grupo["poblacion"]]; ?>">
                                            <?php echo hCovid($grupo["poblacion"]); ?>
                                        </td>
                                        <?php $primeraFilaPoblacion = false; ?>
                                    <?php endif; ?>
                                    <?php if ($cambioGrupo): ?>
                                        <td class="grupo" rowspan="<?php echo $filasGrupo; ?>">
                                            <?php echo hCovid($etiquetaFila); ?>
                                        </td>
                                    <?php endif; ?>
                                    <td class="edad"><?php echo hCovid($etiquetaEdad); ?></td>
                                    <?php foreach ($productos as $producto => $_etiquetaProducto): ?>
                                        <?php
                                        $conteoBase = $conteos[$idFila][$producto];
                                        $ajuste = ajusteCovid($ajustes, $idFila, $producto);
                                        ?>
                                        <td>
                                            <span class="conteo-auto"><?php echo $conteoBase; ?></span>
                                            <span class="mas">+</span>
                                            <input class="ajuste" type="number" min="-999999" max="999999"
                                                   name="ajustes[<?php echo hCovid($idFila); ?>][<?php echo hCovid($producto); ?>]"
                                                   value="<?php echo $ajuste; ?>"
                                                   aria-label="Ajuste <?php echo hCovid($producto . " " . $etiquetaFila . " " . $etiquetaEdad); ?>">
                                            <span class="conteo-impresion"><?php echo $conteoBase + $ajuste; ?></span>
                                        </td>
                                    <?php endforeach; ?>
                                    <td class="conteo-auto"><?php echo $totalFila; ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr>
                            <th colspan="3">TOTAL DE DOSIS APLICADAS</th>
                            <?php foreach ($productos as $producto => $_etiqueta): ?>
                                <th><?php echo $totalPorProducto[$producto]; ?></th>
                            <?php endforeach; ?>
                            <th><?php echo $totalGeneral; ?></th>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </section>
    </form>
</main>
</body>
</html>
