<?php
require_once __DIR__ . "/../config/seguridad.php";

if (!isset($_SESSION["id_usuario"])) {
    header("Location: ../login.php");
    exit();
}

$mesesInformeInfluenza = [
    1 => "ENERO", 2 => "FEBRERO", 3 => "MARZO", 4 => "ABRIL",
    5 => "MAYO", 6 => "JUNIO", 7 => "JULIO", 8 => "AGOSTO",
    9 => "SEPTIEMBRE", 10 => "OCTUBRE", 11 => "NOVIEMBRE", 12 => "DICIEMBRE"
];
$mesesTemporadaInfluenza = [10, 11, 12, 1, 2, 3];
$mesActualInfluenza = (int)date("n");
$mesPredeterminadoInfluenza = in_array($mesActualInfluenza, $mesesTemporadaInfluenza, true)
    ? $mesActualInfluenza
    : 3;
$mesValorInfluenza = $_GET["mes"] ?? $mesPredeterminadoInfluenza;
$anioValorInfluenza = $_GET["anio"] ?? (int)date("Y");
$mes = (is_string($mesValorInfluenza) || is_int($mesValorInfluenza))
    ? filter_var($mesValorInfluenza, FILTER_VALIDATE_INT)
    : false;
$anio = (is_string($anioValorInfluenza) || is_int($anioValorInfluenza))
    ? filter_var($anioValorInfluenza, FILTER_VALIDATE_INT)
    : false;
$mesNoDisponibleInfluenza = $mes === false
    || !in_array($mes, $mesesTemporadaInfluenza, true);
if ($mesNoDisponibleInfluenza) {
    $mes = $mesPredeterminadoInfluenza;
}

if (
    $anio === false
    || $anio < 2000
    || $anio > 2100
) {
    http_response_code(400);
    exit("El año indicado para el informe mensual de influenza no es válido.");
}

if (!defined("INFORME_MENSUAL_INFLUENZA_SOLICITANDO_CONTEOS")) {
    define("INFORME_MENSUAL_INFLUENZA_SOLICITANDO_CONTEOS", true);
}
$datosPaloteoInfluenza = require __DIR__ . "/../paloteo/paloteo_influenza.php";
if (!is_array($datosPaloteoInfluenza)) {
    die("No fue posible obtener los conteos de influenza del periodo.");
}

function escaparMensualInfluenza($valor)
{
    return htmlspecialchars((string)$valor, ENT_QUOTES, "UTF-8");
}

function columnaNumeroMensualInfluenza($columna)
{
    $numero = 0;
    foreach (str_split($columna) as $letra) {
        $numero = $numero * 26 + ord($letra) - 64;
    }
    return $numero;
}

function columnaLetraMensualInfluenza($numero)
{
    $columna = "";
    while ($numero > 0) {
        $residuo = ($numero - 1) % 26;
        $columna = chr(65 + $residuo) . $columna;
        $numero = intdiv($numero - 1, 26);
    }
    return $columna;
}

function leerCeldaMensualInfluenza($celdas, $cadenas, $coordenada)
{
    if (!isset($celdas[$coordenada])) {
        return "";
    }
    $celda = $celdas[$coordenada];
    $espacio = "http://schemas.openxmlformats.org/spreadsheetml/2006/main";
    if ($celda->getAttribute("t") === "inlineStr") {
        $texto = $celda->getElementsByTagNameNS($espacio, "t")->item(0);
        return $texto ? $texto->textContent : "";
    }
    $valor = $celda->getElementsByTagNameNS($espacio, "v")->item(0);
    if (!$valor) {
        return "";
    }
    if ($celda->getAttribute("t") === "s") {
        $indice = (int)$valor->textContent;
        if (!array_key_exists($indice, $cadenas)) {
            die("La plantilla mensual de influenza contiene una cadena inválida.");
        }
        return $cadenas[$indice];
    }
    return $valor->textContent;
}

