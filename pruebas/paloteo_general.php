<?php

if (PHP_SAPI !== "cli" || !function_exists("mb_strtolower")) {
    fwrite(STDERR, "Se requiere PHP CLI con mbstring.\n");
    exit(2);
}

$casos = [];
function caso($nombre, $renglon, $biologico, $dosis, $meses, $cambios = [])
{
    global $casos;
    $fecha = new DateTimeImmutable("2024-05-15");
    $aplicacion = [
        "id_aplicacion_vacuna" => 1,
        "fecha_aplicacion" => $fecha->format("Y-m-d"),
        "fecha_nacimiento" => $fecha->modify("-" . $meses . " months")->format("Y-m-d"),
        "fecha_ultima_menstruacion" => null,
        "sexo" => "F",
        "embarazo" => 0,
        "personal_salud" => 0,
        "jornalero_agricola" => 0,
        "estatus_migratorio" => "No aplica",
        "poblacion_indigena_afromexicana" => 0,
        "nombre_dosis" => $dosis,
        "nombre_biologico" => $biologico,
        "nombre_biologico_indicado" => $biologico,
        "comorbilidades" => "",
        "clasificacion_vph" => "",
        "clasificacion_hepatitis_b" => "",
        "clasificacion_sr" => "",
        "clasificacion_varicela_hepatitis_a" => ""
    ];
    $casos[] = [
        "nombre" => $nombre,
        "esperado" => $renglon,
        "entrada" => ["aplicaciones" => [array_replace($aplicacion, $cambios)]]
    ];
}

function fila($id, $biologico, $dosis, $meses, $cambios = [])
{
    caso($id, $id, $biologico, $dosis, $meses, $cambios);
}

