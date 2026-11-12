<?php
require_once __DIR__ . "/../config/seguridad.php";

if (!isset($_SESSION["id_usuario"])) {
    header("Location: ../login.php");
    exit();
}

require_once __DIR__ . "/../config/conexion.php";
require_once __DIR__ . "/../config/unidad_contexto.php";
require_once __DIR__ . "/../vacunacion/funciones_vph.php";
asegurarEsquemaVph($conexion);
asegurarClasificacionHepatitisB($conexion);
asegurarClasificacionSr($conexion);
asegurarClasificacionVaricelaHepatitisA($conexion);

function escapar($valor)
{
    return htmlspecialchars((string)$valor, ENT_QUOTES, "UTF-8");
}

function leerPeriodo($valor, $minimo, $maximo, $predeterminado)
{
    if ($valor === null || $valor === "") {
        return $predeterminado;
    }

    if (!is_string($valor) && !is_int($valor)) {
        http_response_code(400);
        exit("El mes o año indicado no es válido.");
    }

    $validado = filter_var($valor, FILTER_VALIDATE_INT);
    if ($validado === false || $validado < $minimo || $validado > $maximo) {
        http_response_code(400);
        exit("El mes o año indicado no es válido.");
    }

    return $validado;
}

function ajusteGuardado($ajustes, $clave, $campo, $dia = null)
{
    $ajusteRenglon = is_array($ajustes[$clave] ?? null) ? $ajustes[$clave] : [];
    $valor = $dia === null
        ? ($ajusteRenglon[$campo] ?? 0)
        : ((is_array($ajusteRenglon["dias"] ?? null) ? $ajusteRenglon["dias"][$dia] ?? 0 : 0));

    return is_numeric($valor) ? (int)$valor : 0;
}

function normalizarEtiquetaPaloteo($valor)
{
    $valor = mb_strtolower(trim((string)$valor), "UTF-8");
    return strtr($valor, [
        "á" => "a", "é" => "e", "í" => "i", "ó" => "o", "ú" => "u",
        "ü" => "u", "ñ" => "n"
    ]);
}

$obtenerDosisPaloteo = function ($nombre) {
    $nombre = normalizarEtiquetaPaloteo($nombre);
    if (str_contains($nombre, "refuerzo")) {
        return "refuerzo";
    }
    if (str_contains($nombre, "adicional")) {
        return "adicional";
    }
    if (str_contains($nombre, "anual")) {
        return "anual";
    }
    if (str_contains($nombre, "unica")) {
        return "unica";
    }
    if (str_contains($nombre, "primera") || preg_match('/(^|\D)1(\D|$)/', $nombre)) {
        return "primera";
    }
    if (str_contains($nombre, "segunda") || preg_match('/(^|\D)2(\D|$)/', $nombre)) {
        return "segunda";
    }
    if (str_contains($nombre, "tercera") || preg_match('/(^|\D)3(\D|$)/', $nombre)) {
        return "tercera";
    }

    return "";
};

$mesPredeterminado = (int)date("n");
$anioPredeterminado = (int)date("Y");
$modoInformeAnual = defined("INFORME_ANUAL_SOLICITANDO_CONTEOS");
$origenPeriodo = $_SERVER["REQUEST_METHOD"] === "POST" ? $_POST : $_GET;
$solicitudInforme = defined("INFORME_ANUAL_SOLICITANDO_CONTEOS")
    || defined("INFORME_MENSUAL_SOLICITANDO_CONTEOS");
$contextoUnidad = unidadObtenerContexto($conexion);
$idUnidad = $contextoUnidad["id_unidad"];
$soloConsulta = $contextoUnidad["solo_consulta"];
if ($_SERVER["REQUEST_METHOD"] === "POST" && !$solicitudInforme) {
    unidadExigirPermisoEscritura();
}
$mes = leerPeriodo($origenPeriodo["mes"] ?? null, 1, 12, $mesPredeterminado);
$anio = leerPeriodo($origenPeriodo["anio"] ?? null, 2000, 2100, $anioPredeterminado);
$fechaInicio = new DateTimeImmutable(sprintf(
    "%04d-%02d-01",
    $anio,
    $modoInformeAnual ? 1 : $mes
));
$fechaFin = $modoInformeAnual
    ? new DateTimeImmutable(sprintf("%04d-01-01", $anio + 1))
    : $fechaInicio->modify("+1 month");
$diasMes = (int)$fechaInicio->format("t");
$diasFormato = 31;
$inicioSql = $fechaInicio->format("Y-m-d");
$finSql = $fechaFin->format("Y-m-d");

$sqlUsuario = "SELECT nombre, ap_pat, ap_mat FROM USUARIO WHERE id_usuario = ?";
$stmtUsuario = $conexion->prepare($sqlUsuario);
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

$sqlTabla = "CREATE TABLE IF NOT EXISTS PALOTEO_GENERAL (
                 id_paloteo INT NOT NULL AUTO_INCREMENT,
                 id_unidad INT NOT NULL,
                 anio SMALLINT UNSIGNED NOT NULL,
                 mes TINYINT UNSIGNED NOT NULL,
                 datos_json LONGTEXT NOT NULL,
                 id_usuario INT NOT NULL,
                 actualizado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                     ON UPDATE CURRENT_TIMESTAMP,
                 PRIMARY KEY (id_paloteo),
                 UNIQUE KEY uq_paloteo_general_periodo (id_unidad, anio, mes)
             ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
if (!$conexion->query($sqlTabla)) {
    die("No fue posible preparar el almacenamiento del paloteo: " . $conexion->error);
}

$ajustesPorMesAnual = [];
if ($modoInformeAnual) {
    $stmtAjustesAnuales = $conexion->prepare(
        "SELECT mes, datos_json FROM PALOTEO_GENERAL WHERE id_unidad = ? AND anio = ?"
    );
    if (!$stmtAjustesAnuales) {
        die("Error al preparar los ajustes del paloteo anual: " . $conexion->error);
    }
    $stmtAjustesAnuales->bind_param("ii", $idUnidad, $anio);
    if (!$stmtAjustesAnuales->execute()) {
        die("Error al consultar los ajustes del paloteo anual: " . $stmtAjustesAnuales->error);
    }
    $resultadoAjustesAnuales = $stmtAjustesAnuales->get_result();
    while ($filaAjustesAnuales = $resultadoAjustesAnuales->fetch_assoc()) {
        $mesAjustesAnuales = (int)$filaAjustesAnuales["mes"];
        $datosAjustesAnuales = json_decode($filaAjustesAnuales["datos_json"], true);
        if (
            $mesAjustesAnuales < 1
            || $mesAjustesAnuales > 12
            || !is_array($datosAjustesAnuales)
            || json_last_error() !== JSON_ERROR_NONE
        ) {
            $stmtAjustesAnuales->close();
            die("Los ajustes guardados del paloteo anual no se pudieron leer.");
        }
        $ajustesPorMesAnual[$mesAjustesAnuales] =
            is_array($datosAjustesAnuales["ajustes"] ?? null)
                ? $datosAjustesAnuales["ajustes"]
                : [];
    }
    $stmtAjustesAnuales->close();
}

$mensajeError = "";
$datosGuardados = ["ajustes" => [], "encabezado" => []];
$stmtDatos = $conexion->prepare(
    "SELECT datos_json FROM PALOTEO_GENERAL WHERE id_unidad = ? AND anio = ? AND mes = ?"
);
if (!$stmtDatos) {
    die("Error al consultar el paloteo guardado: " . $conexion->error);
}
$stmtDatos->bind_param("iii", $idUnidad, $anio, $mes);
if (!$stmtDatos->execute()) {
    die("Error al consultar el paloteo guardado: " . $stmtDatos->error);
}
$filaDatos = $stmtDatos->get_result()->fetch_assoc();
$stmtDatos->close();

if ($filaDatos) {
    $datosDecodificados = json_decode($filaDatos["datos_json"], true);
    if (!is_array($datosDecodificados) || json_last_error() !== JSON_ERROR_NONE) {
        die("Los datos guardados del paloteo no se pudieron leer.");
    }
    $datosGuardados["ajustes"] = is_array($datosDecodificados["ajustes"] ?? null)
        ? $datosDecodificados["ajustes"]
        : [];
    $datosGuardados["encabezado"] = is_array($datosDecodificados["encabezado"] ?? null)
        ? $datosDecodificados["encabezado"]
        : [];
}

$encabezado = [
    "jurisdiccion" => (string)($datosGuardados["encabezado"]["jurisdiccion"] ?? ""),
    "localidad" => (string)($datosGuardados["encabezado"]["localidad"] ?? ""),
    "responsable" => (string)($datosGuardados["encabezado"]["responsable"] ?? $nombreResponsable)
];
$ajustes = $datosGuardados["ajustes"];

$renglones = [];
$automaticos = [];
$agregarRenglon = function ($id, $pagina, $biologico, $grupo, $detalle, $aliases = [], $dosis = "", $clasificacion = "") use (&$renglones, &$automaticos, $diasFormato) {
    $renglones[$id] = [
        "id" => $id,
        "pagina" => $pagina,
        "biologico" => $biologico,
        "grupo" => $grupo,
        "detalle" => $detalle,
        "aliases" => $aliases,
        "dosis" => $dosis,
        "clasificacion" => $clasificacion
    ];
    $automaticos[$id] = [
        "dias" => array_fill(1, $diasFormato, 0),
        "afromexicano" => 0,
        "indigenas" => 0,
        "migrantes" => 0
    ];
};

