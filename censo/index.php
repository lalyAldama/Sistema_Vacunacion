<?php
session_start();

if (!isset($_SESSION["id_usuario"])) {
    header("Location: ../login.php");
    exit();
}

require_once "../config/conexion.php";

function e($valor)
{
    return htmlspecialchars((string)($valor ?? ""), ENT_QUOTES, "UTF-8");
}

function fechaDMY($fecha)
{
    if (empty($fecha)) {
        return ["", "", ""];
    }

    $ts = strtotime($fecha);
    if ($ts === false) {
        return ["", "", ""];
    }

    return [date("d", $ts), date("m", $ts), date("Y", $ts)];
}

function fechaCompleta($fecha)
{
    if (empty($fecha)) {
        return "";
    }

    $ts = strtotime($fecha);
    return $ts === false ? "" : date("d/m/Y", $ts);
}

function edadAAmm($fechaNacimiento)
{
    if (empty($fechaNacimiento)) {
        return "";
    }

    try {
        $nacimiento = new DateTime($fechaNacimiento);
        $hoy = new DateTime();
        $edad = $nacimiento->diff($hoy);
        return $edad->y . "/" . $edad->m;
    } catch (Exception $e) {
        return "";
    }
}

function esHoy($fecha)
{
    return !empty($fecha) && $fecha === date("Y-m-d");
}

function tipoCenso($idCenso)
{
    if ((int)$idCenso === 1) return "0-9";
    if ((int)$idCenso === 2) return "10-19";
    if ((int)$idCenso === 3) return "adultos";
    return "";
}

$idCenso = isset($_GET["censo"]) ? (int)$_GET["censo"] : 0;
$buscar = isset($_GET["buscar"]) ? trim($_GET["buscar"]) : "";
$idPacienteSolicitado = isset($_GET["id_paciente"]) ? trim($_GET["id_paciente"]) : "";

$pacientes = [];
$tutores = [];
$vacunasEsquema = [];
$aplicaciones = [];
$observacionesPaciente = [];

$nombreCenso = "";
$idEsquema = 0;
$modoResultadosBusqueda = false;


if ($idPacienteSolicitado !== "") {

    $sql = "
        SELECT
            p.*,
            pc.id_censo,
            c.nombre_censo,
            c.id_esquema
        FROM PACIENTE p
        INNER JOIN PACIENTE_CENSO pc
            ON pc.id_paciente = p.id_paciente
        INNER JOIN CENSO c
            ON c.id_censo = pc.id_censo
        WHERE p.id_paciente = ?
        LIMIT 1
    ";

    $stmt = $conexion->prepare($sql);
    if (!$stmt) {
        die("Error al preparar la consulta del paciente: " . $conexion->error);
    }

    $stmt->bind_param("s", $idPacienteSolicitado);
    $stmt->execute();
    $res = $stmt->get_result();

    if ($fila = $res->fetch_assoc()) {
        $pacientes[] = $fila;
        $idCenso = (int)$fila["id_censo"];
        $nombreCenso = $fila["nombre_censo"];
        $idEsquema = (int)$fila["id_esquema"];
    }

    $stmt->close();

} elseif ($buscar !== "") {

    /* La búsqueda NO está condicionada por el censo seleccionado. */
    $termino = "%" . $buscar . "%";

    $sql = "
        SELECT
            p.*,
            pc.id_censo,
            c.nombre_censo,
            c.id_esquema
        FROM PACIENTE p
        INNER JOIN PACIENTE_CENSO pc
            ON pc.id_paciente = p.id_paciente
        INNER JOIN CENSO c
            ON c.id_censo = pc.id_censo
        WHERE
            p.ap_pat LIKE ?
            OR p.ap_mat LIKE ?
            OR p.nombres LIKE ?
            OR p.curp LIKE ?
        ORDER BY
            p.ap_pat,
            p.ap_mat,
            p.nombres
    ";

    $stmt = $conexion->prepare($sql);
    if (!$stmt) {
        die("Error al preparar la búsqueda: " . $conexion->error);
    }

    $stmt->bind_param("ssss", $termino, $termino, $termino, $termino);
    $stmt->execute();
    $res = $stmt->get_result();

    while ($fila = $res->fetch_assoc()) {
        $pacientes[] = $fila;
    }

    $stmt->close();

    $censosEncontrados = [];

    foreach ($pacientes as $paciente) {
        $censosEncontrados[(int)$paciente["id_censo"]] = true;
    }

    if (count($censosEncontrados) === 1 && count($pacientes) > 0) {
        $idCenso = (int)array_key_first($censosEncontrados);
        $nombreCenso = $pacientes[0]["nombre_censo"];
        $idEsquema = (int)$pacientes[0]["id_esquema"];
    } elseif (count($censosEncontrados) > 1) {
        $modoResultadosBusqueda = true;
        $idCenso = 0;
    }

} elseif ($idCenso > 0) {

    $sql = "
        SELECT
            p.*,
            pc.id_censo,
            c.nombre_censo,
            c.id_esquema
        FROM PACIENTE p
        INNER JOIN PACIENTE_CENSO pc
            ON pc.id_paciente = p.id_paciente
        INNER JOIN CENSO c
            ON c.id_censo = pc.id_censo
        WHERE pc.id_censo = ?
        ORDER BY
            p.ap_pat,
            p.ap_mat,
            p.nombres
    ";

    $stmt = $conexion->prepare($sql);
    if (!$stmt) {
        die("Error al preparar el censo: " . $conexion->error);
    }

    $stmt->bind_param("i", $idCenso);
    $stmt->execute();
    $res = $stmt->get_result();

    while ($fila = $res->fetch_assoc()) {
        $pacientes[] = $fila;
        $nombreCenso = $fila["nombre_censo"];
        $idEsquema = (int)$fila["id_esquema"];
    }

    $stmt->close();
}

