<?php
require_once "../config/conexion.php";

$sql = "SELECT 
            u.id_usuario, 
            u.nombre_usuario, 
            u.ap_pat, 
            u.ap_mat, 
            u.nombre,
            r.nombre_rol,
            un.nombre_unidad
        FROM USUARIO u 
        INNER JOIN ROL r 
            ON u.id_rol = r.id_rol
        INNER JOIN UNIDAD un
            ON u.id_unidad = un.id_unidad";

$resultado = $conexion->query($sql);
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Gestión de usuarios</title>
</head>

<body>

<h1>Gestión de usuarios</h1>

<a href="guardar.php">
    <button>+ Nuevo usuario</button>
</a>

<br><br>

<table border="1">

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

        <?php while ($usuario = $resultado->fetch_assoc()) { ?>

        <tr>
            <td><?php echo $usuario['id_usuario']; ?></td>
            <td><?php echo $usuario['nombre_usuario']; ?></td>
            <td><?php echo $usuario['ap_pat']; ?></td>
            <td><?php echo $usuario['ap_mat']; ?></td>
            <td><?php echo $usuario['nombre']; ?></td>
            <td><?php echo $usuario['nombre_rol']; ?></td>
            <td><?php echo $usuario['nombre_unidad']; ?></td>

            <td>

                <a href="editar.php?id=<?php echo $usuario['id_usuario']; ?>">
                    Editar
                </a>

                <a href="eliminar.php?id=<?php echo $usuario['id_usuario']; ?>"
                   onclick="return confirm('¿Seguro que quieres eliminar este usuario?');">
                    Eliminar
                </a>

            </td>
        </tr>

        <?php } ?>

    </tbody>

</table>

</body>
</html>