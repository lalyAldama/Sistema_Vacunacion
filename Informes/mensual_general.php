<?php
require_once __DIR__ . "/../config/seguridad.php";

if (!isset($_SESSION["id_usuario"])) {
    header("Location: ../login.php");
    exit();
}

if (!defined("INFORME_MENSUAL_SOLICITANDO_CONTEOS")) {
    define("INFORME_MENSUAL_SOLICITANDO_CONTEOS", true);
}
$datosPaloteo = require __DIR__ . "/../paloteo/paloteo_general.php";
if (!is_array($datosPaloteo)) {
    die("No fue posible obtener los conteos del periodo.");
}

function escaparInforme($valor)
{
    return htmlspecialchars((string)$valor, ENT_QUOTES, "UTF-8");
}

function textoNormalizadoInforme($valor)
{
    $valor = mb_strtolower(trim((string)$valor), "UTF-8");
    $valor = strtr($valor, [
        "á" => "a", "é" => "e", "í" => "i", "ó" => "o", "ú" => "u",
        "ü" => "u", "ñ" => "n"
    ]);
    $valor = str_replace(
        ["para iniciar o completar esquema", "vpha", "tdpa"],
        ["completar esquema", "vph", "td"],
        $valor
    );
    return trim(preg_replace('/[^a-z0-9]+/', ' ', $valor));
}

function columnaNumeroInforme($columna)
{
    $numero = 0;
    foreach (str_split($columna) as $letra) {
        $numero = ($numero * 26) + ord($letra) - 64;
    }
    return $numero;
}

function columnaLetraInforme($numero)
{
    $columna = "";
    while ($numero > 0) {
        $residuo = ($numero - 1) % 26;
        $columna = chr(65 + $residuo) . $columna;
        $numero = intdiv($numero - 1, 26);
    }
    return $columna;
}

function leerCeldaInforme($celdas, $cadenas, $coordenada)
{
    if (!isset($celdas[$coordenada])) {
        return "";
    }
    $celda = $celdas[$coordenada];
    $tipo = $celda->getAttribute("t");
    $espacio = "http://schemas.openxmlformats.org/spreadsheetml/2006/main";
    if ($tipo === "s") {
        $valor = $celda->getElementsByTagNameNS($espacio, "v")->item(0);
        $indice = $valor ? (int)$valor->textContent : -1;
        if (!array_key_exists($indice, $cadenas)) {
            die("La plantilla del informe contiene una cadena inválida.");
        }
        return $cadenas[$indice];
    }
    if ($tipo === "inlineStr") {
        $texto = $celda->getElementsByTagNameNS($espacio, "t")->item(0);
        return $texto ? $texto->textContent : "";
    }
    $valor = $celda->getElementsByTagNameNS($espacio, "v")->item(0);
    return $valor ? $valor->textContent : "";
}

function leerCeldaFusionadaInforme($columna, $fila, $celdas, $cadenas, $fusiones)
{
    $numeroColumna = columnaNumeroInforme($columna);
    foreach ($fusiones as [$colInicio, $filaInicio, $colFin, $filaFin]) {
        if (
            $numeroColumna >= $colInicio && $numeroColumna <= $colFin
            && $fila >= $filaInicio && $fila <= $filaFin
        ) {
            $columna = "";
            $numero = $colInicio;
            while ($numero > 0) {
                $residuo = ($numero - 1) % 26;
                $columna = chr(65 + $residuo) . $columna;
                $numero = intdiv($numero - 1, 26);
            }
            return leerCeldaInforme($celdas, $cadenas, $columna . $filaInicio);
        }
    }
    return leerCeldaInforme($celdas, $cadenas, $columna . $fila);
}

