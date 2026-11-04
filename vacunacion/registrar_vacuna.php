<?php
require_once __DIR__ . "/../config/seguridad.php";

if(!isset($_SESSION["id_usuario"])) {
    header("Location: ../login.php");
    exit();
}

require_once "../config/conexion.php";
require_once __DIR__ . "/funciones_vph.php";
asegurarEsquemaVph($conexion);
asegurarClasificacionHepatitisB($conexion);
asegurarClasificacionSr($conexion);
asegurarClasificacionVaricelaHepatitisA($conexion);

// =========================================================
// VERIFICAR PACIENTE
// =========================================================

if(!isset($_GET["id"]) || empty($_GET["id"])) {
    header("Location: index.php");
    exit();
}

$id_paciente = $_GET["id"];

function categoriaPresentacion($nombre)
{
    $nombre = mb_strtolower((string)$nombre, "UTF-8");
    $nombre = strtr($nombre, [
        "á" => "a",
        "é" => "e",
        "í" => "i",
        "ó" => "o",
        "ú" => "u"
    ]);

    if (str_contains($nombre, "pediatr")) {
        return "pediatrica";
    }
    if (str_contains($nombre, "adult")) {
        return "adulta";
    }

    return "";
}

function normalizarNombreBiologico($nombre)
{
    $nombre = mb_strtoupper(trim((string)$nombre), "UTF-8");
    $nombre = strtr($nombre, [
        "Á" => "A",
        "É" => "E",
        "Í" => "I",
        "Ó" => "O",
        "Ú" => "U",
        "Ü" => "U",
        "Ñ" => "N"
    ]);

    return preg_replace('/[^A-Z0-9]+/', '', $nombre);
}

function buscarIdBiologicoPorAlias($biologicos, $aliases)
{
    $aliasesNormalizados = array_map("normalizarNombreBiologico", $aliases);
    $idsEncontrados = [];

    foreach ($biologicos as $biologico) {
        if (in_array(
            normalizarNombreBiologico($biologico["nombre_biologico"]),
            $aliasesNormalizados,
            true
        )) {
            $idsEncontrados[(int)$biologico["id_biologico"]] = true;
        }
    }

    return count($idsEncontrados) === 1
        ? (int)array_key_first($idsEncontrados)
        : null;
}

function edadActualEnDias($fechaNacimiento)
{
    try {
        $nacimiento = DateTimeImmutable::createFromFormat("!Y-m-d", (string)$fechaNacimiento);
        $hoy = new DateTimeImmutable("today");
        if (
            !$nacimiento ||
            $nacimiento->format("Y-m-d") !== $fechaNacimiento ||
            $nacimiento > $hoy
        ) {
            return null;
        }

        return $nacimiento->diff($hoy)->days;
    } catch (Throwable $e) {
        return null;
    }
}


// =========================================================
// OBTENER PACIENTE + CENSO + ESQUEMA
// =========================================================

$sqlPaciente = "SELECT
                    p.id_paciente,
                    p.ap_pat,
                    p.ap_mat,
                    p.nombres,
                    p.fecha_nacimiento,
                    p.sexo,
                    p.personal_salud,
                    p.curp,
                    c.id_censo,
                    c.nombre_censo,
                    e.id_esquema,
                    e.nombre_esquema
                FROM PACIENTE p
                INNER JOIN PACIENTE_CENSO pc
                    ON p.id_paciente = pc.id_paciente
                INNER JOIN CENSO c
                    ON pc.id_censo = c.id_censo
                INNER JOIN ESQUEMA e
                    ON c.id_esquema = e.id_esquema
                WHERE p.id_paciente = ?";

$stmtPaciente = $conexion->prepare($sqlPaciente);

if(!$stmtPaciente) {
    die("Error al preparar consulta del paciente: " . $conexion->error);
}

$stmtPaciente->bind_param("s", $id_paciente);
$stmtPaciente->execute();

$resultadoPaciente = $stmtPaciente->get_result();

if($resultadoPaciente->num_rows === 0) {
    die("Paciente no encontrado o no tiene un censo asignado.");
}

$paciente = $resultadoPaciente->fetch_assoc();
$edadDiasPaciente = edadActualEnDias($paciente["fecha_nacimiento"]);

$stmtPaciente->close();


// =========================================================
// OBTENER VACUNAS DEL ESQUEMA DEL PACIENTE
// =========================================================

$sqlVacunas = "SELECT DISTINCT
                    b.id_biologico,
                    b.nombre_biologico
               FROM ESQUEMA_BIOLOGICO eb
               INNER JOIN BIOLOGICO b
                    ON eb.id_biologico = b.id_biologico
               WHERE eb.id_esquema = ?
               ORDER BY b.nombre_biologico";

