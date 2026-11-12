<?php
require_once __DIR__ . "/../config/seguridad.php";

if (!isset($_SESSION["id_usuario"])) {
    header("Location: ../login.php");
    exit();
}

if (($_SESSION["nombre_rol"] ?? "") !== "Personal de unidad") {
    http_response_code(403);
    exit("Esta función está disponible únicamente para el personal de unidad.");
}

require_once __DIR__ . "/../config/conexion.php";
require_once __DIR__ . "/../config/unidad_contexto.php";

$conexionUnidad = $conexion;
$contextoUnidad = unidadObtenerContexto($conexionUnidad);
$idUnidad = (int)$contextoUnidad["id_unidad"];

require_once __DIR__ . "/../config/conexion_bd_central.php";
$conexionCentral = $conexion;
$errorFormulario = "";

if (empty($_SESSION["token_alta_enfermero"])) {
    $_SESSION["token_alta_enfermero"] = bin2hex(random_bytes(32));
}
$tokenFormulario = $_SESSION["token_alta_enfermero"];

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $tokenEnviado = is_string($_POST["token"] ?? null) ? $_POST["token"] : "";
    if (!hash_equals($tokenFormulario, $tokenEnviado)) {
        http_response_code(400);
        exit("La solicitud venció o no es válida. Recarga la página e inténtalo de nuevo.");
    }

    $nombreUsuario = is_string($_POST["nombre_usuario"] ?? null)
        ? trim($_POST["nombre_usuario"])
        : "";
    $contrasena = is_string($_POST["contrasena"] ?? null)
        ? $_POST["contrasena"]
        : "";
    $nombre = is_string($_POST["nombre"] ?? null) ? trim($_POST["nombre"]) : "";
    $apellidoPaterno = is_string($_POST["ap_pat"] ?? null) ? trim($_POST["ap_pat"]) : "";
    $apellidoMaterno = is_string($_POST["ap_mat"] ?? null) ? trim($_POST["ap_mat"]) : "";

    if (
        $nombreUsuario === ""
        || strlen($nombreUsuario) > 50
        || strlen($contrasena) < 8
        || strlen($contrasena) > 4096
        || $nombre === ""
        || mb_strlen($nombre, "UTF-8") > 100
        || $apellidoPaterno === ""
        || mb_strlen($apellidoPaterno, "UTF-8") > 50
        || $apellidoMaterno === ""
        || mb_strlen($apellidoMaterno, "UTF-8") > 50
    ) {
        $errorFormulario = "Revisa los datos. La contraseña debe tener al menos 8 caracteres.";
    }

    if ($errorFormulario === "") {
        foreach ([$conexionUnidad, $conexionCentral] as $conexionRevision) {
            $stmtDuplicado = $conexionRevision->prepare(
                "SELECT 1 FROM USUARIO WHERE nombre_usuario = ? LIMIT 1"
            );
            if (!$stmtDuplicado) {
                die("No se pudo validar el nombre de usuario: " . $conexionRevision->error);
            }
            $stmtDuplicado->bind_param("s", $nombreUsuario);
            if (!$stmtDuplicado->execute()) {
                die("No se pudo validar el nombre de usuario: " . $stmtDuplicado->error);
            }
            $duplicado = $stmtDuplicado->get_result()->num_rows > 0;
            $stmtDuplicado->close();
            if ($duplicado) {
                $errorFormulario = "Ese nombre de usuario ya está registrado.";
                break;
            }
        }
    }

    if ($errorFormulario === "") {
        $nombreRol = "Personal de unidad";
        $stmtRol = $conexionUnidad->prepare(
            "SELECT id_rol FROM ROL WHERE nombre_rol = ? LIMIT 1"
        );
        if (!$stmtRol) {
            die("No se pudo consultar el rol del enfermero: " . $conexionUnidad->error);
        }
        $stmtRol->bind_param("s", $nombreRol);
        if (!$stmtRol->execute()) {
            die("No se pudo consultar el rol del enfermero: " . $stmtRol->error);
        }
        $filaRol = $stmtRol->get_result()->fetch_assoc();
        $stmtRol->close();

        if (!$filaRol) {
            $errorFormulario = "No se encontró el rol Personal de unidad en la base de datos.";
        } else {
            $idRol = (int)$filaRol["id_rol"];
            $hashContrasena = password_hash($contrasena, PASSWORD_DEFAULT);
            $stmtGuardar = $conexionUnidad->prepare(
                "INSERT INTO USUARIO
                    (nombre_usuario, contrasena, nombre, ap_pat, ap_mat, id_rol, id_unidad)
                 VALUES (?, ?, ?, ?, ?, ?, ?)"
            );
            if (!$stmtGuardar) {
                die("No se pudo preparar el alta del enfermero: " . $conexionUnidad->error);
            }
            $stmtGuardar->bind_param(
                "sssssii",
                $nombreUsuario,
                $hashContrasena,
                $nombre,
                $apellidoPaterno,
                $apellidoMaterno,
                $idRol,
                $idUnidad
            );
            if (!$stmtGuardar->execute()) {
                $errorFormulario = "No se pudo guardar la cuenta: " . $stmtGuardar->error;
            } else {
                $stmtGuardar->close();
                unset($_SESSION["token_alta_enfermero"]);
                header("Location: agregar_enfermero.php?creado=1");
                exit();
            }
            $stmtGuardar->close();
        }
    }
}