function asignarCeldaMensualInfluenza($documento, $xpath, $coordenada, $valor, $esTexto)
{
    $espacio = "http://schemas.openxmlformats.org/spreadsheetml/2006/main";
    preg_match('/^([A-Z]+)(\d+)$/', $coordenada, $partes);
    $numeroFila = (int)$partes[2];
    $fila = $xpath->query("//x:sheetData/x:row[@r='{$numeroFila}']")->item(0);
    if (!$fila) {
        die("La plantilla mensual de influenza no contiene la fila " . $numeroFila . ".");
    }
    $celda = $xpath->query("./x:c[@r='{$coordenada}']", $fila)->item(0);
    if (!$celda) {
        $celda = $documento->createElementNS($espacio, "c");
        $celda->setAttribute("r", $coordenada);
        $numeroColumna = columnaNumeroMensualInfluenza($partes[1]);
        $insertada = false;
        foreach ($xpath->query("./x:c", $fila) as $otraCelda) {
            preg_match('/^([A-Z]+)/', $otraCelda->getAttribute("r"), $columnaExistente);
            if (columnaNumeroMensualInfluenza($columnaExistente[1]) > $numeroColumna) {
                $fila->insertBefore($celda, $otraCelda);
                $insertada = true;
                break;
            }
        }
        if (!$insertada) {
            $fila->appendChild($celda);
        }
    }

    while ($celda->firstChild) {
        $celda->removeChild($celda->firstChild);
    }
    if ($esTexto) {
        $celda->setAttribute("t", "inlineStr");
        $inline = $documento->createElementNS($espacio, "is");
        $texto = $documento->createElementNS($espacio, "t");
        $texto->setAttributeNS(
            "http://www.w3.org/XML/1998/namespace",
            "xml:space",
            "preserve"
        );
        $texto->appendChild($documento->createTextNode((string)$valor));
        $inline->appendChild($texto);
        $celda->appendChild($inline);
    } else {
        $celda->removeAttribute("t");
        $celda->appendChild(
            $documento->createElementNS($espacio, "v", (string)(int)$valor)
        );
    }
}

$rutaPlantillaInfluenza = __DIR__ . "/../mensual_influenza.xlsx";
if (!is_file($rutaPlantillaInfluenza)) {
    die("No se encontró la plantilla mensual de influenza.");
}
$archivoPlantilla = new ZipArchive();
if ($archivoPlantilla->open($rutaPlantillaInfluenza, ZipArchive::RDONLY) !== true) {
    die("No fue posible abrir la plantilla mensual de influenza.");
}
$xmlHojaInfluenza = $archivoPlantilla->getFromName("xl/worksheets/sheet1.xml");
$xmlCadenasInfluenza = $archivoPlantilla->getFromName("xl/sharedStrings.xml");
$archivoPlantilla->close();
if ($xmlHojaInfluenza === false || $xmlCadenasInfluenza === false) {
    die("La plantilla mensual de influenza no contiene las hojas requeridas.");
}

$espacioExcelInfluenza = "http://schemas.openxmlformats.org/spreadsheetml/2006/main";
$documentoHojaInfluenza = new DOMDocument();
$documentoCadenasInfluenza = new DOMDocument();
if (
    !$documentoHojaInfluenza->loadXML($xmlHojaInfluenza, LIBXML_NONET)
    || !$documentoCadenasInfluenza->loadXML($xmlCadenasInfluenza, LIBXML_NONET)
) {
    die("No fue posible leer la plantilla mensual de influenza.");
}
$xpathHojaInfluenza = new DOMXPath($documentoHojaInfluenza);
$xpathHojaInfluenza->registerNamespace("x", $espacioExcelInfluenza);
$celdasInfluenza = [];
$filasInfluenza = [];
foreach ($xpathHojaInfluenza->query("//x:sheetData/x:row") as $filaInfluenza) {
    $numeroFila = (int)$filaInfluenza->getAttribute("r");
    $filasInfluenza[$numeroFila] = $filaInfluenza;
    foreach ($xpathHojaInfluenza->query("./x:c", $filaInfluenza) as $celdaInfluenza) {
        $celdasInfluenza[$celdaInfluenza->getAttribute("r")] = $celdaInfluenza;
    }
}
$xpathCadenasInfluenza = new DOMXPath($documentoCadenasInfluenza);
$xpathCadenasInfluenza->registerNamespace("x", $espacioExcelInfluenza);
$cadenasInfluenza = [];
foreach ($xpathCadenasInfluenza->query("//x:si") as $cadenaInfluenza) {
    $textoCadena = "";
    foreach ($xpathCadenasInfluenza->query(".//x:t", $cadenaInfluenza) as $texto) {
        $textoCadena .= $texto->textContent;
    }
    $cadenasInfluenza[] = $textoCadena;
}