$stmtVacunas = $conexion->prepare($sqlVacunas);

if(!$stmtVacunas) {
    die("Error al preparar consulta de vacunas: " . $conexion->error);
}

$stmtVacunas->bind_param("i", $paciente["id_esquema"]);
$stmtVacunas->execute();

$resultadoVacunas = $stmtVacunas->get_result();


// =========================================================
// OBTENER TODAS LAS RELACIONES ESQUEMA-BIOLOGICO-DOSIS
// =========================================================

$sqlDosis = "SELECT
                ebd.id_esquema_biologico_dosis,
                eb.id_esquema,
                eb.id_biologico,
                d.id_dosis,
                d.nombre_dosis
             FROM ESQUEMA_BIOLOGICO_DOSIS ebd
             INNER JOIN ESQUEMA_BIOLOGICO eb
                  ON ebd.id_esquema_biologico = eb.id_esquema_biologico
             INNER JOIN DOSIS d
                  ON ebd.id_dosis = d.id_dosis
             WHERE eb.id_esquema = ?
             ORDER BY eb.id_biologico, ebd.id_esquema_biologico_dosis";

$stmtDosis = $conexion->prepare($sqlDosis);

if(!$stmtDosis) {
    die("Error al preparar consulta de dosis: " . $conexion->error);
}

$stmtDosis->bind_param("i", $paciente["id_esquema"]);
$stmtDosis->execute();

$resultadoDosis = $stmtDosis->get_result();


// =========================================================
// OBTENER LOTES
// =========================================================

$sqlLotes = "SELECT
                l.id_lote,
                l.numero_lote,
                l.fecha_caducidad,
                p.id_biologico,
                b.nombre_biologico,
                p.nombre_presentacion
             FROM LOTE l
             INNER JOIN PRESENTACION p
                  ON l.id_presentacion = p.id_presentacion
             INNER JOIN BIOLOGICO b
                  ON p.id_biologico = b.id_biologico
             ORDER BY b.nombre_biologico, l.fecha_caducidad";

$resultadoLotes = $conexion->query($sqlLotes);

if(!$resultadoLotes) {
    die("Error al consultar lotes: " . $conexion->error);
}

$presentacionesPorBiologico = [];
$resultadoPresentaciones = $conexion->query(
    "SELECT id_biologico, nombre_presentacion FROM PRESENTACION"
);
if (!$resultadoPresentaciones) {
    die("Error al consultar presentaciones: " . $conexion->error);
}
while ($presentacion = $resultadoPresentaciones->fetch_assoc()) {
    $idBiologicoPresentacion = (string)$presentacion["id_biologico"];
    $categoria = categoriaPresentacion($presentacion["nombre_presentacion"]);
    if ($categoria !== "") {
        $presentacionesPorBiologico[$idBiologicoPresentacion][$categoria] = true;
    }
}
$resultadoPresentaciones->free();

$lotesArray = [];
while ($lote = $resultadoLotes->fetch_assoc()) {
    $lotesArray[] = [
        "id_lote" => $lote["id_lote"],
        "id_biologico" => $lote["id_biologico"],
        "numero_lote" => $lote["numero_lote"],
        "fecha_caducidad" => $lote["fecha_caducidad"],
        "nombre_biologico" => $lote["nombre_biologico"],
        "nombre_presentacion" => $lote["nombre_presentacion"],
        "categoria_presentacion" => categoriaPresentacion($lote["nombre_presentacion"])
    ];
}
$resultadoLotes->free();


// =========================================================
// OBTENER BIOLOGICOS
// =========================================================

$sqlBiologicos = "SELECT
                    id_biologico,
                    nombre_biologico
                  FROM BIOLOGICO
                  ORDER BY nombre_biologico";

$resultadoBiologicos = $conexion->query($sqlBiologicos);

if(!$resultadoBiologicos) {
    die("Error al consultar biológicos: " . $conexion->error);
}

$biologicosArray = $resultadoBiologicos->fetch_all(MYSQLI_ASSOC);
$idHexavalente = buscarIdBiologicoPorAlias($biologicosArray, [
    "HEXAVALENTE",
    "HEXAVALENTE DPTA HB VPI HIB",
    "HEXAVALENTE ACELULAR"
]);
$idDpt = buscarIdBiologicoPorAlias($biologicosArray, ["DPT"]);
$idTdpa = buscarIdBiologicoPorAlias($biologicosArray, ["TDPA"]);
$idTd = buscarIdBiologicoPorAlias($biologicosArray, ["TD"]);
$idVph = buscarIdBiologicoPorAlias($biologicosArray, ["VPH"]);
$idHepatitisB = buscarIdBiologicoPorAlias($biologicosArray, ["ANTIHEPATITIS B"]);
$idSr = buscarIdBiologicoPorAlias($biologicosArray, ["SR DOBLE VIRAL", "SR"]);
$idVaricela = buscarIdBiologicoPorAlias($biologicosArray, ["VARICELA"]);
$idHepatitisA = buscarIdBiologicoPorAlias($biologicosArray, ["HEPATITIS A"]);
$categoriasVph = categoriasVph();