function escaparAltaEnfermero($valor)
{
    return htmlspecialchars((string)$valor, ENT_QUOTES, "UTF-8");
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Agregar enfermero</title>
    <link rel="stylesheet" href="../css/estilos.css">
    <link rel="stylesheet" href="../css/gestion_admin.css">
</head>
<body class="gestion-admin">
    <main class="gestion-contenedor">
        <header class="gestion-encabezado">
            <div class="gestion-encabezado-texto">
                <span class="gestion-etiqueta">PERSONAL DE UNIDAD</span>
                <h1>Agregar enfermero</h1>
                <p>La cuenta se asignará automáticamente a <?php echo escaparAltaEnfermero($contextoUnidad["nombre_unidad"]); ?>.</p>
            </div>
            <a class="gestion-volver" href="../menu.php">Volver al menú</a>
        </header>

        <?php if (isset($_GET["creado"])): ?>
            <p class="alerta alerta-exito" role="status">La cuenta del enfermero se creó correctamente.</p>
        <?php endif; ?>

        <?php if ($errorFormulario !== ""): ?>
            <p class="gestion-mensaje-error" role="alert"><?php echo escaparAltaEnfermero($errorFormulario); ?></p>
        <?php endif; ?>

        <form class="gestion-formulario" action="agregar_enfermero.php" method="post">
            <input type="hidden" name="token" value="<?php echo escaparAltaEnfermero($tokenFormulario); ?>">

            <label for="nombre_usuario">Nombre de usuario</label>
            <input id="nombre_usuario" type="text" name="nombre_usuario" maxlength="50" autocomplete="username" required>

            <label for="contrasena">Contraseña inicial</label>
            <input id="contrasena" type="password" name="contrasena" minlength="8" maxlength="4096" autocomplete="new-password" required>

            <label for="nombre">Nombre</label>
            <input id="nombre" type="text" name="nombre" maxlength="100" required>

            <label for="ap_pat">Apellido paterno</label>
            <input id="ap_pat" type="text" name="ap_pat" maxlength="50" required>

            <label for="ap_mat">Apellido materno</label>
            <input id="ap_mat" type="text" name="ap_mat" maxlength="50" required>

            <button type="submit">Guardar enfermero</button>
            <a class="btn gestion-btn-secundario" href="../menu.php">Cancelar</a>
        </form>
    </main>
</body>
</html>
