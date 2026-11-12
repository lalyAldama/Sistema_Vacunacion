<?php
require_once __DIR__ . "/../config/seguridad.php";

if (!isset($_SESSION["id_usuario"])) {
    header("Location: ../login.php");
    exit();
}

require_once __DIR__ . "/../config/conexion.php";
require_once __DIR__ . "/../config/unidad_contexto.php";
$contextoUnidad = unidadObtenerContexto($conexion);
$idUnidad = $contextoUnidad["id_unidad"];
$soloConsulta = $contextoUnidad["solo_consulta"];
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    unidadExigirPermisoEscritura();
}

function hInfluenza($value)
{
    return htmlspecialchars((string)$value, ENT_QUOTES, "UTF-8");
}

function textoInfluenza($value)
{
    $value = mb_strtolower(trim((string)$value), "UTF-8");
    return strtr($value, [
        "á" => "a", "é" => "e", "í" => "i", "ó" => "o", "ú" => "u",
        "ü" => "u", "ñ" => "n"
    ]);
}

function ajusteInfluenza($ajustes, $id, $campo)
{
    $valor = $ajustes[$id][$campo] ?? 0;
    return is_numeric($valor) ? (int)$valor : 0;
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
    exit("El paloteo de influenza solo está disponible de octubre a marzo.");
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
    "SELECT nombre, ap_pat, ap_mat FROM USUARIO WHERE id_usuario = ?"
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
$nombreUnidad = $contextoUnidad["nombre_unidad"];
$nombreResponsable = trim(
    ($usuario["nombre"] ?? "") . " " . ($usuario["ap_pat"] ?? "") . " " . ($usuario["ap_mat"] ?? "")
);

$sqlTabla = "CREATE TABLE IF NOT EXISTS PALOTEO_INFLUENZA (
                 id_paloteo INT NOT NULL AUTO_INCREMENT,
                 id_unidad INT NOT NULL,
                 anio SMALLINT UNSIGNED NOT NULL,
                 mes TINYINT UNSIGNED NOT NULL,
                 datos_json LONGTEXT NOT NULL,
                 id_usuario INT NOT NULL,
                 actualizado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                     ON UPDATE CURRENT_TIMESTAMP,
                 PRIMARY KEY (id_paloteo),
                 UNIQUE KEY uq_paloteo_influenza_periodo (id_unidad, anio, mes)
             ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
if (!$conexion->query($sqlTabla)) {
    die("No fue posible preparar el almacenamiento del paloteo de influenza: " . $conexion->error);
}

$stmtDatos = $conexion->prepare(
    "SELECT datos_json FROM PALOTEO_INFLUENZA WHERE id_unidad = ? AND anio = ? AND mes = ?"
);
if (!$stmtDatos) {
    die("Error al consultar el paloteo de influenza guardado: " . $conexion->error);
}
$stmtDatos->bind_param("iii", $idUnidad, $anio, $mes);
if (!$stmtDatos->execute()) {
    die("Error al leer el paloteo de influenza guardado: " . $stmtDatos->error);
}
$filaDatos = $stmtDatos->get_result()->fetch_assoc();
$stmtDatos->close();
$datosGuardados = ["ajustes" => [], "encabezado" => []];
if ($filaDatos) {
    $datosDecodificados = json_decode($filaDatos["datos_json"], true);
    if (!is_array($datosDecodificados) || json_last_error() !== JSON_ERROR_NONE) {
        die("Los datos guardados del paloteo de influenza no se pudieron leer.");
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
    "jurisdiccion" => "",
    "municipio" => "",
    "localidad" => "",
    "ageb" => "",
    "sector" => "",
    "responsable" => $nombreResponsable
], array_intersect_key($datosGuardados["encabezado"], $camposEncabezado));
$ajustes = $datosGuardados["ajustes"];
$mensajeError = "";

$rangosDosis = [
    "primera" => [
        ["6_11m", "6 a 11 meses", 6, 12],
        ["12_23m", "12 a 23 meses", 12, 24],
        ["24_35m", "24 a 35 meses", 24, 36],
        ["36_47m", "36 a 47 meses", 36, 48],
        ["48_59m", "48 a 59 meses", 48, 60]
    ],
    "segunda" => [
        ["7_11m", "7 a 11 meses", 7, 12],
        ["12_23m", "12 a 23 meses", 12, 24],
        ["24_35m", "24 a 35 meses", 24, 36],
        ["36_47m", "36 a 47 meses", 36, 48],
        ["48_59m", "48 a 59 meses", 48, 60]
    ],
    "anual" => [
        ["18_23m", "18 a 23 meses", 18, 24],
        ["24_35m", "24 a 35 meses", 24, 36],
        ["36_47m", "36 a 47 meses", 36, 48],
        ["48_59m", "48 a 59 meses", 48, 60],
        ["60_plus", "60 años y más", 720, null]
    ]
];
$riesgos = [
    ["vih", "Personas que viven con VIH/SIDA", [1]],
    ["diabetes", "Personas con diabetes mellitus", [2]],
    ["obesidad", "Personas con obesidad mórbida", [3]],
    ["cardiopatias", "Personas con cardiopatías agudas o crónicas", [4, 12, 13]],
    ["pulmonar", "Enfermedad pulmonar crónica, incluye EPOC y asma", [5]],
    ["cancer", "Personas con cáncer", [6]],
    ["congenitas", "Enfermedades cardiacas o pulmonares congénitas u otros padecimientos crónicos que requieran consumo prolongado de salicilatos", [7]],
    ["renal", "Personas con insuficiencia renal", [8]],
    ["inmunosupresion", "Personas con inmunosupresión adquirida por enfermedad o tratamiento, excepto VIH/SIDA", [9]],
    ["otros", "Otros grupos", [10, 11, 14, 15]]
];
$prioridadRiesgo = [
    ["vih", [1]],
    ["diabetes", [2]],
    ["obesidad", [3]],
    ["cardiopatias", [4, 12, 13]],
    ["pulmonar", [5]],
    ["cancer", [6]],
    ["congenitas", [7]],
    ["renal", [8]],
    ["inmunosupresion", [9]],
    ["otros", [10, 11, 14, 15]]
];
$conteos = [];
$agregarFila = function ($id) use (&$conteos) {
    $conteos[$id] = ["no_migrantes" => 0, "migrantes" => 0];
};
foreach (["influenza_riesgo_embarazo", "influenza_riesgo_salud"] as $id) {
    $agregarFila($id);
}
foreach ($rangosDosis as $tipo => $rangos) {
    foreach ($rangos as [$claveRango]) {
        $agregarFila("influenza_{$tipo}_{$claveRango}");
    }
}
foreach ($riesgos as [$claveRiesgo]) {
    foreach (["5_9", "10_19", "20_59"] as $rango) {
        $agregarFila("influenza_riesgo_{$claveRiesgo}_{$rango}");
    }
}

$sqlAplicaciones = "SELECT av.fecha_aplicacion, p.fecha_nacimiento, p.embarazo,
                           p.personal_salud, p.estatus_migratorio, d.nombre_dosis,
                           COALESCE(b_aplicado.nombre_biologico, b.nombre_biologico) AS nombre_biologico,
                           (SELECT GROUP_CONCAT(pc.id_comorbilidad)
                            FROM PACIENTE_COMORBILIDAD pc
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
                      AND (LOWER(b.nombre_biologico) LIKE '%influenza%'
                           OR LOWER(b_aplicado.nombre_biologico) LIKE '%influenza%')
                    ORDER BY av.fecha_aplicacion, d.nombre_dosis";
$stmtAplicaciones = $conexion->prepare($sqlAplicaciones);
if (!$stmtAplicaciones) {
    die("Error al preparar el conteo de influenza: " . $conexion->error);
}
$stmtAplicaciones->bind_param("iss", $idUnidad, $inicioSql, $finSql);
if (!$stmtAplicaciones->execute()) {
    die("Error al consultar las aplicaciones de influenza: " . $stmtAplicaciones->error);
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
        die("Se encontró una fecha inválida en una aplicación de influenza o expediente.");
    }

    $edad = $fechaNacimiento->diff($fechaAplicacion);
    $edadMeses = $edad->y * 12 + $edad->m;
    $comorbilidades = array_map(
        "intval",
        array_filter(explode(",", (string)$aplicacion["comorbilidades"]), "strlen")
    );
    $nombreDosis = textoInfluenza($aplicacion["nombre_dosis"]);
    $tipoDosis = str_contains($nombreDosis, "refuerzo")
        ? "refuerzo"
        : (str_contains($nombreDosis, "anual")
            ? "anual"
            : (str_contains($nombreDosis, "primera") || preg_match('/(^|\D)1(\D|$)/', $nombreDosis)
                ? "primera"
                : (str_contains($nombreDosis, "segunda") || preg_match('/(^|\D)2(\D|$)/', $nombreDosis)
                    ? "segunda"
                    : "")));
    $idFila = null;

    $esPoblacionRiesgo = $edadMeses >= 60 && $edadMeses < 720;
    if ($esPoblacionRiesgo && (int)$aplicacion["embarazo"] === 1) {
        $idFila = "influenza_riesgo_embarazo";
    } elseif ($esPoblacionRiesgo && (int)$aplicacion["personal_salud"] === 1) {
        $idFila = "influenza_riesgo_salud";
    } elseif ($esPoblacionRiesgo && $comorbilidades) {
        foreach ($prioridadRiesgo as [$claveRiesgo, $idsRiesgo]) {
            if ($idsRiesgo && array_intersect($idsRiesgo, $comorbilidades)) {
                $rangoEdad = $edadMeses < 120
                    ? "5_9"
                    : ($edadMeses < 240 ? "10_19" : "20_59");
                $idFila = "influenza_riesgo_{$claveRiesgo}_{$rangoEdad}";
                break;
            }
        }
    }
    if ($idFila === null && in_array($tipoDosis, ["primera", "segunda"], true)) {
        foreach ($rangosDosis[$tipoDosis] as [$claveRango, $_etiqueta, $minimo, $maximo]) {
            if ($edadMeses >= $minimo && $edadMeses < $maximo) {
                $idFila = "influenza_{$tipoDosis}_{$claveRango}";
                break;
            }
        }
    }
    if ($idFila === null && in_array($tipoDosis, ["anual", "refuerzo"], true) && $edadMeses >= 18) {
        foreach ($rangosDosis["anual"] as [$claveRango, $_etiqueta, $minimo, $maximo]) {
            if ($edadMeses >= $minimo && ($maximo === null || $edadMeses < $maximo)) {
                $idFila = "influenza_anual_{$claveRango}";
                break;
            }
        }
    }
    if ($idFila === null || !isset($conteos[$idFila])) {
        $aplicacionesSinClasificar++;
        continue;
    }

    $estatusMigratorio = textoInfluenza($aplicacion["estatus_migratorio"]);
    $columna = $estatusMigratorio !== "" && $estatusMigratorio !== "no aplica"
        ? "migrantes"
        : "no_migrantes";
    $conteos[$idFila][$columna]++;
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
            foreach (["no_migrantes", "migrantes"] as $campo) {
                $valor = $valores[$campo] ?? "0";
                if (
                    (!is_string($valor) && !is_int($valor))
                    || !preg_match('/^-?\d{1,6}$/', (string)$valor)
                ) {
                    $mensajeError = "Los ajustes deben ser números enteros entre -999999 y 999999.";
                    break 2;
                }
                $ajuste = (int)$valor;
                if ($conteos[$idFila][$campo] + $ajuste < 0) {
                    $mensajeError = "Un ajuste no puede dejar un conteo final menor que cero.";
                    break 2;
                }
                $ajustesValidados[$idFila][$campo] = $ajuste;
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
                    "INSERT INTO PALOTEO_INFLUENZA (id_unidad, anio, mes, datos_json, id_usuario)
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
                header("Location: paloteo_influenza.php?mes={$mes}&anio={$anio}&guardado=1");
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

$gruposInfluenza = [
    ["poblacion" => "Población blanco", "grupo" => "Primera dosis",
        "filas" => array_map(fn($r) => [["influenza_primera_" . $r[0]], $r[1]], $rangosDosis["primera"])],
    ["poblacion" => "Población blanco", "grupo" => "Segunda dosis",
        "filas" => array_map(fn($r) => [["influenza_segunda_" . $r[0]], $r[1]], $rangosDosis["segunda"])],
    ["poblacion" => "Población blanco", "grupo" => "Dosis anual",
        "filas" => array_map(fn($r) => [["influenza_anual_" . $r[0]], $r[1]], $rangosDosis["anual"])],
    ["poblacion" => "Población de riesgo de 5 a 59 años", "grupo" => "Grupos de riesgo",
        "filas" => [
            [["influenza_riesgo_embarazo"], "Embarazadas"],
            [["influenza_riesgo_salud"], "Personal de salud en unidades médicas"]
        ]]
];
foreach ($riesgos as [$claveRiesgo, $etiquetaRiesgo]) {
    $gruposInfluenza[] = [
        "poblacion" => "Población de riesgo de 5 a 59 años",
        "grupo" => $etiquetaRiesgo,
        "filas" => array_map(
            fn($rango) => [["influenza_riesgo_{$claveRiesgo}_{$rango[0]}"], $rango[1]],
            [
                ["5_9", "5 a 9 años"],
                ["10_19", "10 a 19 años"],
                ["20_59", "20 a 59 años"]
            ]
        )
    ];
}
$filasPorPoblacion = [];
foreach ($gruposInfluenza as $grupo) {
    $filasPorPoblacion[$grupo["poblacion"]] =
        ($filasPorPoblacion[$grupo["poblacion"]] ?? 0) + count($grupo["filas"]);
}
$conteoFila = function ($idsFila) use ($conteos, $ajustes) {
    $noMigrantes = 0;
    $migrantes = 0;
    foreach ($idsFila as $idFila) {
        if (!isset($conteos[$idFila])) {
            continue;
        }
        $noMigrantes += $conteos[$idFila]["no_migrantes"]
            + ajusteInfluenza($ajustes, $idFila, "no_migrantes");
        $migrantes += $conteos[$idFila]["migrantes"]
            + ajusteInfluenza($ajustes, $idFila, "migrantes");
    }
    return [$noMigrantes, $migrantes];
};
$totalNoMigrantes = 0;
$totalMigrantes = 0;
foreach ($gruposInfluenza as $grupo) {
    foreach ($grupo["filas"] as [$idsFila]) {
        [$noMigrantes, $migrantes] = $conteoFila($idsFila);
        $totalNoMigrantes += $noMigrantes;
        $totalMigrantes += $migrantes;
    }
}

if (defined("INFORME_MENSUAL_INFLUENZA_SOLICITANDO_CONTEOS")) {
    $conteosInforme = [];
    foreach ($conteos as $idFila => $conteo) {
        $conteosInforme[$idFila] = [
            "no_migrantes" => $conteo["no_migrantes"]
                + ajusteInfluenza($ajustes, $idFila, "no_migrantes"),
            "migrantes" => $conteo["migrantes"]
                + ajusteInfluenza($ajustes, $idFila, "migrantes")
        ];
    }

    return [
        "conteos" => $conteosInforme,
        "encabezado" => $encabezado,
        "nombreUnidad" => $nombreUnidad,
        "nombreResponsable" => $nombreResponsable,
        "aplicacionesSinClasificar" => $aplicacionesSinClasificar
    ];
}

$guardado = ($_GET["guardado"] ?? "") === "1";
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Paloteo de influenza</title>
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
        table { width: 100%; border-collapse: collapse; table-layout: fixed; font-size: 10px; }
        th, td { border: 1px solid #8296a2; padding: 4px; text-align: center; }
        thead th { background: #e5edf1; color: #17364b; font-size: 9px; }
        .poblacion { width: 18%; font-weight: 700; }
        .grupo { width: 38%; text-align: left; }
        .edad { width: 18%; }
        .conteo { width: 8%; }
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
            .barra, .nota, .ajuste, .mas { display: none !important; }
            .hoja { padding: 0; }
            .institucional { font-size: 8pt; line-height: 1.15; }
            h1 { margin: 2mm 0; font-size: 11pt; }
            .encabezado { gap: 2mm 3mm; margin-bottom: 2mm; }
            .dato { font-size: 7pt; }
            table { font-size: 7pt; }
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
            <h1>Aplicación de influenza</h1>
            <p>Temporada invernal <?php echo $temporadaInicio . "-" . ($temporadaInicio + 1); ?> · <?php echo hInfluenza($nombreMeses[$mes] . " " . $anio); ?></p>
        </div>
    </header>
    <div class="barra">
        <form class="periodo" method="get" action="paloteo_influenza.php">
            <label for="mes">Mes
                <select name="mes" id="mes">
                    <?php foreach ($mesesTemporada as $numeroMes): ?>
                        <?php $nombreMes = $nombreMeses[$numeroMes]; ?>
                        <option value="<?php echo $numeroMes; ?>" <?php echo $mes === $numeroMes ? "selected" : ""; ?>>
                            <?php echo hInfluenza($nombreMes); ?>
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
        <?php if (!$soloConsulta): ?>
        <button class="primario guardar boton-icono" type="submit" form="formulario-paloteo-influenza" aria-label="Guardar paloteo de influenza" title="Guardar paloteo de influenza">
            <svg aria-hidden="true" viewBox="0 0 24 24" focusable="false">
                <path d="M5 3h12l4 4v14H3V3h2Z"/>
                <path d="M7 3v6h10V3M7 21v-8h10v8"/>
            </svg>
        </button>
        <?php else: ?>
        <span role="status">Modo de consulta: los ajustes no se pueden guardar.</span>
        <?php endif; ?>
        <button class="secundario boton-icono" type="button" onclick="window.print()" aria-label="Imprimir o guardar como PDF" title="Imprimir o guardar como PDF">
            <svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M7 8V3h10v5M7 17H5a2 2 0 0 1-2-2v-4a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2v4a2 2 0 0 1-2 2h-2"></path><path d="M7 14h10v7H7zM18 11h.01"></path>
            </svg>
        </button>
        <?php if ($guardado): ?>
            <p class="estado" role="status">Paloteo de influenza guardado para <?php echo hInfluenza($nombreMeses[$mes] . " " . $anio); ?>.</p>
        <?php endif; ?>
        <?php if ($mensajeError !== ""): ?>
            <p class="error" role="alert"><?php echo hInfluenza($mensajeError); ?></p>
        <?php endif; ?>
    </div>

    <form id="formulario-paloteo-influenza" method="post" action="paloteo_influenza.php?mes=<?php echo $mes; ?>&amp;anio=<?php echo $anio; ?>">
        <input type="hidden" name="mes" value="<?php echo $mes; ?>">
        <input type="hidden" name="anio" value="<?php echo $anio; ?>">
        <section class="hoja">
            <div class="institucional">
                SUBSECRETARÍA DE POLÍTICAS DE SALUD Y BIENESTAR POBLACIONAL<br>
                CENTRO NACIONAL PARA LA SALUD DE LA INFANCIA Y LA ADOLESCENCIA<br>
                PROGRAMA DE VACUNACIÓN UNIVERSAL
            </div>
            <h1>HOJA DE PALOTEO DE DOSIS APLICADAS DE VACUNA ANTI INFLUENZA ESTACIONAL<br>
                TEMPORADA INVERNAL <?php echo $temporadaInicio . "-" . ($temporadaInicio + 1); ?></h1>
            <div class="encabezado">
                <?php foreach ($camposEncabezado as $campo => $etiqueta): ?>
                    <div class="dato <?php echo in_array($campo, ["jurisdiccion", "responsable"], true) ? "dato-largo" : ""; ?>">
                        <label for="encabezado-<?php echo hInfluenza($campo); ?>"><?php echo hInfluenza($etiqueta); ?>:</label>
                        <input id="encabezado-<?php echo hInfluenza($campo); ?>"
                               name="encabezado[<?php echo hInfluenza($campo); ?>]"
                               value="<?php echo hInfluenza($encabezado[$campo] ?? ""); ?>">
                    </div>
                <?php endforeach; ?>
                <div class="dato dato-largo">
                    <label>Institución de salud:</label>
                    <span>SERVICIOS DE SALUD DE CHIHUAHUA</span>
                </div>
                <div class="dato dato-largo">
                    <label>Unidad de salud:</label>
                    <span><?php echo hInfluenza($nombreUnidad); ?></span>
                </div>
                <div class="dato">
                    <label>Fecha:</label>
                    <span><?php echo hInfluenza($nombreMeses[$mes] . " " . $anio); ?></span>
                </div>
            </div>

            <div class="tabla-contenedor">
                <table>
                    <thead>
                        <tr>
                            <th class="poblacion" rowspan="2">POBLACIÓN</th>
                            <th class="grupo" colspan="2" rowspan="2">DOSIS / GRUPO POBLACIONAL</th>
                            <th colspan="2">INFLUENZA ESTACIONAL</th>
                            <th class="conteo" rowspan="2">TOTAL</th>
                        </tr>
                        <tr>
                            <th class="conteo">NO INCLUYE MIGRANTES</th>
                            <th class="conteo">MIGRANTES</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $poblacionAnterior = null; ?>
                        <?php foreach ($gruposInfluenza as $grupo): ?>
                            <?php
                            $primeraFilaPoblacion = $poblacionAnterior !== $grupo["poblacion"];
                            $poblacionAnterior = $grupo["poblacion"];
                            $primeraFilaGrupo = true;
                            foreach ($grupo["filas"] as [$idsFila, $etiquetaFila]):
                                [$noMigrantes, $migrantes] = $conteoFila($idsFila);
                                $totalFila = $noMigrantes + $migrantes;
                                $idAjuste = $idsFila[0];
                                $ajusteNoMigrantes = ajusteInfluenza($ajustes, $idAjuste, "no_migrantes");
                                $ajusteMigrantes = ajusteInfluenza($ajustes, $idAjuste, "migrantes");
                                $baseNoMigrantes = $noMigrantes - $ajusteNoMigrantes;
                                $baseMigrantes = $migrantes - $ajusteMigrantes;
                            ?>
                                <tr>
                                    <?php if ($primeraFilaPoblacion): ?>
                                        <td class="poblacion" rowspan="<?php echo $filasPorPoblacion[$grupo["poblacion"]]; ?>">
                                            <?php echo hInfluenza($grupo["poblacion"]); ?>
                                        </td>
                                        <?php $primeraFilaPoblacion = false; ?>
                                    <?php endif; ?>
                                    <?php if ($primeraFilaGrupo): ?>
                                        <td class="grupo" rowspan="<?php echo count($grupo["filas"]); ?>">
                                            <?php echo hInfluenza($grupo["grupo"]); ?>
                                        </td>
                                        <?php $primeraFilaGrupo = false; ?>
                                    <?php endif; ?>
                                    <td class="edad"><?php echo hInfluenza($etiquetaFila); ?></td>
                                    <td>
                                        <span class="conteo-auto"><?php echo $baseNoMigrantes; ?></span>
                                        <span class="mas">+</span>
                                        <input class="ajuste ajuste-no-migrantes" type="number" step="1" min="<?php echo -$baseNoMigrantes; ?>"
                                               data-auto="<?php echo $baseNoMigrantes; ?>"
                                               aria-label="Ajuste de no migrantes: <?php echo hInfluenza($grupo["grupo"] . ", " . $etiquetaFila); ?>"
                                               name="ajustes[<?php echo hInfluenza($idAjuste); ?>][no_migrantes]"
                                               value="<?php echo $ajusteNoMigrantes; ?>">
                                        <span class="conteo-impresion"><?php echo $noMigrantes; ?></span>
                                    </td>
                                    <td>
                                        <span class="conteo-auto"><?php echo $baseMigrantes; ?></span>
                                        <span class="mas">+</span>
                                        <input class="ajuste ajuste-migrantes" type="number" step="1" min="<?php echo -$baseMigrantes; ?>"
                                               data-auto="<?php echo $baseMigrantes; ?>"
                                               aria-label="Ajuste de migrantes: <?php echo hInfluenza($grupo["grupo"] . ", " . $etiquetaFila); ?>"
                                               name="ajustes[<?php echo hInfluenza($idAjuste); ?>][migrantes]"
                                               value="<?php echo $ajusteMigrantes; ?>">
                                        <span class="conteo-impresion"><?php echo $migrantes; ?></span>
                                    </td>
                                    <td class="conteo total-fila"><?php echo $totalFila; ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr>
                            <th colspan="3">TOTAL DE DOSIS APLICADAS</th>
                            <th id="total-no-migrantes"><?php echo $totalNoMigrantes; ?></th>
                            <th id="total-migrantes"><?php echo $totalMigrantes; ?></th>
                            <th id="total-general"><?php echo $totalNoMigrantes + $totalMigrantes; ?></th>
                        </tr>
                    </tfoot>
                </table>
            </div>
            <?php if ($aplicacionesSinClasificar > 0): ?>
                <p class="error" role="status">
                    No se pudieron clasificar automáticamente <?php echo $aplicacionesSinClasificar; ?>
                    aplicación(es) de influenza del periodo. Revise edad, dosis y clasificación de riesgo.
                </p>
            <?php endif; ?>
        </section>
    </form>
</main>
<script>
    function actualizarTotalesInfluenza() {
        let totalNoMigrantes = 0;
        let totalMigrantes = 0;
        document.querySelectorAll("tbody tr").forEach(function (fila) {
            const campoNoMigrantes = fila.querySelector(".ajuste-no-migrantes");
            const campoMigrantes = fila.querySelector(".ajuste-migrantes");
            const noMigrantes = Number(campoNoMigrantes.dataset.auto) + (Number(campoNoMigrantes.value) || 0);
            const migrantes = Number(campoMigrantes.dataset.auto) + (Number(campoMigrantes.value) || 0);
            fila.querySelectorAll(".conteo-impresion")[0].textContent = String(noMigrantes);
            fila.querySelectorAll(".conteo-impresion")[1].textContent = String(migrantes);
            fila.querySelector(".total-fila").textContent = String(noMigrantes + migrantes);
            totalNoMigrantes += noMigrantes;
            totalMigrantes += migrantes;
        });
        document.getElementById("total-no-migrantes").textContent = String(totalNoMigrantes);
        document.getElementById("total-migrantes").textContent = String(totalMigrantes);
        document.getElementById("total-general").textContent = String(totalNoMigrantes + totalMigrantes);
    }

    document.querySelectorAll(".ajuste").forEach(function (campo) {
        campo.addEventListener("input", actualizarTotalesInfluenza);
    });
</script>
</body>
</html>