$reemplazosPorBiologico = [];
if ($idHexavalente !== null && $idDpt !== null) {
    $reemplazosPorBiologico[(string)$idHexavalente][] = $idDpt;
}
if ($idTdpa !== null && $idTd !== null) {
    $reemplazosPorBiologico[(string)$idTdpa][] = $idTd;
}
if ($idTdpa !== null && $idDpt !== null) {
    $reemplazosPorBiologico[(string)$idTdpa][] = $idDpt;
}


// =========================================================
// OBTENER UNIDAD DEL USUARIO
// =========================================================

$sqlUsuario = "SELECT
                    u.id_unidad,
                    un.nombre_unidad
               FROM USUARIO u
               LEFT JOIN UNIDAD un
                    ON u.id_unidad = un.id_unidad
               WHERE u.id_usuario = ?";

$stmtUsuario = $conexion->prepare($sqlUsuario);

if(!$stmtUsuario) {
    die("Error al consultar unidad: " . $conexion->error);
}

$stmtUsuario->bind_param("i", $_SESSION["id_usuario"]);
$stmtUsuario->execute();

$resultadoUsuario = $stmtUsuario->get_result();

$usuario = $resultadoUsuario->fetch_assoc();

$stmtUsuario->close();

if(empty($usuario["id_unidad"])) {
    die("El usuario no tiene una unidad asignada.");
}


// =========================================================
// GUARDAR APLICACIÓN
// =========================================================