function extraerEstilosInforme($xml, $espacio)
{
    $documento = new DOMDocument();
    if (!$documento->loadXML($xml, LIBXML_NONET)) {
        die("No fue posible leer los estilos de la plantilla oficial.");
    }

    $fuentes = $documento->getElementsByTagNameNS($espacio, "fonts")->item(0);
    $rellenos = $documento->getElementsByTagNameNS($espacio, "fills")->item(0);
    $bordes = $documento->getElementsByTagNameNS($espacio, "borders")->item(0);
    $formatos = $documento->getElementsByTagNameNS($espacio, "cellXfs")->item(0);
    if (!$fuentes || !$rellenos || !$bordes || !$formatos) {
        die("La plantilla oficial no contiene sus estilos de celda requeridos.");
    }

    $estilos = [];
    foreach ($formatos->getElementsByTagNameNS($espacio, "xf") as $formato) {
        $reglas = [];
        $fuente = $fuentes->getElementsByTagNameNS($espacio, "font")->item(
            (int)$formato->getAttribute("fontId")
        );
        if ($fuente) {
            foreach ([
                "b" => ["font-weight", "bold"],
                "i" => ["font-style", "italic"],
                "u" => ["text-decoration", "underline"]
            ] as $etiqueta => [$propiedad, $valor]) {
                $atributo = $fuente->getElementsByTagNameNS($espacio, $etiqueta)->item(0);
                if (
                    $atributo
                    && !in_array($atributo->getAttribute("val"), ["0", "false", "none"], true)
                ) {
                    $reglas[] = $propiedad . ":" . $valor;
                }
            }
            $tamano = $fuente->getElementsByTagNameNS($espacio, "sz")->item(0);
            if ($tamano && is_numeric($tamano->getAttribute("val"))) {
                $reglas[] = "font-size:" . (float)$tamano->getAttribute("val") . "pt";
            }
            $colorFuente = $fuente->getElementsByTagNameNS($espacio, "color")->item(0);
            if ($colorFuente && preg_match('/^(?:FF)?([A-Fa-f0-9]{6})$/', $colorFuente->getAttribute("rgb"), $color)) {
                $reglas[] = "color:#" . $color[1];
            }
        }

        $relleno = $rellenos->getElementsByTagNameNS($espacio, "fill")->item(
            (int)$formato->getAttribute("fillId")
        );
        if ($relleno) {
            $patron = $relleno->getElementsByTagNameNS($espacio, "patternFill")->item(0);
            $colorFondo = $patron
                ? $patron->getElementsByTagNameNS($espacio, "fgColor")->item(0)
                : null;
            if (
                $patron
                && $patron->getAttribute("patternType") === "solid"
                && $colorFondo
                && preg_match('/^(?:FF)?([A-Fa-f0-9]{6})$/', $colorFondo->getAttribute("rgb"), $color)
            ) {
                $reglas[] = "background-color:#" . $color[1];
            }
        }

        $borde = $bordes->getElementsByTagNameNS($espacio, "border")->item(
            (int)$formato->getAttribute("borderId")
        );
        if ($borde) {
            $estilosBorde = [
                "hair" => "0.5px solid",
                "dotted" => "1px dotted",
                "dashed" => "1px dashed",
                "thin" => "1px solid",
                "medium" => "2px solid",
                "thick" => "3px solid",
                "double" => "3px double"
            ];
            foreach (["left", "right", "top", "bottom"] as $lado) {
                $arista = $borde->getElementsByTagNameNS($espacio, $lado)->item(0);
                $estiloArista = $arista ? $arista->getAttribute("style") : "";
                if (!isset($estilosBorde[$estiloArista])) {
                    continue;
                }
                $colorArista = $arista->getElementsByTagNameNS($espacio, "color")->item(0);
                $colorCss = $colorArista
                    && preg_match('/^(?:FF)?([A-Fa-f0-9]{6})$/', $colorArista->getAttribute("rgb"), $color)
                    ? "#" . $color[1]
                    : "#000";
                $reglas[] = "border-" . $lado . ":" . $estilosBorde[$estiloArista] . " " . $colorCss;
            }
        }

        $alineacion = $formato->getElementsByTagNameNS($espacio, "alignment")->item(0);
        if ($alineacion) {
            $horizontal = [
                "left" => "left",
                "center" => "center",
                "right" => "right",
                "justify" => "justify"
            ][$alineacion->getAttribute("horizontal")] ?? null;
            $vertical = [
                "top" => "top",
                "center" => "middle",
                "bottom" => "bottom"
            ][$alineacion->getAttribute("vertical")] ?? null;
            if ($horizontal !== null) {
                $reglas[] = "text-align:" . $horizontal;
            }
            if ($vertical !== null) {
                $reglas[] = "vertical-align:" . $vertical;
            }
            if ($alineacion->getAttribute("wrapText") === "1") {
                $reglas[] = "white-space:pre-line";
            }
            if (
                is_numeric($alineacion->getAttribute("textRotation"))
                && (int)$alineacion->getAttribute("textRotation") > 0
            ) {
                $reglas[] = "writing-mode:vertical-rl";
            }
        }
        $estilos[] = implode(";", $reglas);
    }

    return $estilos;
}

function puntuarTextoInforme($esperado, $actual)
{
    $esperado = textoNormalizadoInforme($esperado);
    $actual = textoNormalizadoInforme($actual);
    if ($esperado === $actual) {
        return 100;
    }
    if ($esperado === "" || $actual === "") {
        return 0;
    }

    similar_text($esperado, $actual, $similitud);
    $tokensEsperados = array_unique(explode(" ", $esperado));
    $tokensActuales = array_unique(explode(" ", $actual));
    $coincidencias = count(array_intersect($tokensEsperados, $tokensActuales));
    $f1Tokens = 200 * $coincidencias
        / (count($tokensEsperados) + count($tokensActuales));

    return max($similitud, $f1Tokens);
}

function conteosRenglonInforme($id, $datosPaloteo)
{
    $automaticos = $datosPaloteo["automaticos"][$id];
    $total = array_sum($automaticos["dias"] ?? []);
    foreach (($datosPaloteo["ajustes"][$id]["dias"] ?? []) as $dia => $ajuste) {
        if ((int)$dia >= 1 && (int)$dia <= $datosPaloteo["diasMes"]) {
            $total += is_numeric($ajuste) ? (int)$ajuste : 0;
        }
    }
    $migrantes = $automaticos["migrantes"]
        + ajusteGuardado($datosPaloteo["ajustes"], $id, "migrantes");

    return [
        "total" => $total + $migrantes,
        "afromexicano" => $automaticos["afromexicano"]
            + ajusteGuardado($datosPaloteo["ajustes"], $id, "afromexicano"),
        "indigenas" => $automaticos["indigenas"]
            + ajusteGuardado($datosPaloteo["ajustes"], $id, "indigenas"),
        "migrantes" => $migrantes
    ];
}

