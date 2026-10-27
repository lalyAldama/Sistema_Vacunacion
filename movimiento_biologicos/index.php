<?php
require_once __DIR__ . "/../config/seguridad.php";

if (!isset($_SESSION["id_usuario"])) {
    header("Location: ../login.php");
    exit();
}

require_once "../config/conexion.php";

$zonaHoraria = new DateTimeZone("America/Mexico_City");
$mesActual = (new DateTimeImmutable("today", $zonaHoraria))->format("Y-m");
$mesSolicitado = $_GET["mes"] ?? "";
$mesValido = is_string($mesSolicitado)
    && preg_match('/^(\d{4})-(\d{2})$/', $mesSolicitado, $partes)
    && checkdate((int)$partes[2], 1, (int)$partes[1])
    && $mesSolicitado <= $mesActual;
$mes = $mesValido ? $mesSolicitado : $mesActual;
$puedeRegistrarMovimientos = $mes === $mesActual;
$inicioMes = $mes . "-01";
$finMes = date("Y-m-d", strtotime($inicioMes . " +1 month"));
$inicioAnio = substr($mes, 0, 4) . "-01-01";
$nombreMes = date("F Y", strtotime($inicioMes));
$meses = ["January"=>"enero", "February"=>"febrero", "March"=>"marzo", "April"=>"abril", "May"=>"mayo", "June"=>"junio", "July"=>"julio", "August"=>"agosto", "September"=>"septiembre", "October"=>"octubre", "November"=>"noviembre", "December"=>"diciembre"];
$nombreMes = strtr($nombreMes, $meses);
$nombreMesEncabezado = mb_strtoupper(strtr(date("F", strtotime($inicioMes)), $meses), "UTF-8");
$anioEncabezado = substr($mes, 0, 4);

$stmtUnidad = $conexion->prepare("SELECT id_unidad FROM USUARIO WHERE id_usuario = ?");
$stmtUnidad->bind_param("i", $_SESSION["id_usuario"]);
$stmtUnidad->execute();
$unidad = $stmtUnidad->get_result()->fetch_assoc();
$stmtUnidad->close();
if (empty($unidad["id_unidad"])) {
    die("El usuario no tiene una unidad asignada.");
}
$idUnidad = (int)$unidad["id_unidad"];

function cargarFilas($conexion, $sql, $tipos = "", ...$params) {
    $stmt = $conexion->prepare($sql);
    if (!$stmt) die("Error al preparar el informe: " . htmlspecialchars($conexion->error));
    if ($tipos !== "") $stmt->bind_param($tipos, ...$params);
    if (!$stmt->execute()) die("Error al consultar el informe: " . htmlspecialchars($stmt->error));
    $resultado = $stmt->get_result();
    $filas = $resultado->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $filas;
}