$filasPaginaUno = [
    ["bcg_24h","119 BCG","Recién nacido (primeras 24 horas)","Única"],
    ["bcg_neonato","119 BCG","Recién nacido (de 2 a 28 días)","Única"],
    ["bcg_29d_11m","119 BCG","29 días a 11 meses","Única"],
    ["bcg_1_4","119 BCG","1 a 4 años","Única"],
    ["bcg_5_9","119 BCG","5 a 13 años","Única"],
    ["hepb_rn","121 HEPATITIS B","Única recién nacido (0 a 7 días)",""],
    ["hepb_mayor11_1","121 HEPATITIS B","Mayores de 11 años (sin antecedente vacunal)","Primera"],
    ["hepb_mayor11_2","121 HEPATITIS B","Mayores de 11 años (sin antecedente vacunal)","Segunda"],
    ["hepb_riesgo_1","121 HEPATITIS B","Población en riesgo","Primera"],
    ["hepb_riesgo_2","121 HEPATITIS B","Población en riesgo","Segunda"],
    ["hepb_salud_1","121 HEPATITIS B","Personal de salud","Primera"],
    ["hepb_salud_2","121 HEPATITIS B","Personal de salud","Segunda"],
    ["hexa_1","275 HEXAVALENTE ACELULAR DPaT + IPV + Hib + HB","Primera","2 a 11 meses"],
    ["hexa_2","275 HEXAVALENTE ACELULAR DPaT + IPV + Hib + HB","Segunda","4 a 11 meses"],
    ["hexa_3","275 HEXAVALENTE ACELULAR DPaT + IPV + Hib + HB","Tercera","6 a 11 meses"],
    ["hexa_ref_18","275 HEXAVALENTE ACELULAR DPaT + IPV + Hib + HB","Refuerzo","18 a 23 meses"],
    ["hexa_rescate1","275 HEXAVALENTE ACELULAR DPaT + IPV + Hib + HB","Completar esquema 1 a 4 años","Primera"],
    ["hexa_rescate2","275 HEXAVALENTE ACELULAR DPaT + IPV + Hib + HB","Completar esquema 1 a 4 años","Segunda"],
    ["hexa_rescate3","275 HEXAVALENTE ACELULAR DPaT + IPV + Hib + HB","Completar esquema 1 a 4 años","Tercera"],
    ["hexa_rescate_refuerzo","275 HEXAVALENTE ACELULAR DPaT + IPV + Hib + HB","Completar esquema 1 a 4 años","Refuerzo"],
    ["hexa_4","Hexavalente acelular DPaT+IPV+Hib+HB/DPT","Única","4 años"],
    ["dpt_4_6","Hexavalente acelular DPaT+IPV+Hib+HB/DPT","Para iniciar o completar esquema","5 a 6 años única"],
    ["dpt_unica_4","123 DPT","Única","4 años"],
    ["dpt_rescate_5_6","123 DPT","Para iniciar o completar esquema","5 a 6 años única"],
    ["rota_1","274 Rotavirus RV1","Primera","2 meses"],
    ["rota_2","274 Rotavirus RV1","Segunda","4 meses"],
    ["rota_rescate1","274 Rotavirus RV1","Para iniciar o completar esquema","Primera 3 a 7 meses"],
    ["rota_rescate2","274 Rotavirus RV1","Para iniciar o completar esquema","Segunda 5 a 7 meses"],
    ["neumo_1","125 NEUMOCÓCICA CONJUGADA (13 VALENTE)","Primera","2 a 11 meses"],
    ["neumo_2","125 NEUMOCÓCICA CONJUGADA (13 VALENTE)","Segunda","4 a 11 meses"],
    ["neumo_3","125 NEUMOCÓCICA CONJUGADA (13 VALENTE)","Tercera","12 a 23 meses"],
    ["neumo_rescate1","125 NEUMOCÓCICA CONJUGADA (13 VALENTE)","Completar esquema 1 a 4 años","Primera"],
    ["neumo_rescate2","125 NEUMOCÓCICA CONJUGADA (13 VALENTE)","Completar esquema 1 a 4 años","Segunda"],
    ["neumo_rescate3","125 NEUMOCÓCICA CONJUGADA (13 VALENTE)","Completar esquema 1 a 4 años","Tercera"],
    ["neumo_unica60","125 NEUMOCÓCICA CONJUGADA (13 VALENTE)","60 años y más sin factores de riesgo","Única"],
    ["neumo_riesgo60","125 NEUMOCÓCICA CONJUGADA (13 VALENTE)","60 años y más con factores de riesgo","Única"],
    ["neumo20_1","354 Neumocócica conjugada (20 valente)","Primera","2 a 11 meses"],
    ["neumo20_2","354 Neumocócica conjugada (20 valente)","Segunda","4 a 11 meses"],
    ["neumo20_3","354 Neumocócica conjugada (20 valente)","Tercera","12 a 23 meses"],
    ["neumo20_rescate1","354 Neumocócica conjugada (20 valente)","Para iniciar o completar esquema 1 a 4 años","Primera"],
    ["neumo20_rescate2","354 Neumocócica conjugada (20 valente)","Para iniciar o completar esquema 1 a 4 años","Segunda"],
    ["neumo20_rescate3","354 Neumocócica conjugada (20 valente)","Para iniciar o completar esquema 1 a 4 años","Tercera"],
    ["neumo20_60","354 Neumocócica conjugada (20 valente)","Personas de 60 años y más","Única"],
    ["neumo23_60","NEUMOCÓCICA POLISACÁRIDA (23 VALENTE)","60 años y más","Única"],
    ["neumo23_riesgo","NEUMOCÓCICA POLISACÁRIDA (23 VALENTE)","60 años y más con factores de riesgo","Única"],
    ["neumo23_refuerzo_riesgo","NEUMOCÓCICA POLISACÁRIDA (23 VALENTE)","Refuerzo personas de 61 años y más, con factores de riesgo","12 meses después de la dosis previa"],
    ["srp_1","127 SRP Triple viral","Primera","12 meses"],
    ["srp_2","127 SRP Triple viral","Segunda","18 meses"],
    ["srp_rescate1","127 SRP Triple viral","Para iniciar o completar esquema","Primera 17 meses a 9 años"],
    ["srp_rescate2","127 SRP Triple viral","Para iniciar o completar esquema","Segunda 2 a 9 años"],
    ["sr_6m","128 SR Doble viral","Adicional","6 a 11 meses"],
    ["sr_1_4","128 SR Doble viral","Adicional","1 a 4 años"],
    ["sr_10_19","128 SR Doble viral","Única","10 a 19 años"],
    ["sr_20_39","128 SR Doble viral","Única","20 a 39 años"],
    ["sr_40_49","128 SR Doble viral","Única","40 a 49 años"],
    ["sr_salud","128 SR Doble viral","Personal de salud","Única"],
    ["sr_educativo","128 SR Doble viral","Personal educativo","Única"],
    ["sr_jornalero","128 SR Doble viral","Jornaleros agrícolas","Única"],
    ["vph_ninas","129 VPH *","Única","Personas de quinto año de primaria o de 11 años no escolarizadas"],
    ["vph_rezagados","129 VPH *","Única","Niñas y/o adolescentes de 12 a 16 años (rezagadas)"],
    ["vph_violencia_9_14","129 VPH *","Única","Niñas y/o adolescentes víctimas de violación sexual de 9 a 14 años"],
    ["vph_violencia_15_19","129 VPH *","Única","Niñas y/o adolescentes víctimas de violación sexual de 15 a 19 años"],
    ["vph_vih_1","129 VPH *","Personas de 11 a 49 años que viven con VIH","Primera"],
    ["vph_vih_2","129 VPH *","Personas de 11 a 49 años que viven con VIH","Segunda"],
    ["vph_vih_3","129 VPH *","Personas de 11 a 49 años que viven con VIH","Tercera"],
    ["varicela_unica_1","131 Varicela *","Única","1 año"],
    ["varicela_rezago_2_4","131 Varicela *","Rezagos","2 a 4 años"],
    ["hepa_unica_18m","122 Hepatitis A","Única","18 meses"],
    ["hepa_rezago_2_4","122 Hepatitis A","Rezagos","2 a 4 años"],
    ["hepa_riesgo","122 Hepatitis A","Población en riesgo",""]
];
foreach ($filasPaginaUno as $fila) {
    $agregarRenglon($fila[0], 1, $fila[1], $fila[2], $fila[3]);
}

$gruposEdadTd = [
    ["15_19", "15 a 19 años", 15, 20],
    ["20_59", "20 a 59 años", 20, 60],
    ["60_plus", "60 y más años", 60, 200]
];
$dosisTd = [
    "primera" => ["Primera", "primera", 1],
    "segunda" => ["Segunda (1 a 2 meses después de la 1era dosis)", "segunda", 1],
    "tercera" => ["Tercera (1 año después de la 2a dosis)", "tercera", 1],
    "refuerzo" => ["Refuerzo (cada 10 años hasta completar 5 dosis)", "refuerzo", 2]
];
foreach ($dosisTd as $claveDosisTd => [$etiquetaDosisTd, $codigoDosisTd, $paginaDosisTd]) {
    foreach ([
        ["10_19", "10 a 19 años", 10, 20],
        ["20_plus", "20 y más años", 20, 200]
    ] as $edadTd) {
        $id = "td_" . $claveDosisTd . "_embarazadas_" . $edadTd[0];
        $agregarRenglon(
            $id,
            1,
            "132 Td",
            ucfirst($codigoDosisTd) . " — Embarazadas",
            $edadTd[1]
        );
    }

    foreach ($gruposEdadTd as $edadTd) {
        $id = "td_" . $claveDosisTd . "_poblacion_" . $edadTd[0];
        $agregarRenglon(
            $id,
            $paginaDosisTd,
            "132 Td",
            $etiquetaDosisTd . " — Población",
            $edadTd[1]
        );
    }
}

$filasPaginaDos = [
    ["tdpa_embarazo","133 Tdpa","Embarazada a partir de la semana 20 de gestación","Única"],
    ["tdpa_salud","133 Tdpa","Personal en riesgo*","Única"],
    ["tdpa_dpt_4","Tdpa/DPT","","Única — 4 años"],
    ["tdpa_dpt_catchup","Tdpa/DPT","","Para iniciar o completar esquema — 5 a 6 años única"],
];
foreach ($filasPaginaDos as $fila) {
    $agregarRenglon($fila[0], 2, $fila[1], $fila[2], $fila[3]);
}

$dosisReemplazoTd = [
    "primera" => "Primera",
    "segunda" => "Segunda (1 a 2 meses después de la 1era dosis)",
    "tercera" => "Tercera (1 año después de la 2a dosis)",
    "refuerzo" => "Refuerzo (cada 10 años hasta completar 5 dosis)"
];
foreach ($dosisReemplazoTd as $claveDosis => $etiquetaDosis) {
    foreach ($gruposEdadTd as $edadTd) {
        $agregarRenglon(
            "tdpa_td_" . $claveDosis . "_" . $edadTd[0],
            2,
            "Tdpa/Td",
            "Población",
            $etiquetaDosis . " — " . $edadTd[1]
        );
    }
}
$agregarRenglon(
    "vsr_embarazo_28_36",
    2,
    "355 VSR",
    "Embarazadas entre las semanas 28 y 36 de gestación",
    ""
);
$filasCovid = [
    ["covid_6_59m_primera","6 a 59 meses","Primera"],
    ["covid_6_59m_segunda","6 a 59 meses","Segunda"],
    ["covid_6_59m_refuerzo","6 a 59 meses","Refuerzo"],
    ["covid_riesgo_5_59","5 a 59 años con factores de riesgo","Refuerzo"],
    ["covid_60_plus","60 años y más","Refuerzo"],
    ["covid_embarazadas","Embarazadas","Refuerzo"],
    ["covid_salud","Personal de salud","Refuerzo"]
];
foreach ($filasCovid as [$id, $grupo, $dosis]) {
    $agregarRenglon($id, 2, "344 COVID-19", $grupo, $dosis);
}

