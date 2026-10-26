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
            font-size: 5px;
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

        
        .encabezado-formato-oficial { margin: 12px 0 16px; color: #111; }
        .datos-administrativos-censo { display: grid; grid-template-columns: repeat(4, minmax(120px, 1fr)); border-top: 1px solid #333; border-left: 1px solid #333; font-size: 10px; }
        .dato-admin { min-width: 0; border-right: 1px solid #333; border-bottom: 1px solid #333; padding: 4px; }
        .dato-admin > span { display: block; font-weight: bold; font-size: 9px; margin-bottom: 3px; }
        .dato-ancho { grid-column: span 2; }
        .admin-linea { display: flex; align-items: stretch; gap: 3px; min-height: 20px; }
        .admin-linea > b { font-size: 8px; align-self: center; }
        .admin-casillas, .admin-valor { min-width: 18px; min-height: 20px; border-bottom: 1px solid #555; padding: 2px 3px; outline-offset: -1px; overflow-wrap: anywhere; }
        .admin-casillas { flex: 0 0 48px; border: 1px solid #555; letter-spacing: 3px; text-align: center; }
        .admin-valor { flex: 1; }
        .aviso-admin { margin: 4px 0 0; font-size: 9px; color: #555; }
        @media (max-width: 700px) { .datos-administrativos-censo { grid-template-columns: repeat(2, minmax(110px, 1fr)); } }
    

.encabezado-formato-oficial { width:100%; margin:8px 0 12px; color:#111; font-family:Arial, sans-serif; }
.cabecera-superior-formato { position:relative; display:flex; flex-direction:column; align-items:stretch; justify-content:flex-start; gap:5px; min-height:0; padding:0; }
.cabecera-titulos-oficiales { position:static; width:100%; box-sizing:border-box; transform:none; text-align:center; font-size:10px; font-weight:bold; line-height:1.35; color:#111; z-index:1; padding:0 4px; }
.cabecera-titulos-oficiales strong { display:block; margin-bottom:3px; }
.logos-institucionales-censo { display:block; width:48%; max-width:620px; height:auto; max-height:38px; object-fit:contain; object-position:right center; align-self:flex-end; margin:0; transform:none; }
.datos-administrativos-censo { display:grid; grid-template-columns: 1.25fr .78fr 1fr; gap:0; border:1px solid #777; font-size:9px; }
.bloque-admin-izquierdo,.bloque-admin-centro,.bloque-admin-derecho { min-width:0; padding:3px 5px; }
.bloque-admin-izquierdo { grid-column:1; grid-row:1; }
.bloque-admin-centro { grid-column:2; grid-row:1; }
.bloque-admin-derecho { grid-column:3; grid-row:1; }
.horizontal-admin { display:flex; align-items:center; gap:4px; min-width:0; min-height:22px; border-bottom:1px solid #777; padding:2px 1px; }
.horizontal-admin:last-child { border-bottom:0; }
.horizontal-admin > span { flex:0 0 68px; font-size:8px; font-weight:bold; white-space:nowrap; }
.horizontal-admin .admin-casillas { flex:0 0 52px; min-width:0; min-height:17px; height:17px; border:1px solid #555; border-radius:3px; text-align:center; letter-spacing:2px; padding:1px!important; }
.horizontal-admin .admin-valor { flex:1 1 auto; min-width:0; min-height:17px; height:17px; border-bottom:1px solid #555; padding:1px 3px!important; }
.admin-ageb-casillas { flex:0 0 90px!important; }
.bloque-admin-inferior { grid-column:1 / -1; display:grid; grid-template-columns:.8fr .8fr 1.3fr 1fr; border-top:1px solid #777; padding:3px 5px; gap:5px; }
.bloque-admin-inferior .horizontal-admin { border:0; }
.bloque-admin-inferior .horizontal-admin > span { flex-basis:auto; }
.unidad-admin .admin-valor { border:1px solid #555; border-radius:3px; }
.clues-admin .horizontal-admin > span { flex-basis:70px; }
.aviso-admin { margin:3px 0 0; font-size:8px; color:#555; }
@media (max-width:800px) { .cabecera-titulos-oficiales { position:static; transform:none; width:100%; } .logos-institucionales-censo { width:72%; max-width:100%; max-height:34px; } .datos-administrativos-censo { grid-template-columns:1fr; } .bloque-admin-izquierdo,.bloque-admin-centro,.bloque-admin-derecho,.bloque-admin-inferior { grid-column:1; grid-row:auto; } .bloque-admin-inferior { grid-template-columns:1fr 1fr; } }
        .pie-censo-oficial { margin: 10px 0 18px; font-family: Arial, sans-serif; font-size: 10px; color: #111; border: 1px solid #555; }
        .pie-notas { display: grid; grid-template-columns: 1fr 0.65fr 1.45fr 1.15fr 1.15fr; border-bottom: 1px solid #555; }
        .pie-bloque { min-width: 0; padding: 5px 6px; border-right: 1px solid #777; }
        .pie-bloque:last-child { border-right: 0; }
        .pie-bloque > strong { display: block; margin-bottom: 5px; font-size: 10px; }
        .pie-bloque p { margin: 3px 0; line-height: 1.25; }
        .pie-lista-tres { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 4px 7px; line-height: 1.25; }
        .pie-lista-dos { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 4px 7px; line-height: 1.25; }
        .pie-firmas { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; padding: 9px 12px 7px; text-align: center; border-bottom: 1px solid #777; font-weight: bold; }
        .linea-firma { height: 20px; border-bottom: 1px solid #555; margin: 0 8px; }
        .pie-aclaracion-vacuna { padding: 5px 7px; font-size: 9px; line-height: 1.25; }
        .pie-aclaracion-vacuna p { margin: 3px 0; }
        @media (max-width: 900px) { .pie-notas { grid-template-columns: repeat(2, minmax(0, 1fr)); } .pie-bloque { border-bottom: 1px solid #777; } .pie-firmas { gap: 8px; } }
        @media print { .pie-censo-oficial { break-inside: avoid; } }
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

        <section class="encabezado-formato-oficial">
    <div class="cabecera-superior-formato">
        <div class="cabecera-titulos-oficiales">
            <strong><?php echo $idCenso === 1 ? "ANEXO 1-A" : ($idCenso === 2 ? "ANEXO 1-B" : "ANEXO 1-C"); ?></strong>
            <div>CENTRO NACIONAL PARA LA SALUD DE LA INFANCIA Y LA ADOLESCENCIA</div>
            <div><?php
                if ($idCenso === 1) { echo "CENSO NOMINAL PARA REGISTRO DE ESQUEMAS DE VACUNACIÓN DE 0 A 9 AÑOS"; }
                elseif ($idCenso === 2) { echo "CENSO NOMINAL PARA REGISTRO DE ESQUEMAS DE VACUNACIÓN DE 10 A 19 AÑOS"; }
                else { echo "CENSO NOMINAL PARA POBLACIÓN ADULTA (20 AÑOS Y MÁS) Y MUJERES EMBARAZADAS"; }
            ?></div>
        </div>
        <img class="logos-institucionales-censo" src="data:image/png;base64,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" alt="Logotipos institucionales del formato oficial">
    </div>
    <div class="datos-administrativos-censo">
        <div class="bloque-admin-izquierdo">
            <div class="dato-admin horizontal-admin"><span>INSTITUCIÓN</span><div class="admin-casillas" contenteditable="true" data-editable-key="admin-institucion-clave"></div><div class="admin-valor" contenteditable="true" data-editable-key="admin-institucion-nombre"></div></div>
            <div class="dato-admin horizontal-admin"><span>ESTADO</span><div class="admin-casillas" contenteditable="true" data-editable-key="admin-estado-clave"></div><div class="admin-valor" contenteditable="true" data-editable-key="admin-estado-nombre"></div></div>
            <div class="dato-admin horizontal-admin"><span>JURISDICCIÓN</span><div class="admin-casillas" contenteditable="true" data-editable-key="admin-jurisdiccion-clave"></div><div class="admin-valor" contenteditable="true" data-editable-key="admin-jurisdiccion-nombre"></div></div>
        </div>
        <div class="bloque-admin-centro">
            <div class="dato-admin horizontal-admin"><span>DELEGACIÓN</span><div class="admin-casillas" contenteditable="true" data-editable-key="admin-delegacion-clave"></div><div class="admin-valor" contenteditable="true" data-editable-key="admin-delegacion"></div></div>
            <div class="dato-admin horizontal-admin"><span>ZONA</span><div class="admin-casillas" contenteditable="true" data-editable-key="admin-zona-clave"></div><div class="admin-valor" contenteditable="true" data-editable-key="admin-zona"></div></div>
        </div>
        <div class="bloque-admin-derecho">
            <div class="dato-admin horizontal-admin"><span>MUNICIPIO</span><div class="admin-casillas" contenteditable="true" data-editable-key="admin-municipio-clave"></div><div class="admin-valor" contenteditable="true" data-editable-key="admin-municipio-nombre"></div></div>
            <div class="dato-admin horizontal-admin"><span>LOCALIDAD</span><div class="admin-casillas" contenteditable="true" data-editable-key="admin-localidad-clave"></div><div class="admin-valor" contenteditable="true" data-editable-key="admin-localidad-nombre"></div></div>
            <div class="dato-admin horizontal-admin"><span>AGEB</span><div class="admin-casillas admin-ageb-casillas" contenteditable="true" data-editable-key="admin-ageb"></div></div>
        </div>
        <div class="bloque-admin-inferior">
            <div class="dato-admin horizontal-admin"><span>SECTOR</span><div class="admin-casillas" contenteditable="true" data-editable-key="admin-sector-clave"></div><div class="admin-valor" contenteditable="true" data-editable-key="admin-sector"></div></div>
            <div class="dato-admin horizontal-admin"><span>MANZANA</span><div class="admin-casillas" contenteditable="true" data-editable-key="admin-manzana-clave"></div><div class="admin-valor" contenteditable="true" data-editable-key="admin-manzana"></div></div>
            <div class="dato-admin horizontal-admin unidad-admin"><span>UNIDAD DE SALUD</span><div class="admin-valor" contenteditable="true" data-editable-key="admin-unidad-salud"><?php echo e($nombreUnidad); ?></div></div>
            <div class="dato-admin horizontal-admin clues-admin"><span>CLAVE CLUES</span><div class="admin-valor" contenteditable="true" data-editable-key="admin-clues"></div></div>
        </div>
    </div>
    <p class="aviso-admin">Haz clic en los espacios para llenar los datos. Se guardan en este navegador.</p>
</section>

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
                            <?php
                            if ($tipo === "0-9") {
                                echo $tutor ? e($tutor["telefono_tutor"]) : "";
                            } else {
                                echo e($paciente["telefono_paciente"]);
                            }
                            ?>
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

        <!-- Pie del formato oficial del censo, basado en pie_formato.xlsx -->
        <section class="pie-censo-oficial" aria-label="Pie del formato oficial del censo">
            <div class="pie-notas">
                <div class="pie-bloque pie-nota-principal">
                    <strong>NOTA:</strong>
                    <p>(1).- REGISTRAR DATOS DEL MENOR.</p>
                    <p>(2).- REGISTRAR DATOS DEL ACOMPAÑANTE.</p>
                </div>
                <div class="pie-bloque">
                    <strong>(a) SEXO:</strong>
                    <p>F: Femenino</p><p>M: Masculino</p>
                </div>
                <div class="pie-bloque pie-derechohabiencia">
                    <strong>(b) DERECHOHABIENCIA:</strong>
                    <div class="pie-lista-tres">
                        <span>1.- SECRETARÍA DE SALUD</span><span>2.- IMSS</span><span>3.- ISSSTE</span>
                        <span>4.- IMSS BIENESTAR</span><span>5.- SEDENA</span><span>6.- SEMAR</span>
                        <span>7.- PEMEX</span><span>8.- DIF</span><span>9.- OTROS</span>
                    </div>
                </div>
                <div class="pie-bloque pie-parentesco">
                    <strong>PARENTESCO:</strong>
                    <div class="pie-lista-dos">
                        <span>(1).- Madre</span><span>(7).- Hija</span><span>(2).- Padre</span><span>(8).- Hijo</span>
                        <span>(3).- Abuelo</span><span>(9).- Prima</span><span>(4).- Abuela</span><span>(10).- Primo</span>
                        <span>(5).- Tío</span><span>(11).- Vecino (a)</span><span>(6).- Tía</span><span>(12).- Otro parentesco</span>
                    </div>
                </div>
                <div class="pie-bloque pie-migratorio">
                    <strong>(c) ESTATUS MIGRATORIO</strong>
                    <p><b>REGULAR:</b> Persona extranjera que ha cumplido con las disposiciones migratorias para su entrada y estancia en México.</p>
                    <p><b>IRREGULAR:</b> Persona que ha incumplido las disposiciones migratorias para su entrada y estancia en México.</p>
                </div>
                <div class="pie-bloque pie-codigos">
                    <strong>(d) CÓDIGOS:</strong>
                    <div class="pie-lista-dos">
                        <span>A.- AUSENTE</span><span>R.- RENUENTE</span><span>I.- INMIGRÓ</span><span>E.- EMIGRÓ</span><span>D.- DEFUNCIÓN</span><span>F.- ENFERMO</span>
                    </div>
                </div>
            </div>
            <div class="pie-firmas">
                <div>Nombre y Firma del Vacunador<div class="linea-firma"></div></div>
                <div>Nombre, Cargo y Firma de quien Valida<div class="linea-firma"></div></div>
            </div>
            <div class="pie-aclaracion-vacuna">
                <p><b>*</b> La vacuna contra el VPH se aplica a niñas y niños de 5to año de primaria y de 11 años no escolarizados. De existir niñas y niños de 9 años cursando el 5to grado, deberá registrarse en el presente censo nominal, considerando que la edad mínima de aplicación de la vacuna contra el VPH es a los 9 años.</p>
                <p><b>** Esquema primario:</b> 2 dosis en la misma temporada invernal, con intervalo de 4 semanas entre cada una a niñas y niños de 6 a 59 meses. Posteriormente un refuerzo anual y cada temporada invernal hasta los 59 meses de edad. Una dosis anual (refuerzo) en niñas y niños de 5 a 9 años con comorbilidades de riesgo. Ver lineamientos vigentes.</p>
            </div>
        </section>

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

<script>
/* Los campos administrativos se conservan en el navegador, sin crear tablas en MySQL. */
document.querySelectorAll("[data-editable-key]").forEach(function (campo) {
    var clave = "censo_admin_" + campo.getAttribute("data-editable-key");
    try {
        var guardado = localStorage.getItem(clave);
        if (guardado !== null) campo.textContent = guardado;
    } catch (error) {}
    campo.addEventListener("input", function () {
        try { localStorage.setItem(clave, campo.textContent); } catch (error) {}
    });
});
</script>

</body>
</html>