$iniciosMesesInfluenza = [];
foreach ($filasInfluenza as $numeroFila => $_fila) {
    if (str_starts_with(
        trim(leerCeldaMensualInfluenza(
            $celdasInfluenza,
            $cadenasInfluenza,
            "A" . $numeroFila
        )),
        "Informe Mensual de Unidad"
    )) {
        $mesPlantilla = mb_strtoupper(
            trim(leerCeldaMensualInfluenza(
                $celdasInfluenza,
                $cadenasInfluenza,
                "C" . ($numeroFila + 4)
            )),
            "UTF-8"
        );
        if (in_array($mesPlantilla, $mesesInformeInfluenza, true)) {
            $iniciosMesesInfluenza[$mesPlantilla] = $numeroFila;
        }
    }
}
$nombreMesInforme = $mesesInformeInfluenza[$mes];
if (!isset($iniciosMesesInfluenza[$nombreMesInforme])) {
    die("No se encontró el mes seleccionado en la plantilla de influenza.");
}
$inicioBloqueInfluenza = $iniciosMesesInfluenza[$nombreMesInforme];
$iniciosOrdenadosInfluenza = array_values($iniciosMesesInfluenza);
sort($iniciosOrdenadosInfluenza);
$finBloqueInfluenza = max($filasInfluenza);
foreach ($iniciosOrdenadosInfluenza as $inicio) {
    if ($inicio > $inicioBloqueInfluenza) {
        $finBloqueInfluenza = $inicio - 1;
        break;
    }
}

$conteosInfluenza = $datosPaloteoInfluenza["conteos"];
$mapaCodigoInfluenza = [
    "BIE01" => ["influenza_primera_6_11m"],
    "BIE28" => ["influenza_primera_12_23m"],
    "BIE29" => ["influenza_primera_24_35m"],
    "BIE30" => ["influenza_primera_36_47m"],
    "BIE31" => ["influenza_primera_48_59m"],
    "BIE04" => ["influenza_segunda_7_11m"],
    "BIE32" => ["influenza_segunda_12_23m"],
    "BIE33" => ["influenza_segunda_24_35m"],
    "BIE34" => ["influenza_segunda_36_47m"],
    "BIE35" => ["influenza_segunda_48_59m"],
    "BIE36" => ["influenza_anual_18_23m"],
    "BIE37" => ["influenza_anual_24_35m"],
    "BIE38" => ["influenza_anual_36_47m"],
    "BIE39" => ["influenza_anual_48_59m"],
    "BIE40" => ["influenza_anual_60_plus"],
    "BIO96" => ["influenza_riesgo_embarazo"],
    "BIO97" => ["influenza_riesgo_salud"],
    "BIE09" => ["influenza_riesgo_vih_5_9"],
    "BIE10" => ["influenza_riesgo_vih_10_19"],
    "BIE41" => ["influenza_riesgo_vih_20_59"],
    "BIE12" => ["influenza_riesgo_diabetes_5_9"],
    "BIE13" => ["influenza_riesgo_diabetes_10_19"],
    "BIE42" => ["influenza_riesgo_diabetes_20_59"],
    "BIE15" => ["influenza_riesgo_obesidad_5_9"],
    "BIE16" => ["influenza_riesgo_obesidad_10_19"],
    "BIE43" => ["influenza_riesgo_obesidad_20_59"],
    "BIE18" => ["influenza_riesgo_cardiopatias_5_9"],
    "BIE19" => ["influenza_riesgo_cardiopatias_10_19"],
    "BIE44" => ["influenza_riesgo_cardiopatias_20_59"],
    "BIE48" => ["influenza_riesgo_pulmonar_5_9"],
    "BIE49" => ["influenza_riesgo_pulmonar_10_19"],
    "BIE50" => ["influenza_riesgo_pulmonar_20_59"],
    "BIE24" => ["influenza_riesgo_cancer_5_9"],
    "BIE25" => ["influenza_riesgo_cancer_10_19"],
    "BIE46" => ["influenza_riesgo_cancer_20_59"],
    "BIE51" => ["influenza_riesgo_congenitas_5_9"],
    "BIE52" => ["influenza_riesgo_congenitas_10_19"],
    "BIE53" => ["influenza_riesgo_renal_5_9"],
    "BIE54" => ["influenza_riesgo_renal_10_19"],
    "BIE55" => ["influenza_riesgo_renal_20_59"],
    "BIE56" => ["influenza_riesgo_inmunosupresion_5_9"],
    "BIE57" => ["influenza_riesgo_inmunosupresion_10_19"],
    "BIE58" => ["influenza_riesgo_inmunosupresion_20_59"],
    "BIE59" => ["influenza_riesgo_otros_5_9"],
    "BIE60" => ["influenza_riesgo_otros_10_19"],
    "BIE61" => ["influenza_riesgo_otros_20_59"]
];
$conteosPorCodigoInfluenza = [];
$idsRepresentadosInfluenza = [];
foreach ($mapaCodigoInfluenza as $codigo => $idsFila) {
    $totalCodigo = 0;
    foreach ($idsFila as $idFila) {
        $idsRepresentadosInfluenza[$idFila] = true;
        $conteoFila = $conteosInfluenza[$idFila] ?? null;
        if ($conteoFila) {
            $totalCodigo += (int)$conteoFila["no_migrantes"]
                + (int)$conteoFila["migrantes"];
        }
    }
    $conteosPorCodigoInfluenza[$codigo] = $totalCodigo;
}
$conteosSinRenglonInfluenza = [];
foreach ($conteosInfluenza as $idFila => $conteoFila) {
    if (!isset($idsRepresentadosInfluenza[$idFila])) {
        $totalNoRepresentado = (int)$conteoFila["no_migrantes"]
            + (int)$conteoFila["migrantes"];
        if ($totalNoRepresentado > 0) {
            $conteosSinRenglonInfluenza[$idFila] = $totalNoRepresentado;
        }
    }
}

