
<?php
session_start();
require_once "config/conexion.php";

$mensaje = "";

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $nombre_usuario = $_POST["nombre_usuario"];
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
                ON u.id_rol = r.id_rol
            WHERE u.nombre_usuario = ?";

    $stmt = $conexion->prepare($sql);
    $stmt->bind_param("s", $nombre_usuario);
    $stmt->execute();

    $resultado = $stmt->get_result();

    if ($resultado->num_rows == 1) {
        $usuario = $resultado->fetch_assoc();

        if (password_verify($contrasena, $usuario["contrasena"])) {
            $_SESSION["id_usuario"] = $usuario["id_usuario"];
            $_SESSION["nombre_usuario"] = $usuario["nombre_usuario"];
            $_SESSION["nombre"] = $usuario["nombre"];
            $_SESSION["id_rol"] = $usuario["id_rol"];
            $_SESSION["nombre_rol"] = $usuario["nombre_rol"];

            header("Location: menu.php");
            exit();
        } else {
            $mensaje = "Usuario o contraseña incorrectos.";
        }
    } else {
        $mensaje = "Usuario o contraseña incorrectos.";
    }

    $stmt->close();
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Iniciar sesión | Sistema de Vacunación</title>

    <link rel="stylesheet" href="css/login.css">
</head>

<body class="login-body">

    <main class="login-contenedor">

        <header class="login-encabezado">
            <div class="login-logo">
                <span aria-hidden="true">✚</span>
            </div>

            <h1>Vacunación</h1>
            <p>DISTRITO III</p>

            <div class="login-linea"></div>
        </header>

        <section class="login-panel">

            <div class="login-introduccion">

                <h2>¡Bienvenido!</h2>

                <p>
                    Ingresa tus credenciales para continuar.
                </p>
            </div>

            <?php if ($mensaje != "") { ?>
                <div class="login-error" role="alert">
                    <span aria-hidden="true">!</span>
                    <p>
                        <?php
                        echo htmlspecialchars(
                            $mensaje,
                            ENT_QUOTES,
                            "UTF-8"
                        );
                        ?>
                    </p>
                </div>
            <?php } ?>

            <form method="POST" action="login.php" class="login-formulario">

                <div class="login-campo">
                    <label for="nombre_usuario">
                        Nombre de usuario
                    </label>

                    <input
                        type="text"
                        id="nombre_usuario"
                        name="nombre_usuario"
                        placeholder="Escribe tu usuario"
                        autocomplete="username"
                        required
                    >
                </div>

                <div class="login-campo">
                    <label for="contrasena">
                        Contraseña
                    </label>

                    <input
                        type="password"
                        id="contrasena"
                        name="contrasena"
                        placeholder="Escribe tu contraseña"
                        autocomplete="current-password"
                        required
                    >
                </div>

                <button type="submit" class="login-boton">
                    Iniciar sesión
                </button>

            </form>

            <div class="login-separador">
                <span></span>
                <span class="login-separador-icono">✚</span>
                <span></span>
            </div>

        </section>

        <footer class="login-footer">
            Sistema de Vacunación
            <span>·</span>
            Jurisdicción Sanitaria
        </footer>

    </main>

</body>
</html>
