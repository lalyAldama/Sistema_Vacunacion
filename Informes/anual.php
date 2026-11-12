<?php
require_once __DIR__ . "/../config/seguridad.php";

if (!isset($_SESSION["id_usuario"])) {
    header("Location: ../login.php");
    exit();
}

require_once __DIR__ . "/../config/conexion.php";
require_once __DIR__ . "/../config/unidad_contexto.php";
$contextoUnidadInforme = unidadObtenerContexto($conexion);
$soloConsulta = $contextoUnidadInforme["solo_consulta"];
require_once __DIR__ . "/../exportar_importar/funciones.php";
asegurarEsquemaExportacionUnidad($conexion);

$periodoAnual = $_SERVER["REQUEST_METHOD"] === "POST" ? $_POST : $_GET;
$anioRecibido = $periodoAnual["anio"] ?? (int)date("Y");
$anioInformeAnual = (is_string($anioRecibido) || is_int($anioRecibido))
    ? filter_var($anioRecibido, FILTER_VALIDATE_INT)
    : false;
if (
    $anioInformeAnual === false
    || $anioInformeAnual < 2000
    || $anioInformeAnual > 2100
) {
    http_response_code(400);
    exit("El año indicado para el informe anual no es válido.");
}

define("INFORME_ANUAL_SOLICITANDO_CONTEOS", true);
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $_POST["anio"] = $anioInformeAnual;
}
$datosPaloteoAnual = require __DIR__ . "/../paloteo/paloteo_general.php";
if (!is_array($datosPaloteoAnual)) {
    die("No fue posible obtener los conteos del informe anual.");
}

function escaparInformeAnual($valor)
{
    return htmlspecialchars((string)$valor, ENT_QUOTES, "UTF-8");
}

function numeroInformeAnual($valor)
{
    $texto = number_format((float)$valor, 2, ".", ",");
    return rtrim(rtrim($texto, "0"), ".");
}

function numeroCeldaInformeAnual($valor, $formato)
{
    $decimales = in_array((int)$formato, [1, 3], true)
        ? 0
        : (in_array((int)$formato, [2, 4], true) ? 2 : null);
    if ($decimales === null) {
        return numeroInformeAnual($valor);
    }
    $separadorMiles = in_array((int)$formato, [3, 4], true) ? "," : "";
    return number_format((float)$valor, $decimales, ".", $separadorMiles);
}

function columnaInformeAnual($numero)
{
    $columna = "";
    while ($numero > 0) {
        $residuo = ($numero - 1) % 26;
        $columna = chr(65 + $residuo) . $columna;
        $numero = intdiv($numero - 1, 26);
    }
    return $columna;
}

function claveRenglonInformeAnual($id)
{
    if (str_starts_with($id, "bcg_")) {
        return "bcg";
    }
    if ($id === "hepb_rn") {
        return "hepb_ninos";
    }
    if (str_starts_with($id, "hepb_")) {
        return "hepb_adultos";
    }
    if (str_starts_with($id, "hepa_")) {
        return "hepa";
    }
    if (str_starts_with($id, "dpt_") || str_starts_with($id, "tdpa_dpt_")) {
        return "dpt";
    }
    if (str_starts_with($id, "rota_")) {
        return "rotavirus";
    }
    if (str_starts_with($id, "neumo20_")) {
        return str_ends_with($id, "_60") ? "neumo20_adultos" : "neumo20_ninos";
    }
    if (str_starts_with($id, "neumo23_")) {
        return "neumo23";
    }
    if (str_starts_with($id, "neumo_")) {
        return in_array($id, ["neumo_unica60", "neumo_riesgo60"], true)
            ? "neumo13_adultos"
            : "neumo13_ninos";
    }
    if (str_starts_with($id, "srp_")) {
        return "srp";
    }
    if (str_starts_with($id, "sr_")) {
        return in_array($id, ["sr_6m", "sr_1_4"], true) ? "sr_ninos" : "sr_adultos";
    }
    if (str_starts_with($id, "vph_")) {
        return "vph";
    }
    if (str_starts_with($id, "varicela_")) {
        return "varicela";
    }
    if ($id === "tdpa_embarazo") {
        return "tdpa";
    }
    if (str_starts_with($id, "td_")) {
        return "td";
    }
    if (str_starts_with($id, "hexa_")) {
        return "hexa";
    }
    if (str_starts_with($id, "vsr_")) {
        return "vsr";
    }
    return null;
}

function conteoMensualInformeAnual($datos, $idRenglon, $mes)
{
    $conteo = (int)($datos["conteosPorMes"][$idRenglon][$mes] ?? 0);
    $ajusteRenglon = $datos["ajustesPorMes"][$mes][$idRenglon] ?? [];
    foreach (($ajusteRenglon["dias"] ?? []) as $dia => $ajusteDia) {
        if (
            (int)$dia < 1
            || (int)$dia > cal_days_in_month(
                CAL_GREGORIAN,
                $mes,
                (int)$datos["anio"]
            )
        ) {
            continue;
        }
        if (!is_numeric($ajusteDia)) {
            die("Uno de los ajustes diarios del informe anual no es válido.");
        }
        $conteo += (int)$ajusteDia;
    }
    $ajusteMigrantes = $ajusteRenglon["migrantes"] ?? 0;
    if (!is_numeric($ajusteMigrantes)) {
        die("Uno de los ajustes de migrantes del informe anual no es válido.");
    }
    $conteo += (int)$ajusteMigrantes;
    if ($conteo < 0) {
        die("Los ajustes del paloteo dejaron un conteo mensual negativo.");
    }
    return $conteo;
}

$metasPredeterminadasAnual = [
    "bcg" => 43,
    "hepb_ninos" => 18,
    "hepb_adultos" => 19,
    "hepa" => 35,
    "dpt" => 38,
    "rotavirus" => 42,
    "neumo13_ninos" => 80,
    "neumo13_adultos" => 23,
    "neumo20_ninos" => 80,
    "neumo20_adultos" => 23,
    "neumo23" => 12,
    "srp" => 92,
    "sr_ninos" => 56,
    "sr_adultos" => 24,
    "vph" => 33,
    "varicela" => 35,
    "td" => 65,
    "tdpa" => 48,
    "hexa" => 148,
    "vsr" => 17
];
$metasInformeAnual = $metasPredeterminadasAnual;
$sqlTablaMetas = "CREATE TABLE IF NOT EXISTS INFORME_ANUAL_META (
    id_unidad INT NOT NULL,
    anio SMALLINT UNSIGNED NOT NULL,
    clave VARCHAR(40) NOT NULL,
    meta DECIMAL(12,2) NOT NULL DEFAULT 0,
    id_usuario INT NOT NULL,
    actualizado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id_unidad, anio, clave)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
if (!$conexion->query($sqlTablaMetas)) {
    die("No fue posible preparar el almacenamiento de las metas anuales: " . $conexion->error);
}

$stmtMetas = $conexion->prepare(
    "SELECT clave, meta FROM INFORME_ANUAL_META WHERE id_unidad = ? AND anio = ?"
);
if (!$stmtMetas) {
    die("Error al preparar la consulta de metas anuales: " . $conexion->error);
}
$idUnidadInformeAnual = (int)$datosPaloteoAnual["idUnidad"];
$stmtEncabezadosAnuales = $conexion->prepare(
    "SELECT clave, valor
     FROM INFORME_ANUAL_ENCABEZADO
     WHERE id_unidad = ?"
);
if (!$stmtEncabezadosAnuales) {
    die("Error al preparar la consulta de encabezados anuales: " . $conexion->error);
}
$stmtEncabezadosAnuales->bind_param("i", $idUnidadInformeAnual);
if (!$stmtEncabezadosAnuales->execute()) {
    die("Error al consultar los encabezados anuales: " . $stmtEncabezadosAnuales->error);
}
$encabezadosInformeAnual = [];
$resultadoEncabezadosAnuales = $stmtEncabezadosAnuales->get_result();
while ($filaEncabezadoAnual = $resultadoEncabezadosAnuales->fetch_assoc()) {
    if (in_array($filaEncabezadoAnual["clave"], ["C4", "C5"], true)) {
        $encabezadosInformeAnual[$filaEncabezadoAnual["clave"]] =
            (string)$filaEncabezadoAnual["valor"];
    }
}
$stmtEncabezadosAnuales->close();
$stmtMetas->bind_param("ii", $idUnidadInformeAnual, $anioInformeAnual);
if (!$stmtMetas->execute()) {
    die("Error al consultar las metas anuales: " . $stmtMetas->error);
}
$resultadoMetas = $stmtMetas->get_result();
while ($filaMeta = $resultadoMetas->fetch_assoc()) {
    if (array_key_exists($filaMeta["clave"], $metasInformeAnual)) {
        $metasInformeAnual[$filaMeta["clave"]] = (float)$filaMeta["meta"];
    }
}
$stmtMetas->close();