$cabeceraInfluenza = [
    "clues" => $_GET["clues"] ?? leerCeldaMensualInfluenza(
        $celdasInfluenza,
        $cadenasInfluenza,
        "C" . ($inicioBloqueInfluenza + 2)
    ),
    "unidad" => $_GET["unidad"] ?? $datosPaloteoInfluenza["nombreUnidad"],
    "responsable" => $_GET["responsable"] ?? $datosPaloteoInfluenza["nombreResponsable"]
];
foreach ($cabeceraInfluenza as $campo => $valor) {
    if (
        !is_string($valor)
        || mb_strlen($valor, "UTF-8") > ($campo === "clues" ? 20 : 150)
    ) {
        $cabeceraInfluenza[$campo] = "";
    } else {
        $cabeceraInfluenza[$campo] = trim($valor);
    }
}
$sobrescriturasCabeceraInfluenza = [
    "C" . ($inicioBloqueInfluenza + 2) => $cabeceraInfluenza["clues"],
    "C" . ($inicioBloqueInfluenza + 3) => $cabeceraInfluenza["unidad"],
    "C" . ($inicioBloqueInfluenza + 4) => $nombreMesInforme,
    "I" . ($inicioBloqueInfluenza + 4) => (string)$anio
];

$valoresCeldasInfluenza = [];
foreach ($celdasInfluenza as $coordenada => $celda) {
    if (!preg_match('/^([A-Z]+)(\d+)$/', $coordenada, $partes)) {
        continue;
    }
    $numeroFila = (int)$partes[2];
    $columna = $partes[1];
    if ($numeroFila < $inicioBloqueInfluenza || $numeroFila > $finBloqueInfluenza) {
        continue;
    }
    $valor = leerCeldaMensualInfluenza($celdasInfluenza, $cadenasInfluenza, $coordenada);
    if ($columna === "I" && isset($celdasInfluenza["H" . $numeroFila])) {
        $codigo = trim(leerCeldaMensualInfluenza(
            $celdasInfluenza,
            $cadenasInfluenza,
            "H" . $numeroFila
        ));
        if (isset($conteosPorCodigoInfluenza[$codigo])) {
            $valor = (string)$conteosPorCodigoInfluenza[$codigo];
        }
    }
    if (isset($sobrescriturasCabeceraInfluenza[$coordenada])) {
        $valor = $sobrescriturasCabeceraInfluenza[$coordenada];
    }
    $valoresCeldasInfluenza[$coordenada] = $valor;
}