fila("bcg_24h", "BCG", "Única", 0);
fila("bcg_neonato", "BCG", "Única", 0, ["fecha_nacimiento" => "2024-04-17"]);
fila("bcg_29d_11m", "BCG", "Única", 0, ["fecha_nacimiento" => "2024-04-16"]);
fila("bcg_1_4", "BCG", "Única", 12);
fila("bcg_5_9", "BCG", "Única", 13 * 12);
fila("hepb_rn", "HepatitisB", "Única", 0, ["fecha_nacimiento" => "2024-05-08"]);
foreach (["sin_antecedente" => "mayor11", "riesgo" => "riesgo"] as $grupo => $id) {
    foreach (["Primera", "Segunda"] as $indice => $dosis) {
        fila("hepb_{$id}_" . ($indice + 1), "HepatitisB", $dosis, 15 * 12, [
            "clasificacion_hepatitis_b" => $grupo
        ]);
    }
}
foreach (["Primera", "Segunda"] as $indice => $dosis) {
    fila("hepb_salud_" . ($indice + 1), "HepatitisB", $dosis, 25 * 12, ["personal_salud" => 1]);
}
foreach (["Primera" => 2, "Segunda" => 4, "Tercera" => 6] as $dosis => $meses) {
    $numero = ["Primera" => 1, "Segunda" => 2, "Tercera" => 3][$dosis];
    fila("hexa_$numero", "Hexavalente", $dosis, $meses);
    fila("hexa_rescate$numero", "Hexavalente", $dosis, 12);
}
fila("hexa_ref_18", "Hexavalente", "Refuerzo", 18);
fila("hexa_rescate_refuerzo", "Hexavalente", "Refuerzo", 24);
fila("hexa_4", "Hexavalente", "Única", 48);
fila("dpt_4_6", "DPT", "Única", 60, ["nombre_biologico_indicado" => "Hexavalente"]);
fila("dpt_unica_4", "DPT", "Única", 48);
fila("dpt_rescate_5_6", "DPT", "Única", 60);
foreach ([
    ["rota_1", "Primera", 2], ["rota_2", "Segunda", 4],
    ["rota_rescate1", "Primera", 3], ["rota_rescate2", "Segunda", 5]
] as [$id, $dosis, $meses]) {
    fila($id, "Rotavirus", $dosis, $meses);
}
foreach (["neumo" => "Antineumococicaconjugada13", "neumo20" => "Neumococica20valente"] as $prefijo => $bio) {
    foreach (["Primera" => 2, "Segunda" => 4, "Tercera" => 12] as $dosis => $meses) {
        $numero = ["Primera" => 1, "Segunda" => 2, "Tercera" => 3][$dosis];
        fila("{$prefijo}_$numero", $bio, $dosis, $meses);
        fila("{$prefijo}_rescate$numero", $bio, $dosis, 36);
    }
}
fila("neumo_unica60", "Antineumococicaconjugada13", "Única", 720);
fila("neumo_riesgo60", "Antineumococicaconjugada13", "Única", 720, ["comorbilidades" => "2"]);
fila("neumo20_60", "Neumococica20valente", "Única", 720);
fila("neumo23_60", "Antineumococicapolisacarida23valente", "Única", 720);
fila("neumo23_riesgo", "Antineumococicapolisacarida23valente", "Única", 720, ["comorbilidades" => "2"]);
fila("neumo23_refuerzo_riesgo", "Antineumococicapolisacarida23valente", "Refuerzo", 732, ["comorbilidades" => "2"]);
foreach ([
    ["srp_1", "Primera", 12], ["srp_2", "Segunda", 18],
    ["srp_rescate1", "Primera", 17], ["srp_rescate2", "Segunda", 24]
] as [$id, $dosis, $meses]) {
    fila($id, "SRP", $dosis, $meses);
}
foreach ([
    ["sr_6m", "Adicional", 6, []], ["sr_1_4", "Adicional", 12, []],
    ["sr_10_19", "Única", 120, []], ["sr_20_39", "Única", 240, []],
    ["sr_40_49", "Única", 480, []], ["sr_salud", "Única", 300, ["personal_salud" => 1]],
    ["sr_educativo", "Única", 300, ["clasificacion_sr" => "personal_educativo"]],
    ["sr_jornalero", "Única", 300, ["jornalero_agricola" => 1]]
] as [$id, $dosis, $meses, $cambios]) {
    fila($id, "SR", $dosis, $meses, $cambios);
}
foreach ([
    ["vph_ninas", "primaria_11", 132], ["vph_rezagados", "rezagados", 144],
    ["vph_violencia_9_14", "violencia_9_14", 108],
    ["vph_violencia_15_19", "violencia_15_19", 180]
] as [$id, $clasificacion, $meses]) {
    fila($id, "VPH", "Única", $meses, ["clasificacion_vph" => $clasificacion]);
}
foreach (["Primera", "Segunda", "Tercera"] as $i => $dosis) {
    fila("vph_vih_" . ($i + 1), "VPH", $dosis, 132, [
        "clasificacion_vph" => "mujeres_vih", "comorbilidades" => "1"
    ]);
}
fila("varicela_unica_1", "Varicela", "Única", 12);
fila("varicela_rezago_2_4", "Varicela", "Única", 24);
fila("hepa_unica_18m", "HepatitisA", "Única", 18);
fila("hepa_rezago_2_4", "HepatitisA", "Única", 24);
fila("hepa_riesgo", "HepatitisA", "Única", 24, ["comorbilidades" => "2"]);