$metasRecibidas = null;
$encabezadosRecibidos = [];
$guardarMetasAnuales = $_SERVER["REQUEST_METHOD"] === "POST"
    && (
        ($_POST["guardar_metas"] ?? "") === "1"
        || (!$soloConsulta && ($_POST["descargar"] ?? "") === "1")
    );
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    if ($guardarMetasAnuales) {
        unidadExigirPermisoEscritura();
    }
    $encabezadosPost = $_POST["encabezados"] ?? [];
    if (!is_array($encabezadosPost)) {
        http_response_code(400);
        exit("No se recibieron encabezados válidos para el informe anual.");
    }
    foreach (["C4", "C5"] as $coordenadaEncabezado) {
        $valorEncabezado = $encabezadosPost[$coordenadaEncabezado] ?? null;
        if (!is_string($valorEncabezado) || mb_strlen($valorEncabezado, "UTF-8") > 150) {
            http_response_code(400);
            exit("Los encabezados deben ser texto de hasta 150 caracteres.");
        }
        $encabezadosRecibidos[$coordenadaEncabezado] = trim($valorEncabezado);
    }

    $metasPost = $_POST["metas"] ?? null;
    if (!is_array($metasPost)) {
        http_response_code(400);
        exit("No se recibieron metas válidas para el informe anual.");
    }
    $metasRecibidas = [];
    foreach ($metasPredeterminadasAnual as $claveMeta => $_metaPredeterminada) {
        $valorMeta = $metasPost[$claveMeta] ?? null;
        if (
            (!is_string($valorMeta) && !is_int($valorMeta) && !is_float($valorMeta))
            || !preg_match('/^\d{1,10}(?:\.\d{1,2})?$/', (string)$valorMeta)
        ) {
            http_response_code(400);
            exit("Las metas deben ser números no negativos con un máximo de dos decimales.");
        }
        $metasRecibidas[$claveMeta] = (float)$valorMeta;
    }

    if ($guardarMetasAnuales) {
        if (!$conexion->begin_transaction()) {
            die("No fue posible iniciar el guardado de las metas anuales: " . $conexion->error);
        }
    $stmtGuardarMeta = $conexion->prepare(
        "INSERT INTO INFORME_ANUAL_META (id_unidad, anio, clave, meta, id_usuario)
         VALUES (?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE meta = VALUES(meta), id_usuario = VALUES(id_usuario)"
    );
    if (!$stmtGuardarMeta) {
        $conexion->rollback();
        die("Error al preparar el guardado de las metas anuales: " . $conexion->error);
    }
    $idUsuarioInformeAnual = (int)$_SESSION["id_usuario"];
    $valorMetaGuardar = 0.0;
    $claveMetaGuardar = "";
    $stmtGuardarMeta->bind_param(
        "iisdi",
        $idUnidadInformeAnual,
        $anioInformeAnual,
        $claveMetaGuardar,
        $valorMetaGuardar,
        $idUsuarioInformeAnual
    );
    foreach ($metasRecibidas as $claveMetaGuardar => $valorMetaGuardar) {
        if (!$stmtGuardarMeta->execute()) {
            $errorGuardarMeta = $stmtGuardarMeta->error;
            $stmtGuardarMeta->close();
            $conexion->rollback();
            die("Error al guardar las metas anuales: " . $errorGuardarMeta);
        }
    }
    $stmtGuardarMeta->close();
    $stmtGuardarEncabezado = $conexion->prepare(
        "INSERT INTO INFORME_ANUAL_ENCABEZADO
            (id_unidad, clave, valor, id_usuario)
         VALUES (?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
            valor = VALUES(valor),
            id_usuario = VALUES(id_usuario)"
    );
    if (!$stmtGuardarEncabezado) {
        $conexion->rollback();
        die("Error al preparar el guardado de encabezados anuales: " . $conexion->error);
    }
    $claveEncabezadoGuardar = "";
    $valorEncabezadoGuardar = "";
    $stmtGuardarEncabezado->bind_param(
        "issi",
        $idUnidadInformeAnual,
        $claveEncabezadoGuardar,
        $valorEncabezadoGuardar,
        $idUsuarioInformeAnual
    );
    foreach ($encabezadosRecibidos as $claveEncabezadoGuardar => $valorEncabezadoGuardar) {
        if (!$stmtGuardarEncabezado->execute()) {
            $errorGuardarEncabezado = $stmtGuardarEncabezado->error;
            $stmtGuardarEncabezado->close();
            $conexion->rollback();
            die("Error al guardar los encabezados anuales: " . $errorGuardarEncabezado);
        }
    }
    $stmtGuardarEncabezado->close();
        if (!$conexion->commit()) {
            $conexion->rollback();
            die("No fue posible confirmar el guardado de las metas anuales: " . $conexion->error);
        }
    }
    $metasInformeAnual = $metasRecibidas;
    $encabezadosInformeAnual = $encabezadosRecibidos;

    if ($guardarMetasAnuales && ($_POST["descargar"] ?? "") !== "1") {
        header("Location: anual.php?anio=" . $anioInformeAnual . "&guardado=1");
        exit();
    }
}

$clavesPrimeraTabla = [
    "bcg", "hepb_ninos", "hepb_adultos", "hepa", "dpt", "rotavirus",
    "neumo13_ninos", "neumo13_adultos", "neumo20_ninos",
    "neumo20_adultos", "neumo23"
];
$columnasPrimeraTabla = [
    "bcg" => "B",
    "hepb_ninos" => "C",
    "hepb_adultos" => "D",
    "hepa" => "E",
    "dpt" => "F",
    "rotavirus" => "G",
    "neumo13_ninos" => "H",
    "neumo13_adultos" => "I",
    "neumo20_ninos" => "J",
    "neumo20_adultos" => "K",
    "neumo23" => "L"
];
$clavesSegundaTabla = [
    "srp", "sr_ninos", "sr_adultos", "vph", "varicela",
    "td", "tdpa", "hexa", "vsr"
];
$columnasSegundaTabla = [
    "srp" => "B",
    "sr_ninos" => "C",
    "sr_adultos" => "D",
    "vph" => "E",
    "varicela" => "F",
    "td" => "G",
    "tdpa" => "H",
    "hexa" => "I",
    "vsr" => "J"
];
$nombresMesesAnual = [
    1 => "ENERO", 2 => "FEBRERO", 3 => "MARZO", 4 => "ABRIL",
    5 => "MAYO", 6 => "JUNIO", 7 => "JULIO", 8 => "AGOSTO",
    9 => "SEPTIEMBRE", 10 => "OCTUBRE", 11 => "NOVIEMBRE", 12 => "DICIEMBRE"
];
$conteosMensualesAnuales = [];
foreach ($datosPaloteoAnual["renglones"] as $idRenglon => $_renglonAnual) {
    $claveAnual = claveRenglonInformeAnual($idRenglon);
    if ($claveAnual === null) {
        continue;
    }
    for ($mesAnual = 1; $mesAnual <= 12; $mesAnual++) {
        $conteosMensualesAnuales[$claveAnual][$mesAnual] =
            ($conteosMensualesAnuales[$claveAnual][$mesAnual] ?? 0)
            + conteoMensualInformeAnual($datosPaloteoAnual, $idRenglon, $mesAnual);
    }
}

$aplicacionesAcumuladasAnual = [];
$totalesAplicacionAnual = [];
foreach (array_merge($clavesPrimeraTabla, $clavesSegundaTabla) as $claveAnual) {
    $acumulado = 0;
    for ($mesAnual = 1; $mesAnual <= 12; $mesAnual++) {
        $acumulado += (int)($conteosMensualesAnuales[$claveAnual][$mesAnual] ?? 0);
        $aplicacionesAcumuladasAnual[$claveAnual][$mesAnual] = $acumulado;
    }
    $totalesAplicacionAnual[$claveAnual] = $acumulado;
}

$rutaPlantillaAnual = __DIR__ . "/../Anual.xlsx";
if (!is_file($rutaPlantillaAnual)) {
    die("No se encontró la plantilla oficial del informe anual.");
}
$archivoPlantillaAnual = new ZipArchive();
if ($archivoPlantillaAnual->open($rutaPlantillaAnual, ZipArchive::RDONLY) !== true) {
    die("No fue posible abrir la plantilla oficial del informe anual.");
}
$xmlHojaAnual = $archivoPlantillaAnual->getFromName("xl/worksheets/sheet1.xml");
$xmlCadenasAnual = $archivoPlantillaAnual->getFromName("xl/sharedStrings.xml");
$xmlEstilosAnual = $archivoPlantillaAnual->getFromName("xl/styles.xml");
$archivoPlantillaAnual->close();
if (
    $xmlHojaAnual === false
    || $xmlCadenasAnual === false
    || $xmlEstilosAnual === false
) {
    die("La plantilla anual no contiene la hoja, las cadenas o los estilos requeridos.");
}

$espacioExcelAnual = "http://schemas.openxmlformats.org/spreadsheetml/2006/main";
$documentoHojaAnual = new DOMDocument();
$documentoCadenasAnual = new DOMDocument();
$documentoEstilosAnual = new DOMDocument();
if (
    !$documentoHojaAnual->loadXML($xmlHojaAnual, LIBXML_NONET)
    || !$documentoCadenasAnual->loadXML($xmlCadenasAnual, LIBXML_NONET)
    || !$documentoEstilosAnual->loadXML($xmlEstilosAnual, LIBXML_NONET)
) {
    die("No fue posible leer la estructura de la plantilla anual.");
}
$xpathHojaAnual = new DOMXPath($documentoHojaAnual);
$xpathHojaAnual->registerNamespace("x", $espacioExcelAnual);
$consultarXPathAnual = function (
    DOMXPath $xpath,
    string $consulta,
    ?DOMNode $contexto = null
): DOMNodeList {
    $resultado = $xpath->query($consulta, $contexto);
    if (!$resultado instanceof DOMNodeList) {
        die("No fue posible consultar la estructura de la plantilla anual.");
    }
    return $resultado;
};
$obtenerElementoAnual = function ($nodo): ?DOMElement {
    return $nodo instanceof DOMElement ? $nodo : null;
};
$celdasAnuales = [];
$filasAnuales = [];
foreach ($consultarXPathAnual($xpathHojaAnual, "//x:sheetData/x:row") as $filaAnual) {
    if (!$filaAnual instanceof DOMElement) {
        continue;
    }
    $filasAnuales[(int)$filaAnual->getAttribute("r")] = $filaAnual;
    foreach ($consultarXPathAnual($xpathHojaAnual, "./x:c", $filaAnual) as $celdaAnual) {
        if (!$celdaAnual instanceof DOMElement) {
            continue;
        }
        $celdasAnuales[$celdaAnual->getAttribute("r")] = $celdaAnual;
    }
}
$xpathCadenasAnual = new DOMXPath($documentoCadenasAnual);
$xpathCadenasAnual->registerNamespace("x", $espacioExcelAnual);
$cadenasAnuales = [];
foreach ($consultarXPathAnual($xpathCadenasAnual, "//x:si") as $cadenaAnual) {
    if (!$cadenaAnual instanceof DOMElement) {
        continue;
    }
    $textoCadenaAnual = "";
    foreach ($consultarXPathAnual($xpathCadenasAnual, ".//x:t", $cadenaAnual) as $textoAnual) {
        $textoCadenaAnual .= $textoAnual->textContent;
    }
    $cadenasAnuales[] = $textoCadenaAnual;
}
$leerCeldaAnual = function ($coordenada) use ($celdasAnuales, $cadenasAnuales, $espacioExcelAnual) {
    $celda = $celdasAnuales[$coordenada] ?? null;
    if (!$celda instanceof DOMElement) {
        return "";
    }
    if ($celda->getAttribute("t") === "inlineStr") {
        $texto = $celda->getElementsByTagNameNS($espacioExcelAnual, "t")->item(0);
        return $texto ? $texto->textContent : "";
    }
    $valor = $celda->getElementsByTagNameNS($espacioExcelAnual, "v")->item(0);
    if (!$valor) {
        return "";
    }
    if ($celda->getAttribute("t") === "s") {
        $indice = (int)$valor->textContent;
        if (!array_key_exists($indice, $cadenasAnuales)) {
            die("La plantilla anual contiene una cadena inválida.");
        }
        return $cadenasAnuales[$indice];
    }
    return $valor->textContent;
};

$obtenerColorAnual = function ($nodo) {
    if (!$nodo instanceof DOMElement) {
        return null;
    }
    $rgb = $nodo->getAttribute("rgb");
    return preg_match('/^(?:FF)?([A-Fa-f0-9]{6})$/', $rgb, $partes)
        ? "#" . $partes[1]
        : null;
};
$xpathEstilosAnual = new DOMXPath($documentoEstilosAnual);
$xpathEstilosAnual->registerNamespace("x", $espacioExcelAnual);
$fuentesAnuales = $consultarXPathAnual($xpathEstilosAnual, "//x:fonts/x:font");
$rellenosAnuales = $consultarXPathAnual($xpathEstilosAnual, "//x:fills/x:fill");
$bordesAnuales = $consultarXPathAnual($xpathEstilosAnual, "//x:borders/x:border");
$formatosAnuales = $consultarXPathAnual($xpathEstilosAnual, "//x:cellXfs/x:xf");
$estilosAnuales = [];
$formatosNumeroAnuales = [];
foreach ($formatosAnuales as $formatoAnual) {
    if (!$formatoAnual instanceof DOMElement) {
        continue;
    }
    $reglasAnuales = [];
    $fuenteAnual = $obtenerElementoAnual(
        $fuentesAnuales->item((int)$formatoAnual->getAttribute("fontId"))
    );
    if ($fuenteAnual) {
        foreach ([
            "b" => ["font-weight", "bold"],
            "i" => ["font-style", "italic"],
            "u" => ["text-decoration", "underline"]
        ] as $etiquetaAnual => [$propiedadAnual, $valorAnual]) {
            $atributoAnual = $obtenerElementoAnual(
                $fuenteAnual->getElementsByTagNameNS(
                    $espacioExcelAnual,
                    $etiquetaAnual
                )->item(0)
            );
            if (
                $atributoAnual
                && !in_array(
                    $atributoAnual->getAttribute("val"),
                    ["0", "false", "none"],
                    true
                )
            ) {
                $reglasAnuales[] = $propiedadAnual . ":" . $valorAnual;
            }
        }
        $tamanoAnual = $obtenerElementoAnual(
            $fuenteAnual->getElementsByTagNameNS($espacioExcelAnual, "sz")->item(0)
        );
        if ($tamanoAnual && is_numeric($tamanoAnual->getAttribute("val"))) {
            $reglasAnuales[] = "font-size:" . (float)$tamanoAnual->getAttribute("val") . "pt";
        }
        $colorTextoAnual = $obtenerColorAnual(
            $fuenteAnual->getElementsByTagNameNS($espacioExcelAnual, "color")->item(0)
        );
        if ($colorTextoAnual) {
            $reglasAnuales[] = "color:" . $colorTextoAnual;
        }
    }
    $rellenoAnual =     $obtenerElementoAnual(
        $rellenosAnuales->item((int)$formatoAnual->getAttribute("fillId"))
    );
    if ($rellenoAnual) {
        $patronAnual = $obtenerElementoAnual(
            $rellenoAnual->getElementsByTagNameNS($espacioExcelAnual, "patternFill")->item(0)
        );
        $colorFondoAnual = $patronAnual
            ? $obtenerColorAnual(
                $patronAnual->getElementsByTagNameNS($espacioExcelAnual, "fgColor")->item(0)
            )
            : null;
        if ($patronAnual && $patronAnual->getAttribute("patternType") === "solid" && $colorFondoAnual) {
            $reglasAnuales[] = "background-color:" . $colorFondoAnual;
        }
    }
    $bordeAnual = $obtenerElementoAnual(
        $bordesAnuales->item((int)$formatoAnual->getAttribute("borderId"))
    );
    if ($bordeAnual) {
        $estilosBordeAnual = [
            "hair" => "0.5px solid",
            "dotted" => "1px dotted",
            "dashed" => "1px dashed",
            "thin" => "1px solid",
            "medium" => "2px solid",
            "thick" => "3px solid",
            "double" => "3px double"
        ];
        foreach (["left", "right", "top", "bottom"] as $ladoAnual) {
            $aristaAnual = $obtenerElementoAnual(
                $bordeAnual->getElementsByTagNameNS($espacioExcelAnual, $ladoAnual)->item(0)
            );
            $estiloAristaAnual = $aristaAnual ? $aristaAnual->getAttribute("style") : "";
            if (!isset($estilosBordeAnual[$estiloAristaAnual])) {
                continue;
            }
            $colorAristaAnual = $obtenerColorAnual(
                $aristaAnual->getElementsByTagNameNS($espacioExcelAnual, "color")->item(0)
            ) ?? "#000";
            $reglasAnuales[] = "border-" . $ladoAnual . ":"
                . $estilosBordeAnual[$estiloAristaAnual] . " " . $colorAristaAnual;
        }
    }
    $alineacionAnual = $obtenerElementoAnual(
        $formatoAnual->getElementsByTagNameNS($espacioExcelAnual, "alignment")->item(0)
    );
    if ($alineacionAnual) {
        $horizontalAnual = [
            "left" => "left",
            "center" => "center",
            "right" => "right",
            "justify" => "justify"
        ][$alineacionAnual->getAttribute("horizontal")] ?? null;
        $verticalAnual = [
            "top" => "top",
            "center" => "middle",
            "bottom" => "bottom"
        ][$alineacionAnual->getAttribute("vertical")] ?? null;
        if ($horizontalAnual !== null) {
            $reglasAnuales[] = "text-align:" . $horizontalAnual;
        }
        if ($verticalAnual !== null) {
            $reglasAnuales[] = "vertical-align:" . $verticalAnual;
        }
        if ($alineacionAnual->getAttribute("wrapText") === "1") {
            $reglasAnuales[] = "white-space:pre-line";
        }
    }
    $estilosAnuales[] = implode(";", $reglasAnuales);
    $formatosNumeroAnuales[] = (int)$formatoAnual->getAttribute("numFmtId");
}

$anchosAnuales = array_fill(1, 25, 80.0);
foreach ($consultarXPathAnual($xpathHojaAnual, "//x:cols/x:col") as $columnaAnual) {
    if (!$columnaAnual instanceof DOMElement) {
        continue;
    }
    $anchoPxAnual = $columnaAnual->hasAttribute("width")
        ? (float)$columnaAnual->getAttribute("width") * 7
        : 80.0;
    $minColumnaAnual = (int)$columnaAnual->getAttribute("min");
    $maxColumnaAnual = min(25, (int)$columnaAnual->getAttribute("max"));
    for ($columnaIndiceAnual = max(1, $minColumnaAnual); $columnaIndiceAnual <= $maxColumnaAnual; $columnaIndiceAnual++) {
        $anchosAnuales[$columnaIndiceAnual] = $anchoPxAnual;
    }
}

$actualizacionesAnuales = [];
$ponerActualizacionAnual = function ($coordenada, $valor, $texto = false, $calculo = null) use (&$actualizacionesAnuales) {
    $actualizacionesAnuales[$coordenada] = [
        "valor" => $valor,
        "texto" => $texto,
        "calculo" => $calculo
    ];
};
$ponerActualizacionAnual(
    "C4",
    $encabezadosInformeAnual["C4"] ?? $leerCeldaAnual("C4"),
    true
);
$ponerActualizacionAnual(
    "C5",
    $encabezadosInformeAnual["C5"] ?? $datosPaloteoAnual["nombreUnidad"],
    true
);
$ponerActualizacionAnual("C6", (string)$anioInformeAnual, true);
foreach ($encabezadosRecibidos as $coordenadaEncabezado => $valorEncabezado) {
    $ponerActualizacionAnual($coordenadaEncabezado, $valorEncabezado, true);
}

foreach ($clavesPrimeraTabla as $claveMetaAnual) {
    $columnaAnual = $columnasPrimeraTabla[$claveMetaAnual];
    $metaAnual = (float)$metasInformeAnual[$claveMetaAnual];
    $ponerActualizacionAnual($columnaAnual . "26", $metaAnual);
    $ponerActualizacionAnual(
        $columnaAnual . "27",
        $metaAnual > 0
            ? ($totalesAplicacionAnual[$claveMetaAnual] / $metaAnual) * 100
            : 0,
        false,
        ["tipo" => "cobertura", "clave" => $claveMetaAnual, "acumulado" => $totalesAplicacionAnual[$claveMetaAnual]]
    );
    for ($mesAnual = 1; $mesAnual <= 12; $mesAnual++) {
        $filaMensualAnual = 12 + $mesAnual;
        $ponerActualizacionAnual(
            $columnaAnual . $filaMensualAnual,
            (int)($conteosMensualesAnuales[$claveMetaAnual][$mesAnual] ?? 0)
        );
    }
    $ponerActualizacionAnual(
        $columnaAnual . "25",
        (int)$totalesAplicacionAnual[$claveMetaAnual]
    );
}

$necesidadesPrimeraTabla = [
    "bcg" => "P",
    "hepb_ninos" => "Q",
    "hepb_adultos" => "R",
    "hepa" => "S",
    "dpt" => "T",
    "rotavirus" => "U",
    "neumo13_ninos" => "V",
    "neumo13_adultos" => "W",
    "neumo23" => "X"
];
foreach ($necesidadesPrimeraTabla as $claveNecesidad => $columnaNecesidad) {
    $metaMensual = (float)$metasInformeAnual[$claveNecesidad] / 12;
    $ponerActualizacionAnual(
        $columnaNecesidad . "13",
        $metaMensual,
        false,
        ["tipo" => "meta-mensual", "clave" => $claveNecesidad]
    );
    for ($mesAnual = 1; $mesAnual <= 12; $mesAnual++) {
        $filaNecesidad = 13 + $mesAnual;
        $aplicadoAcumulado = (int)($aplicacionesAcumuladasAnual[$claveNecesidad][$mesAnual] ?? 0);
        $ponerActualizacionAnual(
            $columnaNecesidad . $filaNecesidad,
            ($metaMensual * $mesAnual) - $aplicadoAcumulado,
            false,
            [
                "tipo" => "necesidad",
                "clave" => $claveNecesidad,
                "mes" => $mesAnual,
                "acumulado" => $aplicadoAcumulado
            ]
        );
    }
}
$ponerActualizacionAnual("O13", "meta mensual", true);
for ($mesAnual = 1; $mesAnual <= 12; $mesAnual++) {
    $ponerActualizacionAnual("O" . (13 + $mesAnual), $nombresMesesAnual[$mesAnual], true);
}

foreach ($clavesSegundaTabla as $claveMetaAnual) {
    $columnaAnual = $columnasSegundaTabla[$claveMetaAnual];
    $metaAnual = (float)$metasInformeAnual[$claveMetaAnual];
    $ponerActualizacionAnual(
        $columnaAnual . "51",
        $metaAnual
    );
    $ponerActualizacionAnual(
        $columnaAnual . "52",
        $metaAnual > 0
            ? ($totalesAplicacionAnual[$claveMetaAnual] / $metaAnual) * 100
            : 0,
        false,
        ["tipo" => "cobertura", "clave" => $claveMetaAnual, "acumulado" => $totalesAplicacionAnual[$claveMetaAnual]]
    );
    for ($mesAnual = 1; $mesAnual <= 12; $mesAnual++) {
        $filaMensualAnual = 37 + $mesAnual;
        $ponerActualizacionAnual(
            $columnaAnual . $filaMensualAnual,
            (int)($conteosMensualesAnuales[$claveMetaAnual][$mesAnual] ?? 0)
        );
    }
    $ponerActualizacionAnual(
        $columnaAnual . "50",
        (int)$totalesAplicacionAnual[$claveMetaAnual]
    );
}

$necesidadesSegundaTabla = [
    "srp" => "P",
    "sr_ninos" => "Q",
    "sr_adultos" => "R",
    "vph" => "S",
    "varicela" => "T",
    "td" => "U",
    "tdpa" => "V",
    "hexa" => "W"
];
foreach ($necesidadesSegundaTabla as $claveNecesidad => $columnaNecesidad) {
    $metaMensual = (float)$metasInformeAnual[$claveNecesidad] / 12;
    $ponerActualizacionAnual(
        $columnaNecesidad . "38",
        $metaMensual,
        false,
        ["tipo" => "meta-mensual", "clave" => $claveNecesidad]
    );
    for ($mesAnual = 1; $mesAnual <= 12; $mesAnual++) {
        $filaNecesidad = 38 + $mesAnual;
        $aplicadoAcumulado = (int)($aplicacionesAcumuladasAnual[$claveNecesidad][$mesAnual] ?? 0);
        $ponerActualizacionAnual(
            $columnaNecesidad . $filaNecesidad,
            ($metaMensual * $mesAnual) - $aplicadoAcumulado,
            false,
            [
                "tipo" => "necesidad",
                "clave" => $claveNecesidad,
                "mes" => $mesAnual,
                "acumulado" => $aplicadoAcumulado
            ]
        );
    }
}
$ponerActualizacionAnual("O38", "meta mensual", true);
for ($mesAnual = 1; $mesAnual <= 12; $mesAnual++) {
    $ponerActualizacionAnual("O" . (38 + $mesAnual), $nombresMesesAnual[$mesAnual], true);
}
foreach ([[28, 12], [29, 12], [53, 10], [54, 10]] as [$filaResumenAnual, $ultimaColumnaResumenAnual]) {
    for ($columnaResumenAnual = 1; $columnaResumenAnual <= $ultimaColumnaResumenAnual; $columnaResumenAnual++) {
        $ponerActualizacionAnual(
            columnaInformeAnual($columnaResumenAnual) . $filaResumenAnual,
            "",
            true
        );
    }
}

if ($_SERVER["REQUEST_METHOD"] === "POST" && ($_POST["descargar"] ?? "") === "1") {
    foreach ([28, 29, 53, 54] as $filaResumenAnual) {
        if (isset($filasAnuales[$filaResumenAnual])) {
            $filasAnuales[$filaResumenAnual]->setAttribute("hidden", "1");
        }
    }
    $escribirCeldaAnual = function ($coordenada, $valor, $esTexto) use (
        $documentoHojaAnual,
        $xpathHojaAnual,
        $consultarXPathAnual,
        &$celdasAnuales,
        $espacioExcelAnual
    ) {
        if (!isset($celdasAnuales[$coordenada])) {
            if (!preg_match('/^([A-Z]+)(\d+)$/', $coordenada, $partes)) {
                die("La plantilla anual contiene una coordenada inválida.");
            }
            $fila = $partes[2];
            $nodoFila = $consultarXPathAnual(
                $xpathHojaAnual,
                "//x:sheetData/x:row[@r='{$fila}']"
            )->item(0);
            if (!$nodoFila instanceof DOMElement) {
                die("La plantilla anual no contiene una fila requerida.");
            }
            $celda = $documentoHojaAnual->createElementNS($espacioExcelAnual, "c");
            $celda->setAttribute("r", $coordenada);
            $nodoFila->appendChild($celda);
            $celdasAnuales[$coordenada] = $celda;
        }
        $celda = $celdasAnuales[$coordenada];
        foreach (["v", "is", "f"] as $nombreHijo) {
            $nodosHijo = $celda->getElementsByTagNameNS($espacioExcelAnual, $nombreHijo);
            while ($nodosHijo->length > 0) {
                $nodosHijo->item(0)->parentNode->removeChild($nodosHijo->item(0));
            }
        }
        if (!$esTexto) {
            $celda->setAttribute("t", "n");
            $celda->appendChild(
                $documentoHojaAnual->createElementNS(
                    $espacioExcelAnual,
                    "v",
                    sprintf("%.12g", (float)$valor)
                )
            );
            return;
        }
        $celda->setAttribute("t", "inlineStr");
        $inline = $documentoHojaAnual->createElementNS($espacioExcelAnual, "is");
        $texto = $documentoHojaAnual->createElementNS($espacioExcelAnual, "t");
        $texto->setAttributeNS(
            "http://www.w3.org/XML/1998/namespace",
            "xml:space",
            "preserve"
        );
        $texto->appendChild($documentoHojaAnual->createTextNode((string)$valor));
        $inline->appendChild($texto);
        $celda->appendChild($inline);
    };
    foreach ($actualizacionesAnuales as $coordenada => $actualizacionAnual) {
        $esMetaEditable = preg_match('/^[B-L](?:26)$/', $coordenada)
            || preg_match('/^[B-J]51$/', $coordenada);
        $valorExportacion = $actualizacionAnual["valor"];
        if ($esMetaEditable) {
            $valorExportacion = (float)$valorExportacion;
        }
        $esTexto = $actualizacionAnual["texto"];
        $escribirCeldaAnual($coordenada, $valorExportacion, $esTexto);
    }

    $archivoSalidaAnual = tempnam(sys_get_temp_dir(), "informe_anual_");
    if ($archivoSalidaAnual === false || !copy($rutaPlantillaAnual, $archivoSalidaAnual)) {
        if ($archivoSalidaAnual !== false) {
            unlink($archivoSalidaAnual);
        }
        die("No fue posible preparar el archivo anual.");
    }
    $libroSalidaAnual = new ZipArchive();
    if ($libroSalidaAnual->open($archivoSalidaAnual) !== true) {
        unlink($archivoSalidaAnual);
        die("No fue posible abrir la copia de la plantilla anual.");
    }
    $hojaActualizadaAnual = $libroSalidaAnual->addFromString(
        "xl/worksheets/sheet1.xml",
        $documentoHojaAnual->saveXML()
    );
    $archivoCerradoAnual = $libroSalidaAnual->close();
    if (!$hojaActualizadaAnual || !$archivoCerradoAnual) {
        unlink($archivoSalidaAnual);
        die("No fue posible guardar los datos en la plantilla anual.");
    }
    header("Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet");
    header("Content-Disposition: attachment; filename=\"Informe_Anual_Biologicos_" . $anioInformeAnual . ".xlsx\"");
    header("Content-Length: " . filesize($archivoSalidaAnual));
    readfile($archivoSalidaAnual);
    unlink($archivoSalidaAnual);
    exit();
}

$fusionesAnuales = [];
$celdasCubiertasAnuales = [];
foreach ($consultarXPathAnual($xpathHojaAnual, "//x:mergeCells/x:mergeCell") as $fusionAnual) {
    if (!$fusionAnual instanceof DOMElement) {
        continue;
    }
    if (!preg_match('/^([A-Z]+)(\d+):([A-Z]+)(\d+)$/', $fusionAnual->getAttribute("ref"), $partesFusion)) {
        continue;
    }
    $numeroColumnaInicio = 0;
    foreach (str_split($partesFusion[1]) as $letraColumnaInicio) {
        $numeroColumnaInicio = $numeroColumnaInicio * 26 + ord($letraColumnaInicio) - 64;
    }
    $numeroColumnaFin = 0;
    foreach (str_split($partesFusion[3]) as $letraColumnaFin) {
        $numeroColumnaFin = $numeroColumnaFin * 26 + ord($letraColumnaFin) - 64;
    }
    $filaInicioFusion = (int)$partesFusion[2];
    $filaFinFusion = (int)$partesFusion[4];
    $coordenadaInicioFusion = $partesFusion[1] . $filaInicioFusion;
    $fusionesAnuales[$coordenadaInicioFusion] = [
        $numeroColumnaFin - $numeroColumnaInicio + 1,
        $filaFinFusion - $filaInicioFusion + 1
    ];
    for ($filaFusion = $filaInicioFusion; $filaFusion <= $filaFinFusion; $filaFusion++) {
        for ($columnaFusion = $numeroColumnaInicio; $columnaFusion <= $numeroColumnaFin; $columnaFusion++) {
            if ($filaFusion !== $filaInicioFusion || $columnaFusion !== $numeroColumnaInicio) {
                $celdasCubiertasAnuales[$filaFusion . ":" . $columnaFusion] = true;
            }
        }
    }
}

$categoriasGraficaAnual = [];
$etiquetasGraficaAnual = [
    "bcg" => "BCG",
    "hepb_ninos" => "Hepatitis B · niños",
    "hepb_adultos" => "Hepatitis B · adultos",
    "hepa" => "Hepatitis A",
    "dpt" => "DPT",
    "rotavirus" => "Rotavirus RV1",
    "neumo13_ninos" => "Neumocócica 13 valente · niños",
    "neumo13_adultos" => "Neumocócica 13 valente · adultos",
    "neumo23" => "Neumocócica polisacárida 23 valente",
    "srp" => "SRP triple viral",
    "sr_ninos" => "SR doble viral · niños",
    "sr_adultos" => "SR doble viral · adultos",
    "vph" => "VPH",
    "varicela" => "Varicela",
    "td" => "Td",
    "tdpa" => "Tdpa · embarazadas",
    "hexa" => "Hexavalente",
    "vsr" => "Virus sincicial respiratorio"
];
foreach (array_merge($necesidadesPrimeraTabla, $necesidadesSegundaTabla) as $claveGrafica => $_columnaGrafica) {
    $categoriasGraficaAnual[$claveGrafica] = [
        "nombre" => $etiquetasGraficaAnual[$claveGrafica],
        "necesidades" => array_map(
            function ($mesGrafica) use ($claveGrafica, $metasInformeAnual, $aplicacionesAcumuladasAnual) {
                return ((float)$metasInformeAnual[$claveGrafica] / 12 * $mesGrafica)
                    - (int)($aplicacionesAcumuladasAnual[$claveGrafica][$mesGrafica] ?? 0);
            },
            range(1, 12)
        ),
        "meta" => (float)$metasInformeAnual[$claveGrafica],
        "acumulado" => (int)$totalesAplicacionAnual[$claveGrafica]
    ];
}
$filasVistaAnual = [];
for ($numeroFilaAnual = 2; $numeroFilaAnual <= 57; $numeroFilaAnual++) {
    $celdasFilaAnual = [];
    for ($numeroColumnaAnual = 1; $numeroColumnaAnual <= 25; $numeroColumnaAnual++) {
        if (isset($celdasCubiertasAnuales[$numeroFilaAnual . ":" . $numeroColumnaAnual])) {
            continue;
        }
        $coordenadaAnual = columnaInformeAnual($numeroColumnaAnual) . $numeroFilaAnual;
        $actualizacionAnual = $actualizacionesAnuales[$coordenadaAnual] ?? null;
        $contenidoAnual = $actualizacionAnual
            ? $actualizacionAnual["valor"]
            : $leerCeldaAnual($coordenadaAnual);
        $celdaPlantillaAnual = $celdasAnuales[$coordenadaAnual] ?? null;
        $claseMetaAnual = null;
        if ($numeroFilaAnual === 26 && in_array($numeroColumnaAnual, range(2, 12), true)) {
            $claseMetaAnual = $clavesPrimeraTabla[$numeroColumnaAnual - 2];
        } elseif ($numeroFilaAnual === 51 && in_array($numeroColumnaAnual, range(2, 10), true)) {
            $claseMetaAnual = $clavesSegundaTabla[$numeroColumnaAnual - 2];
        }
        $calculoAnual = $actualizacionAnual["calculo"] ?? null;
        $celdasFilaAnual[] = [
            "coordenada" => $coordenadaAnual,
            "contenido" => $contenidoAnual,
            "estilo" => $celdaPlantillaAnual ? $celdaPlantillaAnual->getAttribute("s") : "",
            "formatoNumero" => $celdaPlantillaAnual
                ? ($formatosNumeroAnuales[(int)$celdaPlantillaAnual->getAttribute("s")] ?? 0)
                : 0,
            "columnas" => $fusionesAnuales[$coordenadaAnual][0] ?? 1,
            "filas" => $fusionesAnuales[$coordenadaAnual][1] ?? 1,
            "meta" => $claseMetaAnual,
            "calculo" => $calculoAnual
        ];
    }
    $nodoFilaVistaAnual = $filasAnuales[$numeroFilaAnual] ?? null;
    $filasVistaAnual[] = [
        "numero" => $numeroFilaAnual,
        "alto" => $nodoFilaVistaAnual ? $nodoFilaVistaAnual->getAttribute("ht") : "",
        "celdas" => $celdasFilaAnual
    ];
}

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Informe anual | Aplicación de biológicos</title>
    <link rel="stylesheet" href="../css/tema_reportes.css?v=20261107-1">
    <style>
        :root { font-family: Arial, Helvetica, sans-serif; color: #233843; background: #f2f8fa; }
        * { box-sizing: border-box; }
        body { margin: 0; padding: 18px; }
        main { max-width: 1900px; margin: 0 auto; }
        .barra { display: flex; align-items: end; flex-wrap: wrap; gap: 10px; margin-bottom: 12px; padding: 12px; }
        .campo { display: grid; gap: 4px; font-size: 12px; font-weight: 700; }
        .campo select { width: 130px; }
        .barra button, .barra .boton { display: inline-flex; min-height: 40px; align-items: center; justify-content: center; gap: 7px; padding: 7px 12px; border: 1px solid #347f91; border-radius: 7px; background: #347f91; color: #fff; font: inherit; font-size: 12px; font-weight: 700; text-decoration: none; cursor: pointer; }
        .barra button svg, .barra .boton svg { width: 18px; height: 18px; fill: none; stroke: currentColor; stroke-width: 2; }
        .barra .boton-secundario { background: #e3f0f5; color: #285f70; }
        .barra .boton-secundario:hover { background: #c7eee2; color: #285f70; }
        .estado { align-self: center; color: #278a83; font-size: 12px; font-weight: 600; }
        .hoja-scroll { overflow: auto; border: 1px solid #7d9299; background: #fff; }
        .hoja-anual { width: max-content; min-width: 100%; border-collapse: collapse; table-layout: fixed; background: #fff; color: #111; font-family: Arial, Helvetica, sans-serif; }
        .hoja-anual td { min-width: 1px; height: 16px; padding: 1px 3px; border: 0; font-size: 9px; text-align: center; vertical-align: middle; white-space: pre-line; overflow-wrap: anywhere; }
        .hoja-anual td[data-col="A"], .hoja-anual td[data-col="C"], .hoja-anual td[data-col="O"] { text-align: left; }
        .meta-editable { display: block; width: 100%; min-width: 32px; padding: 1px 2px; border: 0; border-bottom: 1px dashed #7f9da8; border-radius: 0; background: transparent; color: inherit; font: inherit; text-align: center; }
        .meta-editable:focus { border-bottom: 2px solid #35afa8; outline: 0; background: #fff; }
        .encabezado-editable { display: inline-block; min-width: 72px; min-height: 1em; border-bottom: 1px dashed #7f9da8; outline: 0; }
        .encabezado-editable:focus { border-bottom: 2px solid #35afa8; background: #fff; }
        .grafica-panel { margin-top: 18px; padding: 18px; border: 1px solid #dce9ed; border-radius: 10px; background: #fff; box-shadow: 0 4px 16px rgba(40, 95, 112, .07); }
        .grafica-encabezado { display: flex; align-items: end; justify-content: space-between; flex-wrap: wrap; gap: 12px; }
        .grafica-encabezado h2 { margin: 0; color: #285f70; font-size: 20px; }
        .grafica-encabezado label { display: grid; gap: 4px; color: #285f70; font-size: 12px; font-weight: 700; }
        .grafica-encabezado select { min-width: 230px; min-height: 38px; padding: 7px; }
        .grafica-resumen { display: flex; gap: 22px; flex-wrap: wrap; margin: 14px 0 4px; color: #34495e; font-size: 13px; }
        .grafica-resumen strong { color: #285f70; }
        .grafica-svg { display: block; width: 100%; min-width: 620px; height: 310px; }
        .grafica-scroll { overflow-x: auto; }
        .leyenda-necesidades { margin: 8px 0 0; color: #647985; font-size: 12px; }
        @media print {
            @page { size: landscape; margin: 5mm; }
            body { padding: 0; background: #fff; }
            .barra, .grafica-panel, .estado, .boton-menu-principal { display: none !important; }
            .hoja-scroll { overflow: visible; border: 0; }
            .hoja-anual { width: 100%; min-width: 0; }
            .hoja-anual td { height: auto; padding: 0; font-size: 5px; }
            .meta-editable { min-width: 0; padding: 0; border: 0; background: transparent; }
        }
        @media (max-width: 600px) {
            body { padding: 10px; }
            .grafica-panel { padding: 13px; }
            .grafica-encabezado h2 { font-size: 18px; }
        }
    </style>
</head>
<body>
<main>
    <header class="encabezado-reporte">
        <div class="encabezado-reporte__contenido">
            <span class="encabezado-reporte__seccion">Informe anual</span>
            <h1>Aplicación de biológicos</h1>
            <p>Concentrado anual de dosis, metas y necesidades · <?php echo $anioInformeAnual; ?></p>
        </div>
    </header>
    <form method="get" action="anual.php">
        <div class="barra">
            <label class="campo" for="anio">Año del informe
                <select name="anio" id="anio">
                    <?php for ($opcionAnio = 2000; $opcionAnio <= (int)date("Y") + 1; $opcionAnio++): ?>
                        <option value="<?php echo $opcionAnio; ?>" <?php echo $anioInformeAnual === $opcionAnio ? "selected" : ""; ?>>
                            <?php echo $opcionAnio; ?>
                        </option>
                    <?php endfor; ?>
                </select>
            </label>
            <button type="submit" title="Consultar año" aria-label="Consultar año">
                <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="10.8" cy="10.8" r="6.8"></circle><path d="m16 16 5 5"></path></svg>
                Consultar
            </button>
            <a class="boton boton-secundario" href="mensual_general.php?anio=<?php echo $anioInformeAnual; ?>" title="Abrir informes mensuales">
                <svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="4" width="18" height="17" rx="2"></rect><path d="M16 2v4M8 2v4M3 10h18"></path></svg>
                Informe mensual
            </a>
            <button type="button" class="boton-secundario" title="Imprimir informe" aria-label="Imprimir informe" onclick="window.print()">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7 8V3h10v5M7 17H5a2 2 0 0 1-2-2v-4a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2v4a2 2 0 0 1-2 2h-2"></path><path d="M7 14h10v7H7zM18 11h.01"></path></svg>
                Imprimir
            </button>
            <span class="estado" aria-live="polite">
                <?php echo $soloConsulta
                    ? "Modo de consulta."
                    : (isset($_GET["guardado"]) ? "Metas guardadas." : "Las metas se guardan por unidad y año."); ?>
            </span>
        </div>
    </form>

    <form id="formulario-anual" method="post" action="anual.php">
        <input type="hidden" name="anio" value="<?php echo $anioInformeAnual; ?>">
        <input type="hidden" name="encabezados[C4]" data-encabezado-valor="C4" value="<?php echo escaparInformeAnual($actualizacionesAnuales["C4"]["valor"]); ?>">
        <input type="hidden" name="encabezados[C5]" data-encabezado-valor="C5" value="<?php echo escaparInformeAnual($actualizacionesAnuales["C5"]["valor"]); ?>">
        <div class="barra">
            <?php if (!$soloConsulta): ?>
            <button type="submit" name="guardar_metas" value="1" title="Guardar metas por unidad y año" aria-label="Guardar metas">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 3h12l4 4v14H3V3z"></path><path d="M7 3v6h10V3M7 21v-8h10v8"></path></svg>
                Guardar metas
            </button>
            <?php endif; ?>
            <button type="submit" name="descargar" value="1" title="Descargar formato anual oficial" aria-label="Descargar Excel anual">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3v12m0 0 5-5m-5 5-5-5"></path><path d="M5 17v4h14v-4"></path></svg>
                Descargar Excel
            </button>
            <span class="estado" id="estado-encabezados" aria-live="polite">Guarda las metas para incluir los encabezados en el paquete de la unidad.</span>
        </div>
        <div class="hoja-scroll">
            <table class="hoja-anual">
                <colgroup>
                    <?php foreach ($anchosAnuales as $anchoColumnaAnual): ?>
                        <col style="width: <?php echo number_format($anchoColumnaAnual, 2, ".", ""); ?>px">
                    <?php endforeach; ?>
                </colgroup>
                <tbody>
                    <?php foreach ($filasVistaAnual as $filaVistaAnual): ?>
                        <?php if (in_array($filaVistaAnual["numero"], [28, 29, 53, 54], true)) { continue; } ?>
                        <tr<?php if ($filaVistaAnual["alto"] !== ""): ?> style="height: <?php echo number_format((float)$filaVistaAnual["alto"] * 1.333, 2, ".", ""); ?>px"<?php endif; ?>>
                            <?php foreach ($filaVistaAnual["celdas"] as $celdaVistaAnual): ?>
                                <?php
                                $columnaCeldaAnual = preg_replace('/\d/', '', $celdaVistaAnual["coordenada"]);
                                $calculoCeldaAnual = $celdaVistaAnual["calculo"];
                                ?>
                                <td
                                    data-cell="<?php echo escaparInformeAnual($celdaVistaAnual["coordenada"]); ?>"
                                    data-col="<?php echo escaparInformeAnual($columnaCeldaAnual); ?>"
                                    data-formato-numero="<?php echo (int)$celdaVistaAnual["formatoNumero"]; ?>"
                                    <?php if ($celdaVistaAnual["estilo"] !== ""): ?>style="<?php echo escaparInformeAnual($estilosAnuales[(int)$celdaVistaAnual["estilo"]] ?? ""); ?>"<?php endif; ?>
                                    <?php if ($celdaVistaAnual["columnas"] > 1): ?>colspan="<?php echo (int)$celdaVistaAnual["columnas"]; ?>"<?php endif; ?>
                                    <?php if ($celdaVistaAnual["filas"] > 1): ?>rowspan="<?php echo (int)$celdaVistaAnual["filas"]; ?>"<?php endif; ?>
                                    <?php if ($calculoCeldaAnual): ?>
                                        data-calculo="<?php echo escaparInformeAnual($calculoCeldaAnual["tipo"]); ?>"
                                        data-meta-clave="<?php echo escaparInformeAnual($calculoCeldaAnual["clave"]); ?>"
                                        data-acumulado="<?php echo (int)($calculoCeldaAnual["acumulado"] ?? 0); ?>"
                                        <?php if (isset($calculoCeldaAnual["mes"])): ?>data-mes="<?php echo (int)$calculoCeldaAnual["mes"]; ?>"<?php endif; ?>
                                    <?php endif; ?>
                                >
                                    <?php if ($celdaVistaAnual["meta"] !== null): ?>
                                        <input
                                            class="meta-editable"
                                            type="number"
                                            min="0"
                                            max="9999999999.99"
                                            step="0.01"
                                            name="metas[<?php echo escaparInformeAnual($celdaVistaAnual["meta"]); ?>]"
                                            data-meta="<?php echo escaparInformeAnual($celdaVistaAnual["meta"]); ?>"
                                            aria-label="Meta anual de <?php echo escaparInformeAnual(str_replace("_", " ", $celdaVistaAnual["meta"])); ?>"
                                            value="<?php echo escaparInformeAnual(number_format((float)$metasInformeAnual[$celdaVistaAnual["meta"]], 2, ".", "")); ?>"
                                            <?php echo $soloConsulta ? "readonly" : ""; ?>
                                        >
                                    <?php elseif ($calculoCeldaAnual): ?>
                                        <?php
                                        if ($calculoCeldaAnual["tipo"] === "cobertura") {
                                            $valorCeldaAnual = $metasInformeAnual[$calculoCeldaAnual["clave"]] > 0
                                                ? numeroCeldaInformeAnual(
                                                    $calculoCeldaAnual["acumulado"]
                                                        / $metasInformeAnual[$calculoCeldaAnual["clave"]] * 100,
                                                    $celdaVistaAnual["formatoNumero"]
                                                )
                                                : "—";
                                        } else {
                                            $valorCeldaAnual = numeroCeldaInformeAnual(
                                                $celdaVistaAnual["contenido"],
                                                $celdaVistaAnual["formatoNumero"]
                                            );
                                        }
                                        ?>
                                        <span><?php echo escaparInformeAnual($valorCeldaAnual); ?></span>
                                    <?php elseif (in_array($celdaVistaAnual["coordenada"], ["C4", "C5"], true)): ?>
                                        <span
                                            class="encabezado-editable"
                                            contenteditable="<?php echo $soloConsulta ? "false" : "true"; ?>"
                                            role="textbox"
                                            aria-label="<?php echo $celdaVistaAnual["coordenada"] === "C4" ? "CLUES" : "Nombre de la unidad"; ?>"
                                            spellcheck="false"
                                            data-encabezado-editable="<?php echo escaparInformeAnual($celdaVistaAnual["coordenada"]); ?>"
                                        ><?php echo escaparInformeAnual($celdaVistaAnual["contenido"]); ?></span>
                                    <?php else: ?>
                                        <?php echo nl2br(escaparInformeAnual($celdaVistaAnual["contenido"])); ?>
                                    <?php endif; ?>
                                </td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </form>

    <section class="grafica-panel" aria-labelledby="titulo-grafica">
        <div class="grafica-encabezado">
            <h2 id="titulo-grafica">Necesidades y cobertura</h2>
            <label for="categoria-grafica">Biológico
                <select id="categoria-grafica">
                    <?php foreach ($categoriasGraficaAnual as $claveGrafica => $datosGrafica): ?>
                        <option value="<?php echo escaparInformeAnual($claveGrafica); ?>">
                            <?php echo escaparInformeAnual($datosGrafica["nombre"]); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
        </div>
        <div class="grafica-resumen" aria-live="polite">
            <span>Meta anual: <strong id="resumen-meta"></strong></span>
            <span>Dosis aplicadas: <strong id="resumen-aplicadas"></strong></span>
            <span>Cobertura anual: <strong id="resumen-cobertura"></strong></span>
        </div>
        <div class="grafica-scroll">
            <svg id="grafica-necesidades" class="grafica-svg" role="img" aria-label="Necesidades acumuladas por mes"></svg>
        </div>
        <p class="leyenda-necesidades">Barras sobre cero: dosis que faltan para la meta acumulada. Barras bajo cero: dosis aplicadas por encima de esa meta.</p>
    </section>
</main>
<script id="datos-grafica" type="application/json"><?php
echo json_encode(
    $categoriasGraficaAnual,
    JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
);
?></script>
<script>
    (function () {
        "use strict";
        const campos = document.querySelectorAll("[data-encabezado-editable]");
        const estado = document.getElementById("estado-encabezados");
        const unidad = <?php
            echo json_encode(
                (int)$idUnidadInformeAnual,
                JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
            );
        ?>;
        const claveAlmacenamiento = "informe-anual-encabezados-v1-" + unidad;

        function sincronizarCampo(campo) {
            const coordenada = campo.dataset.encabezadoEditable;
            const texto = campo.textContent.replace(/\s+/g, " ").trim().slice(0, 150);
            if (campo.textContent !== texto) {
                campo.textContent = texto;
            }
            const campoOculto = document.querySelector(
                '[data-encabezado-valor="' + coordenada + '"]'
            );
            if (campoOculto) {
                campoOculto.value = texto;
            }
            return texto;
        }

        try {
            const guardados = JSON.parse(localStorage.getItem(claveAlmacenamiento) || "{}");
            campos.forEach(function (campo) {
                const valor = guardados[campo.dataset.encabezadoEditable];
                if (typeof valor === "string" && valor.length <= 150) {
                    campo.textContent = valor;
                }
                sincronizarCampo(campo);
                campo.addEventListener("input", function () {
                    const valoresActuales = {};
                    campos.forEach(function (elemento) {
                        valoresActuales[elemento.dataset.encabezadoEditable] =
                            sincronizarCampo(elemento);
                    });
                    try {
                        localStorage.setItem(
                            claveAlmacenamiento,
                            JSON.stringify(valoresActuales)
                        );
                        estado.textContent = "Guardados en este navegador; presiona «Guardar metas» para incluirlos en el paquete.";
                    } catch (_error) {
                        estado.textContent = "No fue posible guardar los encabezados en este navegador.";
                    }
                });
                campo.addEventListener("keydown", function (evento) {
                    if (evento.key === "Enter") {
                        evento.preventDefault();
                        campo.blur();
                    }
                });
            });
            document.getElementById("formulario-anual").addEventListener("submit", function () {
                campos.forEach(sincronizarCampo);
            });
        } catch (_error) {
            estado.textContent = "No fue posible recuperar los encabezados guardados en este navegador.";
            campos.forEach(sincronizarCampo);
        }
    })();
</script>
<script>
    (function () {
        "use strict";
        const datos = JSON.parse(document.getElementById("datos-grafica").textContent);
        const selector = document.getElementById("categoria-grafica");
        const grafica = document.getElementById("grafica-necesidades");
        const resumenMeta = document.getElementById("resumen-meta");
        const resumenAplicadas = document.getElementById("resumen-aplicadas");
        const resumenCobertura = document.getElementById("resumen-cobertura");
        const meses = ["Ene", "Feb", "Mar", "Abr", "May", "Jun", "Jul", "Ago", "Sep", "Oct", "Nov", "Dic"];
        const ns = "http://www.w3.org/2000/svg";

        function formatear(valor) {
            return new Intl.NumberFormat("es-MX", { maximumFractionDigits: 2 }).format(valor);
        }

        function formatearCelda(valor, formato) {
            const decimales = [1, 3].includes(formato) ? 0 : ([2, 4].includes(formato) ? 2 : 2);
            return new Intl.NumberFormat("es-MX", {
                minimumFractionDigits: decimales,
                maximumFractionDigits: decimales,
                useGrouping: [3, 4].includes(formato)
            }).format(valor);
        }

        function svgElemento(nombre, atributos, texto) {
            const elemento = document.createElementNS(ns, nombre);
            Object.keys(atributos).forEach(function (clave) {
                elemento.setAttribute(clave, atributos[clave]);
            });
            if (texto !== undefined) {
                elemento.textContent = texto;
            }
            return elemento;
        }

        function actualizarCalculos() {
            document.querySelectorAll("[data-calculo][data-meta-clave]").forEach(function (celda) {
                const clave = celda.dataset.metaClave;
                const campoMeta = document.querySelector('[data-meta="' + clave + '"]');
                const meta = Number(campoMeta ? campoMeta.value : 0);
                const acumulado = Number(celda.dataset.acumulado || 0);
                const mes = Number(celda.dataset.mes || 0);
                let valor = 0;
                if (celda.dataset.calculo === "necesidad") {
                    valor = (meta / 12 * mes) - acumulado;
                } else if (celda.dataset.calculo === "meta-mensual") {
                    valor = meta / 12;
                } else if (celda.dataset.calculo === "cobertura") {
                    celda.textContent = meta > 0
                        ? formatearCelda(acumulado / meta * 100, Number(celda.dataset.formatoNumero))
                        : "—";
                    return;
                }
                celda.textContent = formatearCelda(valor, Number(celda.dataset.formatoNumero));
            });
            pintarGrafica();
        }

        function pintarGrafica() {
            const clave = selector.value;
            const categoria = datos[clave];
            const campoMeta = document.querySelector('[data-meta="' + clave + '"]');
            const meta = Number(campoMeta ? campoMeta.value : categoria.meta);
            const necesidades = categoria.necesidades.map(function (_valor, indice) {
                const mes = indice + 1;
                const celda = document.querySelector(
                    '[data-calculo="necesidad"][data-meta-clave="' + clave + '"][data-mes="' + mes + '"]'
                );
                return celda
                    ? (meta / 12 * mes) - Number(celda.dataset.acumulado || 0)
                    : 0;
            });
            const ancho = 920;
            const alto = 310;
            const margen = { arriba: 18, derecha: 20, abajo: 42, izquierda: 64 };
            const altoGrafica = alto - margen.arriba - margen.abajo;
            const anchoGrafica = ancho - margen.izquierda - margen.derecha;
            let minimo = Math.min(0, ...necesidades);
            let maximo = Math.max(0, ...necesidades);
            if (minimo === maximo) {
                maximo = 1;
            }
            const rango = maximo - minimo;
            const y = function (valor) {
                return margen.arriba + ((maximo - valor) / rango) * altoGrafica;
            };
            const cero = y(0);
            grafica.replaceChildren();
            grafica.setAttribute("viewBox", "0 0 " + ancho + " " + alto);
            grafica.appendChild(svgElemento("line", {
                x1: margen.izquierda, y1: margen.arriba + altoGrafica,
                x2: margen.izquierda + anchoGrafica, y2: margen.arriba + altoGrafica,
                stroke: "#9bb0b7", "stroke-width": "1"
            }));
            for (let division = 0; division <= 4; division++) {
                const valorEje = maximo - rango * division / 4;
                const yEje = y(valorEje);
                grafica.appendChild(svgElemento("line", {
                    x1: margen.izquierda, y1: yEje,
                    x2: margen.izquierda + anchoGrafica, y2: yEje,
                    stroke: division === 0 || division === 4 ? "#dce9ed" : "#eef3f5",
                    "stroke-width": "1"
                }));
                grafica.appendChild(svgElemento("text", {
                    x: margen.izquierda - 9, y: yEje + 4,
                    "text-anchor": "end", fill: "#647985", "font-size": "11"
                }, formatear(valorEje)));
            }
            grafica.appendChild(svgElemento("line", {
                x1: margen.izquierda, y1: cero,
                x2: margen.izquierda + anchoGrafica, y2: cero,
                stroke: "#647985", "stroke-width": "1.5"
            }));
            const espacioMes = anchoGrafica / 12;
            necesidades.forEach(function (necesidad, indice) {
                const anchoBarra = espacioMes * 0.58;
                const x = margen.izquierda + espacioMes * indice + (espacioMes - anchoBarra) / 2;
                const valorY = y(necesidad);
                grafica.appendChild(svgElemento("rect", {
                    x: x,
                    y: Math.min(cero, valorY),
                    width: anchoBarra,
                    height: Math.max(1, Math.abs(cero - valorY)),
                    rx: "3",
                    fill: necesidad < 0 ? "#b8424d" : "#35afa8"
                }));
                grafica.appendChild(svgElemento("text", {
                    x: margen.izquierda + espacioMes * indice + espacioMes / 2,
                    y: alto - 16,
                    "text-anchor": "middle",
                    fill: "#647985",
                    "font-size": "11"
                }, meses[indice]));
            });
            resumenMeta.textContent = formatear(meta);
            resumenAplicadas.textContent = formatear(categoria.acumulado);
            resumenCobertura.textContent = meta > 0
                ? formatear(categoria.acumulado / meta * 100) + "%"
                : "Sin meta";
        }

        document.querySelectorAll("[data-meta]").forEach(function (campo) {
            campo.addEventListener("input", actualizarCalculos);
        });
        selector.addEventListener("change", pintarGrafica);
        pintarGrafica();
    })();
</script>
</body>
</html>
