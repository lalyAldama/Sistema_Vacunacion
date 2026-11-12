
<?php
require_once __DIR__ . "/config/seguridad.php";

if (!isset($_SESSION["id_usuario"])) {
    header("Location: login.php");
    exit();
}

$rol = $_SESSION["nombre_rol"] ?? "";
$nombre = $_SESSION["nombre"] ?? "Usuario";
$esRolCoordinador = in_array($rol, ["Administrador", "Supervisor"], true);
$unidadesDisponibles = [];
$unidadSeleccionada = null;
if ($esRolCoordinador) {
    if (isset($_GET["seleccionar_unidad"])) {
        unset($_SESSION["id_unidad_seleccionada"]);
    } elseif (isset($_GET["unidad"])) {
        $idUnidadSolicitada = filter_var($_GET["unidad"], FILTER_VALIDATE_INT);
        if ($idUnidadSolicitada === false || $idUnidadSolicitada < 1) {
            http_response_code(400);
            exit("La unidad seleccionada no es válida.");
        }
        require_once __DIR__ . "/config/conexion.php";
        $stmtUnidadElegida = $conexion->prepare(
            "SELECT id_unidad FROM UNIDAD WHERE id_unidad = ? LIMIT 1"
        );
        if (!$stmtUnidadElegida) {
            http_response_code(500);
            exit("No se pudo validar la unidad seleccionada: " . $conexion->error);
        }
        $stmtUnidadElegida->bind_param("i", $idUnidadSolicitada);
        if (!$stmtUnidadElegida->execute()) {
            http_response_code(500);
            exit("No se pudo validar la unidad seleccionada: " . $stmtUnidadElegida->error);
        }
        $unidadExiste = $stmtUnidadElegida->get_result()->fetch_assoc() !== null;
        $stmtUnidadElegida->close();
        if (!$unidadExiste) {
            http_response_code(404);
            exit("La unidad seleccionada no existe.");
        }
        $_SESSION["id_unidad_seleccionada"] = $idUnidadSolicitada;
        header("Location: menu.php");
        exit();
    }

    if (!empty($_SESSION["id_unidad_seleccionada"])) {
        require_once __DIR__ . "/config/conexion.php";
        $idUnidadActual = (int)$_SESSION["id_unidad_seleccionada"];
        $stmtUnidadActual = $conexion->prepare(
            "SELECT id_unidad, nombre_unidad FROM UNIDAD WHERE id_unidad = ? LIMIT 1"
        );
        if (!$stmtUnidadActual) {
            http_response_code(500);
            exit("No se pudo consultar la unidad seleccionada: " . $conexion->error);
        }
        $stmtUnidadActual->bind_param("i", $idUnidadActual);
        if (!$stmtUnidadActual->execute()) {
            http_response_code(500);
            exit("No se pudo consultar la unidad seleccionada: " . $stmtUnidadActual->error);
        }
        $unidadSeleccionada = $stmtUnidadActual->get_result()->fetch_assoc();
        $stmtUnidadActual->close();
        if (!$unidadSeleccionada) {
            unset($_SESSION["id_unidad_seleccionada"]);
        }
    }

    if (!$unidadSeleccionada) {
        require_once __DIR__ . "/config/conexion.php";
        $resultadoUnidades = $conexion->query(
            "SELECT id_unidad, nombre_unidad FROM UNIDAD ORDER BY nombre_unidad"
        );
        if (!$resultadoUnidades) {
            http_response_code(500);
            exit("No se pudo cargar el listado de unidades: " . $conexion->error);
        }
        $unidadesDisponibles = $resultadoUnidades->fetch_all(MYSQLI_ASSOC);
        $resultadoUnidades->free();
    }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Menú principal</title>
    <link rel="stylesheet" href="css/menu.css">
</head>

<body class="menu-body">

    <main class="menu-contenedor">

        <?php if ($rol === "Administrador") { ?>
            <details class="menu-admin-config">
                <summary aria-label="Abrir acciones de administrador" title="Acciones de administrador">
                    <svg viewBox="0 0 24 24" aria-hidden="true">
                        <path d="M12 8a4 4 0 1 0 0 8 4 4 0 0 0 0-8Z"></path>
                        <path d="m19.4 15 .1.1a1.5 1.5 0 1 1-2.1 2.1l-.1-.1a1.5 1.5 0 0 0-2.6 1.1v.2a1.5 1.5 0 1 1-3 0v-.2a1.5 1.5 0 0 0-2.6-1.1l-.1.1a1.5 1.5 0 1 1-2.1-2.1l.1-.1a1.5 1.5 0 0 0-1.1-2.6h-.2a1.5 1.5 0 1 1 0-3h.2a1.5 1.5 0 0 0 1.1-2.6l-.1-.1a1.5 1.5 0 1 1 2.1-2.1l.1.1a1.5 1.5 0 0 0 2.6-1.1v-.2a1.5 1.5 0 1 1 3 0v.2a1.5 1.5 0 0 0 2.6 1.1l.1-.1a1.5 1.5 0 1 1 2.1 2.1l-.1.1a1.5 1.5 0 0 0 1.1 2.6h.2a1.5 1.5 0 1 1 0 3h-.2a1.5 1.5 0 0 0-1.1 2.6Z"></path>
                    </svg>
                </summary>
                <?php require __DIR__ . "/menu_admin.php"; ?>
            </details>
        <?php } elseif ($rol === "Personal de unidad") { ?>
            <details class="menu-admin-config">
                <summary aria-label="Abrir acciones de personal de unidad" title="Acciones de personal de unidad">
                    <svg viewBox="0 0 24 24" aria-hidden="true">
                        <path d="M12 8a4 4 0 1 0 0 8 4 4 0 0 0 0-8Z"></path>
                        <path d="m19.4 15 .1.1a1.5 1.5 0 1 1-2.1 2.1l-.1-.1a1.5 1.5 0 0 0-2.6 1.1v.2a1.5 1.5 0 1 1-3 0v-.2a1.5 1.5 0 0 0-2.6-1.1l-.1.1a1.5 1.5 0 1 1-2.1-2.1l.1-.1a1.5 1.5 0 0 0-1.1-2.6h-.2a1.5 1.5 0 1 1 0-3h.2a1.5 1.5 0 0 0 1.1-2.6l-.1-.1a1.5 1.5 0 1 1 2.1-2.1l.1.1a1.5 1.5 0 0 0 2.6-1.1v-.2a1.5 1.5 0 1 1 3 0v.2a1.5 1.5 0 0 0 2.6 1.1l.1-.1a1.5 1.5 0 1 1 2.1 2.1l-.1.1a1.5 1.5 0 0 0 1.1 2.6h.2a1.5 1.5 0 1 1 0 3h-.2a1.5 1.5 0 0 0-1.1 2.6Z"></path>
                    </svg>
                </summary>
                <section class="menu-admin-panel" aria-label="Acciones de personal de unidad">
                    <div class="menu-admin-encabezado">
                        <span>PERSONAL DE UNIDAD</span>
                        <h2>Acciones</h2>
                    </div>
                    <a class="menu-admin-enlace menu-admin-usuarios" href="usuarios/agregar_enfermero.php">
                        <span class="menu-admin-enlace-icono" aria-hidden="true">
                            <svg viewBox="0 0 24 24">
                                <circle cx="9" cy="8" r="3"></circle>
                                <path d="M3.5 20v-1.5a5.5 5.5 0 0 1 11 0V20zM18 8v6M15 11h6"></path>
                            </svg>
                        </span>
                        <span>
                            <strong>Agregar enfermero</strong>
                            <small>Crear una cuenta para esta unidad</small>
                        </span>
                        <span class="menu-admin-enlace-flecha" aria-hidden="true">›</span>
                    </a>
                </section>
            </details>
        <?php } ?>

        <header class="menu-encabezado">

            <div class="menu-titulos">
                <h1 class="menu-subtitulo">Vacunación</h1>
                <h2 class="menu-subtitulo2">DISTRITO III PARRAL</h2>
            </div>
        </header>

        <section class="menu-bienvenida">
            <span class="menu-etiqueta">PANEL PRINCIPAL</span>

            <h2>¡Bienvenido!</h2>

            <p>
                Hola,
                <strong>
                    <?php echo htmlspecialchars($nombre, ENT_QUOTES, "UTF-8"); ?>
                </strong>.
                ¿Qué deseas realizar hoy?
            </p>

            <?php if ($rol === "Administrador") { ?>
                <span class="menu-rol">Administrador</span>
            <?php } elseif ($rol === "Personal de unidad") { ?>
                <span class="menu-rol">Personal de unidad</span>
            <?php } elseif ($rol === "Supervisor") { ?>
                <span class="menu-rol">Supervisor</span>
            <?php } ?>
            <?php if ($unidadSeleccionada) { ?>
                <p class="menu-unidad-activa">
                    Unidad seleccionada:
                    <strong><?php echo htmlspecialchars($unidadSeleccionada["nombre_unidad"], ENT_QUOTES, "UTF-8"); ?></strong>
                    <a href="menu.php?seleccionar_unidad=1">Cambiar unidad</a>
                </p>
            <?php } ?>
        </section>

        <section class="menu-modulos">

            <?php if ($esRolCoordinador && !$unidadSeleccionada) { ?>
                <div class="menu-mensaje">
                    <h3>Selecciona una unidad</h3>
                    <p>Elige la unidad que deseas consultar<?php echo $rol === "Supervisor" ? " o administrar" : ""; ?>.</p>
                </div>
                <?php foreach ($unidadesDisponibles as $unidadDisponible) { ?>
                    <a class="menu-tarjeta menu-tarjeta-unidad turquesa"
                       href="menu.php?unidad=<?php echo (int)$unidadDisponible["id_unidad"]; ?>">
                        <span class="menu-icono" aria-hidden="true">⌂</span>
                        <span class="menu-texto">
                            <strong><?php echo htmlspecialchars($unidadDisponible["nombre_unidad"], ENT_QUOTES, "UTF-8"); ?></strong>
                            <small>Unidad <?php echo (int)$unidadDisponible["id_unidad"]; ?></small>
                        </span>
                        <span class="menu-flecha" aria-hidden="true">›</span>
                    </a>
                <?php } ?>
            <?php } elseif ($rol === "Administrador" || $rol === "Supervisor" || $rol === "Personal de unidad") { ?>

                <a class="menu-tarjeta verde" href="censo/index.php">
                    <span class="menu-icono"><img src="iconos_menu/01_censo_nominal.png" alt=""></span>
                    <span class="menu-texto">
                        <strong>Censo nominal</strong>
                    </span>
                    <span class="menu-flecha">›</span>
                </a>

                <a class="menu-tarjeta azul" href="movimiento_biologicos/index.php">
                    <span class="menu-icono"><img src="iconos_menu/02_movimiento_biologicos_jeringas.png" alt=""></span>
                    <span class="menu-texto">
                        <strong>Movimiento de biológicos y jeringas</strong>
                    </span>
                    <span class="menu-flecha">›</span>
                </a>

                <?php if ($rol === "Personal de unidad") { ?>
                <a class="menu-tarjeta rojo" href="vacunacion/index.php">
                    <span class="menu-icono"><img src="iconos_menu/03_aplicacion_vacunas.png" alt=""></span>
                    <span class="menu-texto">
                        <strong>Aplicación de vacunas</strong>
                    </span>
                    <span class="menu-flecha">›</span>
                </a>
                <?php } ?>

                  <a class="menu-tarjeta morado" href="paloteo/paloteo_general.php">
                    <span class="menu-icono"><img src="iconos_menu/04_paloteo.png" alt=""></span>
                    <span class="menu-texto">
                        <strong>Paloteo</strong>
                    </span>
                    <span class="menu-flecha">›</span>
                </a>


                  <a class="menu-tarjeta morado" href="paloteo/paloteo_influenza.php">
                    <span class="menu-icono"><img src="iconos_menu/05_paloteo_influenza.png" alt=""></span>
                    <span class="menu-texto">
                        <strong>Paloteo de influenza</strong>
                    </span>
                    <span class="menu-flecha">›</span>
                </a>

                <a class="menu-tarjeta morado" href="paloteo/paloteo_covid.php">
                    <span class="menu-icono"><img src="iconos_menu/06_paloteo_covid19.png" alt=""></span>
                    <span class="menu-texto">
                        <strong>Paloteo de COVID-19</strong>
                    </span>
                    <span class="menu-flecha">›</span>
                </a>
                                  <a class="menu-tarjeta morado" href="Informes/mensual_general.php">
                      <span class="menu-icono"><img src="iconos_menu/07_informes_mensuales.png" alt=""></span>
                      <span class="menu-texto">
                          <strong>Informes mensuales</strong>
                      </span>
                      <span class="menu-flecha">›</span>
                  </a>

                <a class="menu-tarjeta verde" href="Informes/anual.php">
                    <span class="menu-icono"><img src="iconos_menu/08_informe_anual.png" alt=""></span>
                    <span class="menu-texto">
                        <strong>Informe anual</strong>
                    </span>
                    <span class="menu-flecha">›</span>
                </a>

                <a class="menu-tarjeta naranja" href="consultas/index.php">
                    <span class="menu-icono"><img src="iconos_menu/09_consultas.png" alt=""></span>
                    <span class="menu-texto">
                        <strong>Consultas</strong>
                    </span>
                    <span class="menu-flecha">›</span>
                </a>

                <?php if ($rol === "Personal de unidad") { ?>
                <a class="menu-tarjeta turquesa" href="exportar_importar/index.php">
                    <span class="menu-icono"><img src="iconos_menu/10_exportar_informacion.png" alt=""></span>
                    <span class="menu-texto">
                        <strong>Exportar información</strong>
                    </span>
                    <span class="menu-flecha">›</span>
                </a>

                <a class="menu-tarjeta verde" href="exportar_importar/importar_correcciones.php">
                    <span class="menu-icono"><img src="iconos_menu/11_actualizar_informacion.png" alt=""></span>
                    <span class="menu-texto">
                        <strong>Actualizar información</strong>
                    </span>
                    <span class="menu-flecha">›</span>
                </a>
                <a class="menu-tarjeta turquesa" href="bitacora/index.php">
                    <span class="menu-icono"><img src="iconos_menu/12_bitacora.png" alt=""></span>
                    <span class="menu-texto">
                        <strong>Bitacora de aplicaciones</strong>
                    </span>
                    <span class="menu-flecha">›</span>
                </a>
                <?php } elseif ($rol === "Supervisor") { ?>
                <a class="menu-tarjeta verde" href="exportar_importar/index.php">
                    <span class="menu-icono"><img src="iconos_menu/11_actualizar_informacion.png" alt=""></span>
                    <span class="menu-texto">
                        <strong>Importar información</strong>
                    </span>
                    <span class="menu-flecha">›</span>
                </a>

                <a class="menu-tarjeta naranja" href="exportar_importar/exportar_correcciones.php">
                    <span class="menu-icono"><img src="iconos_menu/10_exportar_informacion.png" alt=""></span>
                    <span class="menu-texto">
                        <strong>Exportar correcciones</strong>
                    </span>
                    <span class="menu-flecha">›</span>
                </a>
                <?php } ?>

            <?php } else { ?>

                <div class="menu-mensaje">
                    <h3>Rol sin opciones configuradas</h3>
                    <p>Consulta con el administrador del sistema.</p>
                </div>

            <?php } ?>

        </section>

        <footer class="menu-pie">
            <span>Sistema de Vacunación</span>

            <a class="menu-salir" href="logout.php">
                Cerrar sesión <span>↗</span>
            </a>
        </footer>

    </main>

</body>
</html>