if($_SERVER["REQUEST_METHOD"] == "POST") {

    $id_biologico = $_POST["id_biologico"] ?? "";
    $id_esquema_biologico_dosis = $_POST["id_esquema_biologico_dosis"] ?? "";
    $id_lote = $_POST["id_lote"] ?? "";
    $fecha_aplicacion = $_POST["fecha_aplicacion"] ?? "";
    $clasificacionVph = is_string($_POST["clasificacion_vph"] ?? null)
        ? $_POST["clasificacion_vph"]
        : "";
    $clasificacionHepatitisB = is_string($_POST["clasificacion_hepatitis_b"] ?? null)
        ? $_POST["clasificacion_hepatitis_b"]
        : "";
    $clasificacionSr = is_string($_POST["clasificacion_sr"] ?? null)
        ? $_POST["clasificacion_sr"]
        : "";
    $clasificacionVaricelaHepatitisA = is_string($_POST["clasificacion_varicela_hepatitis_a"] ?? null)
        ? $_POST["clasificacion_varicela_hepatitis_a"]
        : "";
    $dosis_cero = $_POST["dosis_cero"] ?? "";
    $id_biologico_indicado = $_POST["id_biologico_indicado"] ?? "";
    $observaciones = is_string($_POST["observaciones"] ?? null)
        ? $_POST["observaciones"]
        : "";
    $reemplazo = is_string($_POST["reemplazo"] ?? null)
        ? $_POST["reemplazo"]
        : "";
    $id_biologico_reemplazo = is_string($_POST["id_biologico_reemplazo"] ?? null)
        ? $_POST["id_biologico_reemplazo"]
        : "";
    $motivo_reemplazo = is_string($_POST["motivo_reemplazo"] ?? null)
        ? trim($_POST["motivo_reemplazo"])
        : "";


    // =====================================================
    // VALIDAR DATOS OBLIGATORIOS
    // =====================================================

    if(
        empty($id_biologico) ||
        empty($id_esquema_biologico_dosis) ||
        empty($fecha_aplicacion) ||
        $dosis_cero === "" ||
        !in_array($reemplazo, ["0", "1"], true)
    ) {
        die("Faltan datos obligatorios para registrar la aplicación.");
    }

    if ($idVph !== null && (int)$id_biologico === $idVph) {
        if (!array_key_exists($clasificacionVph, $categoriasVph)) {
            die("Seleccione el grupo de población correspondiente para la aplicación de VPH.");
        }
    } else {
        $clasificacionVph = null;
    }
    if ($idHepatitisB === null || (int)$id_biologico !== $idHepatitisB) {
        $clasificacionHepatitisB = null;
    } elseif (!in_array($clasificacionHepatitisB, ["sin_antecedente", "riesgo"], true)) {
        $clasificacionHepatitisB = null;
    }
    if ($idSr !== null && (int)$id_biologico === $idSr) {
        if (!in_array($clasificacionSr, ["personal_educativo", "otro"], true)) {
            die("Indica si la persona pertenece al personal educativo para la aplicación de SR.");
        }
    } else {
        $clasificacionSr = null;
    }
    if (
        ($idVaricela !== null && (int)$id_biologico === $idVaricela)
        || ($idHepatitisA !== null && (int)$id_biologico === $idHepatitisA)
    ) {
        $categoriasAplicacionPorEdad = (int)$id_biologico === $idVaricela
            ? ["rutina", "rezago"]
            : ["rutina", "rezago", "riesgo"];
        if (!in_array($clasificacionVaricelaHepatitisA, $categoriasAplicacionPorEdad, true)) {
            die("Selecciona si la aplicación es de esquema regular, rezago o población en riesgo.");
        }
    } else {
        $clasificacionVaricelaHepatitisA = null;
    }

    $idBiologicoLote = (int)$id_biologico;
    if ($reemplazo === "1") {
        $reemplazosPermitidos = $reemplazosPorBiologico[(string)$id_biologico] ?? [];
        if (
            $id_biologico_reemplazo === "" ||
            !in_array((int)$id_biologico_reemplazo, $reemplazosPermitidos, true) ||
            $motivo_reemplazo === ""
        ) {
            die("Seleccione un biológico de reemplazo permitido e indique el motivo.");
        }
        if (mb_strlen($motivo_reemplazo, "UTF-8") > 250) {
            die("El motivo del reemplazo no debe superar 250 caracteres.");
        }

        $idBiologicoLote = (int)$id_biologico_reemplazo;
        $observaciones = trim($observaciones);
        $observaciones = ($observaciones === "" ? "" : $observaciones . "\n")
            . "[reemplazo: " . $motivo_reemplazo . "]";
    } elseif (trim($observaciones) !== "") {
        $observaciones = trim($observaciones);
    }

    if (mb_strlen($observaciones, "UTF-8") > 500) {
        die("Las observaciones, incluido el motivo del reemplazo, no deben superar 500 caracteres.");
    }

    if (empty($id_lote)) {
        die("Seleccione un lote del biológico que se aplicó.");
    }

    // =====================================================
    // BIOLOGICO INDICADO
    // =====================================================

    if($id_biologico_indicado === "") {
        $id_biologico_indicado = null;
    }


    // =====================================================
    // VALIDAR QUE LA VACUNA PERTENEZCA AL ESQUEMA
    // =====================================================

    $sqlValidar = "SELECT
                        ebd.id_esquema_biologico_dosis,
                        d.nombre_dosis
                   FROM ESQUEMA_BIOLOGICO_DOSIS ebd
                   INNER JOIN ESQUEMA_BIOLOGICO eb
                        ON ebd.id_esquema_biologico = eb.id_esquema_biologico
                   INNER JOIN DOSIS d ON d.id_dosis = ebd.id_dosis
                   WHERE ebd.id_esquema_biologico_dosis = ?
                   AND eb.id_esquema = ?
                   AND eb.id_biologico = ?";

    $stmtValidar = $conexion->prepare($sqlValidar);

    if(!$stmtValidar) {
        die("Error al validar vacuna: " . $conexion->error);
    }

    $stmtValidar->bind_param(
        "iii",
        $id_esquema_biologico_dosis,
        $paciente["id_esquema"],
        $id_biologico
    );

    $stmtValidar->execute();

    $resultadoValidar = $stmtValidar->get_result();

    if($resultadoValidar->num_rows === 0) {
        die("La vacuna o dosis seleccionada no corresponde al esquema del paciente.");
    }

    $dosisValidada = $resultadoValidar->fetch_assoc();
    $stmtValidar->close();
    if ($idHepatitisB !== null && (int)$id_biologico === $idHepatitisB) {
        $esDosisPrimeraSegunda = obtenerRenglonHepatitisB(
            "riesgo",
            $dosisValidada["nombre_dosis"]
        ) !== null;
        $nacimientoHepatitisB = DateTimeImmutable::createFromFormat(
            "!Y-m-d",
            (string)$paciente["fecha_nacimiento"]
        );
        $aplicacionHepatitisB = DateTimeImmutable::createFromFormat(
            "!Y-m-d",
            (string)$fecha_aplicacion
        );
        if (
            !$nacimientoHepatitisB
            || !$aplicacionHepatitisB
            || $nacimientoHepatitisB->format("Y-m-d") !== $paciente["fecha_nacimiento"]
            || $aplicacionHepatitisB->format("Y-m-d") !== $fecha_aplicacion
        ) {
            die("La fecha de nacimiento o de aplicación no es válida para clasificar Hepatitis B.");
        }
        $edadEnDiasHepatitisB = $nacimientoHepatitisB && $aplicacionHepatitisB
            && $nacimientoHepatitisB <= $aplicacionHepatitisB
            ? (int)$nacimientoHepatitisB->diff($aplicacionHepatitisB)->days
            : null;
        $esHepatitisBNeonatal = $edadEnDiasHepatitisB !== null
            && $edadEnDiasHepatitisB <= 7
            && normalizarNombreBiologico($dosisValidada["nombre_dosis"]) === "UNICA";
        $personalSalud = (int)$paciente["personal_salud"] === 1;

        if ($esDosisPrimeraSegunda && !$personalSalud && !$esHepatitisBNeonatal) {
            if ($clasificacionHepatitisB === null) {
                die("Indica si la aplicación de Hepatitis B es para una persona sin antecedente vacunal o población en riesgo.");
            }
            if (
                $clasificacionHepatitisB === "sin_antecedente"
                && (new DateTimeImmutable($paciente["fecha_nacimiento"]))->diff(new DateTimeImmutable($fecha_aplicacion))->y <= 11
            ) {
                die("La categoría sin antecedente vacunal de Hepatitis B corresponde a mayores de 11 años.");
            }
        } else {
            $clasificacionHepatitisB = null;
        }
    }
    if (
        $idVph !== null
        && (int)$id_biologico === $idVph
        && in_array($clasificacionVph, [
            "rezagados",
            "violencia_9_14",
            "violencia_15_19"
        ], true)
    ) {
        $nacimientoVph = DateTimeImmutable::createFromFormat(
            "!Y-m-d",
            (string)$paciente["fecha_nacimiento"]
        );
        $aplicacionVph = DateTimeImmutable::createFromFormat(
            "!Y-m-d",
            (string)$fecha_aplicacion
        );
        if (
            !$nacimientoVph
            || !$aplicacionVph
            || $nacimientoVph->format("Y-m-d") !== $paciente["fecha_nacimiento"]
            || $aplicacionVph->format("Y-m-d") !== $fecha_aplicacion
            || $nacimientoVph > $aplicacionVph
        ) {
            die("La fecha de nacimiento o de aplicación no es válida para clasificar VPH.");
        }
        $edadVph = $nacimientoVph->diff($aplicacionVph)->y;
        $rangosEdadVph = [
            "rezagados" => [12, 16],
            "violencia_9_14" => [9, 14],
            "violencia_15_19" => [15, 19]
        ];
        [$edadMinimaVph, $edadMaximaVph] = $rangosEdadVph[$clasificacionVph];
        if ($edadVph < $edadMinimaVph || $edadVph > $edadMaximaVph) {
            die("La edad del paciente no corresponde al grupo VPH seleccionado.");
        }
    }
    if (
        $idVph !== null
        && (int)$id_biologico === $idVph
        && in_array($clasificacionVph, ["mujeres_vih", "hombres_vih"], true)
        && obtenerRenglonVph($clasificacionVph, $dosisValidada["nombre_dosis"]) === null
    ) {
        die("Para los grupos que viven con VIH selecciona la primera, segunda o tercera dosis.");
    }
    if (
        $clasificacionVaricelaHepatitisA !== null
        && normalizarNombreBiologico($dosisValidada["nombre_dosis"]) !== "UNICA"
    ) {
        die("La clasificación seleccionada requiere registrar una dosis única.");
    }
    if (
        $clasificacionVaricelaHepatitisA !== null
        && in_array($clasificacionVaricelaHepatitisA, ["rutina", "rezago"], true)
    ) {
        $nacimientoEdadAplicacion = DateTimeImmutable::createFromFormat(
            "!Y-m-d",
            (string)$paciente["fecha_nacimiento"]
        );
        $fechaEdadAplicacion = DateTimeImmutable::createFromFormat("!Y-m-d", (string)$fecha_aplicacion);
        if (
            !$nacimientoEdadAplicacion
            || !$fechaEdadAplicacion
            || $nacimientoEdadAplicacion->format("Y-m-d") !== $paciente["fecha_nacimiento"]
            || $fechaEdadAplicacion->format("Y-m-d") !== $fecha_aplicacion
            || $nacimientoEdadAplicacion > $fechaEdadAplicacion
        ) {
            die("La fecha de nacimiento o de aplicación no es válida para clasificar la vacuna.");
        }
        $diferenciaEdadAplicacion = $nacimientoEdadAplicacion->diff($fechaEdadAplicacion);
        $edadMesesAplicacion = $diferenciaEdadAplicacion->y * 12 + $diferenciaEdadAplicacion->m;
        if ((int)$id_biologico === $idVaricela) {
            $clasificacionValidaPorEdad = $clasificacionVaricelaHepatitisA === "rutina"
                ? $edadMesesAplicacion >= 12 && $edadMesesAplicacion < 24
                : $edadMesesAplicacion >= 24 && $edadMesesAplicacion < 60;
        } else {
            $clasificacionValidaPorEdad = $clasificacionVaricelaHepatitisA === "rutina"
                ? $edadMesesAplicacion === 18
                : $edadMesesAplicacion >= 24 && $edadMesesAplicacion < 60;
        }
        if (!$clasificacionValidaPorEdad) {
            die("La edad del paciente no corresponde a la clasificación seleccionada para la vacuna.");
        }
    }


    // =====================================================
    // VALIDAR QUE EL LOTE CORRESPONDA A LA VACUNA
    // =====================================================

    $sqlValidarLote = "SELECT
                            l.id_lote,
                            p.nombre_presentacion
                       FROM LOTE l
                       INNER JOIN PRESENTACION p
                            ON l.id_presentacion = p.id_presentacion
                       WHERE l.id_lote = ?
                       AND p.id_biologico = ?";

    $stmtValidarLote = $conexion->prepare($sqlValidarLote);

    if(!$stmtValidarLote) {
        die("Error al validar lote: " . $conexion->error);
    }

    $stmtValidarLote->bind_param(
        "ii",
        $id_lote,
        $idBiologicoLote
    );

    $stmtValidarLote->execute();

    $resultadoValidarLote = $stmtValidarLote->get_result();

    if($resultadoValidarLote->num_rows === 0) {
        die("El lote seleccionado no corresponde al biológico elegido para la aplicación.");
    }

    $loteValidado = $resultadoValidarLote->fetch_assoc();
    $stmtValidarLote->close();

    if ($idVph !== null && $idBiologicoLote === $idVph) {
        $existenciaVph = obtenerExistenciaVph(
            $conexion,
            (int)$id_lote,
            (int)$usuario["id_unidad"]
        );
        if (!$existenciaVph || (float)$existenciaVph["disponible"] <= 0) {
            die("El lote VPH seleccionado ya no tiene existencias para esta unidad.");
        }
    }

    $categoriasPresentacion = $presentacionesPorBiologico[(string)$idBiologicoLote] ?? [];
    if (isset($categoriasPresentacion["pediatrica"], $categoriasPresentacion["adulta"])) {
        $edadDias = edadActualEnDias($paciente["fecha_nacimiento"]);
        if ($edadDias === null) {
            die("No se pudo determinar la edad del paciente para validar la presentación.");
        }

        $categoriaEsperada = $edadDias <= 7 ? "pediatrica" : "adulta";
        $categoriaSeleccionada = categoriaPresentacion($loteValidado["nombre_presentacion"]);
        if ($categoriaSeleccionada !== $categoriaEsperada) {
            die(
                $categoriaEsperada === "pediatrica"
                    ? "Para pacientes de 0 a 7 días debe seleccionar un lote de presentación pediátrica."
                    : "Para pacientes mayores de 7 días debe seleccionar un lote de presentación adulta."
            );
        }
    }


    // =====================================================
    // INSERTAR APLICACIÓN
    // =====================================================

    $sqlInsertar = "INSERT INTO APLICACION_VACUNA (
                        id_paciente,
                        id_esquema_biologico_dosis,
                        id_lote,
                        id_unidad,
                        id_biologico_indicado,
                        fecha_aplicacion,
                        clasificacion_vph,
                        clasificacion_hepatitis_b,
                        clasificacion_sr,
                        clasificacion_varicela_hepatitis_a,
                        dosis_cero,
                        observaciones
                    )
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

    $stmtInsertar = $conexion->prepare($sqlInsertar);

    if(!$stmtInsertar) {
        die("Error al preparar registro: " . $conexion->error);
    }

    $stmtInsertar->bind_param(
        "siiiisssssis",
        $id_paciente,
        $id_esquema_biologico_dosis,
        $id_lote,
        $usuario["id_unidad"],
        $id_biologico_indicado,
        $fecha_aplicacion,
        $clasificacionVph,
        $clasificacionHepatitisB,
        $clasificacionSr,
        $clasificacionVaricelaHepatitisA,
        $dosis_cero,
        $observaciones
    );

    if(!$stmtInsertar->execute()) {
        die("Error al registrar aplicación: " . $stmtInsertar->error);
    }

    $stmtInsertar->close();

    echo "<script>
            alert('APLICACIÓN REGISTRADA CORRECTAMENTE');
            window.location.href='../censo/index.php?censo=" . (int)$paciente["id_censo"] . "&capturar_historial=1&volver_vacunacion=1';
          </script>";

    exit();
}
?>