/* Si se seleccionó un censo sin pacientes, obtenemos su información. */
if ($idCenso > 0 && $nombreCenso === "") {

    $stmt = $conexion->prepare("SELECT nombre_censo, id_esquema FROM CENSO WHERE id_censo = ? LIMIT 1");
    $stmt->bind_param("i", $idCenso);
    $stmt->execute();
    $res = $stmt->get_result();

    if ($fila = $res->fetch_assoc()) {
        $nombreCenso = $fila["nombre_censo"];
        $idEsquema = (int)$fila["id_esquema"];
    }

    $stmt->close();
}

$tipo = tipoCenso($idCenso);

/* =========================================================
   TUTORES / ACOMPAÑANTES
========================================================= */

$idsTutores = [];

foreach ($pacientes as $paciente) {
    if (!empty($paciente["id_tutor"])) {
        $idsTutores[] = (int)$paciente["id_tutor"];
    }
}

$idsTutores = array_values(array_unique($idsTutores));

if (count($idsTutores) > 0) {

    $lista = implode(",", $idsTutores);

    $sql = "
        SELECT
            id_tutor,
            ap_pat,
            ap_mat,
            nombres,
            fecha_nacimiento,
            telefono_tutor,
            sexo,
            curp,
            calle_tutor,
            num_tutor,
            colonia_tutor
        FROM TUTOR
        WHERE id_tutor IN ($lista)
    ";

    $res = $conexion->query($sql);

    if ($res) {
        while ($tutor = $res->fetch_assoc()) {
            $tutores[(int)$tutor["id_tutor"]] = $tutor;
        }
    }
}

/* =========================================================
   VACUNAS DEL ESQUEMA
========================================================= */

if ($idEsquema > 0) {

    $sql = "
        SELECT
            eb.id_esquema_biologico,
            eb.id_biologico,
            b.nombre_biologico,
            ebd.id_esquema_biologico_dosis,
            ebd.id_dosis,
            d.nombre_dosis
        FROM ESQUEMA_BIOLOGICO eb
        INNER JOIN BIOLOGICO b
            ON b.id_biologico = eb.id_biologico
        INNER JOIN ESQUEMA_BIOLOGICO_DOSIS ebd
            ON ebd.id_esquema_biologico = eb.id_esquema_biologico
        INNER JOIN DOSIS d
            ON d.id_dosis = ebd.id_dosis
        WHERE eb.id_esquema = ?
        ORDER BY eb.id_biologico, ebd.id_esquema_biologico_dosis
    ";

    $stmt = $conexion->prepare($sql);
    $stmt->bind_param("i", $idEsquema);
    $stmt->execute();
    $res = $stmt->get_result();

    while ($fila = $res->fetch_assoc()) {
        $idBio = (int)$fila["id_biologico"];

        if (!isset($vacunasEsquema[$idBio])) {
            $vacunasEsquema[$idBio] = [
                "id_biologico" => $idBio,
                "nombre" => $fila["nombre_biologico"],
                "dosis" => []
            ];
        }

        $vacunasEsquema[$idBio]["dosis"][] = $fila;
    }

    $stmt->close();
}