function cargarPlantillaInforme($ruta, $mes, $meses)
{
    if (!is_file($ruta)) {
        die("No se encontró la plantilla oficial del informe mensual.");
    }
    $archivo = new ZipArchive();
    if ($archivo->open($ruta, ZipArchive::RDONLY) !== true) {
        die("No fue posible abrir la plantilla oficial del informe mensual.");
    }
    $xmlHoja = $archivo->getFromName("xl/worksheets/sheet1.xml");
    $xmlCadenas = $archivo->getFromName("xl/sharedStrings.xml");
    $xmlEstilos = $archivo->getFromName("xl/styles.xml");
    $archivo->close();
    if ($xmlHoja === false || $xmlCadenas === false || $xmlEstilos === false) {
        die("La plantilla oficial no contiene la hoja o las etiquetas requeridas.");
    }

    $espacio = "http://schemas.openxmlformats.org/spreadsheetml/2006/main";
    $estilosCeldas = extraerEstilosInforme($xmlEstilos, $espacio);
    $documentoHoja = new DOMDocument();
    $documentoCadenas = new DOMDocument();
    if (
        !$documentoHoja->loadXML($xmlHoja, LIBXML_NONET)
        || !$documentoCadenas->loadXML($xmlCadenas, LIBXML_NONET)
    ) {
        die("No fue posible leer la estructura de la plantilla oficial.");
    }
    $xpathHoja = new DOMXPath($documentoHoja);
    $xpathHoja->registerNamespace("x", $espacio);
    $celdas = [];
    $filas = [];
    $maximaFila = 0;
    foreach ($xpathHoja->query("//x:sheetData/x:row") as $nodoFila) {
        $numeroFila = (int)$nodoFila->getAttribute("r");
        $filas[$numeroFila] = $nodoFila;
        $maximaFila = max($maximaFila, $numeroFila);
        foreach ($xpathHoja->query("./x:c", $nodoFila) as $nodoCelda) {
            $celdas[$nodoCelda->getAttribute("r")] = $nodoCelda;
        }
    }

    $xpathCadenas = new DOMXPath($documentoCadenas);
    $xpathCadenas->registerNamespace("x", $espacio);
    $cadenas = [];
    foreach ($xpathCadenas->query("//x:si") as $nodoCadena) {
        $texto = "";
        foreach ($xpathCadenas->query(".//x:t", $nodoCadena) as $nodoTexto) {
            $texto .= $nodoTexto->textContent;
        }
        $cadenas[] = $texto;
    }

    $fusiones = [];
    foreach ($xpathHoja->query("//x:mergeCells/x:mergeCell") as $fusion) {
        if (!preg_match(
            '/^([A-Z]+)(\d+):([A-Z]+)(\d+)$/',
            $fusion->getAttribute("ref"),
            $partes
        )) {
            continue;
        }
        $fusiones[] = [
            columnaNumeroInforme($partes[1]),
            (int)$partes[2],
            columnaNumeroInforme($partes[3]),
            (int)$partes[4]
        ];
    }

    $anchosColumnas = array_fill(1, 28, 8.43);
    foreach ($xpathHoja->query("//x:cols/x:col") as $columna) {
        $ancho = $columna->hasAttribute("width")
            ? (float)$columna->getAttribute("width")
            : 8.43;
        $minimo = (int)$columna->getAttribute("min");
        $maximo = min(28, (int)$columna->getAttribute("max"));
        for ($indice = max(1, $minimo); $indice <= $maximo; $indice++) {
            $anchosColumnas[$indice] = $ancho;
        }
    }

    $encabezadosMes = [];
    foreach ($filas as $numeroFila => $_nodoFila) {
        $nombreMes = mb_strtoupper(
            trim(leerCeldaInforme($celdas, $cadenas, "S" . $numeroFila)),
            "UTF-8"
        );
        foreach ($meses as $numeroMes => $textoMes) {
            if ($nombreMes === $textoMes) {
                $encabezadosMes[$numeroMes] = $numeroFila;
                break;
            }
        }
    }
    if (!isset($encabezadosMes[$mes])) {
        die("La plantilla no contiene el bloque del mes seleccionado.");
    }
    ksort($encabezadosMes);
    $numeroEncabezado = $encabezadosMes[$mes];
    $inicioBloque = max(1, $numeroEncabezado - 3);
    $siguienteEncabezado = null;
    foreach ($encabezadosMes as $numeroMes => $filaMes) {
        if ($numeroMes > $mes) {
            $siguienteEncabezado = $filaMes;
            break;
        }
    }
    $finBloque = $siguienteEncabezado === null
        ? $maximaFila
        : $siguienteEncabezado - 4;

    return [
        "documento" => $documentoHoja,
        "xpath" => $xpathHoja,
        "celdas" => $celdas,
        "cadenas" => $cadenas,
        "estilosCeldas" => $estilosCeldas,
        "fusiones" => $fusiones,
        "filas" => $filas,
        "anchosColumnas" => $anchosColumnas,
        "inicio" => $inicioBloque,
        "fin" => $finBloque,
        "encabezado" => $numeroEncabezado,
        "meses" => $encabezadosMes,
        "espacio" => $espacio
    ];
}

function normalizarBiologicoInforme($biologico)
{
    $biologico = textoNormalizadoInforme($biologico);
    if (
        $biologico === "td dpt"
        || (str_contains($biologico, "hexavalente") && str_contains($biologico, "dpt"))
    ) {
        return "dpt";
    }
    if ($biologico === "td td") {
        return "132td";
    }
    if (preg_match('/^(?:123 )?dpt$/', $biologico)) {
        return "dpt";
    }
    return preg_replace('/\s+/', '', $biologico);
}

function buscarFilaOficialInforme($renglon, $filasOficiales)
{
    $biologicoOrigen = normalizarBiologicoInforme($renglon["biologico"]);
    $descripcionOrigen = trim(
        ($renglon["grupo"] ?? "") . " " . ($renglon["detalle"] ?? "")
    );
    $candidatos = [];
    foreach ($filasOficiales as $indice => $filaOficial) {
        $puntuacionBiologico = puntuarTextoInforme(
            $biologicoOrigen,
            normalizarBiologicoInforme($filaOficial["biologico"])
        );
        if ($puntuacionBiologico < 65) {
            continue;
        }
        $puntuacionDescripcion = puntuarTextoInforme(
            $descripcionOrigen,
            $filaOficial["descripcion"]
        );
        if ($puntuacionDescripcion < 65) {
            continue;
        }
        $candidatos[] = [
            "indice" => $indice,
            "puntuacion" => $puntuacionBiologico * 0.55
                + $puntuacionDescripcion * 0.45
        ];
    }
    usort($candidatos, function ($a, $b) {
        return $b["puntuacion"] <=> $a["puntuacion"];
    });
    if (
        !$candidatos
        || $candidatos[0]["puntuacion"] < 72
        || (
            isset($candidatos[1])
            && $candidatos[0]["puntuacion"] - $candidatos[1]["puntuacion"] < 3
        )
    ) {
        return null;
    }
    return $candidatos[0]["indice"];
}