<!DOCTYPE html>

<html lang="es">

<head>

    <meta charset="UTF-8">

    <title>REGISTRAR APLICACIÓN</title>
    <link rel="stylesheet" href="../css/estilos.css">

</head>

<body>

<h1>REGISTRAR APLICACIÓN DE VACUNA</h1>


<!-- =====================================================
     DATOS DEL PACIENTE
===================================================== -->

<h2>DATOS DEL PACIENTE</h2>

<p>

    <strong>NOMBRE:</strong>

    <?php

    echo htmlspecialchars(
        $paciente["ap_pat"] . " " .
        $paciente["ap_mat"] . " " .
        $paciente["nombres"]
    );

    ?>

</p>


<p>

    <strong>FECHA DE NACIMIENTO:</strong>

    <?php

    echo htmlspecialchars(
        $paciente["fecha_nacimiento"]
    );

    ?>

</p>


<p>

    <strong>SEXO:</strong>

    <?php

    echo htmlspecialchars(
        $paciente["sexo"]
    );

    ?>

</p>


<p>

    <strong>CURP:</strong>

    <?php

    echo htmlspecialchars(
        $paciente["curp"] ?? ""
    );

    ?>

</p>
<hr>


<!-- =====================================================
     DATOS DE LA APLICACIÓN
