<?php
session_start();

if(!isset ($_SESSION["id_usuario"])) {
    header("Location: login.php");
    exit();
}

$rol= $_SESSION["nombre_rol"];
?>

<!DOCTYPE html>
<html lang="es">
    <head>
        <meta charset="UTF-8">
        <title>Menú principal</title>
    </head>

    <body>
        <h1> BIENVENIDO </h1>

        <p>
            <?php echo $_SESSION["nombre"]; ?>
        </p>

        <?php if ($rol == "Administrador") {?>
            <h2>Menu de Administrador</h2>
        <?php } elseif ($rol == "Personal de unidad") {?>
            <a href="censo/index.php">
                <button>Censo Nominal </button>
            </a>

            <a href="movimiento_biologicos/index.php">
                <button> Movimiento de Biologicos y Jeringas</button>
            </a>

            <a href="reportes/index.php">
                <button> Reportes </button>
            </a>

            <a href="consultas/index.php">
                <button>Consultas</button>
            </a>

            <a href="exportar_importar/index.php">
                <button>Exportar/Actualizar</button>
            </a>

            <a href="logout.php">
                <button>Cerrar Sesion</button>
            </a>

        <?php } elseif ($rol == "Supervisor") {?>
            <h2>Menú de Supervisor</h2>
        <?php } ?>
    </body>
</html>