function obtenerRenglonesOficialesInforme($plantilla, $datosPaloteo)
{
    $renglonesPaloteo = $datosPaloteo["renglones"];
    $conteos = [];
    foreach ($renglonesPaloteo as $id => $_renglon) {
        $conteos[$id] = conteosRenglonInforme($id, $datosPaloteo);
    }

    $filasOficiales = [];
    for ($fila = $plantilla["inicio"]; $fila <= $plantilla["fin"]; $fila++) {
        foreach ([
            [
                "clave" => "G",
                "biologico" => "C",
                "descripciones" => ["D", "F"],
                "celdas" => ["H", "J", "L", "N"]
            ],
            [
                "clave" => "U",
                "biologico" => "Q",
                "descripciones" => ["R", "S", "T"],
                "celdas" => ["V", "X", "Z", "AB"]
            ]
        ] as $bloque) {
            $clave = leerCeldaInforme(
                $plantilla["celdas"],
                $plantilla["cadenas"],
                $bloque["clave"] . $fila
            );
            if (!preg_match('/^[A-Z]{2,5}\d{1,3}$/', $clave)) {
                continue;
            }

            $biologico = leerCeldaFusionadaInforme(
                $bloque["biologico"],
                $fila,
                $plantilla["celdas"],
                $plantilla["cadenas"],
                $plantilla["fusiones"]
            );
            $partesDescripcion = [];
            foreach ($bloque["descripciones"] as $columna) {
                $valor = leerCeldaFusionadaInforme(
                    $columna,
                    $fila,
                    $plantilla["celdas"],
                    $plantilla["cadenas"],
                    $plantilla["fusiones"]
                );
                if (trim($valor) !== "") {
                    $partesDescripcion[] = trim($valor);
                }
            }
            $descripcion = implode(" ", $partesDescripcion);
            $columnasClave = $bloque["clave"] === "G"
                ? ["G", "I", "K", "M"]
                : ["U", "W", "Y", "AA"];
            $filasOficiales[] = [
                "fila" => $fila,
                "lado" => $bloque["clave"] === "G" ? 0 : 1,
                "clave" => $clave,
                "claves" => array_map(function ($columna) use ($fila, $plantilla) {
                    return leerCeldaInforme(
                        $plantilla["celdas"],
                        $plantilla["cadenas"],
                        $columna . $fila
                    );
                }, $columnasClave),
                "biologico" => $biologico,
                "descripcion" => $descripcion,
                "valores" => $bloque["celdas"],
                "conteos" => [
                    "total" => 0,
                    "afromexicano" => 0,
                    "indigenas" => 0,
                    "migrantes" => 0
                ]
            ];
        }
    }

    $renglonesPendientes = [];
    foreach ($renglonesPaloteo as $id => $renglon) {
        $biologicoNormalizado = textoNormalizadoInforme($renglon["biologico"]);
        if (
            str_contains($biologicoNormalizado, "influenza")
            || str_contains($biologicoNormalizado, "23 valente")
        ) {
            continue;
        }
        $indiceOficial = buscarFilaOficialInforme($renglon, $filasOficiales);
        if ($indiceOficial !== null) {
            foreach ($conteos[$id] as $campo => $valor) {
                $filasOficiales[$indiceOficial]["conteos"][$campo] += $valor;
            }
        } elseif (array_sum($conteos[$id]) > 0) {
            $renglonesPendientes[] = [
                "biologico" => $renglon["biologico"],
                "descripcion" => trim(
                    ($renglon["grupo"] ?? "") . " " . ($renglon["detalle"] ?? "")
                ),
                "total" => $conteos[$id]["total"]
            ];
        }
    }

    return [
        "filas" => $filasOficiales,
        "pendientes" => $renglonesPendientes,
        "conteos" => $conteos
    ];
}

function construirVistaOficialInforme(
    $plantilla,
    $reporte,
    $datosCabecera,
    $mes,
    $anio,
    $meses
) {
    $sobrescrituras = [];
    foreach ($reporte["filas"] as $filaReporte) {
        foreach ([
            "total" => $filaReporte["valores"][0],
            "afromexicano" => $filaReporte["valores"][1],
            "indigenas" => $filaReporte["valores"][2],
            "migrantes" => $filaReporte["valores"][3]
        ] as $campo => $columna) {
            $sobrescrituras[$columna . $filaReporte["fila"]] =
                (string)(int)$filaReporte["conteos"][$campo];
        }
    }

    $filaResponsable = $plantilla["encabezado"];
    $sobrescrituras["F" . ($filaResponsable - 1)] = $datosCabecera["unidad"];
    $sobrescrituras["S" . ($filaResponsable - 1)] = $datosCabecera["clues"];
    $sobrescrituras["F" . $filaResponsable] = $datosCabecera["responsable"];
    $sobrescrituras["S" . $filaResponsable] = $meses[$mes];
    $sobrescrituras["V" . $filaResponsable] = (string)$anio;

    $celdasCombinadas = [];
    $celdasCubiertas = [];
    foreach ($plantilla["fusiones"] as [$colInicio, $filaInicio, $colFin, $filaFin]) {
        if (
            $filaInicio < $plantilla["inicio"]
            || $filaFin > $plantilla["fin"]
            || $colInicio < 1
            || $colFin > 28
        ) {
            continue;
        }
        $coordenada = columnaLetraInforme($colInicio) . $filaInicio;
        $celdasCombinadas[$coordenada] = [
            "columnas" => $colFin - $colInicio + 1,
            "filas" => $filaFin - $filaInicio + 1
        ];
        for ($fila = $filaInicio; $fila <= $filaFin; $fila++) {
            for ($columna = $colInicio; $columna <= $colFin; $columna++) {
                if ($fila !== $filaInicio || $columna !== $colInicio) {
                    $celdasCubiertas[$fila . ":" . $columna] = true;
                }
            }
        }
    }

    $filasVista = [];
    for ($fila = $plantilla["inicio"]; $fila <= $plantilla["fin"]; $fila++) {
        $celdasFila = [];
        for ($columna = 1; $columna <= 28; $columna++) {
            if (isset($celdasCubiertas[$fila . ":" . $columna])) {
                continue;
            }
            $coordenada = columnaLetraInforme($columna) . $fila;
            $contenido = array_key_exists($coordenada, $sobrescrituras)
                ? $sobrescrituras[$coordenada]
                : leerCeldaInforme(
                    $plantilla["celdas"],
                    $plantilla["cadenas"],
                    $coordenada
                );
            $combinacion = $celdasCombinadas[$coordenada] ?? [
                "columnas" => 1,
                "filas" => 1
            ];
            $celdaPlantilla = $plantilla["celdas"][$coordenada] ?? null;
            $celdasFila[] = [
                "coordenada" => $coordenada,
                "contenido" => $contenido,
                "columnas" => $combinacion["columnas"],
                "filas" => $combinacion["filas"],
                "estilo" => $celdaPlantilla
                    ? $celdaPlantilla->getAttribute("s")
                    : ""
            ];
        }
        $nodoFila = $plantilla["filas"][$fila] ?? null;
        $filasVista[] = [
            "numero" => $fila,
            "alto" => $nodoFila ? $nodoFila->getAttribute("ht") : "",
            "celdas" => $celdasFila
        ];
    }

    return $filasVista;
}

