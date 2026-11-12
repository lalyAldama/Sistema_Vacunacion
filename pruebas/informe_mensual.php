<?php
if (PHP_SAPI !== "cli" || !function_exists("mb_strtolower")) {
    fwrite(STDERR, "Se requiere PHP CLI con mbstring.\n");
    exit(2);
}

$esperados = [
    "bcg_24h" => "VBC01", "bcg_neonato" => "VBC02", "bcg_29d_11m" => "BIO50",
    "bcg_1_4" => "BIO03", "bcg_5_9" => "VBC03", "hepb_rn" => "VAC06",
    "hepb_mayor11_1" => "VHB01", "hepb_mayor11_2" => "VHB02",
    "hepb_riesgo_1" => "VHB03", "hepb_riesgo_2" => "VHB04",
    "hepb_salud_1" => "VHB05", "hepb_salud_2" => "VHB06",
    "hexa_1" => "VAC67", "hexa_2" => "VAC68", "hexa_3" => "VAC69", "hexa_ref_18" => "VAC70",
    "hexa_rescate1" => "VHX01", "hexa_rescate2" => "VHX02", "hexa_rescate3" => "VHX03",
    "hexa_rescate_refuerzo" => "VHX04", "dpt_unica_4" => "VAC12", "dpt_rescate_5_6" => "VAC13",
    "rota_1" => "VRV01", "rota_2" => "VRV02", "rota_rescate1" => "VRV03", "rota_rescate2" => "VRV04",
    "neumo_1" => "VAC17", "neumo_2" => "VAC18", "neumo_3" => "VAC19",
    "neumo_rescate1" => "VNC01", "neumo_rescate2" => "VNC02", "neumo_rescate3" => "VNC03",
    "neumo_unica60" => "VNC04", "neumo_riesgo60" => "VNC04",
    "neumo20_1" => "VCC01", "neumo20_2" => "VCC02", "neumo20_3" => "VCC03",
    "neumo20_rescate1" => "VCC04", "neumo20_rescate2" => "VCC05", "neumo20_rescate3" => "VCC06",
    "neumo20_60" => "VCC07", "srp_1" => "VAC23", "srp_2" => "VTV01", "srp_rescate1" => "VTV02",
    "srp_rescate2" => "VTV03", "sr_6m" => "VAC82", "sr_1_4" => "VAC91", "sr_10_19" => "VDV01",
    "sr_20_39" => "VDV02", "sr_40_49" => "VDV03", "sr_salud" => "VDV04",
    "sr_educativo" => "VDV05", "sr_jornalero" => "VDV06",
    "vph_ninas" => "VPH05", "vph_rezagados" => "VPH06", "vph_violencia_9_14" => "VPH07",
    "vph_violencia_15_19" => "VPH08", "vph_vih_1" => "VPH12", "vph_vih_2" => "VPH13",
    "vph_vih_3" => "VPH14", "varicela_unica_1" => "VAR02", "varicela_rezago_2_4" => "VAR03",
    "hepa_unica_18m" => "VHA01", "hepa_rezago_2_4" => "VHA02", "hepa_riesgo" => "BIO88",
    "td_primera_embarazadas_10_19" => "VAC39", "td_primera_embarazadas_20_plus" => "VAC40",
    "td_segunda_embarazadas_10_19" => "VAC47", "td_segunda_embarazadas_20_plus" => "VAC48",
    "td_tercera_embarazadas_10_19" => "VTD01", "td_tercera_embarazadas_20_plus" => "VTD02",
    "td_refuerzo_embarazadas_10_19" => "VAC55", "td_refuerzo_embarazadas_20_plus" => "VAC56",
    "td_primera_poblacion_15_19" => "VTT01", "td_primera_poblacion_20_59" => "VTT02", "td_primera_poblacion_60_plus" => "VTT03",
    "td_segunda_poblacion_15_19" => "VTT04", "td_segunda_poblacion_20_59" => "VTT05", "td_segunda_poblacion_60_plus" => "VTT06",
    "td_tercera_poblacion_15_19" => "VTT07", "td_tercera_poblacion_20_59" => "VTT08", "td_tercera_poblacion_60_plus" => "VTT09",
    "td_refuerzo_poblacion_15_19" => "VTT10", "td_refuerzo_poblacion_20_59" => "VTT11", "td_refuerzo_poblacion_60_plus" => "VTT12",
    "tdpa_embarazo" => "VAC63", "tdpa_salud" => "VDP01", "vsr_embarazo_28_36" => "VS001",
    "covid_6_59m_primera" => "VCV38", "covid_6_59m_segunda" => "VCV39", "covid_6_59m_refuerzo" => "VCV40",
    "covid_riesgo_5_59" => "VCV28", "covid_60_plus" => "VCV16", "covid_embarazadas" => "VCV20",
    "covid_salud" => "VCV21"
];

