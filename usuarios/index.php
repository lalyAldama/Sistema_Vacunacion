<?php
require_once __DIR__ . "/../config/seguridad_administrador.php";
require_once "../config/conexion.php";
require_once __DIR__ . "/../config/conexion_unidad.php";

$conexionCentral = $conexion;
$conexionUnidad = conexionAbrirUnidad();
$sqlCoordinadores = "SELECT
            u.id_usuario,
            u.nombre_usuario,
            u.ap_pat,
            u.ap_mat,
            u.nombre,
            r.nombre_rol,
            un.nombre_unidad,
            'central' AS fuente
        FROM USUARIO u
        INNER JOIN ROL r ON u.id_rol = r.id_rol
        LEFT JOIN UNIDAD un
            ON u.id_unidad = un.id_unidad
        WHERE r.nombre_rol IN ('Administrador', 'Supervisor')";
$resultadoCentral = $conexionCentral->query($sqlCoordinadores);
if (!$resultadoCentral) {
    die("No se pudieron consultar las cuentas de coordinación: " . $conexionCentral->error);
}

$sqlPersonal = "SELECT
            u.id_usuario,
            u.nombre_usuario,
            u.ap_pat,
            u.ap_mat,
            u.nombre,
            r.nombre_rol,
            un.nombre_unidad,
            'unidad' AS fuente
        FROM USUARIO u
        INNER JOIN ROL r ON u.id_rol = r.id_rol
        INNER JOIN UNIDAD un ON u.id_unidad = un.id_unidad
        WHERE r.nombre_rol = 'Personal de unidad'";
$resultadoUnidad = $conexionUnidad->query($sqlPersonal);
if (!$resultadoUnidad) {
    die("No se pudieron consultar las cuentas de las unidades: " . $conexionUnidad->error);
}
$usuarios = array_merge(
    $resultadoCentral->fetch_all(MYSQLI_ASSOC),
    $resultadoUnidad->fetch_all(MYSQLI_ASSOC)
);
$resultadoCentral->free();
$resultadoUnidad->free();
$conexionUnidad->close();
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestión de usuarios</title>
    <link rel="stylesheet" href="../css/estilos.css">
    <link rel="stylesheet" href="../css/gestion_admin.css">
</head>

<body class="gestion-admin">
<main class="gestion-contenedor">
    <header class="gestion-encabezado">
        <div class="gestion-encabezado-texto">
            <span class="gestion-etiqueta">ADMINISTRACIÓN</span>
            <h1>Gestión de usuarios</h1>
            <p>Administra las cuentas, roles y unidades del sistema.</p>
        </div>
    </header>

<nav class="gestion-acciones" aria-label="Acciones de usuarios">
    <a class="btn" href="guardar.php">+ Nuevo usuario</a>
</nav>

<div class="gestion-tabla-contenedor">
<table class="gestion-tabla">

    <thead>
        <tr>
            <th>ID</th>
            <th>Usuario</th>
            <th>Apellido Paterno</th>
            <th>Apellido Materno</th>
            <th>Nombre</th>
            <th>Rol</th>
            <th>Unidad</th>
            <th>Acciones</th>
        </tr>
    </thead>

    <tbody>

        <?php foreach ($usuarios as $usuario) { ?>

        <tr>
            <td><?php echo $usuario['id_usuario']; ?></td>
            <td><?php echo htmlspecialchars($usuario['nombre_usuario'], ENT_QUOTES, "UTF-8"); ?></td>
            <td><?php echo htmlspecialchars($usuario['ap_pat'], ENT_QUOTES, "UTF-8"); ?></td>
            <td><?php echo htmlspecialchars($usuario['ap_mat'], ENT_QUOTES, "UTF-8"); ?></td>
            <td><?php echo htmlspecialchars($usuario['nombre'], ENT_QUOTES, "UTF-8"); ?></td>
            <td><?php echo htmlspecialchars($usuario['nombre_rol'], ENT_QUOTES, "UTF-8"); ?></td>
            <td><?php echo htmlspecialchars($usuario['nombre_unidad'] ?? "Sin unidad", ENT_QUOTES, "UTF-8"); ?></td>

            <td>
                <div class="gestion-tabla-acciones">
                <a class="btn" href="editar.php?id=<?php echo (int)$usuario['id_usuario']; ?>&fuente=<?php echo urlencode($usuario['fuente']); ?>">
                    Editar
                </a>

                <a class="btn btn-peligro" href="eliminar.php?id=<?php echo (int)$usuario['id_usuario']; ?>&fuente=<?php echo urlencode($usuario['fuente']); ?>"
                   onclick="return confirm('¿Seguro que quieres eliminar este usuario?');">
                    Eliminar
                </a>
                </div>
            </td>
        </tr>

        <?php } ?>

    </tbody>

</table>
</div>
</main>
</body>
</html>