$biologicos = cargarFilas($conexion, "SELECT b.id_biologico, b.nombre_biologico,
                                            p.id_presentacion, p.nombre_presentacion
                                     FROM BIOLOGICO b
                                     LEFT JOIN PRESENTACION p ON p.id_biologico = b.id_biologico
                                     ORDER BY b.nombre_biologico, p.nombre_presentacion");

function normalizarNombre($texto) {
    $texto = mb_strtoupper(trim($texto), "UTF-8");
    $texto = strtr($texto, ["Á"=>"A", "É"=>"E", "Í"=>"I", "Ó"=>"O", "Ú"=>"U", "Ü"=>"U", "Ñ"=>"N"]);
    return preg_replace('/[^A-Z0-9]+/', '', $texto);
}

$catalogo = [
    ["grupo"=>"BCG", "filas"=>[["BCG", ["BCG"]]]],
    ["grupo"=>"HEPATITIS B", "familiaEdad"=>"HEPATITIS B", "filas"=>[
        ["ANTI HEPATITIS B Pediatrica", ["ANTI HEPATITIS B", "HEPATITIS B PEDIATRICA", "PRESENTACION HEPATITIS B PEDIATRICA"]],
        ["ANTI HEPATITIS B Adultos", ["ANTI HEPATITIS B", "HEPATITIS B ADULTOS", "PRESENTACION HEPATITIS B ADULTOS"]]
    ]],
    ["grupo"=>"HEPATITIS A", "filas"=>[["ANTI HEPATITIS A", ["ANTI HEPATITIS A", "HEPATITIS A"]]]],
    ["grupo"=>"DPT", "filas"=>[["DPT", ["DPT"]]]],
    ["grupo"=>"ROTAVIRUS", "filas"=>[["ROTAVIRUS\nRV1", ["ROTAVIRUS RV1", "ROTAVIRUS", "ANTIROTAVIRUS"]]]],
    ["grupo"=>"HEXAVALENTE", "filas"=>[
        ["HEXAVALENTE\n(DPTa+HB+VPI+Hib)", ["HEXAVALENTE DPTA HB VPI HIB", "HEXAVALENTE", "HEXAVALENTE ACELULAR"]],
        ["HEXAVALENTE/DPT", ["HEXAVALENTE DPT", "HEXAVALENTE DPT REEMPLAZO"]]
    ]],
    ["grupo"=>"NEUMOCOCO 13", "filas"=>[["ANTI NEUMOCOCO 13 VALENTE", ["ANTI NEUMOCOCO 13 VALENTE", "ANTINEUMOCOCO 13 VALENTE", "ANTINEUMOCOCICA CONJUGADA 13"]]]],
    ["grupo"=>"NEUMOCOCO 20", "filas"=>[["ANTI NEUMOCOCO 20 VALENTE", ["ANTI NEUMOCOCO 20 VALENTE", "ANTINEUMOCOCO 20 VALENTE", "ANTI NEUMO COCCICA 20 VALENTE", "ANTI NEUMO COCCO 20 VALENTE", "ANTINEUMOCOCICA CONJUGADA 20"]]]],
    ["grupo"=>"NEUMOCOCO 23", "filas"=>[["ANTI NEUMOCOCO 23 VALENTE", ["ANTI NEUMOCOCO 23 VALENTE", "ANTINEUMOCOCO 23 VALENTE", "ANTI NEUMO COCCICA 23 VALENTE", "ANTI NEUMO COCCO 23 VALENTE", "ANTINEUMOCOCICA POLISCARIDA 23"]]]],
    ["grupo"=>"TRIPLE VIRAL", "filas"=>[["TRIPLE VIRAL SRP", ["TRIPLE VIRAL SRP", "SRP"]]]],
    ["grupo"=>"DOBLE VIRAL", "filas"=>[["DOBLE VIRAL SR", ["DOBLE VIRAL SR", "DOBLE VIRAL"]]]],
    ["grupo"=>"VPH", "filas"=>[["VPH", ["VPH", "VIRUS DEL PAPILOMA HUMANO"]]]],
    ["grupo"=>"VARICELA", "filas"=>[["ANTI VARICELA", ["ANTI VARICELA", "VARICELA"]]]],
    ["grupo"=>"TD", "filas"=>[["TD", ["TD"]]]],
    ["grupo"=>"TDPA", "filas"=>[
        ["TDPA", ["TDPA"]], ["TDPA/TD", ["TDPA TD", "TDPA/TD"]], ["TDPA/DPT", ["TDPA DPT", "TDPA/DPT"]]
    ]],
    ["grupo"=>"INFLUENZA", "familiaEdad"=>"INFLUENZA", "filas"=>[
        ["ANTI INFLUENZA ESTACIONAL\nNIÑOS", ["ANTI INFLUENZA ESTACIONAL NINOS", "INFLUENZA ESTACIONAL NINOS", "INFLUENZA ESTACIONAL PEDIATRICA", "ANTI INFLUENZA PEDIATRICA", "PRESENTACION INFLUENZA PEDIATRICA"]],
        ["ANTI INFLUENZA ESTACIONAL\nADULTOS", ["ANTI INFLUENZA ESTACIONAL ADULTOS", "INFLUENZA ESTACIONAL ADULTOS", "PRESENTACION INFLUENZA ADULTOS"]]
    ]],
    ["grupo"=>"COVID PFIZER", "filas"=>[["COVID-19 PFIZER", ["COVID 19 PFIZER", "PFIZER COVID 19"]]]],
    ["grupo"=>"COVID MODERNA", "familiaEdad"=>"COVID MODERNA", "filas"=>[
        ["COVID-19 MODERNA NIÑOS", ["COVID 19 MODERNA NINOS", "MODERNA NINOS", "PRESENTACION COVID MODERNA PEDIATRICA"]],
        ["COVID-19 MODERNA ADULTOS", ["COVID 19 MODERNA ADULTOS", "MODERNA ADULTOS", "PRESENTACION COVID MODERNA ADULTOS"]]
    ]],
    ["grupo"=>"COVID OTRA", "filas"=>[["COVID-19 OTRA", ["COVID 19 OTRA", "OTRA COVID 19"]]]],
    ["grupo"=>"VSR", "filas"=>[["VIRUS SINCITAL\nRESPIRATORIO VSR", ["VIRUS SINCITAL RESPIRATORIO VSR", "VSR"]]]]
];
$idsPorNombre = [];
$presentacionesPorNombre = $presentacionesPorBiologico = [];
foreach ($biologicos as $biologico) {
    $idBio = (int)$biologico["id_biologico"];
    $idsPorNombre[normalizarNombre($biologico["nombre_biologico"])] = $idBio;
    if (!empty($biologico["id_presentacion"])) {
        $idPresentacion = (int)$biologico["id_presentacion"];
        $presentacionesPorNombre[normalizarNombre($biologico["nombre_presentacion"])] = ["id"=>$idPresentacion, "biologico"=>$idBio];
        $presentacionesPorBiologico[$idBio][] = $idPresentacion;
    }
}
foreach ($catalogo as &$grupo) {
    foreach ($grupo["filas"] as &$fila) {
        $fila["ids"] = [];
        $fila["presentaciones"] = [];
        foreach ($fila[1] as $alias) {
            $clave = normalizarNombre($alias);
            if (isset($idsPorNombre[$clave])) $fila["ids"][] = $idsPorNombre[$clave];
            if (isset($presentacionesPorNombre[$clave])) {
                $fila["presentaciones"][] = $presentacionesPorNombre[$clave]["id"];
                $fila["ids"][] = $presentacionesPorNombre[$clave]["biologico"];
            }
        }
        if ($grupo["grupo"] === "INFLUENZA") {
            $nombreFila = normalizarNombre($fila[0]);
            $esFilaPediatrica = strpos($nombreFila, "NINOS") !== false
                || strpos($nombreFila, "PEDIATR") !== false;
            foreach ($presentacionesPorNombre as $nombrePresentacion => $presentacion) {
                if (strpos($nombrePresentacion, "INFLUENZA") === false) continue;

                $esPresentacionPediatrica = strpos($nombrePresentacion, "NINO") !== false
                    || strpos($nombrePresentacion, "PEDIATR") !== false;
                $esPresentacionAdulta = strpos($nombrePresentacion, "ADULT") !== false;
                if (
                    ($esFilaPediatrica && $esPresentacionPediatrica)
                    || (!$esFilaPediatrica && $esPresentacionAdulta)
                ) {
                    $fila["presentaciones"][] = $presentacion["id"];
                    $fila["ids"][] = $presentacion["biologico"];
                }
            }
        }
        $fila["ids"] = array_values(array_unique($fila["ids"]));
        $fila["presentaciones"] = array_values(array_unique($fila["presentaciones"]));
        
        if (!$fila["presentaciones"]) {
            foreach ($fila["ids"] as $idBio) $fila["presentaciones"] = array_merge($fila["presentaciones"], $presentacionesPorBiologico[$idBio] ?? []);
            $fila["presentaciones"] = array_values(array_unique($fila["presentaciones"]));
        }
        if (isset($grupo["familiaEdad"])) {
            $fila["familiaEdad"] = $grupo["familiaEdad"];
            $fila["edadReporte"] = strpos(normalizarNombre($fila[0]), "PEDIATRICA") !== false
                || strpos(normalizarNombre($fila[0]), "NINOS") !== false
                ? "pediatrica"
                : "adulto";
        }
    }
    unset($fila);
}
unset($grupo);


$sql = "SELECT p.id_biologico, COUNT(*) AS total
        FROM APLICACION_VACUNA av
        INNER JOIN LOTE l ON l.id_lote = av.id_lote
        INNER JOIN PRESENTACION p ON p.id_presentacion = l.id_presentacion
        WHERE av.id_unidad = ? AND av.fecha_aplicacion >= ? AND av.fecha_aplicacion < ?
        GROUP BY p.id_biologico";
$aplicadasMes = cargarFilas($conexion, $sql, "iss", $idUnidad, $inicioMes, $finMes);
$sql = "SELECT p.id_biologico, COUNT(*) AS total
        FROM APLICACION_VACUNA av
        INNER JOIN LOTE l ON l.id_lote = av.id_lote
        INNER JOIN PRESENTACION p ON p.id_presentacion = l.id_presentacion
        WHERE av.id_unidad = ? AND av.fecha_aplicacion >= ? AND av.fecha_aplicacion < ?
        GROUP BY p.id_biologico";
$aplicadasAnio = cargarFilas($conexion, $sql, "iss", $idUnidad, $inicioAnio, $finMes);

$sqlDesecho = "SELECT p.id_presentacion,
                      SUM(CASE WHEN mb.fecha_movimiento >= ? AND mb.fecha_movimiento < ? THEN mb.cantidad_dosis ELSE 0 END) AS mes,
                      SUM(CASE WHEN mb.fecha_movimiento >= ? AND mb.fecha_movimiento < ? THEN mb.cantidad_dosis ELSE 0 END) AS anio
               FROM MOVIMIENTO_BIOLOGICO mb
               INNER JOIN LOTE l ON l.id_lote = mb.id_lote
               INNER JOIN PRESENTACION p ON p.id_presentacion = l.id_presentacion
               WHERE mb.id_unidad = ? AND mb.tipo_movimiento = 'Desecho'
                 AND mb.fecha_movimiento < ?
               GROUP BY p.id_presentacion";
$desechos = cargarFilas($conexion, $sqlDesecho, "ssssis", $inicioMes, $finMes, $inicioAnio, $finMes, $idUnidad, $finMes);

$sqlEntradas = "SELECT p.id_presentacion,
                       SUM(CASE WHEN mb.fecha_movimiento >= ? AND mb.fecha_movimiento < ? THEN mb.cantidad_frascos ELSE 0 END) AS mes,
                       SUM(CASE WHEN mb.fecha_movimiento >= ? AND mb.fecha_movimiento < ? THEN mb.cantidad_frascos ELSE 0 END) AS anio
                FROM MOVIMIENTO_BIOLOGICO mb
                INNER JOIN LOTE l ON l.id_lote = mb.id_lote
                INNER JOIN PRESENTACION p ON p.id_presentacion = l.id_presentacion
                WHERE mb.id_unidad = ? AND mb.tipo_movimiento = 'Entrada' AND mb.fecha_movimiento < ?
                GROUP BY p.id_presentacion";
$entradas = cargarFilas($conexion, $sqlEntradas, "ssssis", $inicioMes, $finMes, $inicioAnio, $finMes, $idUnidad, $finMes);


$sqlLotes = "SELECT b.id_biologico, b.nombre_biologico, p.id_presentacion, l.id_lote, l.numero_lote,
                    l.fecha_caducidad, p.dosis_por_frasco,
                    SUM(CASE WHEN mb.tipo_movimiento = 'Entrada' THEN mb.cantidad_frascos ELSE 0 END) AS recibidos,
                    (SELECT COUNT(*) FROM APLICACION_VACUNA av
                     WHERE av.id_lote = l.id_lote AND av.id_unidad = ? AND av.fecha_aplicacion < ?) AS aplicaciones,
                    SUM(CASE WHEN mb.tipo_movimiento = 'Desecho' THEN mb.cantidad_dosis ELSE 0 END) AS dosis_desechadas
             FROM LOTE l
             INNER JOIN PRESENTACION p ON p.id_presentacion = l.id_presentacion
             INNER JOIN BIOLOGICO b ON b.id_biologico = p.id_biologico
             LEFT JOIN MOVIMIENTO_BIOLOGICO mb ON mb.id_lote = l.id_lote AND mb.id_unidad = ? AND mb.fecha_movimiento < ?
             GROUP BY b.id_biologico, b.nombre_biologico, p.id_presentacion, l.id_lote, l.numero_lote, l.fecha_caducidad, p.dosis_por_frasco
             HAVING recibidos <> 0 OR aplicaciones <> 0 OR dosis_desechadas <> 0
             ORDER BY b.nombre_biologico, l.fecha_caducidad, l.numero_lote";
$lotesSaldo = cargarFilas($conexion, $sqlLotes, "isis", $idUnidad, $finMes, $idUnidad, $finMes);
$lotesApertura = cargarFilas($conexion, $sqlLotes, "isis", $idUnidad, $inicioMes, $idUnidad, $inicioMes);

$sqlLotesMes = "SELECT b.id_biologico, p.id_presentacion, l.id_lote, l.numero_lote,
                       l.fecha_caducidad, SUM(mb.cantidad_frascos) AS frascos,
                       GROUP_CONCAT(DISTINCT NULLIF(TRIM(mb.observaciones), '') SEPARATOR ' | ') AS observaciones
                FROM MOVIMIENTO_BIOLOGICO mb
                INNER JOIN LOTE l ON l.id_lote = mb.id_lote
                INNER JOIN PRESENTACION p ON p.id_presentacion = l.id_presentacion
                INNER JOIN BIOLOGICO b ON b.id_biologico = p.id_biologico
                WHERE mb.id_unidad = ? AND mb.tipo_movimiento = 'Entrada'
                  AND mb.fecha_movimiento >= ? AND mb.fecha_movimiento < ?
                GROUP BY b.id_biologico, p.id_presentacion, l.id_lote, l.numero_lote, l.fecha_caducidad
                ORDER BY b.nombre_biologico, l.fecha_caducidad";
$lotesEntradasMes = cargarFilas($conexion, $sqlLotesMes, "iss", $idUnidad, $inicioMes, $finMes);

$sqlLotesDesecho = "SELECT p.id_presentacion, l.id_lote,
                           GROUP_CONCAT(DISTINCT NULLIF(TRIM(mb.observaciones), '') SEPARATOR ' | ') AS observaciones
                    FROM MOVIMIENTO_BIOLOGICO mb
                    INNER JOIN LOTE l ON l.id_lote = mb.id_lote
                    INNER JOIN PRESENTACION p ON p.id_presentacion = l.id_presentacion
                    WHERE mb.id_unidad = ? AND mb.tipo_movimiento = 'Desecho'
                      AND mb.fecha_movimiento >= ? AND mb.fecha_movimiento < ?
                    GROUP BY p.id_presentacion, l.id_lote";
$lotesDesechoMes = cargarFilas($conexion, $sqlLotesDesecho, "iss", $idUnidad, $inicioMes, $finMes);


$sqlLotesDonacion = "SELECT p.id_presentacion, l.id_lote,
                            SUM(mb.cantidad_dosis) AS dosis_donadas,
                            GROUP_CONCAT(DISTINCT u.nombre_unidad SEPARATOR ' | ') AS unidad_receptora,
                            GROUP_CONCAT(DISTINCT NULLIF(TRIM(mb.observaciones), '') SEPARATOR ' | ') AS observaciones
                     FROM MOVIMIENTO_BIOLOGICO mb
                     INNER JOIN LOTE l ON l.id_lote = mb.id_lote
                     INNER JOIN PRESENTACION p ON p.id_presentacion = l.id_presentacion
                     LEFT JOIN UNIDAD u ON u.id_unidad = mb.id_unidad_receptora
                     WHERE mb.id_unidad = ?
                       AND mb.tipo_movimiento = 'Donación'
                       AND mb.fecha_movimiento >= ?
                       AND mb.fecha_movimiento < ?
                     GROUP BY p.id_presentacion, l.id_lote";

$lotesDonacionMes = cargarFilas(
    $conexion,
    $sqlLotesDonacion,
    "iss",
    $idUnidad,
    $inicioMes,
    $finMes
);

$sqlJeringas = "SELECT j.id_jeringa, j.tipo,
                       COALESCE(SUM(CASE
                           WHEN mj.fecha_movimiento < ? THEN
                               CASE WHEN mj.tipo_movimiento = 'Entrada' THEN mj.cantidad_jeringas ELSE -mj.cantidad_jeringas END
                           ELSE 0
                       END), 0) AS existencia_inicial,
                       COALESCE(SUM(CASE
                           WHEN mj.fecha_movimiento >= ? AND mj.fecha_movimiento < ?
                                AND mj.tipo_movimiento = 'Entrada' THEN mj.cantidad_jeringas
                           ELSE 0
                       END), 0) AS entradas_mes,
                       COALESCE(SUM(CASE
                           WHEN mj.fecha_movimiento >= ? AND mj.fecha_movimiento < ?
                                AND mj.tipo_movimiento = 'Uso' THEN mj.cantidad_jeringas
                           ELSE 0
                       END), 0) AS usos_mes,
                       COALESCE(SUM(CASE
                           WHEN mj.fecha_movimiento >= ? AND mj.fecha_movimiento < ?
                                AND mj.tipo_movimiento = 'Desecho' THEN mj.cantidad_jeringas
                           ELSE 0
                       END), 0) AS desechos_mes,
                       COALESCE(SUM(CASE
                           WHEN mj.fecha_movimiento >= ? AND mj.fecha_movimiento < ?
                                AND mj.tipo_movimiento = 'Uso' THEN mj.cantidad_jeringas
                           ELSE 0
                       END), 0) AS usos_anio,
                       COALESCE(SUM(CASE
                           WHEN mj.fecha_movimiento < ? THEN
                               CASE WHEN mj.tipo_movimiento = 'Entrada' THEN mj.cantidad_jeringas ELSE -mj.cantidad_jeringas END
                           ELSE 0
                       END), 0) AS existencia_final,
                       COALESCE((
                           SELECT mj2.necesidad
                           FROM MOVIMIENTO_JERINGA mj2
                           WHERE mj2.id_jeringa = j.id_jeringa
                             AND mj2.id_unidad = ?
                             AND mj2.fecha_movimiento < ?
                           ORDER BY mj2.fecha_movimiento DESC, mj2.id_movimiento_jeringa DESC
                           LIMIT 1
                       ), 0) AS necesidad
                FROM JERINGA j
                LEFT JOIN MOVIMIENTO_JERINGA mj
                    ON mj.id_jeringa = j.id_jeringa
                   AND mj.id_unidad = ?
                   AND mj.fecha_movimiento < ?
                GROUP BY j.id_jeringa, j.tipo
                ORDER BY j.tipo";
$jeringas = cargarFilas(
    $conexion,
    $sqlJeringas,
    str_repeat("s", 10) . "isis",
    $inicioMes,
    $inicioMes,
    $finMes,
    $inicioMes,
    $finMes,
    $inicioMes,
    $finMes,
    $inicioAnio,
    $finMes,
    $finMes,
    $idUnidad,
    $finMes,
    $idUnidad,
    $finMes
);


function mapaPorBiologico($filas, $campo = "total", $idCampo = "id_biologico") {
    $mapa = [];
    foreach ($filas as $fila) $mapa[(int)$fila[$idCampo]] = $fila[$campo];
    return $mapa;
}
$appsMes = mapaPorBiologico($aplicadasMes);
$appsAnio = mapaPorBiologico($aplicadasAnio);
$desechoMes = mapaPorBiologico($desechos, "mes", "id_presentacion");
$desechoAnio = mapaPorBiologico($desechos, "anio", "id_presentacion");
$entradasMes = mapaPorBiologico($entradas, "mes", "id_presentacion");
$entradasAnio = mapaPorBiologico($entradas, "anio", "id_presentacion");
$detallePorBio = $aperturaPorBio = [];
foreach ($lotesEntradasMes as $lote) $detallePorBio[(int)$lote["id_presentacion"]][(int)$lote["id_lote"]]["entrada"] = $lote;
foreach ($lotesSaldo as $lote) $detallePorBio[(int)$lote["id_presentacion"]][(int)$lote["id_lote"]]["saldo"] = $lote;
foreach ($lotesDesechoMes as $lote) $detallePorBio[(int)$lote["id_presentacion"]][(int)$lote["id_lote"]]["desecho"] = $lote;
foreach ($lotesDonacionMes as $lote) {
    $detallePorBio[(int)$lote["id_presentacion"]][(int)$lote["id_lote"]]["donacion"] = $lote;
}

$appsEdadMes = $appsEdadAnio = [];
$rangosEdad = ["mes"=>[$inicioMes, $finMes], "anio"=>[$inicioAnio, $finMes]];
foreach ($catalogo as $grupo) {
    if (empty($grupo["familiaEdad"])) continue;
    $idsFamilia = [];
    foreach ($grupo["filas"] as $definicion) $idsFamilia = array_merge($idsFamilia, $definicion["ids"]);
    $idsFamilia = array_values(array_unique(array_map("intval", $idsFamilia)));
    if (!$idsFamilia) continue;
    $listaIds = implode(",", $idsFamilia);
    foreach ($rangosEdad as $periodo => [$desde, $hasta]) {
        $sqlEdad = "SELECT
                        SUM(CASE WHEN DATEDIFF(av.fecha_aplicacion, pa.fecha_nacimiento) BETWEEN 0 AND 7 THEN 1 ELSE 0 END) AS pediatrica,
                        SUM(CASE WHEN DATEDIFF(av.fecha_aplicacion, pa.fecha_nacimiento) >= 8 THEN 1 ELSE 0 END) AS adulto
                    FROM APLICACION_VACUNA av
                    INNER JOIN PACIENTE pa ON pa.id_paciente = av.id_paciente
                    INNER JOIN LOTE l ON l.id_lote = av.id_lote
                    INNER JOIN PRESENTACION pr ON pr.id_presentacion = l.id_presentacion
                    WHERE av.id_unidad = ? AND av.fecha_aplicacion >= ? AND av.fecha_aplicacion < ?
                      AND pr.id_biologico IN ($listaIds)";
        $conteoEdad = cargarFilas($conexion, $sqlEdad, "iss", $idUnidad, $desde, $hasta)[0] ?? ["pediatrica"=>0, "adulto"=>0];
        $destino = $periodo === "mes" ? "appsEdadMes" : "appsEdadAnio";
        ${$destino}[$grupo["familiaEdad"]] = $conteoEdad;
    }
}

function h($valor) { return htmlspecialchars((string)$valor, ENT_QUOTES, "UTF-8"); }
function fmt($valor) { return number_format((float)$valor, 2, ".", ""); }
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Movimiento de biológicos</title>
    <link rel="stylesheet" href="../css/estilos.css">
    <style>
        .filtro-informe { margin: 16px 0; }
        .tabla-movimiento { overflow-x: auto; }
        .tabla-movimiento table { min-width: 1250px; border-collapse: collapse; }
        .tabla-movimiento th, .tabla-movimiento td { border: 1px solid #333; padding: 6px; text-align: center; vertical-align: middle; }
        .tabla-movimiento th { background: #f1f1f1; }
        .tabla-movimiento thead tr:first-child th:first-child { background: #fff200; }
        .tabla-jeringas table { min-width: 950px; }
        .tabla-movimiento td.biologico { text-align: center; }
        .detalle-lote { display: block; white-space: nowrap; }
        .saldo-negativo { color: #b00020; font-weight: bold; }
        .nota-reemplazo { margin: 12px 0; }
        .detalle-enlace {
            display: inline-block;
            margin-top: 4px;
            color: #687386;
            font-size: 0.82em;
            text-decoration: none;
        }
        .detalle-enlace:hover {
            color: #245a84;
            text-decoration: underline;
        }
        .movimiento {
            display: inline-block;
            padding: 3px 6px;
            border-radius: 3px;
            font-weight: 700;
        }
        .movimiento-entrada {
            color: #145c2b;
            background: #b8f2c8;
        }
        .movimiento-desecho {
            color: #124e75;
            background: #b8e5ff;
        }
        .movimiento-donacion {
            color: #665000;
            background: #fff176;
        }
        .movimiento .detalle-enlace {
            color: inherit;
        }
        .botones button:disabled {
            background: #87949a;
            cursor: not-allowed;
            opacity: 0.7;
        }
        .encabezado-institucional {
            display: grid;
            grid-template-columns: repeat(12, minmax(0, 1fr));
            gap: 0 10px;
            margin: 0 0 22px;
            padding: 14px;
            border: 1px solid #DCE9ED;
            border-left: 5px solid #347F91;
            border-radius: 8px;
            background: #fff;
            color: #34495E;
            box-shadow: 0 2px 8px rgba(40, 95, 112, 0.08);
        }
        .encabezado-institucional h2 {
            grid-column: 1 / -1;
            margin: 0 0 6px;
            padding: 2px 0 8px;
            border-bottom: 2px solid #347F91;
            color: #285F70;
            font-size: 14px;
            text-align: center;
        }
        .campo-encabezado {
            display: flex;
            align-items: center;
            min-width: 0;
            gap: 6px;
            padding: 5px 2px;
            border-bottom: 1px solid #DCE9ED;
        }
        .campo-encabezado label {
            flex: 0 0 auto;
            margin: 0;
            color: #285F70;
            font-size: 10px;
            white-space: nowrap;
        }
        .campo-encabezado input {
            min-width: 0;
            min-height: 25px;
            padding: 2px 4px;
            border: 0;
            border-radius: 0;
            border-bottom: 2px solid #347F91;
            background: transparent;
            color: #34495E;
            font-size: 12px;
            font-weight: 600;
            text-align: center;
        }
        .campo-encabezado input:focus {
            outline: 1px solid #347F91;
            box-shadow: none;
        }
        .campo-entidad { grid-column: span 6; }
        .campo-region { grid-column: span 3; }
        .campo-unidad { grid-column: span 6; }
        .campo-clues { grid-column: span 6; }
        .campo-elabora { grid-column: span 6; }
        .campo-puesto { grid-column: span 6; }
        .campo-periodo { grid-column: span 2; }
        .estado-encabezado {
            grid-column: 1 / -1;
            margin: 4px 0 0;
            color: #52636b;
            font-size: 11px;
            text-align: right;
        }
        @media (max-width: 700px) {
            .campo-encabezado { grid-column: span 6; }
            .campo-entidad, .campo-unidad, .campo-elabora { grid-column: 1 / -1; }
        }
        @media print {
            .campo-encabezado input {
                border: 0;
                border-bottom: 2px solid #347F91;
                appearance: none;
                -webkit-appearance: none;
            }
            .estado-encabezado { display: none; }
        }
    </style>
</head>
<body>
    <h1>MOVIMIENTO DE BIOLÓGICOS Y JERINGAS</h1>
    <section class="encabezado-institucional" aria-label="Encabezado institucional editable">
        <h2>SERVICIOS DE SALUD DE CHIHUAHUA</h2>
        <div class="campo-encabezado campo-entidad">
            <label for="encabezado-entidad">ENTIDAD FEDERATIVA</label>
            <input id="encabezado-entidad" data-encabezado="entidad" value="CHIHUAHUA">
        </div>
        <div class="campo-encabezado campo-region">
            <label for="encabezado-region">REGIÓN</label>
            <input id="encabezado-region" data-encabezado="region" value="3">
        </div>
        <div class="campo-encabezado campo-unidad">
            <label for="encabezado-unidad">UNIDAD</label>
            <input id="encabezado-unidad" data-encabezado="unidad" value="CENTRO DE SALUD COLONIA ALMANCÉN">
        </div>
        <div class="campo-encabezado campo-clues">
            <label for="encabezado-clues">CLUES</label>
            <input id="encabezado-clues" data-encabezado="clues" value="CHSSA001685">
        </div>
        <div class="campo-encabezado campo-elabora">
            <label for="encabezado-elabora">ELABORA</label>
            <input id="encabezado-elabora" data-encabezado="elabora" value="ENFRA. Remedios Díaz García">
        </div>
        <div class="campo-encabezado campo-puesto">
            <label for="encabezado-puesto">PUESTO</label>
            <input id="encabezado-puesto" data-encabezado="puesto" value="ENFERMERO">
        </div>
        <div class="campo-encabezado campo-periodo">
            <label for="encabezado-mes">MES</label>
            <input id="encabezado-mes" value="<?php echo h($nombreMesEncabezado); ?>" readonly>
        </div>
        <div class="campo-encabezado campo-periodo">
            <label for="encabezado-anio">AÑO</label>
            <input id="encabezado-anio" value="<?php echo h($anioEncabezado); ?>" readonly>
        </div>

    </section>
        <form class="filtro-informe" method="get">
        <label for="mes"><strong>MES DEL INFORME:</strong></label>
        <input id="mes" name="mes" type="month" max="<?php echo h($mesActual); ?>" value="<?php echo h($mes); ?>">
        <button type="submit">CONSULTAR</button>
    </form>

    <h2>MOVIMIENTO DE BIOLÓGICOS</h2>
    <div class="botones">
        <?php if ($puedeRegistrarMovimientos): ?>
        <a href="entrada.php"><button>+ NUEVA ENTRADA</button></a>
        <a href="desecho.php"><button>+ NUEVO DESECHO</button></a>
        <a href="donacion.php"><button>+ NUEVA DONACIÓN</button></a>
        <?php else: ?>
        <button type="button" disabled>+ NUEVA ENTRADA</button>
        <button type="button" disabled>+ NUEVO DESECHO</button>
        <button type="button" disabled>+ NUEVA DONACIÓN</button>
        <?php endif; ?>
    </div>
    <?php if (!$puedeRegistrarMovimientos): ?>
        <p role="status">Estás consultando un periodo anterior. Los registros están deshabilitados para proteger el informe histórico.</p>
    <?php endif; ?>
    <p><strong>PERIODO:</strong> <?php echo h(mb_strtoupper($nombreMes, "UTF-8")); ?></p>
    <div class="contenedor-tabla tabla-movimiento">
        <table>
            <thead>
                <tr>
                    <th rowspan="3">EXISTENCIA DEL MES ANTERIOR<br>EN FRASCOS</th>
                    <th rowspan="3">BIOLÓGICO</th>
                    <th colspan="4">FRASCOS RECIBIDOS</th>
                    <th colspan="2">DOSIS EN EL MES</th>
                    <th colspan="2">EN EL AÑO</th>
                    <th colspan="3">EXISTENCIA AL CORTE DEL INFORME</th>
                    <th rowspan="3">DOSIS DONADAS</th>
                    <th rowspan="3">UNIDAD QUE RECIBIÓ LA DONACIÓN</th>
                    <th rowspan="3">OBSERVACIONES</th>
                    <th rowspan="3">NECESIDAD</th>
                </tr>
                <tr>
                    <th colspan="3">EN EL MES</th><th rowspan="2">EN EL AÑO</th>
                    <th rowspan="2">APLICADAS</th><th rowspan="2">DESECHADAS</th>
                    <th rowspan="2">APLICADAS</th><th rowspan="2">DESECHADAS</th>
                    <th rowspan="2">CANTIDAD</th><th rowspan="2">LOTE</th><th rowspan="2">CADUCIDAD</th>
                </tr>
                <tr><th>CANTIDAD</th><th>LOTE</th><th>CADUCIDAD</th></tr>
            </thead>
            <tbody>
            <?php foreach ($catalogo as $grupo):
                $filasGrupo = [];
                foreach ($grupo["filas"] as $definicion) {
                    $detalles = [];
                    foreach ($definicion["presentaciones"] as $idPresentacion) {
                        foreach (($detallePorBio[$idPresentacion] ?? []) as $idLote => $detalle) $detalles[$idLote] = $detalle;
                    }
                    uasort($detalles, function($a, $b) {
                        $la = $a["saldo"] ?? $a["entrada"];
                        $lb = $b["saldo"] ?? $b["entrada"];
                        return strcmp($la["fecha_caducidad"] . $la["numero_lote"], $lb["fecha_caducidad"] . $lb["numero_lote"]);
                    });
                    $filasGrupo[] = ["definicion"=>$definicion, "detalles"=>array_values($detalles), "cantidad_filas"=>max(1, count($detalles))];
                }
                $totalFilasGrupo = array_sum(array_column($filasGrupo, "cantidad_filas"));
                $idsGrupo = [];
                foreach ($grupo["filas"] as $definicion) $idsGrupo = array_merge($idsGrupo, $definicion["ids"]);
                $existenciaAnterior = 0;
                foreach (array_unique($idsGrupo) as $idBioGrupo) $existenciaAnterior += (float)($aperturaPorBio[$idBioGrupo] ?? 0);
                $esPrimeraFilaGrupo = true;
                foreach ($filasGrupo as $datosFila):
                    $definicion = $datosFila["definicion"];
                    $idsFila = $definicion["ids"];
                    $cantidadFilasProducto = $datosFila["cantidad_filas"];
                    for ($i = 0; $i < $cantidadFilasProducto; $i++):
                        $detalle = $datosFila["detalles"][$i] ?? [];
                        $loteSaldo = $detalle["saldo"] ?? null;
                        $loteEntrada = $detalle["entrada"] ?? null;
                        $loteDesecho = $detalle["desecho"] ?? null;
                        $loteDonacion = $detalle["donacion"] ?? null;
                        $dpf = $loteSaldo ? max(1, (float)$loteSaldo["dosis_por_frasco"]) : 1;
                        $stockLote = $loteSaldo ? (float)$loteSaldo["recibidos"] - ((float)$loteSaldo["aplicaciones"] + (float)$loteSaldo["dosis_desechadas"]) / $dpf : 0;
                        $sumarBio = function($mapa) use ($idsFila) { $total = 0; foreach ($idsFila as $id) $total += (float)($mapa[$id] ?? 0); return $total; };
                        $sumarPresentacion = function($mapa) use ($definicion) { $total = 0; foreach ($definicion["presentaciones"] as $id) $total += (float)($mapa[$id] ?? 0); return $total; };
                        $aplicadasMesFila = isset($definicion["familiaEdad"])
                            ? (float)($appsEdadMes[$definicion["familiaEdad"]][$definicion["edadReporte"]] ?? 0)
                            : $sumarBio($appsMes);
                        $aplicadasAnioFila = isset($definicion["familiaEdad"])
                            ? (float)($appsEdadAnio[$definicion["familiaEdad"]][$definicion["edadReporte"]] ?? 0)
                            : $sumarBio($appsAnio);
            ?>
                <tr>
                    <?php if ($esPrimeraFilaGrupo): ?>
                    <td rowspan="<?php echo $totalFilasGrupo; ?>"><?php echo fmt($existenciaAnterior); ?></td>
                    <?php $esPrimeraFilaGrupo = false; endif; ?>
                    <?php if ($i === 0): ?>
                    <td class="biologico" rowspan="<?php echo $cantidadFilasProducto; ?>"><?php echo nl2br(h($definicion[0])); ?></td>
                    <?php endif; ?>
                    <td><?php echo $loteEntrada ? fmt($loteEntrada["frascos"]) : ""; ?></td>
                    <td><?php echo $loteEntrada ? h($loteEntrada["numero_lote"]) : ""; ?></td>
                    <td><?php echo $loteEntrada ? h($loteEntrada["fecha_caducidad"]) : ""; ?></td>
                    <?php if ($i === 0): ?>
                    <td rowspan="<?php echo $cantidadFilasProducto; ?>"><?php echo fmt($sumarPresentacion($entradasAnio)); ?></td>
                    <td rowspan="<?php echo $cantidadFilasProducto; ?>"><?php echo fmt($aplicadasMesFila); ?></td>
                    <td rowspan="<?php echo $cantidadFilasProducto; ?>"><?php echo fmt($sumarPresentacion($desechoMes)); ?></td>
                    <td rowspan="<?php echo $cantidadFilasProducto; ?>"><?php echo fmt($aplicadasAnioFila); ?></td>
                    <td rowspan="<?php echo $cantidadFilasProducto; ?>"><?php echo fmt($sumarPresentacion($desechoAnio)); ?></td>
                    <?php endif; ?>
                    <td class="<?php echo $stockLote < 0 ? "saldo-negativo" : ""; ?>"><?php echo $loteSaldo ? fmt($stockLote) : ""; ?></td>
                    <td><?php echo $loteSaldo ? h($loteSaldo["numero_lote"]) : ""; ?></td>
                    <td><?php echo $loteSaldo ? h($loteSaldo["fecha_caducidad"]) : ""; ?></td>
                    <?php if ($i === 0): ?>
                    <td rowspan="<?php echo $cantidadFilasProducto; ?>">
                        <?php echo $loteDonacion ? fmt($loteDonacion["dosis_donadas"]) : ""; ?>
                    </td>
                    <td rowspan="<?php echo $cantidadFilasProducto; ?>">
                        <?php echo $loteDonacion ? h($loteDonacion["unidad_receptora"] ?? "") : ""; ?>
                    </td>
                    <?php endif; ?>
                    <td><?php
                        $observaciones = [];

                        if (!empty($loteEntrada["observaciones"])) {
                            $observaciones[] = '<span class="movimiento movimiento-entrada">Entrada: '
                                . h($loteEntrada["observaciones"]) . '</span>';
                        }

                        if (!empty($loteDesecho["observaciones"])) {
                            $urlDesecho = "detalle_movimiento.php?" . http_build_query([
                                "tipo" => "Desecho",
                                "lote" => $loteDesecho["id_lote"],
                                "mes" => $mes
                            ]);

                            $observaciones[] = '<span class="movimiento movimiento-desecho">Desecho: '
                                . h($loteDesecho["observaciones"])
                                . ' <a class="detalle-enlace" href="' . h($urlDesecho) . '">Ver detalles</a></span>';
                        }

                        if (!empty($loteDonacion["observaciones"])) {
                            $urlDonacion = "detalle_movimiento.php?" . http_build_query([
                                "tipo" => "Donación",
                                "lote" => $loteDonacion["id_lote"],
                                "mes" => $mes
                            ]);

                            $observaciones[] = '<span class="movimiento movimiento-donacion">Donación: '
                                . h($loteDonacion["observaciones"])
                                . ' <a class="detalle-enlace" href="' . h($urlDonacion) . '">Ver detalles</a></span>';
                        }

                        echo implode("<br>", $observaciones);
                    ?></td>
                    <?php if ($i === 0): ?>
                    <td rowspan="<?php echo $cantidadFilasProducto; ?>"></td>
                    <?php endif; ?>
                </tr>
            <?php endfor; endforeach; endforeach; ?>
            </tbody>
        </table>
    </div>
    <h2 id="jeringas">MOVIMIENTO DE JERINGAS</h2>
    <?php if (($_GET["jeringas"] ?? "") === "registrado"): ?>
    <?php endif; ?>
    <div class="botones">
        <?php if ($puedeRegistrarMovimientos): ?>
        <a href="entrada_jeringa.php"><button>+ NUEVA ENTRADA DE JERINGAS</button></a>
        <a href="salida_jeringa.php"><button>+ NUEVA SALIDA DE JERINGAS</button></a>
        <?php else: ?>
        <button type="button" disabled>+ NUEVA ENTRADA DE JERINGAS</button>
        <button type="button" disabled>+ NUEVA SALIDA DE JERINGAS</button>
        <?php endif; ?>
    </div>
    <div class="contenedor-tabla tabla-movimiento tabla-jeringas">
        <table>
            <thead>
                <tr>
                    <th rowspan="2">EXISTENCIA ANTERIOR</th>
                    <th rowspan="2">JERINGA</th>
                    <th colspan="4">JERINGAS RECIBIDAS</th>
                    <th colspan="3">SALIDA DE JERINGAS</th>
                    <th colspan="3">EXISTENCIA FINAL DE JERINGAS</th>
                    <th rowspan="2">NECESIDAD</th>
                </tr>
                <tr>
                    <th>JERINGAS</th>
                    <th>LOTE</th>
                    <th>CADUCIDAD</th>
                    <th>EN EL MES</th>
                    <th>USADAS EN EL MES</th>
                    <th>DESECHADAS</th>
                    <th>USADAS EN EL AÑO</th>
                    <th>CANTIDAD UNIDAD</th>
                    <th>LOTE</th>
                    <th>CADUCIDAD</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($jeringas as $jeringa): ?>
                <tr>
                    <td class="<?php echo (int)$jeringa["existencia_inicial"] < 0 ? "saldo-negativo" : ""; ?>">
                        <?php echo (int)$jeringa["existencia_inicial"]; ?>
                    </td>
                    <td><?php echo h($jeringa["tipo"]); ?></td>
                    <td><?php echo (int)$jeringa["entradas_mes"]; ?></td>
                    <td></td>
                    <td></td>
                    <td><?php echo (int)$jeringa["entradas_mes"]; ?></td>
                    <td><?php echo (int)$jeringa["usos_mes"]; ?></td>
                    <td><?php echo (int)$jeringa["desechos_mes"]; ?></td>
                    <td><?php echo (int)$jeringa["usos_anio"]; ?></td>
                    <td class="<?php echo (int)$jeringa["existencia_final"] < 0 ? "saldo-negativo" : ""; ?>">
                        <?php echo (int)$jeringa["existencia_final"]; ?>
                    </td>
                    <td></td>
                    <td></td>
                    <td><?php echo (int)$jeringa["necesidad"]; ?></td>
                </tr>
                <?php endforeach; ?>
                <?php if (!$jeringas): ?>
                <tr>
                    <td colspan="13">No hay tipos de jeringa registrados en el catálogo.</td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <script>
        (function () {
            const claveAlmacenamiento = "sistema-vacunacion.encabezado-movimiento";
            const campos = document.querySelectorAll("[data-encabezado]");
            const estado = document.getElementById("estado-encabezado");
            const selectorPeriodo = document.getElementById("mes");
            const campoMes = document.getElementById("encabezado-mes");
            const campoAnio = document.getElementById("encabezado-anio");
            const nombresMeses = [
                "ENERO", "FEBRERO", "MARZO", "ABRIL", "MAYO", "JUNIO",
                "JULIO", "AGOSTO", "SEPTIEMBRE", "OCTUBRE", "NOVIEMBRE", "DICIEMBRE"
            ];

            function actualizarPeriodoEncabezado() {
                const partes = selectorPeriodo.value.split("-");
                const indiceMes = Number(partes[1]) - 1;
                if (partes.length === 2 && nombresMeses[indiceMes]) {
                    campoMes.value = nombresMeses[indiceMes];
                    campoAnio.value = partes[0];
                }
            }

            try {
                const guardado = JSON.parse(localStorage.getItem(claveAlmacenamiento) || "{}");
                campos.forEach(function (campo) {
                    const clave = campo.dataset.encabezado;
                    if (typeof guardado[clave] === "string") campo.value = guardado[clave];
                });
            } catch (error) {
                estado.textContent = "No se pudieron cargar los datos guardados del encabezado.";
            }

            actualizarPeriodoEncabezado();
            selectorPeriodo.addEventListener("change", actualizarPeriodoEncabezado);

            campos.forEach(function (campo) {
                campo.addEventListener("input", function () {
                    try {
                        const datos = {};
                        campos.forEach(function (entrada) {
                            datos[entrada.dataset.encabezado] = entrada.value;
                        });
                        localStorage.setItem(claveAlmacenamiento, JSON.stringify(datos));
                        estado.textContent = "Cambios guardados en este navegador.";
                    } catch (error) {
                        estado.textContent = "No fue posible guardar los cambios en este navegador.";
                    }
                });
            });
        })();
    </script>
</body>
</html>