function ejecutarInformePrueba($entrada)
{
    $comando = [
        PHP_BINARY, "-n", "-d", "extension_dir=" . ini_get("extension_dir"),
        "-d", "extension=mbstring", "-d", "extension=zip", __DIR__ . DIRECTORY_SEPARATOR . "paloteo_entorno.php"
    ];
    $proceso = proc_open($comando, [0 => ["pipe", "r"], 1 => ["pipe", "w"], 2 => ["pipe", "w"]], $pipes);
    if (!is_resource($proceso)) {
        throw new RuntimeException("No se pudo iniciar el entorno de prueba.");
    }
    fwrite($pipes[0], json_encode($entrada, JSON_THROW_ON_ERROR));
    fclose($pipes[0]);
    $salida = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $errores = stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    $codigo = proc_close($proceso);
    $resultado = json_decode($salida, true);
    if ($codigo !== 0 || !is_array($resultado)) {
        throw new RuntimeException("Falló el entorno del informe: $errores $salida");
    }
    return $resultado;
}

function comprobarInforme($entrada, $valoresEsperados, $pendientesEsperados, $desglosesEsperados = [])
{
    $datos = ejecutarInformePrueba($entrada);
    $fallos = [];
    $vista = [];
    foreach ($datos["vista"] as $fila) {
        foreach ($fila["celdas"] as $celda) {
            $vista[$celda["coordenada"]] = $celda["contenido"];
        }
    }
    $documento = new DOMDocument();
    if (!$documento->loadXML($datos["excel"], LIBXML_NONET)) {
        throw new RuntimeException("Excel generado inválido.");
    }
    $xpath = new DOMXPath($documento);
    $xpath->registerNamespace("x", "http://schemas.openxmlformats.org/spreadsheetml/2006/main");
    $celdasExcel = [];
    foreach ($xpath->query("//x:sheetData/x:row/x:c") as $celdaExcel) {
        $valorExcel = $xpath->query("./x:v | ./x:is/x:t", $celdaExcel)->item(0);
        if ($valorExcel) {
            $celdasExcel[$celdaExcel->getAttribute("r")] = $valorExcel->textContent;
        }
    }
    $encontrados = [];
    foreach ($datos["reporte"]["filas"] as $fila) {
        $codigo = $fila["clave"];
        $esperado = $valoresEsperados[$codigo] ?? 0;
        $coordenada = $fila["valores"][0] . $fila["fila"];
        if (
            $fila["conteos"]["total"] !== $esperado
            || (int)($vista[$coordenada] ?? -1) !== $esperado
            || !isset($celdasExcel[$coordenada]) || (int)$celdasExcel[$coordenada] !== $esperado
        ) {
            $fallos[] = "$codigo ($coordenada): esperado $esperado, reporte {$fila["conteos"]["total"]}";
        }
        foreach (["afromexicano" => 1, "indigenas" => 2, "migrantes" => 3] as $campo => $indice) {
            $esperadoCampo = $desglosesEsperados[$codigo][$campo] ?? 0;
            $coordCampo = $fila["valores"][$indice] . $fila["fila"];
            if (
                $fila["conteos"][$campo] !== $esperadoCampo
                || (int)($vista[$coordCampo] ?? -1) !== $esperadoCampo
                || !isset($celdasExcel[$coordCampo]) || (int)$celdasExcel[$coordCampo] !== $esperadoCampo
            ) {
                $fallos[] = "$codigo $campo ($coordCampo): esperado $esperadoCampo";
            }
        }
        $coordClave = $fila["columnaClave"] . $fila["fila"];
        if (($vista[$coordClave] ?? null) !== $codigo || ($celdasExcel[$coordClave] ?? null) !== $codigo) {
            $fallos[] = "$codigo no se muestra correctamente en $coordClave";
        }
        $encontrados[$codigo] = true;
    }
    foreach ($valoresEsperados as $codigo => $_) {
        if (!isset($encontrados[$codigo])) {
            $fallos[] = "La plantilla no contiene $codigo";
        }
    }
    if (count($datos["reporte"]["pendientes"]) !== $pendientesEsperados) {
        $fallos[] = "Pendientes esperados: $pendientesEsperados; obtenidos: " . count($datos["reporte"]["pendientes"]);
    }
    return $fallos;
}