function establecerValorCeldaInforme($documento, &$celdas, $coordenada, $valor)
{
    $espacio = "http://schemas.openxmlformats.org/spreadsheetml/2006/main";
    if (!isset($celdas[$coordenada])) {
        if (!preg_match('/^([A-Z]+)(\d+)$/', $coordenada, $partes)) {
            die("La plantilla contiene una coordenada de celda inválida.");
        }
        $fila = $partes[2];
        if (!isset($celdas["A" . $fila])) {
            die("La plantilla oficial no contiene una celda requerida.");
        }
        $nodoFila = $celdas["A" . $fila]->parentNode;
        $celda = $documento->createElementNS($espacio, "c");
        $celda->setAttribute("r", $coordenada);
        $nodoFila->appendChild($celda);
        $celdas[$coordenada] = $celda;
    }
    $celda = $celdas[$coordenada];
    foreach (["v", "is", "f"] as $nombre) {
        $nodos = $celda->getElementsByTagNameNS($espacio, $nombre);
        while ($nodos->length > 0) {
            $nodos->item(0)->parentNode->removeChild($nodos->item(0));
        }
    }

    if (is_int($valor) || is_float($valor)) {
        $celda->setAttribute("t", "n");
        $celda->appendChild(
            $documento->createElementNS($espacio, "v", (string)(int)$valor)
        );
        return;
    }

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
}

function establecerAreaImpresionInforme($xml, $inicio, $fin)
{
    $espacio = "http://schemas.openxmlformats.org/spreadsheetml/2006/main";
    $documento = new DOMDocument();
    if (!$documento->loadXML($xml, LIBXML_NONET)) {
        die("No fue posible preparar el área de impresión del informe.");
    }
    $xpath = new DOMXPath($documento);
    $xpath->registerNamespace("x", $espacio);
    $areas = $xpath->query(
        '//x:definedName[@name="_xlnm.Print_Area" and @localSheetId="0"]'
    );
    if ($areas->length > 0) {
        $area = $areas->item(0);
    } else {
        $nombres = $xpath->query("//x:definedNames")->item(0);
        if (!$nombres) {
            $nombres = $documento->createElementNS($espacio, "definedNames");
            $hojas = $xpath->query("//x:sheets")->item(0);
            $hojas->parentNode->insertBefore($nombres, $hojas->nextSibling);
        }
        $area = $documento->createElementNS($espacio, "definedName");
        $area->setAttribute("name", "_xlnm.Print_Area");
        $area->setAttribute("localSheetId", "0");
        $nombres->appendChild($area);
    }
    $area->textContent = "'Biologicos'!\$A\$$inicio:\$BF\$$fin";
    return $documento->saveXML();
}

function prepararArchivoExcelInforme(
    $plantilla,
    $reporte,
    $datosPaloteo,
    $datosCabecera,
    $mes,
    $anio
)
{
    $documento = $plantilla["documento"];
    $celdas = $plantilla["celdas"];
    $inicio = $plantilla["inicio"];
    $fin = $plantilla["fin"];
    $filaDatosUnidad = $plantilla["encabezado"] - 1;

    foreach ($plantilla["filas"] as $numeroFila => $nodoFila) {
        if ($numeroFila < $inicio || $numeroFila > $fin) {
            $nodoFila->setAttribute("hidden", "1");
        } else {
            $nodoFila->removeAttribute("hidden");
        }

        foreach ([
            ["G", ["H", "J", "L", "N"]],
            ["U", ["V", "X", "Z", "AB"]]
        ] as [$columnaClave, $columnasValores]) {
            $clave = leerCeldaInforme(
                $plantilla["celdas"],
                $plantilla["cadenas"],
                $columnaClave . $numeroFila
            );
            if (preg_match('/^[A-Z]{2,5}\d{1,3}$/', $clave)) {
                foreach ($columnasValores as $columna) {
                    establecerValorCeldaInforme(
                        $documento,
                        $celdas,
                        $columna . $numeroFila,
                        0
                    );
                }
            }
        }
    }

    foreach ($reporte["filas"] as $filaReporte) {
        foreach ([
            "total" => $filaReporte["valores"][0],
            "afromexicano" => $filaReporte["valores"][1],
            "indigenas" => $filaReporte["valores"][2],
            "migrantes" => $filaReporte["valores"][3]
        ] as $campo => $columna) {
            establecerValorCeldaInforme(
                $documento,
                $celdas,
                $columna . $filaReporte["fila"],
                (int)$filaReporte["conteos"][$campo]
            );
        }
    }

    establecerValorCeldaInforme(
        $documento,
        $celdas,
        "F" . $filaDatosUnidad,
        $datosCabecera["unidad"]
    );
    establecerValorCeldaInforme(
        $documento,
        $celdas,
        "F" . $plantilla["encabezado"],
        $datosCabecera["responsable"]
    );
    establecerValorCeldaInforme(
        $documento,
        $celdas,
        "S" . $filaDatosUnidad,
        $datosCabecera["clues"]
    );
    establecerValorCeldaInforme(
        $documento,
        $celdas,
        "S" . $plantilla["encabezado"],
        $GLOBALS["nombreMesesInforme"][$mes]
    );
    establecerValorCeldaInforme(
        $documento,
        $celdas,
        "V" . $plantilla["encabezado"],
        $anio
    );

    foreach ($plantilla["xpath"]->query("//x:sheetViews/x:sheetView") as $vista) {
        $vista->setAttribute("topLeftCell", "A" . $inicio);
    }
    foreach ($plantilla["xpath"]->query("//x:sheetViews/x:sheetView/x:selection") as $seleccion) {
        $seleccion->setAttribute("activeCell", "A" . $inicio);
        $seleccion->setAttribute("sqref", "A" . $inicio);
    }

    return $documento->saveXML();
}