$fusionesInfluenza = [];
foreach ($xpathHojaInfluenza->query("//x:mergeCells/x:mergeCell") as $fusion) {
    if (!preg_match('/^([A-Z]+)(\d+):([A-Z]+)(\d+)$/', $fusion->getAttribute("ref"), $partes)) {
        continue;
    }
    $colInicio = columnaNumeroMensualInfluenza($partes[1]);
    $filaInicio = (int)$partes[2];
    $colFin = columnaNumeroMensualInfluenza($partes[3]);
    $filaFin = (int)$partes[4];
    if ($filaFin < $inicioBloqueInfluenza || $filaInicio > $finBloqueInfluenza) {
        continue;
    }
    $fusionesInfluenza[$partes[1] . $filaInicio] = [
        $colFin - $colInicio + 1,
        $filaFin - $filaInicio + 1
    ];
}
$cubiertasInfluenza = [];
foreach ($fusionesInfluenza as $coordenada => [$columnasFusion, $filasFusion]) {
    preg_match('/^([A-Z]+)(\d+)$/', $coordenada, $partes);
    $columnaInicio = columnaNumeroMensualInfluenza($partes[1]);
    $filaInicio = (int)$partes[2];
    for ($filaFusion = $filaInicio; $filaFusion < $filaInicio + $filasFusion; $filaFusion++) {
        for ($columnaFusion = $columnaInicio; $columnaFusion < $columnaInicio + $columnasFusion; $columnaFusion++) {
            if ($filaFusion !== $filaInicio || $columnaFusion !== $columnaInicio) {
                $cubiertasInfluenza[columnaLetraMensualInfluenza($columnaFusion) . $filaFusion] = true;
            }
        }
    }
}

if (($_GET["descargar"] ?? "") === "1") {
    if ($datosPaloteoInfluenza["aplicacionesSinClasificar"] > 0) {
        http_response_code(422);
        exit("No se puede generar el informe: hay aplicaciones de influenza sin clasificar. Revisa el paloteo del periodo.");
    }
    if ($conteosSinRenglonInfluenza) {
        http_response_code(422);
        exit("No se puede generar el informe: hay conteos clasificados que no tienen un renglón equivalente en la plantilla oficial de influenza.");
    }

    $documentoDescarga = new DOMDocument();
    if (!$documentoDescarga->loadXML($xmlHojaInfluenza, LIBXML_NONET)) {
        die("No fue posible preparar la hoja del informe mensual de influenza.");
    }
    $xpathDescarga = new DOMXPath($documentoDescarga);
    $xpathDescarga->registerNamespace("x", $espacioExcelInfluenza);
    foreach ($xpathDescarga->query("//x:sheetData/x:row") as $filaInfluenza) {
        $numeroFila = (int)$filaInfluenza->getAttribute("r");
        if ($numeroFila < $inicioBloqueInfluenza || $numeroFila > $finBloqueInfluenza) {
            $filaInfluenza->setAttribute("hidden", "1");
        } else {
            $filaInfluenza->removeAttribute("hidden");
        }
    }
    foreach ($conteosPorCodigoInfluenza as $codigo => $total) {
        foreach ($filasInfluenza as $numeroFila => $_fila) {
            if (
                $numeroFila >= $inicioBloqueInfluenza
                && $numeroFila <= $finBloqueInfluenza
                && trim(leerCeldaMensualInfluenza(
                    $celdasInfluenza,
                    $cadenasInfluenza,
                    "H" . $numeroFila
                )) === $codigo
            ) {
                asignarCeldaMensualInfluenza(
                    $documentoDescarga,
                    $xpathDescarga,
                    "I" . $numeroFila,
                    $total,
                    false
                );
            }
        }
    }
    foreach ($sobrescriturasCabeceraInfluenza as $coordenada => $valor) {
        asignarCeldaMensualInfluenza(
            $documentoDescarga,
            $xpathDescarga,
            $coordenada,
            $valor,
            !str_starts_with($coordenada, "I")
        );
    }
    foreach ($xpathDescarga->query("//x:sheetViews/x:sheetView") as $vista) {
        $vista->setAttribute("topLeftCell", "A" . $inicioBloqueInfluenza);
    }

    $archivoSalida = tempnam(sys_get_temp_dir(), "mensual_influenza_");
    if ($archivoSalida === false || !copy($rutaPlantillaInfluenza, $archivoSalida)) {
        if ($archivoSalida !== false) {
            unlink($archivoSalida);
        }
        die("No fue posible preparar el archivo mensual de influenza.");
    }
    $libroSalida = new ZipArchive();
    if ($libroSalida->open($archivoSalida) !== true) {
        unlink($archivoSalida);
        die("No fue posible abrir la copia del informe mensual de influenza.");
    }
    $guardadoHoja = $libroSalida->addFromString(
        "xl/worksheets/sheet1.xml",
        $documentoDescarga->saveXML()
    );
    $cerradoLibro = $libroSalida->close();
    if (!$guardadoHoja || !$cerradoLibro) {
        unlink($archivoSalida);
        die("No fue posible guardar los conteos en la plantilla mensual de influenza.");
    }

    header("Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet");
    header(
        "Content-Disposition: attachment; filename=\"mensual_influenza_"
        . $anio . "_" . sprintf("%02d", $mes) . ".xlsx\""
    );
    header("Content-Length: " . filesize($archivoSalida));
    readfile($archivoSalida);
    unlink($archivoSalida);
    exit();
}

