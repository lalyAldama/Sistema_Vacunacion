<?php
require_once __DIR__ . "/../config/seguridad.php";

if(!isset($_SESSION["id_usuario"])){
    header("Location: ../login.php");
    exit();
}

require_once "../config/conexion.php";
require_once __DIR__ . "/../config/unidad_contexto.php";
unidadExigirPersonalDeUnidad();
$contextoUnidad = unidadObtenerContexto($conexion);
$idUnidad = $contextoUnidad["id_unidad"];
$soloConsulta = $contextoUnidad["solo_consulta"];
unidadAsegurarUnidadEnPacienteCenso($conexion);

$resultados= null;
$busqueda= "";
$stmt = null;

if(isset($_GET["buscar"])) {
    $busqueda = trim($_GET["buscar"]);

    if($busqueda !== ""){
        $termino = "%" . $busqueda . "%";

        $sql = "SELECT DISTINCT p.id_paciente, p.ap_pat, p.ap_mat, p.nombres, p.fecha_nacimiento, p.curp
                FROM PACIENTE p
                INNER JOIN PACIENTE_CENSO pc ON pc.id_paciente = p.id_paciente
                WHERE pc.id_unidad = ?
                  AND (p.ap_pat LIKE ? OR p.ap_mat LIKE ? OR p.nombres LIKE ? OR p.curp LIKE ?)
                ORDER BY  ap_pat, ap_mat, nombres";
        $stmt = $conexion->prepare($sql);

        $stmt->bind_param("issss", $idUnidad, $termino, $termino, $termino, $termino);
        $stmt->execute();
        $resultados = $stmt->get_result();
    }
}

$biologicos = $conexion->query(
    "SELECT id_biologico, nombre_biologico FROM BIOLOGICO ORDER BY nombre_biologico"
);
if (!$biologicos) {
    die("No se pudieron consultar los biológicos: " . $conexion->error);
}
$listaBiologicos = $biologicos->fetch_all(MYSQLI_ASSOC);
$biologicos->free();

$stmtLotes = $conexion->prepare(
    "SELECT DISTINCT l.id_lote, l.numero_lote, l.fecha_caducidad,
            pr.id_biologico, pr.nombre_presentacion, b.nombre_biologico
     FROM APLICACION_VACUNA av
     INNER JOIN LOTE l ON l.id_lote = av.id_lote
     INNER JOIN PRESENTACION pr ON pr.id_presentacion = l.id_presentacion
     INNER JOIN BIOLOGICO b ON b.id_biologico = pr.id_biologico
     WHERE av.id_unidad = ?
     ORDER BY b.nombre_biologico, l.numero_lote, l.fecha_caducidad, l.id_lote"
);
if (!$stmtLotes) {
    die("No se pudieron preparar los lotes de aplicaciones: " . $conexion->error);
}
$stmtLotes->bind_param("i", $idUnidad);
if (!$stmtLotes->execute()) {
    die("No se pudieron consultar los lotes de aplicaciones: " . $stmtLotes->error);
}
$listaLotes = $stmtLotes->get_result()->fetch_all(MYSQLI_ASSOC);
$stmtLotes->close();

$consultarAplicaciones = isset($_GET["consultar_aplicaciones"]);
$idBiologicoFiltro = filter_var($_GET["biologico"] ?? null, FILTER_VALIDATE_INT);
$idLoteFiltro = ($_GET["lote"] ?? "") === ""
    ? null
    : filter_var($_GET["lote"], FILTER_VALIDATE_INT);