$rangosInfluenza = [
    ["6_11m", "6 a 11 meses", 6, 12],
    ["12_23m", "12 a 23 meses", 12, 24],
    ["24_35m", "24 a 35 meses", 24, 36],
    ["36_47m", "36 a 47 meses", 36, 48],
    ["48_59m", "48 a 59 meses", 48, 60]
];
$rangosSegundaDosisInfluenza = [
    ["7_11m", "7 a 11 meses", 7, 12],
    ["12_23m", "12 a 23 meses", 12, 24],
    ["24_35m", "24 a 35 meses", 24, 36],
    ["36_47m", "36 a 47 meses", 36, 48],
    ["48_59m", "48 a 59 meses", 48, 60]
];
foreach ($rangosInfluenza as $rango) {
    $agregarRenglon(
        "influenza_primera_" . $rango[0],
        2,
        "INFLUENZA",
        "Población blanco",
        "Primera — " . $rango[1]
    );
}
foreach ($rangosSegundaDosisInfluenza as $rango) {
    $agregarRenglon(
        "influenza_segunda_" . $rango[0],
        2,
        "INFLUENZA",
        "Población blanco",
        "Segunda — " . $rango[1]
    );
}
foreach ([
    ["18_23m", "18 a 23 meses"],
    ["24_35m", "24 a 35 meses"],
    ["36_47m", "36 a 47 meses"],
    ["48_59m", "48 a 59 meses"],
    ["60_plus", "60 años y más"]
] as $rango) {
    $agregarRenglon(
        "influenza_anual_" . $rango[0],
        2,
        "INFLUENZA",
        "Población blanco",
        "Dosis anual de revacunación — " . $rango[1]
    );
}
$agregarRenglon(
    "influenza_riesgo_embarazo",
    2,
    "INFLUENZA",
    "Antiinfluenza Población de riesgo de 5 a 59 años",
    "Grupos de riesgo — Embarazadas"
);
$agregarRenglon(
    "influenza_riesgo_salud",
    2,
    "INFLUENZA",
    "Antiinfluenza Población de riesgo de 5 a 59 años",
    "Grupos de riesgo — Personal de salud en unidades médicas"
);
foreach ([
    ["vih", "Personas con VIH", [1]],
    ["diabetes", "Personas con diabetes mellitus", [2]],
    ["obesidad", "Personas con obesidad mórbida", [3]],
    ["cardiopatias", "Personas con cardiopatías", [4, 7, 12, 13]],
    ["asma", "Personas con asma sin control o parcialmente controlado", [5]],
    ["cancer", "Personas con cáncer", [6]],
    ["pulmonar", "Enfermedades pulmonares crónicas/congénitas", [5, 7, 11]],
    ["renal", "Personas con insuficiencia renal", [8]],
    ["inmunosupresion", "Personas con inmunosupresión adquirida", [9, 14]],
    ["otros", "Otros grupos", [10, 15]]
] as $riesgo) {
    foreach ([["5_9", "5 a 9 años"], ["10_19", "10 a 19 años"], ["20_59", "20 a 59 años"]] as $rango) {
        $agregarRenglon(
            "influenza_riesgo_" . $riesgo[0] . "_" . $rango[0],
            2,
            "INFLUENZA",
            "Antiinfluenza Población de riesgo de 5 a 59 años",
            $riesgo[1] . " — " . $rango[1]
        );
    }
}

$prefijosFamilia = [
    "bcg" => ["bcg_"],
    "hepatitisb" => ["hepb_"],
    "hexavalente" => ["hexa_"],
    "dpt" => ["dpt_", "tdpa_dpt_"],
    "rotavirus" => ["rota_"],
    "neumococo20" => ["neumo20_"],
    "neumococo23" => ["neumo23_"],
    "neumococo" => ["neumo_"],
    "srp" => ["srp_"],
    "sr" => ["sr_"],
    "vph" => ["vph_"],
    "varicela" => ["varicela_"],
    "hepatitisa" => ["hepa_"],
    "tdpa" => ["tdpa_", "tdpa_td_"],
    "td" => ["td_"],
    "vsr" => ["vsr_"],
    "covid" => ["covid_"],
    "influenza" => ["influenza_"]
];

$sqlAplicaciones = "SELECT
                        av.id_aplicacion_vacuna,
                        av.fecha_aplicacion,
                        av.clasificacion_vph,
                        av.clasificacion_hepatitis_b,
                        av.clasificacion_sr,
                        av.clasificacion_varicela_hepatitis_a,
                        p.fecha_nacimiento,
                        p.fecha_ultima_menstruacion,
                        p.sexo,
                        p.embarazo,
                        p.personal_salud,
                        p.jornalero_agricola,
                        p.estatus_migratorio,
                        p.poblacion_indigena_afromexicana,
                        d.nombre_dosis,
                        b.nombre_biologico AS nombre_biologico_indicado,
                        COALESCE(b_aplicado.nombre_biologico, b.nombre_biologico) AS nombre_biologico,
                        (
                            SELECT GROUP_CONCAT(pc.id_comorbilidad)
                            FROM PACIENTE_COMORBILIDAD pc
                            WHERE pc.id_paciente = p.id_paciente
                        ) AS comorbilidades
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
                    WHERE av.id_unidad = ?
                        AND av.fecha_aplicacion >= ?
                        AND av.fecha_aplicacion < ?
                    ORDER BY av.fecha_aplicacion, b.nombre_biologico, d.nombre_dosis";
$stmtAplicaciones = $conexion->prepare($sqlAplicaciones);
if (!$stmtAplicaciones) {
    die("Error al preparar el conteo de aplicaciones: " . $conexion->error);
}
$stmtAplicaciones->bind_param("iss", $idUnidad, $inicioSql, $finSql);
if (!$stmtAplicaciones->execute()) {
    die("Error al consultar las aplicaciones del periodo: " . $stmtAplicaciones->error);
}
$resultadoAplicaciones = $stmtAplicaciones->get_result();
$aplicacionesSinClasificar = 0;
$aplicacionesSinClasificarPorFamilia = [];
$aplicacionesSinClasificarDetalle = [];
$aplicacionSinClasificarActual = [];
$marcarAplicacionSinClasificar = function ($familia, $motivo = "") use (
    &$aplicacionesSinClasificar,
    &$aplicacionesSinClasificarPorFamilia,
    &$aplicacionesSinClasificarDetalle,
    &$aplicacionSinClasificarActual
) {
    $aplicacionesSinClasificar++;
    $aplicacionesSinClasificarPorFamilia[$familia] =
        ($aplicacionesSinClasificarPorFamilia[$familia] ?? 0) + 1;
    $aplicacionesSinClasificarDetalle[] = array_merge(
        $aplicacionSinClasificarActual,
        ["familia" => $familia, "motivo" => $motivo]
    );
};
$conteosPorMesAnual = [];
$registrarConteoPaloteo = function ($idRenglon, $fecha, $esMigrante, $esPoblacionIndigenaAfromexicana = false) use (&$automaticos, &$conteosPorMesAnual) {
    $mesAplicacion = (int)$fecha->format("n");
    $conteosPorMesAnual[$idRenglon][$mesAplicacion] =
        ($conteosPorMesAnual[$idRenglon][$mesAplicacion] ?? 0) + 1;
    if ($esPoblacionIndigenaAfromexicana) {
        $automaticos[$idRenglon]["afromexicano"]++;
        $automaticos[$idRenglon]["indigenas"]++;
    }
    if ($esMigrante) {
        $automaticos[$idRenglon]["migrantes"]++;
    } else {
        $automaticos[$idRenglon]["dias"][(int)$fecha->format("j")]++;
    }
};

