<?php
require_once "../config/conexion.php";

//Para actualizar usuario en la base de datos
if(isset($_POST['actualizar'])){
    $id_usuario = $_GET['id'];
    $nombre_usuario= $_POST['nombre_usuario'];
    $nombre = $_POST['nombre'];
    $apellido_paterno = $_POST['ap_pat'];
    $apellido_materno = $_POST['ap_mat'];
    $id_rol = $_POST['id_rol'];
    $id_unidad = $_POST['id_unidad'];

    $sql = "UPDATE USUARIO
            SET nombre_usuario= ?, nombre= ?, ap_pat= ?, ap_mat= ?, id_rol= ?, id_unidad= ?
            WHERE id_usuario =?";
    $stmt= $conexion->prepare($sql);
    $stmt->bind_param(
        "ssssiii",
        $nombre_usuario,
        $nombre,
        $apellido_paterno,
        $apellido_materno,
        $id_rol,
        $id_unidad,
        $id_usuario
    );

    if ($stmt->execute()){
        header("Location: index.php");
        exit;
    } else {
        echo "Error al actualizar usuario ". $stmt->error;
    }
    $stmt->close();
}

//obetener id de usuario que se desea editar

$id_usuario = $_GET['id'];

//Buscar usuario 
$sql = "SELECT  
            id_usuario,
            nombre_usuario,
            nombre,
            ap_pat,
            ap_mat,
            id_rol,
            id_unidad
        FROM usuario 
        WHERE id_usuario = ?";
$stmt = $conexion->prepare($sql);
$stmt->bind_param("i", $id_usuario);
$stmt->execute();
$resultado= $stmt->get_result();
$usuario= $resultado->fetch_assoc();

// Obtener roles
$sql_roles = "SELECT id_rol, nombre_rol FROM ROL";
$roles = $conexion->query($sql_roles);

// Obtener unidades
$sql_unidades = "SELECT id_unidad, nombre_unidad FROM UNIDAD";
$unidades = $conexion->query($sql_unidades);
?>

<!DOCTYPE html>
<html lang="es">
    <head>
        <meta charset="UTF-8">
        <title>Editar usuario</title>
    </head>
    <body>
        <h1>Editar usuario</h1>

        <form action="editar.php?id=<?php echo $usuario['id_usuario']; ?>" method="POST">
            <label>Nombre de usuario</label>
            <input type="text" name="nombre_usuario" value="<?php echo $usuario['nombre_usuario']; ?>" required>
            <br><br>

            <label>Nombre</label>
            <input type="text" name="nombre" value="<?php echo $usuario['nombre']; ?>"required>
            <br><br>

            <label>Apellido Paterno</label>
            <input type="text" name="ap_pat" value="<?php echo $usuario['ap_pat'];?>" required>
            <br><br>

            <label>Apellido Materno</label>
            <input type="text" name="ap_mat" value="<?php echo $usuario['ap_mat'];?>" required>
            <br><br>

            <label>Rol</label>
            <select name="id_rol" required>
                <?php while ($rol = $roles->fetch_assoc()){ ?>
                    <option value="<?php echo $rol['id_rol']; ?>"
                        <?php if ($rol['id_rol'] == $usuario['id_rol']) echo "selected"; ?>>
                        <?php echo $rol['nombre_rol']; ?>
                    
                    </option>
                <?php } ?>
            </select>
            <br><br>

            <label>Unidad</label>
            <select name="id_unidad" required>
                <?php while ($unidad = $unidades->fetch_assoc()){ ?>
                    <option value="<?php echo $unidad['id_unidad']; ?>"
                        <?php if ($unidad['id_unidad'] == $usuario['id_unidad']) echo "selected"; ?>>
                        <?php echo $unidad['nombre_unidad']; ?>
                    </option>
                <?php } ?>
            </select>
            <br><br>

            <button type="submit" name="actualizar">
                Guardar cambios
            </button>

            <a href="index.php">
                <button type="button">Cancelar</button>
            </a>
        </form>
    </body>
</html>