foreach (["Primera", "Segunda", "Tercera", "Refuerzo"] as $dosis) {
    $codigo = strtolower($dosis);
    foreach (["10_19" => 120, "20_plus" => 240] as $grupo => $meses) {
        fila("td_{$codigo}_embarazadas_$grupo", "Td", $dosis, $meses, ["embarazo" => 1]);
    }
    foreach (["15_19" => 180, "20_59" => 240, "60_plus" => 720] as $grupo => $meses) {
        fila("td_{$codigo}_poblacion_$grupo", "Td", $dosis, $meses);
        fila("tdpa_td_{$codigo}_$grupo", "Tdpa", $dosis, $meses, ["nombre_biologico_indicado" => "Td"]);
    }
}
fila("tdpa_embarazo", "Tdpa", "Única", 240, ["embarazo" => 1, "fecha_ultima_menstruacion" => "2023-12-27"]);
fila("tdpa_salud", "Tdpa", "Única", 240, ["personal_salud" => 1, "comorbilidades" => "2"]);
fila("tdpa_dpt_4", "Tdpa", "Única", 48, ["nombre_biologico_indicado" => "DPT"]);
fila("tdpa_dpt_catchup", "Tdpa", "Única", 60, ["nombre_biologico_indicado" => "DPT"]);
fila("vsr_embarazo_28_36", "VSR", "Única", 240, ["embarazo" => 1, "fecha_ultima_menstruacion" => "2023-11-01"]);
foreach ([
    ["covid_6_59m_primera", "Primera", 6, []],
    ["covid_6_59m_segunda", "Segunda", 6, []],
    ["covid_6_59m_refuerzo", "Refuerzo", 6, []],
    ["covid_riesgo_5_59", "Refuerzo", 60, ["comorbilidades" => "2"]],
    ["covid_60_plus", "Refuerzo", 720, []],
    ["covid_embarazadas", "Refuerzo", 240, ["embarazo" => 1]],
    ["covid_salud", "Refuerzo", 240, ["personal_salud" => 1]]
] as [$id, $dosis, $meses, $cambios]) {
    fila($id, "COVID", $dosis, $meses, $cambios);
}
foreach (["6_11m" => 6, "12_23m" => 12, "24_35m" => 24, "36_47m" => 36, "48_59m" => 48] as $rango => $meses) {
    fila("influenza_primera_$rango", "Influenza", "Primera", $meses);
}
foreach (["7_11m" => 7, "12_23m" => 12, "24_35m" => 24, "36_47m" => 36, "48_59m" => 48] as $rango => $meses) {
    fila("influenza_segunda_$rango", "Influenza", "Segunda", $meses);
}
foreach (["18_23m" => 18, "24_35m" => 24, "36_47m" => 36, "48_59m" => 48, "60_plus" => 720] as $rango => $meses) {
    fila("influenza_anual_$rango", "Influenza", "Anual", $meses);
}
fila("influenza_riesgo_embarazo", "Influenza", "Anual", 240, ["embarazo" => 1]);
fila("influenza_riesgo_salud", "Influenza", "Anual", 240, ["personal_salud" => 1]);
foreach ([
    "vih" => 1, "diabetes" => 2, "obesidad" => 3, "cardiopatias" => 4,
    "asma" => 5, "cancer" => 6, "pulmonar" => 11, "renal" => 8,
    "inmunosupresion" => 9, "otros" => 10
] as $riesgo => $comorbilidad) {
    foreach (["5_9" => 60, "10_19" => 120, "20_59" => 240] as $rango => $meses) {
        fila("influenza_riesgo_{$riesgo}_$rango", "Influenza", "Anual", $meses, [
            "comorbilidades" => (string)$comorbilidad
        ]);
    }
}