function valorCabeceraInforme($nombre, $valorPredeterminado, $longitudMaxima)
{
    $valor = $_GET[$nombre] ?? $valorPredeterminado;
    if (!is_string($valor) && !is_numeric($valor)) {
        return (string)$valorPredeterminado;
    }
    return mb_substr(trim((string)$valor), 0, $longitudMaxima, "UTF-8");
}

$nombreMesesInforme = [
    1 => "ENERO", 2 => "FEBRERO", 3 => "MARZO", 4 => "ABRIL",
    5 => "MAYO", 6 => "JUNIO", 7 => "JULIO", 8 => "AGOSTO",
    9 => "SEPTIEMBRE", 10 => "OCTUBRE", 11 => "NOVIEMBRE", 12 => "DICIEMBRE"
];
$mesInforme = (int)$mes;
$anioInforme = (int)$anio;
$rutaPlantilla = __DIR__ . "/../informe_mensual.xlsx";
$plantillaInforme = cargarPlantillaInforme(
    $rutaPlantilla,
    $mesInforme,
    $nombreMesesInforme
);
$rotulosInforme = [
    "titulo" => leerCeldaInforme(
        $plantillaInforme["celdas"],
        $plantillaInforme["cadenas"],
        "D1"
    ),
    "seccion" => leerCeldaInforme(
        $plantillaInforme["celdas"],
        $plantillaInforme["cadenas"],
        "AB1"
    ),
    "subtitulo" => leerCeldaInforme(
        $plantillaInforme["celdas"],
        $plantillaInforme["cadenas"],
        "D2"
    ),
    "hoja" => leerCeldaInforme(
        $plantillaInforme["celdas"],
        $plantillaInforme["cadenas"],
        "AB2"
    ),
    "unidad" => leerCeldaInforme(
        $plantillaInforme["celdas"],
        $plantillaInforme["cadenas"],
        "A3"
    ),
    "clues" => leerCeldaInforme(
        $plantillaInforme["celdas"],
        $plantillaInforme["cadenas"],
        "R3"
    ),
    "responsable" => leerCeldaInforme(
        $plantillaInforme["celdas"],
        $plantillaInforme["cadenas"],
        "A4"
    ),
    "mes" => leerCeldaInforme(
        $plantillaInforme["celdas"],
        $plantillaInforme["cadenas"],
        "R4"
    ),
    "anio" => leerCeldaInforme(
        $plantillaInforme["celdas"],
        $plantillaInforme["cadenas"],
        "U4"
    ),
    "sistema" => leerCeldaInforme(
        $plantillaInforme["celdas"],
        $plantillaInforme["cadenas"],
        "A5"
    )
];
$datosCabecera = [
    "unidad" => valorCabeceraInforme(
        "unidad",
        $datosPaloteo["nombreUnidad"],
        150
    ),
    "responsable" => valorCabeceraInforme(
        "responsable",
        $datosPaloteo["encabezado"]["responsable"],
        150
    ),
    "clues" => valorCabeceraInforme(
        "clues",
        leerCeldaInforme(
            $plantillaInforme["celdas"],
            $plantillaInforme["cadenas"],
            "S" . ($plantillaInforme["encabezado"] - 1)
        ),
        20
    )
];
$reporteInforme = obtenerRenglonesOficialesInforme(
    $plantillaInforme,
    $datosPaloteo
);
$vistaInforme = construirVistaOficialInforme(
    $plantillaInforme,
    $reporteInforme,
    $datosCabecera,
    $mesInforme,
    $anioInforme,
    $nombreMesesInforme
);

