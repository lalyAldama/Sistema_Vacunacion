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
    </body>
</html>