while ($aplicacion = $resultadoAplicaciones->fetch_assoc()) {
    $fechaAplicacion = DateTimeImmutable::createFromFormat("!Y-m-d", (string)$aplicacion["fecha_aplicacion"]);
    $fechaNacimiento = DateTimeImmutable::createFromFormat("!Y-m-d", (string)$aplicacion["fecha_nacimiento"]);
    if (!$fechaAplicacion || !$fechaNacimiento || $fechaNacimiento > $fechaAplicacion) {
        die("Se encontró una fecha inválida en una aplicación o expediente del reporte.");
    }

    $nombreBiologico = normalizarEtiquetaPaloteo($aplicacion["nombre_biologico"]);
    $edadPaciente = $fechaNacimiento->diff($fechaAplicacion);
    $aplicacionSinClasificarActual = [
        "id" => (int)$aplicacion["id_aplicacion_vacuna"],
        "fecha" => $fechaAplicacion->format("Y-m-d"),
        "biologico" => (string)$aplicacion["nombre_biologico"],
        "biologicoIndicado" => (string)$aplicacion["nombre_biologico_indicado"],
        "dosis" => (string)$aplicacion["nombre_dosis"],
        "edad" => $edadPaciente->y . " año(s), " . $edadPaciente->m . " mes(es)",
        "clasificaciones" => [
            "hepatitis_b" => (string)($aplicacion["clasificacion_hepatitis_b"] ?? ""),
            "sr" => (string)($aplicacion["clasificacion_sr"] ?? ""),
            "vph" => (string)($aplicacion["clasificacion_vph"] ?? ""),
            "varicela_hepatitis_a" => (string)($aplicacion["clasificacion_varicela_hepatitis_a"] ?? "")
        ],
        "clasificacionHepatitisB" => (string)($aplicacion["clasificacion_hepatitis_b"] ?? ""),
        "clasificacionVph" => (string)($aplicacion["clasificacion_vph"] ?? ""),
        "personalSalud" => (int)$aplicacion["personal_salud"] === 1,
        "embarazo" => (int)$aplicacion["embarazo"] === 1,
        "personal_salud" => (int)$aplicacion["personal_salud"] === 1,
        "jornalero_agricola" => (int)$aplicacion["jornalero_agricola"] === 1
    ];
    $familia = null;
    $biologicoCompacto = preg_replace('/[^a-z0-9]/', '', $nombreBiologico);
    if (str_contains($biologicoCompacto, "neumococ") || str_contains($biologicoCompacto, "prevenar")) {
        preg_match_all('/(?<!\d)(13|20|23)(?!\d)/', $biologicoCompacto, $valencias);
        $valencias = array_unique($valencias[1]);
        if (count($valencias) === 1) {
            $familia = ["13" => "neumococo", "20" => "neumococo20", "23" => "neumococo23"][reset($valencias)];
        } elseif (count($valencias) === 0 && str_contains($biologicoCompacto, "354neumococica")) {
            $familia = "neumococo20";
        } elseif (count($valencias) === 0 && (
            str_contains($biologicoCompacto, "polisacarida")
            || str_contains($biologicoCompacto, "poliscarida")
        )) {
            $familia = "neumococo23";
        } elseif (count($valencias) === 0 && str_contains($biologicoCompacto, "antineumococicaconjugada")) {
            $familia = "neumococo";
        }
    }
    foreach ([
        "bcg" => ["bcg"],
        "hepatitisb" => ["antihepatitisb", "antihepatitis b", "hepatitisb"],
        "hexavalente" => ["hexavalente"],
        "dpt" => ["dpt"],
        "rotavirus" => ["antirotavirus", "rotavirus"],
        "srp" => ["srp"],
        "sr" => ["dobleviral"],
        "vph" => ["vph"],
        "varicela" => ["varicela"],
        "hepatitisa" => ["hepatitisa"],
        "vsr" => ["vsr", "virus sincicial respiratorio"],
        "covid" => ["covid"],
        "tdpa" => ["tdpa"],
        "td" => ["td"],
        "influenza" => ["influenza"]
    ] as $familiaCandidata => $aliasList) {
        if ($familia !== null) {
            break;
        }
        foreach ($aliasList as $alias) {
            $alias = normalizarEtiquetaPaloteo($alias);
            if (
                ($familiaCandidata === "td" && $nombreBiologico === "td")
                || ($familiaCandidata === "sr" && ($nombreBiologico === "sr" || str_starts_with($nombreBiologico, "srdoble")))
                || ($familiaCandidata !== "td" && $familiaCandidata !== "sr" && str_contains($nombreBiologico, $alias))
            ) {
                $familia = $familiaCandidata;
                break 2;
            }
        }
    }
    if ($familia === null) {
        $biologicoCompacto = preg_replace('/[^a-z0-9]/', '', $nombreBiologico);
        $familiaSinClasificar = str_contains($biologicoCompacto, "23valente")
            ? "neumococo23"
            : (str_contains($nombreBiologico, "influenza") ? "influenza" : "sin_clasificar");
        $marcarAplicacionSinClasificar(
            $familiaSinClasificar,
            "El nombre del biológico no coincide con los alias del paloteo."
        );
        continue;
    }

    $diferenciaEdad = $edadPaciente;
    $edadMeses = ($diferenciaEdad->y * 12) + $diferenciaEdad->m;
    $fechaUltimaMenstruacion = !empty($aplicacion["fecha_ultima_menstruacion"])
        ? DateTimeImmutable::createFromFormat("!Y-m-d", (string)$aplicacion["fecha_ultima_menstruacion"])
        : null;
    $semanasGestacion = $fechaUltimaMenstruacion && $fechaUltimaMenstruacion <= $fechaAplicacion
        ? intdiv((int)$fechaUltimaMenstruacion->diff($fechaAplicacion)->days, 7)
        : null;
    $sexo = strtoupper(trim((string)$aplicacion["sexo"]));
    $embarazada = (int)$aplicacion["embarazo"] === 1;
    $personalSalud = (int)$aplicacion["personal_salud"] === 1;
    $jornaleroAgricola = (int)$aplicacion["jornalero_agricola"] === 1;
    $comorbilidades = array_map("intval", array_filter(explode(",", (string)$aplicacion["comorbilidades"]), "strlen"));
    $codigoDosis = $obtenerDosisPaloteo($aplicacion["nombre_dosis"]);
    if ($familia === "hepatitisb") {
        $edadDiasHepatitisB = (int)$fechaNacimiento->diff($fechaAplicacion)->days;
        if ($edadDiasHepatitisB <= 7 && $codigoDosis === "unica") {
            $estatusMigratorio = normalizarEtiquetaPaloteo($aplicacion["estatus_migratorio"]);
            $registrarConteoPaloteo(
                "hepb_rn",
                $fechaAplicacion,
                $estatusMigratorio !== "" && $estatusMigratorio !== "no aplica",
                (int)$aplicacion["poblacion_indigena_afromexicana"] === 1
            );
            continue;
        }

        if (!empty($aplicacion["clasificacion_hepatitis_b"])) {
            $filaHepatitisB = obtenerRenglonHepatitisB(
                (string)$aplicacion["clasificacion_hepatitis_b"],
                (string)$aplicacion["nombre_dosis"]
            );
            if ($filaHepatitisB === null || !isset($automaticos[$filaHepatitisB])) {
                $marcarAplicacionSinClasificar("hepatitisb");
                continue;
            }
            $estatusMigratorio = normalizarEtiquetaPaloteo($aplicacion["estatus_migratorio"]);
            $registrarConteoPaloteo(
                $filaHepatitisB,
                $fechaAplicacion,
                $estatusMigratorio !== "" && $estatusMigratorio !== "no aplica",
                (int)$aplicacion["poblacion_indigena_afromexicana"] === 1
            );
            continue;
        }
    }

    if ($familia === "sr" && $aplicacion["clasificacion_sr"] === "personal_educativo") {
        $estatusMigratorio = normalizarEtiquetaPaloteo($aplicacion["estatus_migratorio"]);
        $registrarConteoPaloteo(
            "sr_educativo",
            $fechaAplicacion,
            $estatusMigratorio !== "" && $estatusMigratorio !== "no aplica",
            (int)$aplicacion["poblacion_indigena_afromexicana"] === 1
        );
        continue;
    }

    if ($familia === "vph" && !empty($aplicacion["clasificacion_vph"])) {
        $filaVph = obtenerRenglonVph(
            (string)$aplicacion["clasificacion_vph"],
            (string)$aplicacion["nombre_dosis"],
            $edadMeses
        );
        if ($filaVph === null || !isset($automaticos[$filaVph])) {
            $marcarAplicacionSinClasificar("vph");
            continue;
        }
        $estatusMigratorio = normalizarEtiquetaPaloteo($aplicacion["estatus_migratorio"]);
        $registrarConteoPaloteo(
            $filaVph,
            $fechaAplicacion,
            $estatusMigratorio !== "" && $estatusMigratorio !== "no aplica",
            (int)$aplicacion["poblacion_indigena_afromexicana"] === 1
        );
        continue;
    }
    if ($familia === "varicela" && !empty($aplicacion["clasificacion_varicela_hepatitis_a"])) {
        $categoriaVacunaEdad = $aplicacion["clasificacion_varicela_hepatitis_a"];
        $filaVaricela = [
            "rutina" => "varicela_unica_1",
            "rezago" => "varicela_rezago_2_4"
        ][$categoriaVacunaEdad] ?? null;
        if ($filaVaricela === null) {
            $marcarAplicacionSinClasificar("varicela");
            continue;
        }
        $estatusMigratorio = normalizarEtiquetaPaloteo($aplicacion["estatus_migratorio"]);
        $registrarConteoPaloteo(
            $filaVaricela,
            $fechaAplicacion,
            $estatusMigratorio !== "" && $estatusMigratorio !== "no aplica",
            (int)$aplicacion["poblacion_indigena_afromexicana"] === 1
        );
        continue;
    }
    if ($familia === "hepatitisa" && !empty($aplicacion["clasificacion_varicela_hepatitis_a"])) {
        $categoriaVacunaEdad = $aplicacion["clasificacion_varicela_hepatitis_a"];
        $filaHepatitisA = [
            "rutina" => "hepa_unica_18m",
            "rezago" => "hepa_rezago_2_4",
            "riesgo" => "hepa_riesgo"
        ][$categoriaVacunaEdad] ?? null;
        if ($filaHepatitisA === null) {
            $marcarAplicacionSinClasificar("hepatitisa");
            continue;
        }
        $estatusMigratorio = normalizarEtiquetaPaloteo($aplicacion["estatus_migratorio"]);
        $registrarConteoPaloteo(
            $filaHepatitisA,
            $fechaAplicacion,
            $estatusMigratorio !== "" && $estatusMigratorio !== "no aplica",
            (int)$aplicacion["poblacion_indigena_afromexicana"] === 1
        );
        continue;
    }
    $familiasRiesgo = [
        "vih" => [1],
        "diabetes" => [2],
        "obesidad" => [3],
        "cardiopatias" => [4, 7, 12, 13],
        "asma" => [5],
        "cancer" => [6],
        "pulmonares" => [5, 7, 11],
        "renal" => [8],
        "inmunosupresion" => [9, 14],
        "otros" => [10, 15]
    ];
    $filaEncontrada = null;
    $mejorPuntaje = -1;
    $nombreBiologicoIndicado = normalizarEtiquetaPaloteo(
        $aplicacion["nombre_biologico_indicado"]
    );
    $esReemplazoTdpaDpt = $familia === "tdpa"
        && str_contains($nombreBiologico, "tdpa")
        && str_contains($nombreBiologicoIndicado, "dpt")
        && !str_contains($nombreBiologicoIndicado, "tdpa");
    $esReemplazoTdpaTd = $familia === "tdpa"
        && str_contains($nombreBiologico, "tdpa")
        && $nombreBiologicoIndicado === "td";
    $esReemplazoDptHexavalente = $familia === "dpt"
        && str_contains($nombreBiologicoIndicado, "hexavalente");

    if ($esReemplazoTdpaDpt) {
        $edadAniosAplicacion = $fechaNacimiento->diff($fechaAplicacion)->y;
        if ($codigoDosis === "unica" && $edadAniosAplicacion === 4) {
            $filaEncontrada = "tdpa_dpt_4";
        } elseif (
            $codigoDosis === "unica"
            && $edadAniosAplicacion >= 5
            && $edadAniosAplicacion <= 6
        ) {
            $filaEncontrada = "tdpa_dpt_catchup";
        }
    } elseif ($esReemplazoTdpaTd) {
        $edadAniosAplicacion = $fechaNacimiento->diff($fechaAplicacion)->y;
        $rangoEdadTd = $edadAniosAplicacion >= 15 && $edadAniosAplicacion <= 19
            ? "15_19"
            : ($edadAniosAplicacion >= 20 && $edadAniosAplicacion <= 59
                ? "20_59"
                : ($edadAniosAplicacion >= 60 ? "60_plus" : null));
        if ($rangoEdadTd !== null && isset($dosisReemplazoTd[$codigoDosis])) {
            $filaEncontrada = "tdpa_td_" . $codigoDosis . "_" . $rangoEdadTd;
        }
    } elseif ($esReemplazoDptHexavalente) {
        $edadAniosAplicacion = $fechaNacimiento->diff($fechaAplicacion)->y;
        if ($edadAniosAplicacion >= 5 && $edadAniosAplicacion <= 6 && $codigoDosis === "unica") {
            $filaEncontrada = "dpt_4_6";
        }
    } elseif ($familia === "vsr") {
        if (
            $embarazada
            && $semanasGestacion !== null
            && $semanasGestacion >= 28
            && $semanasGestacion <= 36
        ) {
            $filaEncontrada = "vsr_embarazo_28_36";
        }
    } elseif (
        $familia === "dpt"
        && $codigoDosis === "unica"
        && $fechaNacimiento->diff($fechaAplicacion)->y === 4
    ) {
        $filaEncontrada = "dpt_unica_4";
    } elseif (
        $familia === "dpt"
        && $codigoDosis === "unica"
        && $fechaNacimiento->diff($fechaAplicacion)->y >= 5
        && $fechaNacimiento->diff($fechaAplicacion)->y <= 6
    ) {
        $filaEncontrada = "dpt_rescate_5_6";
    } elseif ($familia === "bcg") {
        $edadDias = (int)$fechaNacimiento->diff($fechaAplicacion)->days;
        $edadAnios = $fechaNacimiento->diff($fechaAplicacion)->y;
        if ($edadDias === 0) {
            $filaEncontrada = "bcg_24h";
        } elseif ($edadDias <= 28) {
            $filaEncontrada = "bcg_neonato";
        } elseif ($edadAnios === 0) {
            $filaEncontrada = "bcg_29d_11m";
        } elseif ($edadAnios <= 4) {
            $filaEncontrada = "bcg_1_4";
        } elseif ($edadAnios <= 13) {
            $filaEncontrada = "bcg_5_9";
        }
    } elseif (
        $familia === "varicela"
        && $codigoDosis === "unica"
        && $edadMeses >= 12
        && $edadMeses < 24
    ) {
        $filaEncontrada = "varicela_unica_1";
    } elseif (
        $familia === "varicela"
        && $codigoDosis === "unica"
        && $edadMeses >= 24
        && $edadMeses < 60
    ) {
        $filaEncontrada = "varicela_rezago_2_4";
    } elseif (
        $familia === "hepatitisa"
        && $codigoDosis === "unica"
        && count($comorbilidades) > 0
        && count(array_intersect([1, 2, 4, 8], $comorbilidades)) > 0
    ) {
        $filaEncontrada = "hepa_riesgo";
    } elseif (
        $familia === "hepatitisa"
        && $codigoDosis === "unica"
        && $edadMeses === 18
    ) {
        $filaEncontrada = "hepa_unica_18m";
    } elseif (
        $familia === "hepatitisa"
        && $codigoDosis === "unica"
        && $edadMeses >= 24
        && $edadMeses < 60
    ) {
        $filaEncontrada = "hepa_rezago_2_4";
    } elseif (
        $familia === "hexavalente"
        && $edadMeses >= 48
        && $edadMeses < 60
        && $codigoDosis === "unica"
    ) {
        $filaEncontrada = "hexa_4";
    } elseif (
        $familia === "hepatitisb"
        && (int)$fechaNacimiento->diff($fechaAplicacion)->days <= 7
        && $codigoDosis === "unica"
    ) {
        $filaEncontrada = "hepb_rn";
    } elseif (
        $familia === "hexavalente"
        && $edadMeses >= 12
        && $edadMeses < 60
        && in_array($codigoDosis, ["primera", "segunda", "tercera"], true)
    ) {
        $filaEncontrada = [
            "primera" => "hexa_rescate1",
            "segunda" => "hexa_rescate2",
            "tercera" => "hexa_rescate3"
        ][$codigoDosis];
    } elseif (
        $familia === "hexavalente"
        && $edadMeses >= 18
        && $edadMeses < 24
        && $codigoDosis === "refuerzo"
    ) {
        $filaEncontrada = "hexa_ref_18";
    } elseif (
        $familia === "hexavalente"
        && $edadMeses >= 24
        && $edadMeses < 60
        && $codigoDosis === "refuerzo"
    ) {
        $filaEncontrada = "hexa_rescate_refuerzo";
    } else {
    foreach ($renglones as $idRenglon => $renglon) {
        if ($renglon["aliases"] !== []) {
            continue;
        }
        $prefijos = $prefijosFamilia[$familia] ?? [];
        $perteneceFamilia = false;
        foreach ($prefijos as $prefijo) {
            if (str_starts_with($idRenglon, $prefijo)) {
                $perteneceFamilia = true;
                break;
            }
        }
        if (!$perteneceFamilia) {
            continue;
        }

        if ($familia === "sr" && $idRenglon === "sr_educativo") {
            continue;
        }

        if (
            $familia === "covid"
            && (
                ($idRenglon === "covid_embarazadas" && !$embarazada)
                || ($idRenglon === "covid_salud" && (!$personalSalud || $embarazada))
                || (
                    $idRenglon === "covid_riesgo_5_59"
                    && (count($comorbilidades) === 0 || $embarazada || $personalSalud)
                )
            )
        ) {
            continue;
        }

        if (
            $familia === "td"
            && str_contains($idRenglon, "_poblacion_")
            && $embarazada
        ) {
            continue;
        }

        if (
            $familia === "hexavalente"
            && $idRenglon === "hexa_4"
            && ($edadMeses < 48 || $edadMeses >= 60 || $codigoDosis !== "unica")
        ) {
            continue;
        }

        if (
            $familia === "dpt"
            && in_array($idRenglon, [
                "dpt_4_6",
                "dpt_unica_4",
                "dpt_rescate_5_6"
            ], true)
        ) {
            continue;
        }

        if (
            $familia === "hepatitisb"
            && in_array($idRenglon, [
                "hepb_rn",
                "hepb_mayor11_1",
                "hepb_mayor11_2",
                "hepb_riesgo_1",
                "hepb_riesgo_2"
            ], true)
        ) {
            continue;
        }

        if (
            $familia === "hexavalente"
            && in_array($idRenglon, [
                "hexa_rescate1",
                "hexa_rescate2",
                "hexa_rescate3",
                "hexa_rescate_refuerzo"
            ], true)
            && (
                $edadMeses < 12
                || $edadMeses >= 60
                || ($idRenglon === "hexa_rescate_refuerzo" && $edadMeses < 24)
            )
        ) {
            continue;
        }

        if (
            $familia === "vph"
            && in_array($idRenglon, [
                "vph_ninas",
                "vph_rezagados",
                "vph_violencia_9_14",
                "vph_violencia_15_19"
            ], true)
        ) {
            continue;
        }

        $etiqueta = normalizarEtiquetaPaloteo($renglon["grupo"] . " " . $renglon["detalle"]);
        $dosisEsperada = $obtenerDosisPaloteo(
            in_array($familia, ["neumococo", "neumococo20"], true)
                && str_contains($idRenglon, "_rescate")
                ? $renglon["detalle"]
                : $etiqueta
        );
        if ($dosisEsperada !== "" && $dosisEsperada !== $codigoDosis) {
            continue;
        }

        if (str_contains($etiqueta, "victimas de violencia")) {
            continue;
        }
        if (str_contains($etiqueta, "mujeres cis") && ($sexo !== "F" || !in_array(1, $comorbilidades, true))) {
            continue;
        }
        if (str_contains($etiqueta, "hombres cis") && ($sexo !== "M" || !in_array(1, $comorbilidades, true))) {
            continue;
        }
        if (str_contains($etiqueta, "ninas") && $sexo !== "F") {
            continue;
        }
        if (str_contains($etiqueta, "embarazadas") && !$embarazada) {
            continue;
        }
        if (
            str_contains($etiqueta, "semana 20")
            && ($semanasGestacion === null || $semanasGestacion < 20)
        ) {
            continue;
        }
        if (str_contains($etiqueta, "personal de salud") && !$personalSalud) {
            continue;
        }
        if (str_contains($etiqueta, "jornaleros agricolas") && !$jornaleroAgricola) {
            continue;
        }
        if (str_contains($etiqueta, "mujeres") && $sexo !== "F") {
            continue;
        }
        if (str_contains($etiqueta, "hombres") && $sexo !== "M") {
            continue;
        }

        $esRiesgo = count($comorbilidades) > 0;
        if (str_contains($etiqueta, "sin factores de riesgo") && $esRiesgo) {
            continue;
        }
        if (
            (
                str_contains($etiqueta, "poblacion en riesgo")
                || str_contains($etiqueta, "personal en riesgo")
                || str_contains($etiqueta, "con factores de riesgo")
            )
            && !$esRiesgo
        ) {
            continue;
        }
        $riesgoCorrecto = true;
        foreach ($familiasRiesgo as $nombreRiesgo => $idsRiesgo) {
            if (str_contains($etiqueta, normalizarEtiquetaPaloteo($nombreRiesgo))) {
                $riesgoCorrecto = count(array_intersect($idsRiesgo, $comorbilidades)) > 0;
                break;
            }
        }
        if (!$riesgoCorrecto) {
            continue;
        }
        if (str_contains($etiqueta, "con vih") && !in_array(1, $comorbilidades, true)) {
            continue;
        }
        if (
            $familia === "srp"
            && in_array($idRenglon, ["srp_rescate1", "srp_rescate2"], true)
        ) {
            $edadMinimaMeses = $idRenglon === "srp_rescate1" ? 17 : 24;
            if ($edadMeses < $edadMinimaMeses || $edadMeses >= 120) {
                continue;
            }
        } else {
            $etiquetaRangoEdad = $etiqueta;
            if (
                $familia === "influenza"
                && str_starts_with($idRenglon, "influenza_riesgo_")
                && preg_match('/(\d+)\s*a\s*(\d+)\s*(meses|anos)/', normalizarEtiquetaPaloteo($renglon["detalle"]))
            ) {
                $etiquetaRangoEdad = normalizarEtiquetaPaloteo($renglon["detalle"]);
            }

            if (preg_match('/(\d+)\s*a\s*(\d+)\s*(meses|anos)/', $etiquetaRangoEdad, $rango)) {
                $minimo = (int)$rango[1] * ($rango[3] === "anos" ? 12 : 1);
                $maximo = ((int)$rango[2] + 1) * ($rango[3] === "anos" ? 12 : 1);
                if ($edadMeses < $minimo || $edadMeses >= $maximo) {
                    continue;
                }
            } elseif (preg_match('/(\d+)\s*(?:anos y mas|y mas anos)/', $etiquetaRangoEdad, $edadMinima)) {
                if ($edadMeses < ((int)$edadMinima[1] * 12)) {
                    continue;
                }
            } elseif (preg_match('/(\d+)\s*(meses|anos)/', $etiquetaRangoEdad, $edadExacta)) {
                $edadObjetivo = (int)$edadExacta[1] * ($edadExacta[2] === "anos" ? 12 : 1);
                if ($edadMeses !== $edadObjetivo) {
                    continue;
                }
            }
        }

        $puntaje = 0;
        if ($esRiesgo && (str_contains($etiqueta, "riesgo") || str_contains($etiqueta, "vih"))) {
            $puntaje += 2;
        }
        if ($embarazada && str_contains($etiqueta, "embarazadas")) {
            $puntaje += 2;
        }
        if ($personalSalud && str_contains($etiqueta, "personal de salud")) {
            $puntaje += 2;
        }
        if ($jornaleroAgricola && str_contains($etiqueta, "jornaleros agricolas")) {
            $puntaje += 2;
        }
        if (str_contains($etiqueta, "cis") && in_array(1, $comorbilidades, true)) {
            $puntaje += 4;
        }
        if ($puntaje > $mejorPuntaje) {
            $filaEncontrada = $idRenglon;
            $mejorPuntaje = $puntaje;
        }
    }
    }

    if ($filaEncontrada === null) {
        $biologicoCompacto = preg_replace('/[^a-z0-9]/', '', $nombreBiologico);
        $familiaSinClasificar = str_contains($biologicoCompacto, "23valente")
            ? "neumococo23"
            : $familia;
        $motivoSinClasificar = "No se encontró un renglón compatible con la dosis o el grupo.";
        if (
            $familia === "hepatitisb"
            && !$aplicacionSinClasificarActual["personalSalud"]
            && $aplicacionSinClasificarActual["clasificacionHepatitisB"] === ""
            && in_array($codigoDosis, ["primera", "segunda"], true)
        ) {
            $motivoSinClasificar =
                "Falta indicar si corresponde a mayores de 11 años sin antecedente vacunal o a población en riesgo; verifica el expediente.";
        } elseif (
            $familia === "vph"
            && $aplicacionSinClasificarActual["clasificacionVph"] === ""
        ) {
            $motivoSinClasificar =
                "Falta el grupo de población VPH. A los 22 años, los renglones disponibles corresponden a personas de 11 a 49 años que viven con VIH.";
        } elseif (
            $esReemplazoDptHexavalente
            && $fechaNacimiento->diff($fechaAplicacion)->y > 6
        ) {
            $motivoSinClasificar =
                "El registro indica DPT aplicada en lugar de Hexavalente a los 8 años; el formato sólo tiene renglones para este reemplazo entre 5 y 6 años. Verifica la vacuna y el esquema.";
        }
        $marcarAplicacionSinClasificar($familiaSinClasificar, $motivoSinClasificar);
        continue;
    }

    $estatusMigratorio = normalizarEtiquetaPaloteo($aplicacion["estatus_migratorio"]);
    $registrarConteoPaloteo(
        $filaEncontrada,
        $fechaAplicacion,
        $estatusMigratorio !== "" && $estatusMigratorio !== "no aplica",
        (int)$aplicacion["poblacion_indigena_afromexicana"] === 1
    );
}
$stmtAplicaciones->close();