$fallos = [];
$conteosPrueba = [];
$valoresEsperados = [];
$cantidad = 0;
foreach ($esperados as $id => $codigo) {
    $conteosPrueba[$id] = ++$cantidad;
    $valoresEsperados[$codigo] = ($valoresEsperados[$codigo] ?? 0) + $cantidad;
}
foreach (range(1, 12) as $mes) {
    $errores = comprobarInforme(
        ["informe" => true, "mes" => $mes, "conteos_prueba" => $conteosPrueba],
        $valoresEsperados,
        0
    );
    foreach ($errores as $error) {
        $fallos[] = "Mes $mes: $error";
    }
}
foreach ([
    ["Primera", "sin_antecedente", 0, "VHB01"],
    ["Segunda", "sin_antecedente", 0, "VHB02"],
    ["Primera", "riesgo", 0, "VHB03"],
    ["Segunda", "riesgo", 0, "VHB04"],
    ["Primera", "", 1, "VHB05"],
    ["Segunda", "", 1, "VHB06"]
] as [$dosis, $clasificacion, $personalSalud, $codigo]) {
    $aplicacion = [
        "id_aplicacion_vacuna" => 1, "fecha_aplicacion" => "2024-05-15", "fecha_nacimiento" => "2004-05-15",
        "fecha_ultima_menstruacion" => null, "sexo" => "F", "embarazo" => 0, "personal_salud" => $personalSalud,
        "jornalero_agricola" => 0, "estatus_migratorio" => "Migrante", "poblacion_indigena_afromexicana" => 1,
        "nombre_dosis" => $dosis, "nombre_biologico" => "HepatitisB", "nombre_biologico_indicado" => "HepatitisB",
        "comorbilidades" => "", "clasificacion_vph" => "", "clasificacion_hepatitis_b" => $clasificacion,
        "clasificacion_sr" => "", "clasificacion_varicela_hepatitis_a" => ""
    ];
    foreach (comprobarInforme(
        ["informe" => true, "aplicaciones" => [$aplicacion]],
        [$codigo => 1],
        0,
        [$codigo => ["afromexicano" => 1, "indigenas" => 1, "migrantes" => 1]]
    ) as $error) {
        $fallos[] = "Hepatitis B $dosis: $error";
    }
}
foreach (comprobarInforme(["informe" => true, "conteos_prueba" => ["hexa_4" => 1]], [], 1) as $error) {
    $fallos[] = "Categoría sin correspondencia: $error";
}
foreach ($fallos as $fallo) {
    echo "FALLO: $fallo\n";
}
echo "Correspondencias comprobadas: " . count($esperados) . " en 12 meses; fallos: " . count($fallos) . ".\n";
exit($fallos ? 1 : 0);