$alturasFilasInfluenza = [];
foreach ($filasInfluenza as $numeroFila => $filaInfluenza) {
    if ($numeroFila >= $inicioBloqueInfluenza && $numeroFila <= $finBloqueInfluenza) {
        $alturasFilasInfluenza[$numeroFila] = $filaInfluenza->getAttribute("ht");
    }
}
$anchosColumnasInfluenza = array_fill(1, 9, 80);
foreach ($xpathHojaInfluenza->query("//x:cols/x:col") as $columna) {
    $ancho = $columna->hasAttribute("width")
        ? (float)$columna->getAttribute("width") * 7
        : 80;
    for (
        $numeroColumna = (int)$columna->getAttribute("min");
        $numeroColumna <= (int)$columna->getAttribute("max");
        $numeroColumna++
    ) {
        if ($numeroColumna <= 9) {
            $anchosColumnasInfluenza[$numeroColumna] = $ancho;
        }
    }
}

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mensual de influenza | Informe mensual</title>
    <link rel="stylesheet" href="../css/tema_reportes.css?v=20261105-4">
    <style>
        :root { font-family: Arial, Helvetica, sans-serif; color: #233843; background: #f2f8fa; }
        * { box-sizing: border-box; }
        body { margin: 0; padding: 18px; }
        main { max-width: 1500px; margin: 0 auto; }
        .barra { display: flex; align-items: end; flex-wrap: wrap; gap: 10px; margin-bottom: 12px; }
        .campo { display: grid; gap: 4px; font-size: 12px; font-weight: 700; }
        select, input { min-height: 36px; padding: 7px 9px; border: 1px solid #b8cdd4; border-radius: 4px; background: #fff; color: inherit; font: inherit; }
        .campo-periodo select { width: 130px; }
        button, .boton { display: inline-flex; min-height: 36px; align-items: center; justify-content: center; padding: 7px 13px; border: 1px solid #347f91; border-radius: 4px; background: #347f91; color: #fff; font: inherit; font-size: 12px; font-weight: 700; text-decoration: none; cursor: pointer; }
        .boton-secundario { background: #e3f0f5; color: #285f70; }
        .formato-scroll { overflow: auto; border: 1px solid #7d9299; background: #fff; }
        table.formato-influenza { width: max-content; min-width: 100%; table-layout: fixed; border-collapse: collapse; background: #fff; }
        .formato-influenza td { height: 22px; padding: 3px 5px; border: 1px solid #81949b; color: #111; font-size: 12px; white-space: pre-line; overflow-wrap: anywhere; }
        .formato-influenza tr:first-child td { height: 34px; background: #dcebf0; font-size: 16px; font-weight: 700; text-align: center; }
        .formato-influenza td[data-col="I"], .formato-influenza td[data-col="H"] { text-align: center; }
        .formato-influenza td[data-col="A"], .formato-influenza td[data-col="C"], .formato-influenza td[data-col="F"] { text-align: left; }
        .formato-influenza tr:nth-child(7) td { background: #e3f0f5; font-weight: 700; text-align: center; }
        .dato-encabezado { display: block; width: 100%; min-width: 90px; padding: 3px 4px; border: 0; border-bottom: 1px dashed #7f9da8; border-radius: 0; background: transparent; color: #233843; font: inherit; }
        .dato-encabezado:hover { border-bottom-color: #347f91; background: #f5fafb; }
        .dato-encabezado:focus { border-bottom: 2px solid #35afa8; outline: 0; background: #fff; box-shadow: 0 2px 0 rgba(53, 175, 168, .12); }
        .estado-guardado { align-self: center; margin: 0; color: #278a83; font-size: 12px; font-weight: 600; }
        .estado-guardado--error { color: #b8424d; }
        .mensaje-error { margin: 0 0 12px; padding: 10px 12px; border: 1px solid #b8424d; background: #fceDEF; color: #76313a; }
        @media print {
            @page { size: landscape; margin: 6mm; }
            body { padding: 0; background: #fff; }
            .barra, .mensaje-error, .estado-guardado { display: none !important; }
            .formato-scroll { overflow: visible; border: 0; }
            table.formato-influenza { width: 100%; min-width: 0; }
            .formato-influenza td { height: auto; padding: 1px 2px; font-size: 7px; }
            .dato-encabezado { min-width: 0; padding: 0; border: 0; background: transparent; color: #111; }
        }
    </style>
</head>
<body>
<main>
    <header class="encabezado-reporte">
        <div class="encabezado-reporte__contenido">
            <span class="encabezado-reporte__seccion">Informe mensual</span>
            <h1>Aplicación de influenza</h1>
            <p>Temporada de octubre a marzo · <?php echo escaparMensualInfluenza($nombreMesInforme . " " . $anio); ?></p>
        </div>
    </header>
    <form id="formulario-mensual-influenza" method="get" action="mensual_influenza.php">
        <div class="barra">
            <label class="campo campo-periodo" for="mes">Mes de informe
                <select name="mes" id="mes">
                    <?php foreach ($mesesTemporadaInfluenza as $mesDisponible): ?>
                        <option value="<?php echo $mesDisponible; ?>" <?php echo $mes === $mesDisponible ? "selected" : ""; ?>>
                            <?php echo escaparMensualInfluenza($mesesInformeInfluenza[$mesDisponible]); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="campo campo-periodo" for="anio">Año
                <select name="anio" id="anio">
                    <?php for ($opcionAnio = 2000; $opcionAnio <= (int)date("Y") + 1; $opcionAnio++): ?>
                        <option value="<?php echo $opcionAnio; ?>" <?php echo $anio === $opcionAnio ? "selected" : ""; ?>>
                            <?php echo $opcionAnio; ?>
                        </option>
                    <?php endfor; ?>
                </select>
            </label>
            <button type="submit">Consultar</button>
            <?php if (
                $datosPaloteoInfluenza["aplicacionesSinClasificar"] === 0
                && !$conteosSinRenglonInfluenza
            ): ?>
                <button type="submit" name="descargar" value="1">Descargar Excel</button>
            <?php endif; ?>
            <button type="button" class="boton boton-secundario" onclick="window.print()">Imprimir</button>
            <a class="boton boton-secundario" href="mensual_general.php?mes=<?php echo $mes; ?>&amp;anio=<?php echo $anio; ?>">Mensual general</a>
            <span id="estado-guardado" class="estado-guardado" role="status" aria-live="polite"></span>
        </div>
    </form>

    <?php if ($mesNoDisponibleInfluenza): ?>
        <p class="mensaje-error" role="status">
            El paloteo de influenza está disponible de octubre a marzo.
            Se muestra <?php echo escaparMensualInfluenza($nombreMesInforme); ?>;
            puedes elegir otro mes de temporada.
        </p>
    <?php endif; ?>

    <?php if ($datosPaloteoInfluenza["aplicacionesSinClasificar"] > 0): ?>
        <p class="mensaje-error" role="alert">
            Hay <?php echo (int)$datosPaloteoInfluenza["aplicacionesSinClasificar"]; ?>
            aplicación(es) de influenza sin clasificar. Revisa el
            <a href="../paloteo/paloteo_influenza.php?mes=<?php echo $mes; ?>&amp;anio=<?php echo $anio; ?>">paloteo de este periodo</a>
            antes de descargar el formato oficial.
        </p>
    <?php endif; ?>
    <?php if ($conteosSinRenglonInfluenza): ?>
        <p class="mensaje-error" role="alert">
            Hay conteos de influenza clasificados que no tienen renglón correspondiente en la plantilla mensual.
            No se habilitará la descarga para evitar asignarlos a una categoría incorrecta.
        </p>
    <?php endif; ?>

    <div class="formato-scroll">
        <table class="formato-influenza">
            <colgroup>
                <?php foreach ($anchosColumnasInfluenza as $ancho): ?>
                    <col style="width: <?php echo number_format($ancho, 2, ".", ""); ?>px">
                <?php endforeach; ?>
            </colgroup>
            <tbody>
                <?php for ($numeroFila = $inicioBloqueInfluenza; $numeroFila <= $finBloqueInfluenza; $numeroFila++): ?>
                    <tr<?php if (!empty($alturasFilasInfluenza[$numeroFila])): ?> style="height: <?php echo number_format((float)$alturasFilasInfluenza[$numeroFila] * 1.333, 2, ".", ""); ?>px"<?php endif; ?>>
                        <?php for ($numeroColumna = 1; $numeroColumna <= 9; $numeroColumna++): ?>
                            <?php
                            $coordenada = columnaLetraMensualInfluenza($numeroColumna) . $numeroFila;
                            if (isset($cubiertasInfluenza[$coordenada])) {
                                continue;
                            }
                            [$columnasFusion, $filasFusion] = $fusionesInfluenza[$coordenada] ?? [1, 1];
                            $contenido = $valoresCeldasInfluenza[$coordenada] ?? "";
                            $campoEncabezado = $coordenada === "C" . ($inicioBloqueInfluenza + 2)
                                ? "clues"
                                : ($coordenada === "C" . ($inicioBloqueInfluenza + 3)
                                    ? "unidad"
                                    : null);
                            ?>
                            <td data-col="<?php echo columnaLetraMensualInfluenza($numeroColumna); ?>"
                                <?php if ($columnasFusion > 1): ?>colspan="<?php echo $columnasFusion; ?>"<?php endif; ?>
                                <?php if ($filasFusion > 1): ?>rowspan="<?php echo $filasFusion; ?>"<?php endif; ?>>
                                <?php if ($campoEncabezado !== null): ?>
                                    <input
                                        class="dato-encabezado"
                                        type="text"
                                        name="<?php echo $campoEncabezado; ?>"
                                        form="formulario-mensual-influenza"
                                        maxlength="<?php echo $campoEncabezado === "clues" ? 20 : 150; ?>"
                                        aria-label="<?php echo $campoEncabezado === "clues" ? "CLUES" : "Nombre de la unidad"; ?>"
                                        title="Haz clic para editar"
                                        value="<?php echo escaparMensualInfluenza($contenido); ?>"
                                    >
                                <?php else: ?>
                                    <?php echo nl2br(escaparMensualInfluenza($contenido)); ?>
                                <?php endif; ?>
                            </td>
                        <?php endfor; ?>
                    </tr>
                <?php endfor; ?>
            </tbody>
        </table>
    </div>
</main>
<script>
    (function() {
        const formulario = document.getElementById("formulario-mensual-influenza");
        const estado = document.getElementById("estado-guardado");
        const parametros = new URLSearchParams(window.location.search);
        const campos = [
            { nombre: "clues", clave: "sistema-vacunacion:mensual-influenza:clues" },
            { nombre: "unidad", clave: "sistema-vacunacion:mensual-influenza:unidad" }
        ];
        let encabezadoRestaurado = false;

        function indicarEstado(mensaje, esError) {
            estado.textContent = mensaje;
            estado.classList.toggle("estado-guardado--error", esError);
        }

        campos.forEach(function(configuracion) {
            const campo = formulario.elements.namedItem(configuracion.nombre);
            if (!campo) {
                return;
            }

            try {
                const guardado = window.localStorage.getItem(configuracion.clave);
                if (!parametros.has(configuracion.nombre) && guardado !== null) {
                    campo.value = guardado;
                    encabezadoRestaurado = true;
                }
            } catch (error) {
                indicarEstado("No se pudo leer el encabezado guardado en este navegador.", true);
            }

            campo.addEventListener("input", function() {
                try {
                    window.localStorage.setItem(configuracion.clave, campo.value);
                    indicarEstado("Cambios guardados en este navegador.", false);
                } catch (error) {
                    indicarEstado("No se pudieron guardar los cambios en este navegador.", true);
                }
            });
        });

        if (encabezadoRestaurado) {
            indicarEstado("Encabezado guardado en este navegador.", false);
        }
    })();
</script>
</body>
</html>