$sqlAplicacionesVphAnonimas = "SELECT
                                   aa.fecha_aplicacion,
                                   aa.clasificacion_vph,
                                   aa.es_migrante,
                                   d.nombre_dosis
                               FROM APLICACION_VPH_ANONIMA aa
                               INNER JOIN ESQUEMA_BIOLOGICO_DOSIS ebd
                                   ON ebd.id_esquema_biologico_dosis = aa.id_esquema_biologico_dosis
                               INNER JOIN DOSIS d ON d.id_dosis = ebd.id_dosis
                               WHERE aa.id_unidad = ?
                                   AND aa.fecha_aplicacion >= ?
                                   AND aa.fecha_aplicacion < ?
                               ORDER BY aa.fecha_aplicacion, aa.clasificacion_vph, d.nombre_dosis";
$stmtVphAnonimas = $conexion->prepare($sqlAplicacionesVphAnonimas);
if (!$stmtVphAnonimas) {
    die("Error al preparar el conteo confidencial de VPH: " . $conexion->error);
}
$stmtVphAnonimas->bind_param("iss", $idUnidad, $inicioSql, $finSql);
if (!$stmtVphAnonimas->execute()) {
    die("Error al consultar las aplicaciones confidenciales de VPH: " . $stmtVphAnonimas->error);
}
$resultadoVphAnonimas = $stmtVphAnonimas->get_result();
while ($aplicacionVph = $resultadoVphAnonimas->fetch_assoc()) {
    $fechaVph = DateTimeImmutable::createFromFormat(
        "!Y-m-d",
        (string)$aplicacionVph["fecha_aplicacion"]
    );
    if (!$fechaVph) {
        die("Se encontró una fecha inválida en un registro confidencial de VPH.");
    }
    $filaVph = obtenerRenglonVph(
        (string)$aplicacionVph["clasificacion_vph"],
        (string)$aplicacionVph["nombre_dosis"]
    );
    if ($filaVph === null || !isset($automaticos[$filaVph])) {
        $marcarAplicacionSinClasificar("vph");
        continue;
    }
    $registrarConteoPaloteo($filaVph, $fechaVph, (int)$aplicacionVph["es_migrante"] === 1);
}
$stmtVphAnonimas->close();