===================================================== -->

<h2>DATOS DE LA APLICACIÓN</h2>


<form method="POST">


    <!-- =================================================
         VACUNA
    ================================================== -->

    <label>

        <strong>VACUNA:</strong>

    </label>

    <br>


    <select
        name="id_biologico"
        id="vacuna"
        required
    >

        <option value="">

            SELECCIONE UNA VACUNA

        </option>


        <?php while($vacuna = $resultadoVacunas->fetch_assoc()): ?>

            <option
                value="<?php echo $vacuna["id_biologico"]; ?>"
            >

                <?php

                echo htmlspecialchars(
                    $vacuna["nombre_biologico"]
                );

                ?>

            </option>

        <?php endwhile; ?>

    </select>

    <div id="seccion-clasificacion-vph" hidden>
        <br>
        <label for="clasificacion-vph">
            <strong>GRUPO DE POBLACIÓN VPH:</strong>
        </label>
        <select
            name="clasificacion_vph"
            id="clasificacion-vph"
            disabled
        >
            <option value="">SELECCIONE EL GRUPO</option>
            <?php foreach ($categoriasVph as $claveVph => $etiquetaVph): ?>
                <option value="<?php echo htmlspecialchars($claveVph, ENT_QUOTES, "UTF-8"); ?>">
                    <?php echo htmlspecialchars($etiquetaVph, ENT_QUOTES, "UTF-8"); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <p>Esta clasificación se utiliza en el paloteo. En aplicaciones vinculadas a expediente queda asociada al registro de vacunación.</p>
    </div>

    <br>
    <br>


    <!-- =================================================
         DOSIS
    ================================================== -->

    <label>

        <strong>DOSIS:</strong>

    </label>

    <br>


    <select
        name="id_esquema_biologico_dosis"
        id="dosis"
        required
        disabled
    >

        <option value="">

            PRIMERO SELECCIONE UNA VACUNA

        </option>

    </select>


    <br>
    <br>


    <!-- =================================================
         LOTE
    ================================================== -->

    <label>

        <strong id="etiqueta-lote">LOTE DE LA VACUNA DEL ESQUEMA:</strong>

    </label>

    <br>


    <select
        name="id_lote"
        id="lote"
        required
        disabled
    >

        <option value="">

            PRIMERO SELECCIONE UNA VACUNA

        </option>

    </select>


    <br>
    <br>


    <!-- =================================================
         FECHA DE APLICACIÓN
    ================================================== -->

    <label>

        <strong>FECHA DE APLICACIÓN:</strong>

    </label>

    <br>


    <input
        type="date"
        name="fecha_aplicacion"
        id="fecha_aplicacion"
        value="<?php echo date('Y-m-d'); ?>"
        required
    >


    <br>
    <br>


    <!-- =================================================
         DOSIS CERO
    ================================================== -->

    <label>

        <strong>¿DOSIS CERO?</strong>

    </label>

    <br>


    <select
        name="dosis_cero"
        required
    >

        <option value="">

            SELECCIONE

        </option>


        <option value="0">

            NO

        </option>


        <option value="1">

            SÍ

        </option>

    </select>


    <br>
    <br>

    <!-- =================================================
         REEMPLAZO DE BIOLÓGICO
    ================================================== -->

    <label for="reemplazo">
        <strong>¿HABRÁ REEMPLAZO DE BIOLÓGICO?</strong>
    </label>

    <select name="reemplazo" id="reemplazo" required>
        <option value="">SELECCIONE</option>
        <option value="0">NO</option>
        <option value="1">SÍ</option>
    </select>

    <div id="datos-reemplazo" hidden>
        <label for="biologico-reemplazo">
            <strong>BIOLÓGICO DE REEMPLAZO:</strong>
        </label>
        <select
            name="id_biologico_reemplazo"
            id="biologico-reemplazo"
            disabled
        >
            <option value="">PRIMERO SELECCIONE LA VACUNA DEL ESQUEMA</option>
        </select>

        <label for="motivo-reemplazo">
            <strong>MOTIVO DEL REEMPLAZO:</strong>
        </label>
        <textarea
            name="motivo_reemplazo"
            id="motivo-reemplazo"
            rows="3"
            maxlength="250"
            disabled
        ></textarea>
    </div>

    <div id="seccion-clasificacion-hepatitis-b" hidden>
        <br>
        <label for="clasificacion-hepatitis-b">
            <strong>CLASIFICACIÓN DE HEPATITIS B:</strong>
        </label>
        <select
            name="clasificacion_hepatitis_b"
            id="clasificacion-hepatitis-b"
            disabled
        >
            <option value="">SELECCIONE</option>
            <option value="sin_antecedente">Mayores de 11 años sin antecedente vacunal</option>
            <option value="riesgo">Población en riesgo</option>
        </select>
        <p>Personal de salud se clasifica automáticamente con el dato del expediente. Recién nacidos de 0 a 7 días con dosis única también se clasifican automáticamente.</p>
    </div>

    <div id="seccion-clasificacion-sr" hidden>
        <br>
        <label for="clasificacion-sr">
            <strong>¿LA PERSONA PERTENECE AL PERSONAL EDUCATIVO?</strong>
        </label>
        <select
            name="clasificacion_sr"
            id="clasificacion-sr"
            disabled
        >
            <option value="">SELECCIONE</option>
            <option value="personal_educativo">SÍ</option>
            <option value="otro">NO</option>
        </select>
        <p>Este dato se utiliza para clasificar la aplicación de SR en el paloteo.</p>
    </div>

    <div id="seccion-clasificacion-varicela-hepatitis-a" hidden>
        <br>
        <label for="clasificacion-varicela-hepatitis-a">
            <strong>CLASIFICACIÓN DE LA APLICACIÓN:</strong>
        </label>
        <select
            name="clasificacion_varicela_hepatitis_a"
            id="clasificacion-varicela-hepatitis-a"
            disabled
        >
            <option value="">SELECCIONE</option>
        </select>
        <p>Selecciona si corresponde al esquema regular, a un rezago o a población en riesgo. La edad se validará con el expediente.</p>
    </div>

    <br>
    <br>


    <!-- =================================================
         OBSERVACIONES
    ================================================== -->

    <label>

        <strong>OBSERVACIONES:</strong>

    </label>

    <br>


    <textarea
        name="observaciones"
        rows="5"
        cols="50"
        maxlength="500"
    ></textarea>


    <br>
    <br>


    <button type="submit">

        GUARDAR APLICACIÓN

    </button>


