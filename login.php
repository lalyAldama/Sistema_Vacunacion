<?php
session_start();
require_once "config/conexion.php";


$mensaje="";

if($_SERVER["REQUEST_METHOD"] == "POST") {
    $nombre_usuario= $_POST["nombre_usuario"];
    $contrasena = $_POST["contrasena"];

    $sql = "SELECT 
            u.id_usuario,
            u.nombre_usuario,
            u.contrasena,
            u.nombre,
            u.id_rol,
            r.nombre_rol
            FROM USUARIO u 
            INNER JOIN ROL r
                ON  u.id_rol = r.id_rol
            WHERE u.nombre_usuario = ?";
    $stmt = $conexion->prepare($sql);
    $stmt->bind_param("s", $nombre_usuario);
    $stmt->execute();

    $resultado = $stmt->get_result();

    if($resultado->num_rows==1) {
        $usuario = $resultado->fetch_assoc();

        if(password_verify($contrasena, $usuario["contrasena"])) {
            $_SESSION["id_usuario"] = $usuario["id_usuario"];
            $_SESSION["nombre_usuario"] = $usuario["nombre_usuario"];
            $_SESSION["nombre"] = $usuario["nombre"];
            $_SESSION["id_rol"] = $usuario["id_rol"];
            $_SESSION["nombre_rol"] = $usuario["nombre_rol"];

            header("Location: menu.php");
            exit();
        } else {
            $mensaje = "Usuario o contraseña incorrectos. ";
        }
    } else{
        $mensaje= "Usuario o contraseña incorrectos. ";
    }
    $stmt->close();
}
?>

<!DOCTYPE html>
<html lang="es">
    <head>
        <meta charset="UTF-8">
        <title>Inicio de Sesion </title>
    </head>

    <body>
        <h1>Inicio de Sesión</h1>

        <?php if ($mensaje != "") {?>
            <p><?php echo $mensaje; ?></p>
        <?php } ?>

        <form method="POST">
            <label>Nombre de Usuario:</label>
            <br>
            <input type="text" name="nombre_usuario" required>
            <br><br>

            <label>Contraseña:</label>
            <input type="password" name="contrasena" required>
            <br><br>

            <button type="submit">Iniciar sesión </button>
        </form>
    </body>
</html>