if (($_GET["descargar"] ?? "") === "1") {
    if ($datosPaloteo["aplicacionesSinClasificarNoExcluidas"] > 0) {
        http_response_code(422);
        exit("No se puede generar el informe: hay aplicaciones que no se clasificaron automáticamente. Revisa el paloteo del periodo.");
    }
    if ($reporteInforme["pendientes"]) {
        http_response_code(422);
        exit("No se puede generar el informe: existen renglones con aplicaciones que no tienen correspondencia en la plantilla oficial. Revisa el paloteo del periodo.");
    }

    $xmlHoja = prepararArchivoExcelInforme(
        $plantillaInforme,
        $reporteInforme,
        $datosPaloteo,
        $datosCabecera,
        $mesInforme,
        $anioInforme
    );
    $archivoSalida = tempnam(sys_get_temp_dir(), "informe_mensual_");
    if ($archivoSalida === false || !copy($rutaPlantilla, $archivoSalida)) {
        if ($archivoSalida !== false) {
            unlink($archivoSalida);
        }
        die("No fue posible preparar el archivo del informe.");
    }
    $archivoExcel = new ZipArchive();
    if ($archivoExcel->open($archivoSalida) !== true) {
        unlink($archivoSalida);
        die("No fue posible abrir la copia de la plantilla oficial.");
    }
    $xmlLibro = $archivoExcel->getFromName("xl/workbook.xml");
    if ($xmlLibro === false) {
        $archivoExcel->close();
        unlink($archivoSalida);
        die("La plantilla oficial no contiene la configuración del libro.");
    }
    $xmlLibro = establecerAreaImpresionInforme(
        $xmlLibro,
        $plantillaInforme["inicio"],
        $plantillaInforme["fin"]
    );
    $hojaActualizada = $archivoExcel->addFromString(
        "xl/worksheets/sheet1.xml",
        $xmlHoja
    );
    $libroActualizado = $hojaActualizada
        && $archivoExcel->addFromString("xl/workbook.xml", $xmlLibro);
    $archivoCerrado = $archivoExcel->close();
    if (!$hojaActualizada || !$libroActualizado || !$archivoCerrado) {
        unlink($archivoSalida);
        die("No fue posible guardar los datos en la plantilla oficial.");
    }

    header("Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet");
    header(
        "Content-Disposition: attachment; filename=\"Informe_Mensual_Biologicos_"
        . $anioInforme . "_" . sprintf("%02d", $mesInforme) . ".xlsx\""
    );
    header("Content-Length: " . filesize($archivoSalida));
    readfile($archivoSalida);
    unlink($archivoSalida);
    exit();
}