caso("BCG fuera de rango", null, "BCG", "Única", 168);
caso("BCG último día de 13 años", "bcg_5_9", "BCG", "Única", 167);
caso("Hepatitis B después de 7 días", null, "HepatitisB", "Única", 0, ["fecha_nacimiento" => "2024-05-07"]);
caso("SRP rescate antes de 17 meses", null, "SRP", "Primera", 16);
caso("SRP rescate primera a los 9 años", "srp_rescate1", "SRP", "Primera", 119);
caso("SRP rescate primera a los 10 años", null, "SRP", "Primera", 120);
caso("Hexavalente primera a los 11 meses", "hexa_1", "Hexavalente", "Primera", 11);
caso("Hexavalente primera a los 12 meses", "hexa_rescate1", "Hexavalente", "Primera", 12);
caso("Neumococo 13 con nombre de movimientos", "neumo_1", "ANTINEUMOCOCICA CONJUGADA 13", "Primera", 2);
caso("Neumococo 20 con nombre de movimientos", "neumo20_1", "ANTINEUMOCOCICA CONJUGADA 20", "Primera", 2);
caso("Neumococo 23 con nombre de movimientos", "neumo23_60", "ANTINEUMOCOCICA POLISCARIDA 23", "Única", 720);
caso("Neumococo 13 sin valencia explícita", "neumo_1", "Antineumococicaconjugada", "Primera", 2);
caso("Neumococo 20 nombre Prevenar", "neumo20_1", "Prevenar20", "Primera", 2);
caso("Neumococo 20 por clave de formato", "neumo20_1", "354neumococica", "Primera", 2);
foreach (["neumo" => "ANTI NEUMOCOCO 13 VALENTE", "neumo20" => "ANTI NEUMOCOCO 20 VALENTE"] as $prefijo => $bio) {
    foreach (["Primera" => 1, "Segunda" => 2, "Tercera" => 3] as $dosis => $numero) {
        caso("$prefijo rescate $dosis a los 4 años", "{$prefijo}_rescate$numero", $bio, $dosis, 59);
        caso("$prefijo $dosis a los 5 años", null, $bio, $dosis, 60);
    }
    caso("$prefijo primera antes de 2 meses", null, $bio, "Primera", 1);
    caso("$prefijo primera a los 11 meses", "{$prefijo}_1", $bio, "Primera", 11);
    caso("$prefijo segunda a los 12 meses", "{$prefijo}_rescate2", $bio, "Segunda", 12);
    caso("$prefijo tercera a los 23 meses", "{$prefijo}_3", $bio, "Tercera", 23);
    caso("$prefijo tercera a los 24 meses", "{$prefijo}_rescate3", $bio, "Tercera", 24);
}
caso("Neumococo 23 sin riesgo antes de 60 años", null, "ANTI NEUMOCOCO 23 VALENTE", "Única", 719);
caso("Neumococo 23 sin riesgo a los 60 años", "neumo23_60", "ANTI NEUMOCOCO 23 VALENTE", "Única", 720);
caso("Neumococo 23 con riesgo a los 60 años", "neumo23_riesgo", "ANTI NEUMOCOCO 23 VALENTE", "Única", 720, ["comorbilidades" => "2"]);
caso("Neumococo 23 refuerzo con riesgo antes de 61 años", null, "ANTI NEUMOCOCO 23 VALENTE", "Refuerzo", 731, ["comorbilidades" => "2"]);
caso("Neumococo 23 refuerzo con riesgo a los 61 años", "neumo23_refuerzo_riesgo", "ANTI NEUMOCOCO 23 VALENTE", "Refuerzo", 732, ["comorbilidades" => "2"]);
caso("Neumococo 23 refuerzo sin riesgo", null, "ANTI NEUMOCOCO 23 VALENTE", "Refuerzo", 732);
caso("Neumococo 13 no usa renglón de refuerzo 23", null, "ANTI NEUMOCOCO 13 VALENTE", "Refuerzo", 732, ["comorbilidades" => "2"]);
caso("Neumococo polisacárido sin valencia", "neumo23_60", "Antineumococica polisacarida", "Única", 720);
caso("Neumococo con valencias contradictorias", null, "Antineumococica 13 y 23 valente", "Única", 720);
caso("Informe anual neumococo 23 en mayo", "neumo23_60", "ANTI NEUMOCOCO 23 VALENTE", "Única", 720);
$casos[count($casos) - 1]["anual"] = true;
$casos[count($casos) - 1]["entrada"]["anual"] = true;
caso("Informe anual neumococo 20 rescate", "neumo20_rescate2", "ANTI NEUMOCOCO 20 VALENTE", "Segunda", 36);
$casos[count($casos) - 1]["anual"] = true;
$casos[count($casos) - 1]["entrada"]["anual"] = true;
caso("Biológico desconocido", null, "Desconocido", "Única", 12);
caso("Hepatitis B sin clasificación", null, "HepatitisB", "Primera", 180);
caso("VPH con clasificación inválida", null, "VPH", "Única", 132, ["clasificacion_vph" => "invalida"]);
caso("VSR a las 27 semanas", null, "VSR", "Única", 240, ["embarazo" => 1, "fecha_ultima_menstruacion" => "2023-11-08"]);
caso("VSR a las 37 semanas", null, "VSR", "Única", 240, ["embarazo" => 1, "fecha_ultima_menstruacion" => "2023-08-30"]);
caso("Reemplazo DPT a los 7 años", null, "DPT", "Única", 84, ["nombre_biologico_indicado" => "Hexavalente"]);
caso("BCG migrante y población indígena", "bcg_1_4", "BCG", "Única", 12, [
    "estatus_migratorio" => "Migrante", "poblacion_indigena_afromexicana" => 1
]);
$casos[count($casos) - 1]["migrante"] = true;
$casos[count($casos) - 1]["indigena"] = true;
foreach (["violencia_9_14" => "vph_violencia_9_14", "violencia_15_19" => "vph_violencia_15_19"] as $clasificacion => $id) {
    $casos[] = [
        "nombre" => "VPH confidencial $clasificacion",
        "esperado" => $id,
        "entrada" => ["anonimas" => [[
            "fecha_aplicacion" => "2024-05-15", "clasificacion_vph" => $clasificacion,
            "es_migrante" => 1, "nombre_dosis" => "Única"
        ]]],
        "migrante" => true
    ];
}
$casos[] = [
    "nombre" => "VPH confidencial con clasificación inválida",
    "esperado" => null,
    "entrada" => ["anonimas" => [[
        "fecha_aplicacion" => "2024-05-15", "clasificacion_vph" => "invalida",
        "es_migrante" => 0, "nombre_dosis" => "Única"
    ]]]
];
$casos[] = [
    "nombre" => "Informe anual BCG en mayo",
    "esperado" => "bcg_1_4",
    "entrada" => ["anual" => true, "aplicaciones" => [$casos[3]["entrada"]["aplicaciones"][0]]],
    "anual" => true
];

