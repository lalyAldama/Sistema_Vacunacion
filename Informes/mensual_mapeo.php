<?php

function codigosPaloteoInformeMensual(): array
{
    $mapa = [
        "bcg_24h" => "VBC01", "bcg_neonato" => "VBC02", "bcg_29d_11m" => "BIO50",
        "bcg_1_4" => "BIO03", "bcg_5_9" => "VBC03",
        "hepb_rn" => "VAC06", "hepb_mayor11_1" => "VHB01", "hepb_mayor11_2" => "VHB02",
        "hepb_riesgo_1" => "VHB03", "hepb_riesgo_2" => "VHB04",
        "hepb_salud_1" => "VHB05", "hepb_salud_2" => "VHB06",
        "hexa_1" => "VAC67", "hexa_2" => "VAC68", "hexa_3" => "VAC69",
        "hexa_ref_18" => "VAC70", "hexa_rescate1" => "VHX01", "hexa_rescate2" => "VHX02",
        "hexa_rescate3" => "VHX03", "hexa_rescate_refuerzo" => "VHX04",
        "dpt_unica_4" => "VAC12", "dpt_rescate_5_6" => "VAC13",
        "rota_1" => "VRV01", "rota_2" => "VRV02", "rota_rescate1" => "VRV03", "rota_rescate2" => "VRV04",
        "neumo_1" => "VAC17", "neumo_2" => "VAC18", "neumo_3" => "VAC19",
        "neumo_rescate1" => "VNC01", "neumo_rescate2" => "VNC02", "neumo_rescate3" => "VNC03",
        "neumo_unica60" => "VNC04", "neumo_riesgo60" => "VNC04",
        "neumo20_1" => "VCC01", "neumo20_2" => "VCC02", "neumo20_3" => "VCC03",
        "neumo20_rescate1" => "VCC04", "neumo20_rescate2" => "VCC05",
        "neumo20_rescate3" => "VCC06", "neumo20_60" => "VCC07",
        "srp_1" => "VAC23", "srp_2" => "VTV01", "srp_rescate1" => "VTV02", "srp_rescate2" => "VTV03",
        "sr_6m" => "VAC82", "sr_1_4" => "VAC91", "sr_10_19" => "VDV01", "sr_20_39" => "VDV02",
        "sr_40_49" => "VDV03", "sr_salud" => "VDV04", "sr_educativo" => "VDV05",
        "sr_jornalero" => "VDV06",
        "vph_ninas" => "VPH05", "vph_rezagados" => "VPH06", "vph_violencia_9_14" => "VPH07",
        "vph_violencia_15_19" => "VPH08", "vph_vih_1" => "VPH12", "vph_vih_2" => "VPH13",
        "vph_vih_3" => "VPH14", "varicela_unica_1" => "VAR02", "varicela_rezago_2_4" => "VAR03",
        "hepa_unica_18m" => "VHA01", "hepa_rezago_2_4" => "VHA02", "hepa_riesgo" => "BIO88",
        "tdpa_embarazo" => "VAC63", "tdpa_salud" => "VDP01", "vsr_embarazo_28_36" => "VS001",
        "covid_6_59m_primera" => "VCV38", "covid_6_59m_segunda" => "VCV39",
        "covid_6_59m_refuerzo" => "VCV40", "covid_riesgo_5_59" => "VCV28",
        "covid_60_plus" => "VCV16", "covid_embarazadas" => "VCV20", "covid_salud" => "VCV21"
    ];
    $embarazadas = [
        "primera" => ["VAC39", "VAC40"],
        "segunda" => ["VAC47", "VAC48"],
        "tercera" => ["VTD01", "VTD02"],
        "refuerzo" => ["VAC55", "VAC56"]
    ];
    $poblacion = [
        "primera" => ["VTT01", "VTT02", "VTT03"],
        "segunda" => ["VTT04", "VTT05", "VTT06"],
        "tercera" => ["VTT07", "VTT08", "VTT09"],
        "refuerzo" => ["VTT10", "VTT11", "VTT12"]
    ];
    foreach ($embarazadas as $dosis => $codigos) {
        foreach (["10_19", "20_plus"] as $indice => $edad) {
            $mapa["td_{$dosis}_embarazadas_{$edad}"] = $codigos[$indice];
        }
    }
    foreach ($poblacion as $dosis => $codigos) {
        foreach (["15_19", "20_59", "60_plus"] as $indice => $edad) {
            $mapa["td_{$dosis}_poblacion_{$edad}"] = $codigos[$indice];
        }
    }
    return $mapa;
}
