<?php
require_once "../config/conexion.php";

//para obterner roles
$sql_roles= "SELECT id_rol, nombre_rol from rol";
$roles = $conexion->query($sql_roles);

//para obtener unidades
$sql_unidades= "SELECT id_unidad, nombre_unidad from unidad";
$unidades = $conexion->query($sql_unidades);

//para insertar usuario en bd despues de presionar boton guardar
if (isset($_POST['guardar'])) {
    $nombre_usuario = $_POST['nombre_usuario'];
    $contrasena = $_POST['contrasena'];
    $nombre = $_POST['nombre'];
    $apellido_paterno = $_POST['ap_pat'];
    $apellido_materno = $_POST['ap_mat'];
    $id_rol = $_POST['id_rol'];
    $id_unidad = $_POST['id_unidad'];

    $contrasena_hash = password_hash($contrasena, PASSWORD_DEFAULT);

    $sql= "INSERT INTO USUARIO (nombre_usuario, contrasena, nombre, ap_pat, ap_mat, id_rol, id_unidad)
    VALUES (?,?,?,?,?,?,?)";

    $stmt= $conexion->prepare($sql);
    $stmt->bind_param(
     "ssssssi",
    $nombre_usuario,
    $contrasena_hash,
    $nombre,
    $apellido_paterno,
    $apellido_materno,
    $id_rol,
    $id_unidad
    );

    if($stmt->execute()) {
        header("Location: index.php");
        exit;
    } else {
        echo "Error al guardar el usuario: " . $stmt->error;
    }
    $stmt->close();
}
?>

<!DOCTYPE html>
<html lang="es">
    <head>
        <meta charset="UTF-8">
        <title>Nuevo Usuario</title>
    </head>
    <body>
        <h1>Nuevo usuario</h1>
        <form action="guardar.php" method="POST">
            <label>Nombre de usuario</label>
            <input type="text" name="nombre_usuario" required>
            <br><br>

            <label>Contraseña</label>
            <input type="password" name="contrasena" required>
            <br><br>

            <label>Nombre</label>
            <input type="text" name="nombre" required>
            <br><br>

            <label>Apellido Paterno</label>
            <input type="text" name="ap_pat" required>
            <br><br>

            <label>Apellido Materno</label>
            <input type="text" name="ap_mat" required>
            <br><br>

            <label>Rol </label>
            <select name="id_rol" required>
                <option value="">Seleccionar rol</option>
                <?php while ($rol = $roles->fetch_assoc()) { ?>
                    <option value="<?php echo $rol['id_rol']; ?>">
                        <?php echo $rol['nombre_rol']; ?>
                </option>
            <?php } ?>
        </select>
            <br><br>

            <label>Unidad</label>
            <select name="id_unidad" required>
                <option value="">Seleccionar Unidad</option>
                <?php while ($unidad = $unidades->fetch_assoc()) { ?>
                    <option value="<?php echo $unidad['id_unidad']; ?>">
                        <?php echo $unidad['nombre_unidad']; ?>
                    </option>
                <?php } ?>
        </select>
            <br><br>

            <button type="submit" name="guardar">Guardar Usuario</button>

            <a href="index.php">
                <button type="button">Cancelar</button>
            </a>
        </form>
    </body>
</html>