/* =========================================================
   APLICACIONES Y OBSERVACIONES
========================================================= */

if (count($pacientes) > 0 && !$modoResultadosBusqueda) {

    $ids = [];

    foreach ($pacientes as $paciente) {
        $ids[] = "'" . $conexion->real_escape_string($paciente["id_paciente"]) . "'";
    }

    $listaIds = implode(",", $ids);

    $sql = "
        SELECT
            av.id_aplicacion_vacuna,
            av.id_paciente,
            av.id_esquema_biologico_dosis,
            av.id_lote,
            av.fecha_aplicacion,
            av.dosis_cero,
            av.observaciones,
            l.numero_lote,
            l.fecha_caducidad
        FROM APLICACION_VACUNA av
        INNER JOIN LOTE l
            ON l.id_lote = av.id_lote
        WHERE av.id_paciente IN ($listaIds)
        ORDER BY av.fecha_aplicacion ASC, av.id_aplicacion_vacuna ASC
    ";

    $res = $conexion->query($sql);

    if ($res) {
        while ($aplicacion = $res->fetch_assoc()) {

            $idPaciente = $aplicacion["id_paciente"];
            $idEbd = (int)$aplicacion["id_esquema_biologico_dosis"];

            /* La última aplicación de esa dosis es la que se muestra en la celda. */
            $aplicaciones[$idPaciente][$idEbd] = $aplicacion;

            if (!empty(trim((string)$aplicacion["observaciones"]))) {
                $observacionesPaciente[$idPaciente][] = trim($aplicacion["observaciones"]);
            }
        }
    }
}

foreach ($observacionesPaciente as $idPaciente => $listaObs) {
    $observacionesPaciente[$idPaciente] = implode(" | ", array_values(array_unique($listaObs)));
}

/* =========================================================
   UNIDAD
========================================================= */

$nombreUnidad = "";