$errorConsulta = "";
$aplicacionesFiltradas = [];
if ($consultarAplicaciones) {
    $biologicoValido = false;
    foreach ($listaBiologicos as $biologico) {
        if ((int)$biologico["id_biologico"] === $idBiologicoFiltro) {
            $biologicoValido = true;
            break;
        }
    }
    $loteValido = $idLoteFiltro === null;
    foreach ($listaLotes as $lote) {
        if ((int)$lote["id_lote"] === $idLoteFiltro
            && (int)$lote["id_biologico"] === $idBiologicoFiltro) {
            $loteValido = true;
            break;
        }
    }

    if (!$biologicoValido || !$loteValido) {
        http_response_code(400);
        $errorConsulta = "Selecciona un biológico válido y, si eliges un lote, uno que corresponda a ese biológico.";
    } else {
        $sqlAplicacionesFiltro = "SELECT p.nombres, p.ap_pat, p.ap_mat, p.curp,
                                         av.fecha_aplicacion, d.nombre_dosis,
                                         l.numero_lote, l.fecha_caducidad, pr.nombre_presentacion
                                  FROM APLICACION_VACUNA av
                                  INNER JOIN PACIENTE p ON p.id_paciente = av.id_paciente
                                  INNER JOIN LOTE l ON l.id_lote = av.id_lote
                                  INNER JOIN PRESENTACION pr ON pr.id_presentacion = l.id_presentacion
                                  INNER JOIN ESQUEMA_BIOLOGICO_DOSIS ebd
                                      ON ebd.id_esquema_biologico_dosis = av.id_esquema_biologico_dosis
                                  INNER JOIN DOSIS d ON d.id_dosis = ebd.id_dosis
                                  WHERE av.id_unidad = ? AND pr.id_biologico = ?"
            . ($idLoteFiltro !== null ? " AND av.id_lote = ?" : "")
            . " ORDER BY p.ap_pat, p.ap_mat, p.nombres, av.fecha_aplicacion DESC, av.id_aplicacion_vacuna DESC";
        $stmtAplicacionesFiltro = $conexion->prepare($sqlAplicacionesFiltro);
        if (!$stmtAplicacionesFiltro) {
            die("No se pudo preparar la consulta de aplicaciones: " . $conexion->error);
        }
        if ($idLoteFiltro === null) {
            $stmtAplicacionesFiltro->bind_param("ii", $idUnidad, $idBiologicoFiltro);
        } else {
            $stmtAplicacionesFiltro->bind_param("iii", $idUnidad, $idBiologicoFiltro, $idLoteFiltro);
        }
        if (!$stmtAplicacionesFiltro->execute()) {
            die("No se pudieron consultar las aplicaciones: " . $stmtAplicacionesFiltro->error);
        }
        $aplicacionesFiltradas = $stmtAplicacionesFiltro->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmtAplicacionesFiltro->close();
    }
}
?>