</form>


<br>


<a href="index.php">

    <button type="button">

        REGRESAR

    </button>

</a>

<script>
    const idBiologicoVph = <?php
        echo json_encode($idVph, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    ?>;
    const idBiologicoHepatitisB = <?php
        echo json_encode($idHepatitisB, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    ?>;
    const idBiologicoSr = <?php
        echo json_encode($idSr, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    ?>;
    const idBiologicoVaricela = <?php
        echo json_encode($idVaricela, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    ?>;
    const idBiologicoHepatitisA = <?php
        echo json_encode($idHepatitisA, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    ?>;
    const personalSaludPaciente = <?php
        echo json_encode((int)$paciente["personal_salud"] === 1, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    ?>;
    const fechaNacimientoPaciente = <?php
        echo json_encode($paciente["fecha_nacimiento"], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    ?>;
    const edadPacienteEnDias = <?php
        echo json_encode($edadDiasPaciente, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    ?>;

    const datosDosis = <?php
        $dosisArray = [];

        while($dosis = $resultadoDosis->fetch_assoc()) {
            $dosisArray[] = [
                "id_esquema_biologico_dosis" => $dosis["id_esquema_biologico_dosis"],
                "id_biologico" => $dosis["id_biologico"],
                "id_dosis" => $dosis["id_dosis"],
                "nombre_dosis" => $dosis["nombre_dosis"]
            ];
        }

        echo json_encode($dosisArray);
    ?>;

    const datosLotes = <?php
        echo json_encode($lotesArray, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    ?>;

    const biologicosReemplazo = <?php
        echo json_encode($biologicosArray, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    ?>;

    const reemplazosPorBiologico = <?php
        echo json_encode($reemplazosPorBiologico, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    ?>;

    const presentacionesPorBiologico = <?php
        echo json_encode($presentacionesPorBiologico, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    ?>;
</script>

<script src="js/vacunacion.js"></script>




</body>

</html>