if ($modoInformeAnual) {
    return [
        "renglones" => $renglones,
        "conteosPorMes" => $conteosPorMesAnual,
        "ajustesPorMes" => $ajustesPorMesAnual,
        "anio" => $anio,
        "nombreUnidad" => $nombreUnidad,
        "nombreResponsable" => $nombreResponsable,
        "idUnidad" => $idUnidad,
        "aplicacionesSinClasificar" => $aplicacionesSinClasificar,
        "aplicacionesSinClasificarPorFamilia" => $aplicacionesSinClasificarPorFamilia,
        "aplicacionesSinClasificarDetalle" => $aplicacionesSinClasificarDetalle
    ];
}

if (defined("INFORME_MENSUAL_SOLICITANDO_CONTEOS")) {
    return [
        "renglones" => $renglones,
        "automaticos" => $automaticos,
        "ajustes" => $ajustes,
        "encabezado" => $encabezado,
        "nombreUnidad" => $nombreUnidad,
        "nombreResponsable" => $nombreResponsable,
        "aplicacionesSinClasificar" => $aplicacionesSinClasificar,
        "aplicacionesSinClasificarNoExcluidas" => $aplicacionesSinClasificar
            - ($aplicacionesSinClasificarPorFamilia["influenza"] ?? 0)
            - ($aplicacionesSinClasificarPorFamilia["neumococo23"] ?? 0),
        "diasMes" => $diasMes
    ];
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $ajustesRecibidos = $_POST["ajustes"] ?? [];
    $encabezadoRecibido = $_POST["encabezado"] ?? [];

    if (!is_array($ajustesRecibidos) || !is_array($encabezadoRecibido)) {
        $mensajeError = "No se recibieron datos válidos para guardar el paloteo.";
    } else {
        $ajustesValidados = [];
        foreach ($ajustesRecibidos as $clave => $valores) {
            if (!isset($automaticos[$clave]) || !is_array($valores)) {
                $mensajeError = "El formulario contiene un renglón que ya no existe en el catálogo.";
                break;
            }

            $ajustesValidados[$clave] = [
                "dias" => [],
                "afromexicano" => 0,
                "indigenas" => 0,
                "migrantes" => 0
            ];
            $diasRecibidos = $valores["dias"] ?? [];
            if (!is_array($diasRecibidos)) {
                $mensajeError = "Los ajustes diarios recibidos no son válidos.";
                break;
            }

            for ($dia = 1; $dia <= $diasFormato; $dia++) {
                if ($dia > $diasMes) {
                    continue;
                }

                $valor = $diasRecibidos[$dia] ?? "0";
                if (!is_string($valor) && !is_int($valor)) {
                    $mensajeError = "Uno de los ajustes diarios no es válido.";
                    break 2;
                }
                $valor = (string)$valor;
                if (!preg_match('/^-?\d{1,6}$/', $valor)) {
                    $mensajeError = "Los ajustes deben ser números enteros entre -999999 y 999999.";
                    break 2;
                }

                $ajuste = (int)$valor;
                if ($automaticos[$clave]["dias"][$dia] + $ajuste < 0) {
                    $mensajeError = "Un ajuste diario no puede dejar un conteo final menor que cero.";
                    break 2;
                }
                if ($ajuste !== 0) {
                    $ajustesValidados[$clave]["dias"][$dia] = $ajuste;
                }
            }

            foreach (["afromexicano", "indigenas", "migrantes"] as $campo) {
                $valor = $valores[$campo] ?? "0";
                if ((!is_string($valor) && !is_int($valor)) || !preg_match('/^-?\d{1,6}$/', (string)$valor)) {
                    $mensajeError = "Los ajustes de totales deben ser números enteros entre -999999 y 999999.";
                    break 2;
                }
                $ajuste = (int)$valor;
                if ($automaticos[$clave][$campo] + $ajuste < 0) {
                    $mensajeError = "Un ajuste de totales no puede dejar un conteo final menor que cero.";
                    break 2;
                }
                $ajustesValidados[$clave][$campo] = $ajuste;
            }
        }

        $encabezadoValido = [];
        foreach (["jurisdiccion", "localidad", "responsable"] as $campo) {
            $valor = $encabezadoRecibido[$campo] ?? "";
            if (!is_string($valor) || mb_strlen($valor, "UTF-8") > 150) {
                $mensajeError = "Los datos del encabezado deben tener un máximo de 150 caracteres.";
                break;
            }
            $encabezadoValido[$campo] = trim($valor);
        }

        if ($mensajeError === "") {
            $datosJson = json_encode(
                ["ajustes" => $ajustesValidados, "encabezado" => $encabezadoValido],
                JSON_UNESCAPED_UNICODE
            );
            if ($datosJson === false) {
                $mensajeError = "No fue posible preparar los datos para guardarlos.";
            } else {
                $sqlGuardar = "INSERT INTO PALOTEO_GENERAL
                                   (id_unidad, anio, mes, datos_json, id_usuario)
                               VALUES (?, ?, ?, ?, ?)
                               ON DUPLICATE KEY UPDATE
                                   datos_json = VALUES(datos_json),
                                   id_usuario = VALUES(id_usuario)";
                $stmtGuardar = $conexion->prepare($sqlGuardar);
                if (!$stmtGuardar) {
                    die("Error al preparar el guardado del paloteo: " . $conexion->error);
                }
                $stmtGuardar->bind_param(
                    "iiisi",
                    $idUnidad,
                    $anio,
                    $mes,
                    $datosJson,
                    $idUsuario
                );
                if (!$stmtGuardar->execute()) {
                    die("Error al guardar el paloteo: " . $stmtGuardar->error);
                }
                $stmtGuardar->close();

                header(
                    "Location: paloteo_general.php?mes=" . $mes
                    . "&anio=" . $anio . "&guardado=1"
                );
                exit();
            }
        }

        $ajustes = $ajustesRecibidos;
        if (isset($encabezadoValido["jurisdiccion"])) {
            $encabezado = array_merge($encabezado, $encabezadoValido);
        } else {
            foreach (["jurisdiccion", "localidad", "responsable"] as $campo) {
                if (is_string($encabezadoRecibido[$campo] ?? null)) {
                    $encabezado[$campo] = trim($encabezadoRecibido[$campo]);
                }
            }
        }
    }
}

$renglonesPorPagina = [1 => []];
foreach ($renglones as $clave => $renglon) {
    $renglonesPorPagina[1][$renglon["biologico"]][$renglon["grupo"]][] = $clave;
}