$stmt = $conexion->prepare("
    SELECT u.nombre_unidad
    FROM USUARIO usr
    INNER JOIN UNIDAD u ON u.id_unidad = usr.id_unidad
    WHERE usr.id_usuario = ?
    LIMIT 1
");

$stmt->bind_param("i", $_SESSION["id_usuario"]);
$stmt->execute();
$res = $stmt->get_result();

if ($fila = $res->fetch_assoc()) {
    $nombreUnidad = $fila["nombre_unidad"];
}

$stmt->close();

$totalVacunas = 0;
foreach ($vacunasEsquema as $bio) {
    $totalVacunas += count($bio["dosis"]);
}

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Censo Nominal</title>
    <link rel="stylesheet" href="css/censo.css">
    <style>
        .parentesco-tutor {
            margin-top: 5px;
            font-size: 9px;
            font-weight: bold;
            line-height: 1.2;
        }

        .etiqueta-ubicacion {
            font-size: 8px;
            line-height: 1;
            padding: 2px 1px !important;
            white-space: nowrap;
            text-align: center;
        }

        .celda-clave,
        .celda-nombre,
        .celda-ageb {
            padding: 2px !important;
        }

        .censo .encabezado-ubicacion {
            width: 105px;
            min-width: 105px;
            white-space: normal;
        }

        .censo .encabezado-clave {
            width: 68px;
            min-width: 68px;
        }

        .censo .encabezado-nombre {
            width: 114px;
            min-width: 114px;
        }

        
        .censo .celda-clave {
            padding: 0 !important;
            min-width: 42px;
            width: 42px;
        }

        .censo .clave-casillas {
            width: 100%;
            height: 100%;
            table-layout: fixed;
            border-collapse: collapse;
            border-spacing: 0;
            margin: 0;
        }

        .censo .clave-casillas td {
            border: 1px solid #555;
            padding: 0 !important;
            width: auto;
            height: 18px;
            min-width: 0;
        }

        .censo .celda-nombre {
            min-width: 100px;
            width: 100px;
            white-space: nowrap;
        }
    </style>
</head>
<body>

<div class="pagina">

    <div class="barra-superior">
        <div>
            <h1>CENSO NOMINAL</h1>
            <?php if ($nombreUnidad !== ""): ?>
                <div class="unidad"><?php echo e($nombreUnidad); ?></div>
            <?php endif; ?>
        </div>

        <a href="registrar.php" class="btn btn-verde">
            REGISTRAR NUEVO PACIENTE
        </a>

        <a href="../vacunacion/index.php" class=" btn btn-verde">
            REGISTRAR APLICACION DE VACUNA
        </a>
    </div>

    <div class="panel-filtros">
        <form method="GET" action="index.php" class="buscador">
            <input
                type="text"
                name="buscar"
                value="<?php echo e($buscar); ?>"
                placeholder="Buscar por nombre, apellidos o CURP"
            >
            <button type="submit" class="btn btn-azul">BUSCAR</button>
        </form>

        <div class="filtros-censo">
            <a href="index.php?censo=1" class="btn btn-censo">CENSO 0–9</a>
            <a href="index.php?censo=2" class="btn btn-censo">CENSO 10–19</a>
            <a href="index.php?censo=3" class="btn btn-censo">ADULTOS Y EMBARAZADAS</a>
        </div>
    </div>

    <?php if ($modoResultadosBusqueda): ?>

        <div class="panel-resultados">
            <h2>RESULTADOS DE BÚSQUEDA</h2>
            <p>
                La búsqueda se realizó en <strong>todos los censos</strong>.
                Selecciona el paciente que deseas consultar.
            </p>

            <div class="tabla-resultados-scroll">
                <table class="tabla-resultados">
                    <thead>
                        <tr>
                            <th>Nombre completo</th>
                            <th>Fecha de nacimiento</th>
                            <th>CURP</th>
                            <th>Censo</th>
                            <th>Acción</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($pacientes as $resultadoPaciente): ?>
                            <tr>
                                <td>
                                    <?php echo e(
                                        $resultadoPaciente["ap_pat"] . " " .
                                        $resultadoPaciente["ap_mat"] . " " .
                                        $resultadoPaciente["nombres"]
                                    ); ?>
                                </td>
                                <td><?php echo fechaCompleta($resultadoPaciente["fecha_nacimiento"]); ?></td>
                                <td><?php echo e($resultadoPaciente["curp"]); ?></td>
                                <td><?php echo e($resultadoPaciente["nombre_censo"]); ?></td>
                                <td>
                                    <a
                                        class="btn btn-censo"
                                        href="index.php?id_paciente=<?php echo urlencode($resultadoPaciente["id_paciente"]); ?>"
                                    >
                                        VER CENSO
                                    </a>
                                    <a
                                        class="btn btn-censo"
                                        href="editar.php?id=<?php echo urlencode($resultadoPaciente["id_paciente"]); ?>"
                                    >
                                        EDITAR
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

    <?php elseif ($idCenso > 0): ?>

        <div class="encabezado-oficial">
            <strong>
                <?php
                echo $idCenso === 1 ? "ANEXO 1-A" : ($idCenso === 2 ? "ANEXO 1-B" : "ANEXO 1-C");
                ?>
            </strong>
            <div>CENTRO NACIONAL PARA LA SALUD DE LA INFANCIA Y LA ADOLESCENCIA</div>
            <div>
                <?php
                if ($idCenso === 1) {
                    echo "CENSO NOMINAL PARA REGISTRO DE ESQUEMAS DE VACUNACIÓN DE 0 A 9 AÑOS";
                } elseif ($idCenso === 2) {
                    echo "CENSO NOMINAL PARA REGISTRO DE ESQUEMAS DE VACUNACIÓN DE 10 A 19 AÑOS";
                } else {
                    echo "CENSO NOMINAL PARA POBLACIÓN ADULTA (20 AÑOS Y MÁS) Y MUJERES EMBARAZADAS";
                }
                ?>
            </div>
        </div>

        <?php if ($buscar !== ""): ?>
            <div class="resultado-individual">
                Buscando: <strong><?php echo e($buscar); ?></strong>
                — se muestran los resultados encontrados en el censo correspondiente.
            </div>
        <?php endif; ?>

        <div class="tabla-scroll">
            <table class="censo">
                <thead>
                    <tr>
                        <th rowspan="3">No.</th>
                        <th colspan="3">NOMBRE (S)</th>
                        <th colspan="3">FECHA DE NACIMIENTO</th>
                        <th rowspan="3">EDAD</th>
                        <th rowspan="3">SEXO</th>
                        <th rowspan="3">DERECHOHABIENCIA</th>
                        <th rowspan="3">POBLACIÓN INDÍGENA Y AFROMEXICANA</th>
                        <th colspan="2">ESTATUS MIGRATORIO</th>
                        <th colspan="6">DOMICILIO</th>
                        <th rowspan="3">TELÉFONO</th>

                        <?php if ($tipo === "10-19" || $tipo === "adultos"): ?>
                            <th colspan="3">FECHA ÚLTIMA DE MENSTRUACIÓN (FUM)</th>
                        <?php endif; ?>

                        <?php foreach ($vacunasEsquema as $bio): ?>
                            <th colspan="<?php echo count($bio["dosis"]) * 3; ?>" class="encabezado-vacuna">
                                <?php echo e($bio["nombre"]); ?>
                            </th>
                        <?php endforeach; ?>

                        <th rowspan="3" class="observaciones">OBSERVACIONES</th>
                        <th rowspan="3">ACCIONES</th>
                    </tr>

                    <tr>
                        <th>PATERNO</th>
                        <th>MATERNO</th>
                        <th>NOMBRE</th>
                        <th>DÍA</th>
                        <th>MES</th>
                        <th>AÑO</th>
                        <th>REGULAR</th>
                        <th>IRREGULAR</th>
                        <th class="encabezado-ubicacion">UBICACIÓN GEOGRÁFICA DE RESIDENCIA</th>
                        <th class="encabezado-clave">CLAVE</th>
                        <th class="encabezado-nombre">NOMBRE</th>
                        <th>CALLE</th>
                        <th>No.</th>
                        <th>COLONIA</th>

                        <?php if ($tipo === "10-19" || $tipo === "adultos"): ?>
                            <th>DÍA</th>
                            <th>MES</th>
                            <th>AÑO</th>
                        <?php endif; ?>

                        <?php foreach ($vacunasEsquema as $bio): ?>
                            <?php foreach ($bio["dosis"] as $dosis): ?>
                                <th><?php echo e($dosis["nombre_dosis"]); ?><br>FECHA</th>
                                <th>LOTE</th>
                                <th>CADUCIDAD</th>
                            <?php endforeach; ?>
                        <?php endforeach; ?>
                    </tr>

                </thead>

                <tbody>
                <?php if (count($pacientes) === 0): ?>
                    <tr>
                        <td colspan="100" class="sin-resultados">
                            No se encontraron pacientes en este censo.
                        </td>
                    </tr>
                <?php endif; ?>

                <?php
                $numero = 1;
                foreach ($pacientes as $paciente):

                    $idPaciente = $paciente["id_paciente"];
                    $fechaNac = fechaDMY($paciente["fecha_nacimiento"]);
                    $fechaFum = fechaDMY($paciente["fecha_ultima_menstruacion"]);

                    $tutor = null;
                    if ($tipo === "0-9" && !empty($paciente["id_tutor"])) {
                        $tutor = $tutores[(int)$paciente["id_tutor"]] ?? null;
                    }

                  
                    $filasBloque = $tipo === "0-9" ? 7 : 4;
                ?>

                    <!-- =========================================
                         FILA PRINCIPAL DEL PACIENTE
                    ========================================== -->
                    <tr class="fila-paciente">

                        <td rowspan="<?php echo $filasBloque; ?>">
                            <?php echo $numero; ?>
                        </td>

                        <td rowspan="3">
                            <?php echo e($paciente["ap_pat"]); ?>
                        </td>
                        <td rowspan="3">
                            <?php echo e($paciente["ap_mat"]); ?>
                        </td>
                        <td rowspan="3">
                            <?php echo e($paciente["nombres"]); ?>
                        </td>

                        <td rowspan="4"><?php echo $fechaNac[0]; ?></td>
                        <td rowspan="4"><?php echo $fechaNac[1]; ?></td>
                        <td rowspan="4"><?php echo $fechaNac[2]; ?></td>

                        <td rowspan="4">
                            <?php echo e(edadAAmm($paciente["fecha_nacimiento"])); ?>
                        </td>

                        <td rowspan="4"><?php echo e($paciente["sexo"]); ?></td>

                        <td rowspan="4">
                            <?php echo e($paciente["derechoabiencia"]); ?>
                        </td>

                        <td rowspan="4">
                            <?php echo $paciente["poblacion_indigena_afromexicana"] ? "X" : ""; ?>
                        </td>

                        <td rowspan="4">
                            <?php echo strtoupper(trim($paciente["estatus_migratorio"])) === "REGULAR" ? "X" : ""; ?>
                        </td>

                        <td rowspan="4">
                            <?php echo strtoupper(trim($paciente["estatus_migratorio"])) === "IRREGULAR" ? "X" : ""; ?>
                        </td>

                        <!-- UBICACIÓN GEOGRÁFICA: 3 columnas bajo su encabezado -->
                        <td class="etiqueta-ubicacion">ENTIDAD FEDERATIVA</td>
                        <td class="celda-clave"><table class="clave-casillas" aria-label="Clave de entidad"><tr><td></td><td></td></tr></table></td>
                        <td class="celda-nombre"></td>

                        <td rowspan="4"><?php echo e($paciente["calle"]); ?></td>
                        <td rowspan="4"><?php echo e($paciente["num"]); ?></td>
                        <td rowspan="4"><?php echo e($paciente["colonia"]); ?></td>

                        <td rowspan="<?php echo $filasBloque; ?>">
                            <?php echo e($paciente["telefono_paciente"]); ?>
                        </td>

                        <?php if ($tipo === "10-19" || $tipo === "adultos"): ?>
                            <td rowspan="4"><?php echo $fechaFum[0]; ?></td>
                            <td rowspan="4"><?php echo $fechaFum[1]; ?></td>
                            <td rowspan="4"><?php echo $fechaFum[2]; ?></td>
                        <?php endif; ?>

                        <?php foreach ($vacunasEsquema as $bio): ?>
                            <?php foreach ($bio["dosis"] as $dosis): ?>
                                <?php
                                $idEbd = (int)$dosis["id_esquema_biologico_dosis"];
                                $aplicacion = $aplicaciones[$idPaciente][$idEbd] ?? null;
                                $claseHoy = ($aplicacion && esHoy($aplicacion["fecha_aplicacion"])) ? "aplicacion-hoy" : "";
                                ?>

                                <td rowspan="<?php echo $filasBloque; ?>" class="vacuna-dato <?php echo $claseHoy; ?>">
                                    <?php echo $aplicacion ? fechaCompleta($aplicacion["fecha_aplicacion"]) : ""; ?>
                                </td>

                                <td rowspan="<?php echo $filasBloque; ?>" class="vacuna-dato <?php echo $claseHoy; ?>">
                                    <?php echo $aplicacion ? e($aplicacion["numero_lote"]) : ""; ?>
                                </td>

                                <td rowspan="<?php echo $filasBloque; ?>" class="vacuna-dato <?php echo $claseHoy; ?>">
                                    <?php echo $aplicacion ? fechaCompleta($aplicacion["fecha_caducidad"]) : ""; ?>
                                </td>
                            <?php endforeach; ?>
                        <?php endforeach; ?>

                        <td rowspan="<?php echo $filasBloque; ?>" class="observaciones">
                            <?php echo nl2br(e($observacionesPaciente[$idPaciente] ?? "")); ?>
                        </td>

                        <td rowspan="<?php echo $filasBloque; ?>">
                            <a
                                href="editar.php?id=<?php echo urlencode($idPaciente); ?>"
                                class="btn-editar"
                            >
                                EDITAR
                            </a>
                        </td>
                    </tr>

                    <!-- =========================================
                         FILAS RESTANTES DEL PACIENTE
                         CURP QUEDA EN LA CUARTA FILA, NO AL FINAL.
                    ========================================== -->
                    <tr class="fila-secundaria">
                        <td class="etiqueta-ubicacion">MUNICIPIO</td>
                        <td class="celda-clave"><table class="clave-casillas" aria-label="Clave de municipio"><tr><td></td><td></td><td></td></tr></table></td>
                        <td class="celda-nombre"></td>
                    </tr>

                    <tr class="fila-secundaria">
                        <td class="etiqueta-ubicacion">LOCALIDAD</td>
                        <td class="celda-clave"><table class="clave-casillas" aria-label="Clave de localidad"><tr><td></td><td></td><td></td><td></td></tr></table></td>
                        <td class="celda-nombre"></td>
                    </tr>

                    <tr class="fila-secundaria">
                        <!-- La CURP ocupa las tres columnas del nombre del paciente; AGEB sigue dentro de ubicación geográfica. -->
                        <td colspan="3" class="celda-curp">
                            CURP: <?php echo e($paciente["curp"]); ?>
                        </td>
                        <td class="etiqueta-ubicacion">AGEB</td>
                        <td class="celda-clave"><table class="clave-casillas" aria-label="Clave AGEB"><tr><td></td><td></td><td></td><td></td></tr></table></td>
                        <td class="celda-nombre"></td>
                    </tr>

                    <?php if ($tipo === "0-9"): ?>

                        <!-- =====================================
                             ACOMPAÑANTE
                             SIN TEXTO "TUTOR"
                        ====================================== -->
                        <tr class="fila-acompanante">

                            <td rowspan="2">
                                <?php echo $tutor ? e($tutor["ap_pat"]) : ""; ?>
                            </td>
                            <td rowspan="2">
                                <?php echo $tutor ? e($tutor["ap_mat"]) : ""; ?>
                            </td>
                            <td rowspan="2">
                                <?php echo $tutor ? e($tutor["nombres"]) : ""; ?>
                            </td>

                            <?php
                            $fechaTutor = $tutor ? fechaDMY($tutor["fecha_nacimiento"]) : ["", "", ""];
                            ?>

                            <td rowspan="3"><?php echo $fechaTutor[0]; ?></td>
                            <td rowspan="3"><?php echo $fechaTutor[1]; ?></td>
                            <td rowspan="3"><?php echo $fechaTutor[2]; ?></td>

                            <td rowspan="3">
                                <?php echo $tutor ? e(edadAAmm($tutor["fecha_nacimiento"])) : ""; ?>
                            </td>

                            <td rowspan="3">
                                <?php echo $tutor ? e($tutor["sexo"]) : ""; ?>
                            </td>

                            <td rowspan="3"></td>
                            <td rowspan="3"></td>
                            <td rowspan="3"></td>
                            <td rowspan="3"></td>

                            <td class="etiqueta-ubicacion">ENTIDAD FEDERATIVA</td>
                            <td class="celda-clave"><table class="clave-casillas" aria-label="Clave de entidad"><tr><td></td><td></td></tr></table></td>
                            <td class="celda-nombre"></td>

                            <td rowspan="3">
                                <?php echo $tutor ? e($tutor["calle_tutor"]) : ""; ?>
                            </td>
                            <td rowspan="3">
                                <?php echo $tutor ? e($tutor["num_tutor"]) : ""; ?>
                            </td>
                            <td rowspan="3">
                                <?php echo $tutor ? e($tutor["colonia_tutor"]) : ""; ?>
                            </td>

                        </tr>

                        <tr class="fila-acompanante">
                            <td class="etiqueta-ubicacion">MUNICIPIO</td>
                            <td class="celda-clave"><table class="clave-casillas" aria-label="Clave de municipio"><tr><td></td><td></td><td></td></tr></table></td>
                            <td class="celda-nombre"></td>
                        </tr>

                        <tr class="fila-acompanante">
                            <!-- El parentesco ocupa su propio espacio debajo del nombre del tutor.
                                 No debe ocupar columnas de ubicación geográfica. -->
                            <td colspan="3" class="parentesco">
                                <?php if (!empty($paciente["parentesco"])): ?>
                                    PARENTESCO: <?php echo e($paciente["parentesco"]); ?>
                                <?php endif; ?>
                            </td>
                            <td class="etiqueta-ubicacion">LOCALIDAD</td>
                            <td class="celda-clave"><table class="clave-casillas" aria-label="Clave de localidad"><tr><td></td><td></td><td></td><td></td></tr></table></td>
                            <td class="celda-nombre"></td>
                        </tr>

                    <?php endif; ?>

                <?php
                    $numero++;
                endforeach;
                ?>
                </tbody>
            </table>
        </div>

    <?php elseif ($buscar !== "" && count($pacientes) === 0): ?>

        <div class="panel-resultados sin-resultados">
            No se encontró ningún paciente con:
            <strong><?php echo e($buscar); ?></strong>
        </div>

    <?php else: ?>

        <div class="panel-resultados sin-seleccion">
            Selecciona el censo que deseas consultar o utiliza el buscador.
        </div>

    <?php endif; ?>

</div>

</body>
</html>