$erroresInforme = [];
if ($datosPaloteo["aplicacionesSinClasificarNoExcluidas"] > 0) {
    $erroresInforme[] = $datosPaloteo["aplicacionesSinClasificarNoExcluidas"]
        . " aplicación(es) no se pudieron clasificar automáticamente en el paloteo.";
}
if ($reporteInforme["pendientes"]) {
    $erroresInforme[] = count($reporteInforme["pendientes"])
        . " renglón(es) con aplicaciones no tienen correspondencia directa en la plantilla oficial.";
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Informe mensual | Aplicación de biológicos</title>
    <style>
        :root { font-family: Arial, Helvetica, sans-serif; color: #111; background: #f3f3f3; }
        * { box-sizing: border-box; }
        body { margin: 0; padding: 16px; }
        main { max-width: 1900px; margin: 0 auto; }
        .barra { display: flex; align-items: end; flex-wrap: wrap; gap: 10px; padding: 0 0 12px; }
        .control-periodo { display: grid; gap: 4px; font-size: 12px; font-weight: 700; }
        select { width: 135px; min-width: 0; min-height: 38px; padding: 7px; border: 1px solid #777; background: #fff; font: inherit; }
        button { display: inline-grid; place-items: center; width: 40px; height: 38px; padding: 7px; border: 1px solid #555; background: #fff; color: #111; cursor: pointer; }
        button:hover, button:focus-visible { background: #e8e8e8; }
        .boton-mensual-influenza { display: inline-flex; align-items: center; min-height: 38px; padding: 7px 12px; border: 1px solid #347F91; background: #E3F0F5; color: #285F70; font-size: 12px; font-weight: 700; text-decoration: none; }
        .boton-mensual-influenza:hover, .boton-mensual-influenza:focus-visible { background: #C7EEE2; }
        button svg { width: 20px; height: 20px; }
        .hoja-scroll { overflow: auto; background: #fff; border: 1px solid #555; }
        .hoja { padding: 0; background: #fff; }
        .advertencia { margin: 0 0 12px; padding: 10px; border: 1px solid #8a1f11; background: #fff; color: #111; }
        .advertencia ul { margin-bottom: 0; }
        table.formato-oficial { width: max-content; min-width: 100%; table-layout: fixed; border-collapse: collapse; font-size: 8px; }
        table.formato-oficial td { height: 15px; padding: 1px 2px; border: 0; text-align: center; vertical-align: middle; white-space: pre-line; overflow-wrap: anywhere; }
        table.formato-oficial td[data-col="A"], table.formato-oficial td[data-col="C"],
        table.formato-oficial td[data-col="D"], table.formato-oficial td[data-col="Q"],
        table.formato-oficial td[data-col="R"], table.formato-oficial td[data-col="S"],
        table.formato-oficial td[data-col="T"] { text-align: left; }
        .dato-editable { display: block; width: 100%; min-width: 35px; padding: 1px; border: 0; background: transparent; color: inherit; font: inherit; }
        .dato-editable:focus { outline: 1px solid #555; background: #fffde8; }
        @media print {
            @page { size: landscape; margin: 5mm; }
            body { padding: 0; background: #fff; }
            .barra { display: none; }
            .hoja-scroll { overflow: visible; border: 0; }
            .hoja { min-width: 0; padding: 0; }
            table.formato-oficial { width: 100%; min-width: 0; font-size: 5px; }
            table.formato-oficial td { height: auto; padding: 0; }
            .dato-editable { padding: 0; }
            .advertencia { display: none; }
        }
    </style>
    <link rel="stylesheet" href="../css/tema_reportes.css?v=20261105-3">
</head>
<body>
<main>
    <header class="encabezado-reporte">
        <div class="encabezado-reporte__contenido">
            <span class="encabezado-reporte__seccion">Informe mensual</span>
            <h1>Aplicación de biológicos</h1>
            <p>Concentrado mensual de vacunación</p>
        </div>
    </header>
    <?php if ($erroresInforme): ?>
        <div class="advertencia" role="alert">
            <strong>No se puede generar todavía el archivo oficial:</strong>
            <ul>
                <?php foreach ($erroresInforme as $errorInforme): ?>
                    <li><?php echo escaparInforme($errorInforme); ?></li>
                <?php endforeach; ?>
            </ul>
            <?php if ($reporteInforme["pendientes"]): ?>
                <p>Renglones pendientes de ubicar:</p>
                <ul>
                    <?php foreach ($reporteInforme["pendientes"] as $pendiente): ?>
                        <li>
                            <?php echo escaparInforme(
                                $pendiente["biologico"] . " — "
                                . $pendiente["descripcion"] . ": "
                                . $pendiente["total"]
                            ); ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
            <?php if ($datosPaloteo["aplicacionesSinClasificarNoExcluidas"] > 0): ?>
                <p>
                    Revisa la clasificación en el
                    <a href="../paloteo/paloteo_general.php?mes=<?php echo $mesInforme; ?>&amp;anio=<?php echo $anioInforme; ?>">
                        paloteo del mismo mes
                    </a>
                    antes de exportar.
                </p>
            <?php endif; ?>
        </div>
    <?php endif; ?>
    <form method="get" action="mensual_general.php">
        <div class="barra">
        <label class="control-periodo" for="mes"><?php echo escaparInforme($rotulosInforme["mes"]); ?>
            <select name="mes" id="mes">
                <?php foreach ($nombreMesesInforme as $numeroMes => $nombreMes): ?>
                    <option value="<?php echo $numeroMes; ?>" <?php echo $mesInforme === $numeroMes ? "selected" : ""; ?>>
                        <?php echo escaparInforme($nombreMes); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
        <label class="control-periodo" for="anio"><?php echo escaparInforme($rotulosInforme["anio"]); ?>
            <select name="anio" id="anio">
                <?php for ($opcionAnio = 2000; $opcionAnio <= (int)date("Y") + 1; $opcionAnio++): ?>
                    <option value="<?php echo $opcionAnio; ?>" <?php echo $anioInforme === $opcionAnio ? "selected" : ""; ?>>
                        <?php echo $opcionAnio; ?>
                    </option>
                <?php endfor; ?>
            </select>
        </label>
        <button type="submit" title="Consultar mes" aria-label="Consultar mes">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <circle cx="10.8" cy="10.8" r="6.8"></circle>
                <path d="m16 16 5 5"></path>
            </svg>
        </button>
        <a
            class="boton-mensual-influenza"
            href="mensual_influenza.php?mes=<?php echo $mesInforme; ?>&amp;anio=<?php echo $anioInforme; ?>"
        >Mensual influenza</a>
        <?php if (!$erroresInforme): ?>
            <button type="submit" name="descargar" value="1" title="Descargar formato oficial" aria-label="Descargar formato oficial">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <path d="M12 3v12m0 0 5-5m-5 5-5-5"></path>
                    <path d="M5 17v4h14v-4"></path>
                </svg>
            </button>
        <?php endif; ?>
        <button type="button" title="Imprimir informe" aria-label="Imprimir informe" onclick="window.print()">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <path d="M7 8V3h10v5M7 17H5a2 2 0 0 1-2-2v-4a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2v4a2 2 0 0 1-2 2h-2"></path>
                <path d="M7 14h10v7H7zM18 11h.01"></path>
            </svg>
        </button>
        </div>
        <div class="hoja-scroll">
            <section class="hoja">
                <table class="formato-oficial">
                    <colgroup>
                        <?php foreach ($plantillaInforme["anchosColumnas"] as $anchoColumna): ?>
                            <col style="width: <?php echo number_format($anchoColumna * 7, 2, ".", ""); ?>px">
                        <?php endforeach; ?>
                    </colgroup>
                    <tbody>
                        <?php foreach ($vistaInforme as $filaVista): ?>
                            <tr<?php if ($filaVista["alto"] !== ""): ?> style="height: <?php echo number_format((float)$filaVista["alto"] * 1.333, 2, ".", ""); ?>px"<?php endif; ?>>
                                <?php foreach ($filaVista["celdas"] as $celdaVista): ?>
                                    <td
                                        data-cell="<?php echo escaparInforme($celdaVista["coordenada"]); ?>"
                                        data-col="<?php echo escaparInforme(preg_replace('/\d/', '', $celdaVista["coordenada"])); ?>"
                                        <?php if ($celdaVista["estilo"] !== ""): ?>style="<?php echo escaparInforme($plantillaInforme["estilosCeldas"][(int)$celdaVista["estilo"]] ?? ""); ?>"<?php endif; ?>
                                        <?php if ($celdaVista["columnas"] > 1): ?>colspan="<?php echo (int)$celdaVista["columnas"]; ?>"<?php endif; ?>
                                        <?php if ($celdaVista["filas"] > 1): ?>rowspan="<?php echo (int)$celdaVista["filas"]; ?>"<?php endif; ?>
                                    >
                                        <?php if ($celdaVista["coordenada"] === "F" . ($plantillaInforme["encabezado"] - 1)): ?>
                                            <input class="dato-editable" type="text" name="unidad" aria-label="Nombre de la unidad" maxlength="150" value="<?php echo escaparInforme($datosCabecera["unidad"]); ?>">
                                        <?php elseif ($celdaVista["coordenada"] === "S" . ($plantillaInforme["encabezado"] - 1)): ?>
                                            <input class="dato-editable" type="text" name="clues" aria-label="CLUES" maxlength="20" value="<?php echo escaparInforme($datosCabecera["clues"]); ?>">
                                        <?php elseif ($celdaVista["coordenada"] === "F" . $plantillaInforme["encabezado"]): ?>
                                            <input class="dato-editable" type="text" name="responsable" aria-label="Nombre de la persona responsable" maxlength="150" value="<?php echo escaparInforme($datosCabecera["responsable"]); ?>">
                                        <?php else: ?>
                                            <?php echo nl2br(escaparInforme($celdaVista["contenido"])); ?>
                                        <?php endif; ?>
                                    </td>
                                <?php endforeach; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </section>
        </div>
    </form>
</main>
</body>
</html>