$fallos = 0;
$cubiertos = [];
$aprobados = [];
$catalogo = [];
foreach ($casos as $caso) {
    $comando = [
        PHP_BINARY, "-n", "-d", "extension_dir=" . ini_get("extension_dir"),
        "-d", "extension=mbstring", __DIR__ . DIRECTORY_SEPARATOR . "paloteo_entorno.php"
    ];
    $proceso = proc_open($comando, [0 => ["pipe", "r"], 1 => ["pipe", "w"], 2 => ["pipe", "w"]], $pipes);
    if (!is_resource($proceso)) {
        fwrite(STDERR, "No se pudo iniciar el entorno aislado.\n");
        exit(2);
    }
    fwrite($pipes[0], json_encode($caso["entrada"], JSON_THROW_ON_ERROR));
    fclose($pipes[0]);
    $salida = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $errores = stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    $codigo = proc_close($proceso);
    $resultado = json_decode($salida, true);
    if ($codigo !== 0 || !is_array($resultado)) {
        fwrite(STDERR, "Error del entorno en {$caso["nombre"]}: $errores $salida\n");
        exit(2);
    }
    $catalogo = $resultado["renglones"];
    $observados = [];
    if (!empty($caso["anual"])) {
        foreach ($resultado["conteosPorMes"] as $id => $meses) {
            foreach ($meses as $mes => $total) {
                if ($total !== 0) {
                    $observados[$id . ":" . $mes] = $total;
                }
            }
        }
    } else {
        foreach ($resultado["automaticos"] as $id => $conteo) {
            $total = array_sum($conteo["dias"]) + $conteo["migrantes"];
            if ($total !== 0) {
                $observados[$id] = $total;
            }
        }
    }
    $claveEsperada = $caso["esperado"] . (!empty($caso["anual"]) ? ":5" : "");
    $esperado = $caso["esperado"] === null ? [] : [$claveEsperada => 1];
    $correcto = $observados === $esperado
        && $resultado["aplicacionesSinClasificar"] === ($caso["esperado"] === null ? 1 : 0);
    if ($caso["esperado"] !== null) {
        $cubiertos[$caso["esperado"]] = true;
    }
    if ($caso["esperado"] !== null && empty($caso["anual"])) {
        $conteo = $resultado["automaticos"][$caso["esperado"]];
        $migrante = !empty($caso["migrante"]);
        $indigena = !empty($caso["indigena"]);
        $correcto = $correcto
            && $conteo["migrantes"] === ($migrante ? 1 : 0)
            && $conteo["dias"][15] === ($migrante ? 0 : 1)
            && array_sum($conteo["dias"]) === ($migrante ? 0 : 1)
            && $conteo["indigenas"] === ($indigena ? 1 : 0)
            && $conteo["afromexicano"] === ($indigena ? 1 : 0);
    }
    if ($correcto) {
        if ($caso["esperado"] !== null) {
            $aprobados[$caso["esperado"]] = true;
        }
    } else {
        $fallos++;
        echo "FALLO {$caso["nombre"]}: esperado " . json_encode($esperado, JSON_UNESCAPED_UNICODE)
            . ", obtenido " . json_encode($observados, JSON_UNESCAPED_UNICODE)
            . ", sin clasificar {$resultado["aplicacionesSinClasificar"]}\n";
    }
}
$sinCaso = array_diff(array_keys($catalogo), array_keys($cubiertos));
$sinAprobar = array_diff(array_keys($catalogo), array_keys($aprobados));
echo "\nCasos: " . count($casos) . "; fallos: $fallos; renglones: " . count($catalogo)
    . "; con caso: " . (count($catalogo) - count($sinCaso))
    . "; con caso aprobado: " . (count($catalogo) - count($sinAprobar)) . ".\n";
if ($sinCaso) {
    echo "Renglones sin caso: " . implode(", ", $sinCaso) . "\n";
}
if ($sinAprobar) {
    echo "Renglones sin caso aprobado: " . implode(", ", $sinAprobar) . "\n";
}
exit($fallos || $sinCaso ? 1 : 0);