<!DOCTYPE html>
<html lang="es">
    <head>
        <meta charset="UTF-8">
        <title>REGISTRO DE APLICACIÓN DE VACUNAS</title>
        <link rel="stylesheet" href="../css/estilos.css">
        <link rel="stylesheet" href="css/registro-vacunacion.css">
    </head>

    <body>
        <main class="registro-vacunacion">
            <header class="encabezado-registro">
                <div>
                    <span class="sobrelinea-registro">GESTIÓN DE INMUNIZACIÓN</span>
                    <h1>Registro de aplicación</h1>
                    <p>Elige cómo registrar la vacuna para continuar.</p>
                </div>
            </header>

            <?php if (!$soloConsulta): ?>
            <section class="opciones-registro" aria-label="Opciones de registro">
                <article class="opcion-registro opcion-expediente">
                    <div class="encabezado-opcion">
                        <span class="icono-opcion" aria-hidden="true">01</span>
                        <span class="etiqueta-opcion">REGISTRO HABITUAL</span>
                    </div>
                    <h2>Con expediente</h2>
                    <p>Busca a la persona para registrar la aplicación en su expediente y en el esquema correspondiente.</p>

                    <form method="GET" action="index.php" class="formulario-busqueda">
                        <label for="buscar">Nombre, apellidos o CURP</label>
                        <div class="control-busqueda">
                            <input
                                type="text"
                                id="buscar"
                                name="buscar"
                                placeholder="Escribe para buscar..."
                                value="<?php echo htmlspecialchars($busqueda, ENT_QUOTES, "UTF-8"); ?>"
                                required
                            >
                            <button type="submit">Buscar paciente</button>
                        </div>
                    </form>
                </article>

                <article class="opcion-registro opcion-confidencial">
                    <div class="encabezado-opcion">
                        <span class="icono-opcion icono-opcion--turquesa" aria-hidden="true">VPH</span>
                        <span class="etiqueta-opcion etiqueta-opcion--turquesa">MODALIDAD CONFIDENCIAL</span>
                    </div>
                    <h2>Aplicación de VPH</h2>
                    <p>Para registrar esta aplicación sin buscar ni guardar datos de una persona en un expediente.</p>

                    <ul class="resumen-confidencial">
                        <li><span>Se registra</span> grupo, dosis, lote y fecha</li>
                        <li><span>También se indica</span> si la persona es migrante</li>
                        <li><span>No se solicita</span> nombre ni CURP</li>
                    </ul>

                    <a href="registrar_vph_confidencial.php" class="boton-confidencial">
                        Iniciar registro confidencial
                        <span aria-hidden="true">→</span>
                    </a>
                </article>
            </section>
            <?php else: ?>
                <p role="status">Modo de consulta: el administrador puede revisar pacientes de esta unidad, pero no registrar aplicaciones.</p>
            <?php endif; ?>

            <section class="resultados-registro consulta-aplicaciones" aria-labelledby="titulo-consulta-aplicaciones">
                <h2 id="titulo-consulta-aplicaciones">Pacientes vacunados por biológico o lote</h2>
                <p>Selecciona un biológico y, opcionalmente, un lote para consultar las aplicaciones registradas en esta unidad.</p>
                <p>Los registros confidenciales de VPH no aparecen porque no contienen datos del paciente.</p>
                <form method="get" action="index.php" class="filtros-aplicaciones">
                    <input type="hidden" name="consultar_aplicaciones" value="1">
                    <label for="biologico">Biológico aplicado
                        <select name="biologico" id="biologico" required>
                            <option value="">Selecciona un biológico</option>
                            <?php foreach ($listaBiologicos as $biologico): ?>
                                <option value="<?php echo (int)$biologico["id_biologico"]; ?>"
                                    <?php echo (int)$biologico["id_biologico"] === $idBiologicoFiltro ? "selected" : ""; ?>>
                                    <?php echo htmlspecialchars($biologico["nombre_biologico"], ENT_QUOTES, "UTF-8"); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label for="lote">Lote (opcional)
                        <select name="lote" id="lote">
                            <option value="">Todos los lotes</option>
                            <?php foreach ($listaLotes as $lote): ?>
                                <option value="<?php echo (int)$lote["id_lote"]; ?>"
                                    data-biologico="<?php echo (int)$lote["id_biologico"]; ?>"
                                    <?php echo (int)$lote["id_lote"] === $idLoteFiltro ? "selected" : ""; ?>>
                                    <?php echo htmlspecialchars(
                                        $lote["nombre_biologico"] . " — " . $lote["numero_lote"]
                                        . " (" . $lote["nombre_presentacion"] . ", cad. " . $lote["fecha_caducidad"]
                                        . ", ID " . $lote["id_lote"] . ")",
                                        ENT_QUOTES,
                                        "UTF-8"
                                    ); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <button type="submit">Consultar aplicaciones</button>
                </form>

                <?php if ($errorConsulta !== ""): ?>
                    <p class="sin-resultados" role="alert"><?php echo htmlspecialchars($errorConsulta, ENT_QUOTES, "UTF-8"); ?></p>
                <?php elseif ($consultarAplicaciones): ?>
                    <?php if ($aplicacionesFiltradas === []): ?>
                        <p class="sin-resultados">No se encontraron aplicaciones para los filtros seleccionados.</p>
                    <?php else: ?>
                        <p>Aplicaciones encontradas: <?php echo count($aplicacionesFiltradas); ?>. Una persona puede aparecer más de una vez si recibió varias dosis.</p>
                        <div class="tabla-registro-contenedor">
                            <table class="tabla-registro">
                                <thead>
                                    <tr>
                                        <th>Paciente</th>
                                        <th>CURP</th>
                                        <th>Fecha de aplicación</th>
                                        <th>Dosis</th>
                                        <th>Lote</th>
                                        <th>Presentación</th>
                                        <th>Caducidad del lote</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($aplicacionesFiltradas as $aplicacion): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars(trim($aplicacion["ap_pat"] . " " . $aplicacion["ap_mat"] . " " . $aplicacion["nombres"]), ENT_QUOTES, "UTF-8"); ?></td>
                                            <td><?php echo htmlspecialchars($aplicacion["curp"] ?? "", ENT_QUOTES, "UTF-8"); ?></td>
                                            <td><?php echo htmlspecialchars($aplicacion["fecha_aplicacion"], ENT_QUOTES, "UTF-8"); ?></td>
                                            <td><?php echo htmlspecialchars($aplicacion["nombre_dosis"], ENT_QUOTES, "UTF-8"); ?></td>
                                            <td><?php echo htmlspecialchars($aplicacion["numero_lote"], ENT_QUOTES, "UTF-8"); ?></td>
                                            <td><?php echo htmlspecialchars($aplicacion["nombre_presentacion"], ENT_QUOTES, "UTF-8"); ?></td>
                                            <td><?php echo htmlspecialchars($aplicacion["fecha_caducidad"], ENT_QUOTES, "UTF-8"); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </section>

            <?php if ($resultados !== null): ?>
                <section class="resultados-registro" aria-labelledby="titulo-resultados">
                    <div class="titulo-resultados">
                        <div>
                            <span class="sobrelinea-registro">BÚSQUEDA DE PACIENTES</span>
                            <h2 id="titulo-resultados">Resultados</h2>
                        </div>
                        <span class="termino-busqueda"><?php echo htmlspecialchars($busqueda, ENT_QUOTES, "UTF-8"); ?></span>
                    </div>

                    <?php if ($resultados->num_rows > 0): ?>
                        <div class="tabla-registro-contenedor">
                            <table class="tabla-registro">
                                <thead>
                                    <tr>
                                        <th>Nombre completo</th>
                                        <th>Fecha de nacimiento</th>
                                        <th>CURP</th>
                                        <th>Acción</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php while($paciente = $resultados->fetch_assoc()) : ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($paciente["ap_pat"] . " " . $paciente["ap_mat"] . " " . $paciente["nombres"]); ?></td>
                                            <td><?php echo htmlspecialchars($paciente["fecha_nacimiento"]); ?></td>
                                            <td><?php echo htmlspecialchars($paciente["curp"] ?? ""); ?></td>
                                            <td>
                                                <?php if ($soloConsulta): ?>
                                                <a href="../censo/index.php?id_paciente=<?php echo urlencode($paciente["id_paciente"]); ?>" class="boton-tabla">
                                                    Consultar expediente
                                                </a>
                                                <?php else: ?>
                                                <a href="registrar_vacuna.php?id=<?php echo urlencode($paciente["id_paciente"]); ?>" class="boton-tabla">
                                                    Registrar aplicación
                                                </a>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endwhile; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <p class="sin-resultados">No se encontraron pacientes con esos datos. Revisa el nombre o la CURP e inténtalo de nuevo.</p>
                    <?php endif; ?>

                    <?php $stmt->close(); ?>
                </section>
            <?php endif; ?>
        </main>
        <script>
            const biologico = document.getElementById("biologico");
            const lote = document.getElementById("lote");
            function filtrarLotes() {
                Array.from(lote.options).forEach(function (opcion) {
                    if (opcion.value === "") return;
                    const corresponde = opcion.dataset.biologico === biologico.value;
                    opcion.hidden = !corresponde;
                    opcion.disabled = !corresponde;
                    if (!corresponde && opcion.selected) lote.value = "";
                });
            }
            biologico.addEventListener("change", filtrarLotes);
            filtrarLotes();
        </script>
    </body>
</html>