$nombreMeses = [
    1 => "ENERO", 2 => "FEBRERO", 3 => "MARZO", 4 => "ABRIL",
    5 => "MAYO", 6 => "JUNIO", 7 => "JULIO", 8 => "AGOSTO",
    9 => "SEPTIEMBRE", 10 => "OCTUBRE", 11 => "NOVIEMBRE", 12 => "DICIEMBRE"
];
$guardado = ($_GET["guardado"] ?? "") === "1";
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Paloteo general | Registro de aplicación de vacunas</title>
    <style>
        :root {
            color-scheme: light;
            font-family: Arial, Helvetica, sans-serif;
            color: #172b3a;
            background: #eef3f6;
        }
        * { box-sizing: border-box; }
        body { margin: 0; padding: 24px; }
        .pagina { max-width: 100%; margin: 0 auto; }
        .barra, .hoja {
            background: #fff;
            border: 1px solid #c9d5dd;
            border-radius: 10px;
            box-shadow: 0 5px 18px rgba(27, 55, 74, .08);
        }
        .barra { display: flex; flex-wrap: wrap; align-items: end; gap: 12px; padding: 16px; margin-bottom: 18px; }
        .barra label { display: grid; gap: 5px; font-size: 13px; font-weight: 700; }
        select, input, button { font: inherit; }
        .barra select { width: 135px; min-width: 0; padding: 9px; border: 1px solid #aebdc7; border-radius: 5px; }
        button, .boton {
            border: 0;
            border-radius: 5px;
            padding: 10px 16px;
            font-weight: 700;
            text-decoration: none;
            cursor: pointer;
        }
        .boton-guardar { display: inline-flex; align-items: center; justify-content: center; padding: 8px; }
        .boton-icono { display: inline-flex; align-items: center; justify-content: center; width: 40px; height: 40px; padding: 8px; }
        .boton-icono svg { width: 20px; height: 20px; }
        .primario { background: #176b54; color: #fff; }
        .secundario { background: #e7eef2; color: #18384c; }
        .estado { width: 100%; margin: 0; color: #176b54; font-weight: 700; }
        .error { width: 100%; margin: 0; padding: 10px; color: #8a1f11; background: #fff0ed; border: 1px solid #e6b5ad; }
        .hoja { overflow: hidden; }
        .encabezado { padding: 18px 22px 14px; border-bottom: 2px solid #18384c; }
        .barra-institucional { display: flex; justify-content: space-between; gap: 20px; align-items: start; font-size: 11px; font-weight: 700; }
        h1 { margin: 10px 0 2px; text-align: center; font-size: 19px; letter-spacing: .04em; }
        .subtitulo { margin: 0; text-align: center; font-weight: 700; font-size: 12px; }
        .datos { display: grid; grid-template-columns: repeat(4, minmax(150px, 1fr)); gap: 9px 14px; margin-top: 16px; }
        .dato { display: grid; grid-template-columns: max-content 1fr; gap: 6px; align-items: end; font-size: 11px; font-weight: 700; }
        .dato input { min-width: 0; width: 100%; border: 0; border-bottom: 1px solid #607988; border-radius: 0; padding: 3px 4px; background: transparent; }
        .dato .valor-unidad { padding: 3px 4px; border-bottom: 1px solid #607988; }
        .valor-impresion { display: none; }
        .nota { margin: 12px 0; padding: 0 20px; color: #516675; font-size: 12px; }
        .tabla-contenedor { overflow: auto; border-top: 1px solid #aebdc7; }
        table { width: max-content; min-width: 100%; border-collapse: collapse; table-layout: fixed; font-size: 10px; }
        thead { position: sticky; top: 0; z-index: 1; }
        th, td { border: 1px solid #9cadb8; padding: 3px 2px; text-align: center; }
        thead th { background: #e5edf1; color: #17364b; font-size: 9px; }
        th.biologico, td.biologico { width: 175px; min-width: 175px; text-align: left; }
        th.grupo, td.grupo { width: 210px; min-width: 210px; text-align: left; }
        th.detalle, td.detalle { width: 135px; min-width: 135px; text-align: left; }
        th.dia, td.dia { width: 52px; min-width: 52px; }
        th.total, td.total { width: 85px; min-width: 85px; background: #f0f5f7; font-weight: 700; }
        tbody tr:nth-child(even) { background: #f8fafb; }
        td.biologico, td.grupo, td.detalle { font-weight: 600; }
        .conteo-auto { display: block; color: #314d5e; font-weight: 700; }
        .fuera-periodo { background: #e7edf0; }
        .titulo-pagina { margin: 0; padding: 10px; text-align: center; font-size: 13px; }
        .formato-pagina + .formato-pagina { margin-top: 20px; }
        .ajuste { width: 40px; max-width: 100%; padding: 2px 1px; border: 1px solid #c4d0d7; border-radius: 3px; text-align: center; font-size: 10px; }
        .mas { color: #768894; }
        .conteo-impresion { display: none; }
        tfoot th { background: #dce7ec; font-size: 10px; }
        .pie { padding: 12px 18px; color: #526977; font-size: 11px; }
        @media (max-width: 700px) {
            body { padding: 10px; }
            .datos { grid-template-columns: 1fr 1fr; }
            .barra { align-items: stretch; }
            .barra label, .barra select, .barra button, .barra .boton { width: 100%; }
        }
        @media print {
            @page { size: A3 portrait; margin: 5mm; }
            body { padding: 0; background: #fff; }
            .pagina, .hoja { width: 100%; max-width: none; margin: 0; border: 0; border-radius: 0; box-shadow: none; }
            .barra, .nota, .ajuste, .mas, .conteo-auto { display: none !important; }
            .hoja { overflow: visible; }
            .formato-pagina { break-after: page; page-break-after: always; }
            .formato-pagina:last-child { break-after: auto; page-break-after: auto; }
            .formato-pagina + .formato-pagina { margin-top: 0; }
            .titulo-pagina { padding: 1mm; font-size: 8pt; }
            .encabezado { padding: 4mm 3mm 3mm; }
            h1 { margin-top: 2mm; font-size: 14pt; }
            .datos { margin-top: 3mm; }
            .dato input { display: none; }
            .dato .valor-unidad, .dato .valor-impresion { display: inline; border-bottom: 1px solid #333; padding: 1px 3px; }
            .tabla-contenedor { overflow: visible; }
            table { width: 100%; min-width: 0; table-layout: fixed; font-size: 5.5pt; }
            thead { position: static; }
            th, td { padding: 1px; border-color: #555; }
            thead th { font-size: 6pt; }
            th.biologico, td.biologico { width: 28mm; min-width: 0; }
            th.grupo, td.grupo { width: 34mm; min-width: 0; }
            th.detalle, td.detalle { width: 24mm; min-width: 0; }
            th.dia, td.dia { width: 4.2mm; min-width: 0; }
            th.total, td.total { width: 14mm; min-width: 0; }
            .conteo-impresion { display: inline; }
            .pie { padding: 2mm 0; }
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
            <h1>Registro de aplicación de biológicos</h1>
            <p>Paloteo general · <?php echo escapar($nombreMeses[$mes] . " " . $anio); ?></p>
        </div>
    </header>
    <form class="barra" method="get" action="paloteo_general.php">
        <label for="mes">Mes
            <select name="mes" id="mes">
                <?php foreach ($nombreMeses as $numeroMes => $nombreMes): ?>
                    <option value="<?php echo $numeroMes; ?>" <?php echo $mes === $numeroMes ? "selected" : ""; ?>>
                        <?php echo escapar($nombreMes); ?>
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
        <?php if (!$soloConsulta): ?>
        <button class="primario boton-guardar" type="submit" form="form-paloteo"
                aria-label="Guardar paloteo" title="Guardar paloteo">
            <svg aria-hidden="true" viewBox="0 0 24 24" width="20" height="20" fill="none"
                 stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2Z"></path>
                <path d="M17 21v-8H7v8M7 3v5h8"></path>
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
            <p class="estado" role="status">Paloteo guardado correctamente para <?php echo escapar($nombreMeses[$mes] . " " . $anio); ?>.</p>
        <?php endif; ?>
        <?php if ($mensajeError !== ""): ?>
            <p class="error" role="alert"><?php echo escapar($mensajeError); ?></p>
        <?php endif; ?>
    </form>

    <section class="hoja">
        <header class="encabezado">
            <div class="barra-institucional">
                <span>SECRETARÍA DE SALUD<br>SISTEMA DE VACUNACIÓN</span>
                <span>MES: <?php echo escapar($nombreMeses[$mes]); ?> &nbsp; AÑO: <?php echo $anio; ?></span>
            </div>
            <h1>REGISTRO DE APLICACIÓN DE VACUNAS</h1>
            <p class="subtitulo">PALOTEO GENERAL — <?php echo escapar($nombreMeses[$mes] . " " . $anio); ?></p>
            <div class="datos">
                <div class="dato"><span>UNIDAD:</span><span class="valor-unidad"><?php echo escapar($nombreUnidad); ?></span></div>
                <label class="dato"><span>JURISDICCIÓN:</span>
                    <input form="form-paloteo" name="encabezado[jurisdiccion]" maxlength="150" value="<?php echo escapar($encabezado["jurisdiccion"]); ?>">
                    <span class="valor-impresion"><?php echo escapar($encabezado["jurisdiccion"]); ?></span>
                </label>
                <label class="dato"><span>LOCALIDAD:</span>
                    <input form="form-paloteo" name="encabezado[localidad]" maxlength="150" value="<?php echo escapar($encabezado["localidad"]); ?>">
                    <span class="valor-impresion"><?php echo escapar($encabezado["localidad"]); ?></span>
                </label>
                <label class="dato"><span>RESPONSABLE:</span>
                    <input form="form-paloteo" name="encabezado[responsable]" maxlength="150" value="<?php echo escapar($encabezado["responsable"]); ?>">
                    <span class="valor-impresion"><?php echo escapar($encabezado["responsable"]); ?></span>
                </label>
            </div>
        </header>
        <?php if ($aplicacionesSinClasificar > 0): ?>
            <p class="error" role="status"><?php echo $aplicacionesSinClasificar; ?> aplicación(es) no se pudieron ubicar automáticamente en los grupos oficiales; verifica el grupo correspondiente y captura el ajuste manual.</p>
        <?php endif; ?>

        <form id="form-paloteo" method="post" action="paloteo_general.php?mes=<?php echo $mes; ?>&amp;anio=<?php echo $anio; ?>">
            <input type="hidden" name="mes" value="<?php echo $mes; ?>">
            <input type="hidden" name="anio" value="<?php echo $anio; ?>">
            <?php foreach ([1] as $numeroPagina): ?>
                <?php
                $totalesPagina = array_fill(1, $diasFormato, 0);
                $totalNoMigrantesPagina = 0;
                $totalAfromexicanoPagina = 0;
                $totalIndigenasPagina = 0;
                $totalMigrantesPagina = 0;
                foreach ($renglonesPorPagina[$numeroPagina] as $grupos) {
                    foreach ($grupos as $clavesGrupo) {
                        foreach ($clavesGrupo as $claveRenglon) {
                            foreach ($automaticos[$claveRenglon]["dias"] as $dia => $conteo) {
                                if ($dia <= $diasMes) {
                                    $total = $conteo + ajusteGuardado($ajustes, $claveRenglon, "dias", $dia);
                                    $totalesPagina[$dia] += $total;
                                    $totalNoMigrantesPagina += $total;
                                }
                            }
                            $totalMigrantesPagina += $automaticos[$claveRenglon]["migrantes"]
                                + ajusteGuardado($ajustes, $claveRenglon, "migrantes");
                            $totalAfromexicanoPagina += $automaticos[$claveRenglon]["afromexicano"]
                                + ajusteGuardado($ajustes, $claveRenglon, "afromexicano");
                            $totalIndigenasPagina += $automaticos[$claveRenglon]["indigenas"]
                                + ajusteGuardado($ajustes, $claveRenglon, "indigenas");
                        }
                    }
                }
                $totalDosisPagina = $totalNoMigrantesPagina + $totalMigrantesPagina;
                ?>
                <section class="formato-pagina">
                    <h2 class="titulo-pagina">REGISTRO DE APLICACIÓN DE BIOLÓGICOS</h2>
                    <div class="tabla-contenedor">
                        <table class="tabla-oficial">
                            <thead>
                            <tr>
                                <th class="biologico" rowspan="2">BIOLÓGICO</th>
                                <th class="grupo" rowspan="2">Variable</th>
                                <th class="detalle" rowspan="2">ESQUEMA / EDAD</th>
                                <th colspan="<?php echo $diasFormato; ?>">Día</th>
                                <th class="total" rowspan="2">TOTAL DE DOSIS</th>
                                <th class="total" rowspan="2">A AFROMEXICANO</th>
                                <th class="total" rowspan="2">A INDÍGENAS</th>
                                <th class="total" rowspan="2">A MIGRANTES</th>
                            </tr>
                            <tr>
                                <?php for ($dia = 1; $dia <= $diasFormato; $dia++): ?>
                                    <th class="dia"><?php echo $dia; ?></th>
                                <?php endfor; ?>
                            </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($renglonesPorPagina[$numeroPagina] as $biologico => $grupos): ?>
                                <?php
                                $totalFilasBiologico = 0;
                                foreach ($grupos as $clavesGrupo) {
                                    $totalFilasBiologico += count($clavesGrupo);
                                }
                                $primeraFilaBiologico = true;
                                ?>
                                <?php foreach ($grupos as $grupo => $clavesGrupo): ?>
                                    <?php $primeraFilaGrupo = true; ?>
                                    <?php foreach ($clavesGrupo as $clave): ?>
                                        <?php
                                        $renglon = $renglones[$clave];
                                        $totalRenglon = 0;
                                        foreach ($automaticos[$clave]["dias"] as $dia => $conteo) {
                                            if ($dia <= $diasMes) {
                                                $totalRenglon += $conteo + ajusteGuardado($ajustes, $clave, "dias", $dia);
                                            }
                                        }
                                        $migrantesRenglon = $automaticos[$clave]["migrantes"]
                                            + ajusteGuardado($ajustes, $clave, "migrantes");
                                        $afromexicanoRenglon = $automaticos[$clave]["afromexicano"]
                                            + ajusteGuardado($ajustes, $clave, "afromexicano");
                                        $indigenasRenglon = $automaticos[$clave]["indigenas"]
                                            + ajusteGuardado($ajustes, $clave, "indigenas");
                                        ?>
                                        <tr>
                                            <?php if ($primeraFilaBiologico): ?>
                                                <td class="biologico" rowspan="<?php echo $totalFilasBiologico; ?>"><?php echo escapar($biologico); ?></td>
                                                <?php $primeraFilaBiologico = false; ?>
                                            <?php endif; ?>
                                            <?php if ($primeraFilaGrupo): ?>
                                                <td class="grupo" rowspan="<?php echo count($clavesGrupo); ?>"><?php echo escapar($grupo); ?></td>
                                                <?php $primeraFilaGrupo = false; ?>
                                            <?php endif; ?>
                                            <td class="detalle"><?php echo escapar($renglon["detalle"]); ?></td>
                                            <?php for ($dia = 1; $dia <= $diasFormato; $dia++): ?>
                                                <?php if ($dia > $diasMes): ?>
                                                    <td class="dia fuera-periodo"></td>
                                                <?php else: ?>
                                                    <?php
                                                    $conteoAuto = $automaticos[$clave]["dias"][$dia];
                                                    $ajuste = ajusteGuardado($ajustes, $clave, "dias", $dia);
                                                    ?>
                                                    <td class="dia">
                                                        <span class="conteo-auto"><?php echo $conteoAuto; ?></span>
                                                        <span class="mas">+</span>
                                                        <input class="ajuste ajuste-dia"
                                                               type="number" step="1" min="<?php echo -$conteoAuto; ?>" max="999999"
                                                               name="ajustes[<?php echo escapar($clave); ?>][dias][<?php echo $dia; ?>]"
                                                               aria-label="Ajuste manual: <?php echo escapar($biologico . ", " . $grupo . ", " . $renglon["detalle"] . ", día " . $dia); ?>"
                                                               value="<?php echo $ajuste; ?>"
                                                               data-dia="<?php echo $dia; ?>"
                                                               data-auto="<?php echo $conteoAuto; ?>">
                                                        <span class="conteo-impresion"><?php echo $conteoAuto + $ajuste; ?></span>
                                                    </td>
                                                <?php endif; ?>
                                            <?php endfor; ?>
                                            <td class="total total-dosis" data-valor="<?php echo $totalRenglon + $migrantesRenglon; ?>"><?php echo $totalRenglon + $migrantesRenglon; ?></td>
                                            <td class="total total-afromexicano">
                                                <span class="conteo-auto"><?php echo $automaticos[$clave]["afromexicano"]; ?></span>
                                                <span class="mas">+</span>
                                                <input class="ajuste ajuste-total ajuste-afromexicano"
                                                       type="number" step="1" min="<?php echo -$automaticos[$clave]["afromexicano"]; ?>" max="999999"
                                                       name="ajustes[<?php echo escapar($clave); ?>][afromexicano]"
                                                       aria-label="Ajuste manual de dosis a población afromexicana: <?php echo escapar($biologico . ", " . $grupo . ", " . $renglon["detalle"]); ?>"
                                                       value="<?php echo ajusteGuardado($ajustes, $clave, "afromexicano"); ?>"
                                                       data-auto="<?php echo $automaticos[$clave]["afromexicano"]; ?>">
                                                <span class="conteo-impresion"><?php echo $afromexicanoRenglon; ?></span>
                                            </td>
                                            <td class="total total-indigenas">
                                                <span class="conteo-auto"><?php echo $automaticos[$clave]["indigenas"]; ?></span>
                                                <span class="mas">+</span>
                                                <input class="ajuste ajuste-total ajuste-indigenas"
                                                       type="number" step="1" min="<?php echo -$automaticos[$clave]["indigenas"]; ?>" max="999999"
                                                       name="ajustes[<?php echo escapar($clave); ?>][indigenas]"
                                                       aria-label="Ajuste manual de dosis a población indígena: <?php echo escapar($biologico . ", " . $grupo . ", " . $renglon["detalle"]); ?>"
                                                       value="<?php echo ajusteGuardado($ajustes, $clave, "indigenas"); ?>"
                                                       data-auto="<?php echo $automaticos[$clave]["indigenas"]; ?>">
                                                <span class="conteo-impresion"><?php echo $indigenasRenglon; ?></span>
                                            </td>
                                            <td class="total total-migrantes">
                                                <span class="conteo-auto"><?php echo $automaticos[$clave]["migrantes"]; ?></span>
                                                <span class="mas">+</span>
                                                <input class="ajuste ajuste-total ajuste-migrantes"
                                                       type="number" step="1" min="<?php echo -$automaticos[$clave]["migrantes"]; ?>" max="999999"
                                                       name="ajustes[<?php echo escapar($clave); ?>][migrantes]"
                                                       aria-label="Ajuste manual de migrantes: <?php echo escapar($biologico . ", " . $grupo . ", " . $renglon["detalle"]); ?>"
                                                       value="<?php echo ajusteGuardado($ajustes, $clave, "migrantes"); ?>"
                                                       data-auto="<?php echo $automaticos[$clave]["migrantes"]; ?>">
                                                <span class="conteo-impresion"><?php echo $migrantesRenglon; ?></span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endforeach; ?>
                            <?php endforeach; ?>
                            </tbody>
                            <tfoot>
                            <tr>
                                <th colspan="3">TOTAL</th>
                                <?php foreach ($totalesPagina as $dia => $cantidadTotal): ?>
                                    <th class="dia"><?php echo $dia <= $diasMes ? $cantidadTotal : ""; ?></th>
                                <?php endforeach; ?>
                                <th class="total total-dosis"><?php echo $totalDosisPagina; ?></th>
                                <th class="total total-afromexicano"><?php echo $totalAfromexicanoPagina; ?></th>
                                <th class="total total-indigenas"><?php echo $totalIndigenasPagina; ?></th>
                                <th class="total total-migrantes"><?php echo $totalMigrantesPagina; ?></th>
                            </tr>
                            </tfoot>
                        </table>
                    </div>
                </section>
            <?php endforeach; ?>
        </form>
    </section>

</main>
<script>
    function actualizarTotales() {
        var cantidadDias = <?php echo $diasFormato; ?>;
        var diasDelMes = <?php echo $diasMes; ?>;
        document.querySelectorAll(".tabla-oficial").forEach(function (tabla) {
            var filaPie = tabla.querySelector("tfoot tr");
            if (!filaPie) {
                return;
            }

            var totalDosis = 0;
            for (var dia = 1; dia <= cantidadDias; dia++) {
                if (dia > diasDelMes) {
                    filaPie.children[dia].textContent = "";
                    continue;
                }

                var sumaDia = 0;
                tabla.querySelectorAll('.ajuste-dia[data-dia="' + dia + '"]').forEach(function (campo) {
                    sumaDia += Number.parseInt(campo.dataset.auto || "0", 10)
                        + Number.parseInt(campo.value || "0", 10);
                });
                filaPie.children[dia].textContent = String(sumaDia);
                totalDosis += sumaDia;
            }

            function sumaCategoria(selector) {
                var suma = 0;
                tabla.querySelectorAll(selector).forEach(function (campo) {
                    suma += Number.parseInt(campo.dataset.auto || "0", 10)
                        + Number.parseInt(campo.value || "0", 10);
                });
                return suma;
            }

            var totalAfromexicano = sumaCategoria(".ajuste-afromexicano");
            var totalIndigenas = sumaCategoria(".ajuste-indigenas");
            var totalMigrantes = sumaCategoria(".ajuste-migrantes");
            totalDosis += totalMigrantes;
            filaPie.querySelector(".total-dosis").textContent = String(totalDosis);
            filaPie.querySelector(".total-afromexicano").textContent = String(totalAfromexicano);
            filaPie.querySelector(".total-indigenas").textContent = String(totalIndigenas);
            filaPie.querySelector(".total-migrantes").textContent = String(totalMigrantes);
        });
    }

    document.querySelectorAll(".ajuste").forEach(function (campo) {
        campo.addEventListener("input", function () {
            var valorAjuste = Number.parseInt(campo.value || "0", 10);
            if (!Number.isFinite(valorAjuste)) {
                valorAjuste = 0;
            }
            var conteoFinal = Number.parseInt(campo.dataset.auto || "0", 10) + valorAjuste;
            var celda = campo.closest("td");
            var impresion = celda.querySelector(".conteo-impresion");
            if (impresion) {
                impresion.textContent = String(conteoFinal);
            }
            var fila = campo.closest("tr");
            var totalNoMigrantes = 0;
            fila.querySelectorAll(".ajuste-dia").forEach(function (ajusteDia) {
                totalNoMigrantes += Number.parseInt(ajusteDia.dataset.auto || "0", 10)
                    + Number.parseInt(ajusteDia.value || "0", 10);
            });
            var totalMigrantes = Number.parseInt(
                fila.querySelector(".ajuste-migrantes").dataset.auto || "0",
                10
            ) + Number.parseInt(fila.querySelector(".ajuste-migrantes").value || "0", 10);
            var celdaDosis = fila.querySelector(".total-dosis");
            celdaDosis.dataset.valor = String(totalNoMigrantes + totalMigrantes);
            celdaDosis.textContent = String(totalNoMigrantes + totalMigrantes);
            [
                [".ajuste-afromexicano", ".total-afromexicano"],
                [".ajuste-indigenas", ".total-indigenas"],
                [".ajuste-migrantes", ".total-migrantes"]
            ].forEach(function (selectores) {
                var campoCategoria = fila.querySelector(selectores[0]);
                var totalCategoria = Number.parseInt(campoCategoria.dataset.auto || "0", 10)
                    + Number.parseInt(campoCategoria.value || "0", 10);
                fila.querySelector(selectores[1]).textContent = String(totalCategoria);
            });
            actualizarTotales();
        });
    });
</script>
</body>
</html>