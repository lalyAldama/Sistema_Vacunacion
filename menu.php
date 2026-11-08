
<?php
require_once __DIR__ . "/config/seguridad.php";

if (!isset($_SESSION["id_usuario"])) {
    header("Location: login.php");
    exit();
}

$rol = $_SESSION["nombre_rol"] ?? "";
$nombre = $_SESSION["nombre"] ?? "Usuario";
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
        </section>

        <section class="menu-modulos">

            <?php if ($rol === "Administrador") { ?>

                <div class="menu-mensaje">
                    <h3>Menú de Administrador</h3>
                    <p>Selecciona una opción para continuar.</p>
                </div>

            <?php } elseif ($rol === "Personal de unidad") { ?>

                <a class="menu-tarjeta verde" href="censo/index.php">
                    <span class="menu-icono">▤</span>
                    <span class="menu-texto">
                        <strong>Censo nominal</strong>
                    </span>
                    <span class="menu-flecha">›</span>
                </a>

                <a class="menu-tarjeta azul" href="movimiento_biologicos/index.php">
                    <span class="menu-icono">▣</span>
                    <span class="menu-texto">
                        <strong>Movimiento de biológicos y jeringas</strong>
                    </span>
                    <span class="menu-flecha">›</span>
                </a>
                
                <a class="menu-tarjeta morado" href="vacunacion/index.php">
                    <span class="menu-icono">▥</span>
                    <span class="menu-texto">
                        <strong>Aplicación de vacunas</strong>
                    </span>
                    <span class="menu-flecha">›</span>
                </a>

                  <a class="menu-tarjeta morado" href="paloteo/paloteo_general.php">
                    <span class="menu-icono">▥</span>
                    <span class="menu-texto">
                        <strong>Paloteo</strong>
                    </span>
                    <span class="menu-flecha">›</span>
                </a>


                  <a class="menu-tarjeta morado" href="paloteo/paloteo_influenza.php">
                    <span class="menu-icono">▥</span>
                    <span class="menu-texto">
                        <strong>Paloteo de influenza</strong>
                    </span>
                    <span class="menu-flecha">›</span>
                </a>

                <a class="menu-tarjeta morado" href="paloteo/paloteo_covid.php">
                    <span class="menu-icono">▥</span>
                    <span class="menu-texto">
                        <strong>Paloteo de COVID-19</strong>
                    </span>
                    <span class="menu-flecha">›</span>
                </a>
                                  <a class="menu-tarjeta morado" href="Informe_mensual/mensual_general.php">
                      <span class="menu-icono">▤</span>
                      <span class="menu-texto">
                          <strong>Informes mensuales</strong>
                      </span>
                      <span class="menu-flecha">›</span>
                  </a>

                <a class="menu-tarjeta verde" href="Informe_mensual/anual.php">
                    <span class="menu-icono">▤</span>
                    <span class="menu-texto">
                        <strong>Informe anual</strong>
                    </span>
                    <span class="menu-flecha">›</span>
                </a>

                <a class="menu-tarjeta naranja" href="consultas/index.php">
                    <span class="menu-icono">⌕</span>
                    <span class="menu-texto">
                        <strong>Consultas</strong>
                    </span>
                    <span class="menu-flecha">›</span>
                </a>

                <a class="menu-tarjeta turquesa" href="exportar_importar/index.php">
                    <span class="menu-icono">⇄</span>
                    <span class="menu-texto">
                        <strong>Exportar información</strong>
                    </span>
                    <span class="menu-flecha">›</span>
                </a>

            <?php } elseif ($rol === "Supervisor") { ?>

                <div class="menu-mensaje">
                    <h3>Menú de Supervisor</h3>
                    <p>Bienvenido al panel de supervisión.</p>
                </div>

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
