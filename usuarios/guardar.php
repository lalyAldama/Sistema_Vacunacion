<?php
require_once __DIR__ . "/../config/seguridad_administrador.php";
require_once __DIR__ . "/../config/conexion.php";
require_once __DIR__ . "/../config/conexion_unidad.php";

$conexionCentral = $conexion;
$conexionUnidad = conexionAbrirUnidad();
$errorFormulario = "";

if (isset($_POST["guardar"])) {
    $nombreUsuario = is_string($_POST["nombre_usuario"] ?? null)
        ? trim($_POST["nombre_usuario"])
        : "";
    $contrasena = is_string($_POST["contrasena"] ?? null)
        ? $_POST["contrasena"]
        : "";
    $nombre = is_string($_POST["nombre"] ?? null) ? trim($_POST["nombre"]) : "";
    $apellidoPaterno = is_string($_POST["ap_pat"] ?? null) ? trim($_POST["ap_pat"]) : "";
    $apellidoMaterno = is_string($_POST["ap_mat"] ?? null) ? trim($_POST["ap_mat"]) : "";
    $idRol = filter_var($_POST["id_rol"] ?? null, FILTER_VALIDATE_INT);
    $idUnidad = filter_var($_POST["id_unidad"] ?? null, FILTER_VALIDATE_INT);

    $stmtRol = $conexionCentral->prepare(
        "SELECT nombre_rol FROM ROL WHERE id_rol = ? LIMIT 1"
    );
    if (!$stmtRol) {
        die("No se pudo validar el rol: " . $conexionCentral->error);
    }
    if ($idRol === false) {
        $errorFormulario = "Selecciona un rol válido.";
        $nombreRol = "";
    } elseif (!$stmtRol->bind_param("i", $idRol) || !$stmtRol->execute()) {
        die("No se pudo validar el rol: " . $stmtRol->error);
    } else {
        $nombreRol = (string)($stmtRol->get_result()->fetch_assoc()["nombre_rol"] ?? "");
    }
    $stmtRol->close();

    $conexionDestino = in_array($nombreRol, ["Administrador", "Supervisor"], true)
        ? $conexionCentral
        : ($nombreRol === "Personal de unidad" ? $conexionUnidad : null);

    if (
        $errorFormulario === ""
        && (
            $nombreUsuario === ""
            || strlen($nombreUsuario) > 50
            || $contrasena === ""
            || strlen($contrasena) > 4096
            || $nombre === ""
            || mb_strlen($nombre, "UTF-8") > 100
            || $apellidoPaterno === ""
            || mb_strlen($apellidoPaterno, "UTF-8") > 50
            || $apellidoMaterno === ""
            || mb_strlen($apellidoMaterno, "UTF-8") > 50
            || $idUnidad === false
            || !$conexionDestino
        )
    ) {
        $errorFormulario = "Revisa los datos del usuario y su asignación.";
    }

    if ($errorFormulario === "") {
        foreach ([$conexionCentral, $conexionUnidad] as $conexionRevision) {
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
            $existeUsuario = $stmtDuplicado->get_result()->num_rows > 0;
            $stmtDuplicado->close();
            if ($existeUsuario) {
                $errorFormulario = "Ese nombre de usuario ya está registrado.";
                break;
            }
        }
    }

    if ($errorFormulario === "") {
        $stmtUnidad = $conexionDestino->prepare(
            "SELECT 1 FROM UNIDAD WHERE id_unidad = ? LIMIT 1"
        );
        if (!$stmtUnidad) {
            die("No se pudo validar la unidad: " . $conexionDestino->error);
        }
        $stmtUnidad->bind_param("i", $idUnidad);
        if (!$stmtUnidad->execute()) {
            die("No se pudo validar la unidad: " . $stmtUnidad->error);
        }
        $unidadExiste = $stmtUnidad->get_result()->num_rows > 0;
        $stmtUnidad->close();
        if (!$unidadExiste) {
            $errorFormulario = "Selecciona una unidad válida.";
        }
    }

    if ($errorFormulario === "") {
        $idRolDestino = $idRol;
        if ($conexionDestino === $conexionUnidad) {
            $stmtRolDestino = $conexionUnidad->prepare(
                "SELECT id_rol FROM ROL WHERE nombre_rol = ? LIMIT 1"
            );
            if (!$stmtRolDestino) {
                die("No se pudo validar el rol en la base de unidad: " . $conexionUnidad->error);
            }
            $stmtRolDestino->bind_param("s", $nombreRol);
            if (!$stmtRolDestino->execute()) {
                die("No se pudo validar el rol en la base de unidad: " . $stmtRolDestino->error);
            }
            $rolDestino = $stmtRolDestino->get_result()->fetch_assoc();
            $stmtRolDestino->close();
            if (!$rolDestino) {
                $errorFormulario = "El rol no existe en la base de unidad.";
            } else {
                $idRolDestino = (int)$rolDestino["id_rol"];
            }
        }
    }

    if ($errorFormulario === "") {
        $contrasenaHash = password_hash($contrasena, PASSWORD_DEFAULT);
        $stmtGuardar = $conexionDestino->prepare(
            "INSERT INTO USUARIO
                (nombre_usuario, contrasena, nombre, ap_pat, ap_mat, id_rol, id_unidad)
             VALUES (?, ?, ?, ?, ?, ?, ?)"
        );
        if (!$stmtGuardar) {
            die("No se pudo preparar el alta del usuario: " . $conexionDestino->error);
        }
        $stmtGuardar->bind_param(
            "sssssii",
            $nombreUsuario,
            $contrasenaHash,
            $nombre,
            $apellidoPaterno,
            $apellidoMaterno,
            $idRolDestino,
            $idUnidad
        );
        if (!$stmtGuardar->execute()) {
            $errorFormulario = "No se pudo guardar el usuario: " . $stmtGuardar->error;
        } else {
            $stmtGuardar->close();
            header("Location: index.php");
            exit();
        }
        $stmtGuardar->close();
    }
}

$resultadoRoles = $conexionCentral->query(
    "SELECT id_rol, nombre_rol FROM ROL ORDER BY id_rol"
);
if (!$resultadoRoles) {
    die("No se pudieron cargar los roles: " . $conexionCentral->error);
}
$roles = $resultadoRoles->fetch_all(MYSQLI_ASSOC);
$resultadoRoles->free();
$resultadoUnidades = $conexionCentral->query(
    "SELECT id_unidad, nombre_unidad FROM UNIDAD ORDER BY nombre_unidad"
);
if (!$resultadoUnidades) {
    die("No se pudieron cargar las unidades: " . $conexionCentral->error);
}
$unidades = $resultadoUnidades->fetch_all(MYSQLI_ASSOC);
$resultadoUnidades->free();
$conexionUnidad->close();
?>

<!DOCTYPE html>
<html lang="es">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Nuevo Usuario</title>
        <link rel="stylesheet" href="../css/estilos.css">
        <link rel="stylesheet" href="../css/gestion_admin.css">
    </head>
    <body class="gestion-admin">
        <main class="gestion-contenedor">
        <header class="gestion-encabezado">
            <div class="gestion-encabezado-texto">
                <span class="gestion-etiqueta">ADMINISTRACIÓN · USUARIOS</span>
                <h1>Nuevo usuario</h1>
                <p>Crea una cuenta y asigna su rol y unidad.</p>
            </div>
            <a class="gestion-volver" href="index.php">Volver a usuarios</a>
        </header>
        <?php if ($errorFormulario !== ""): ?>
            <p class="gestion-mensaje-error" role="alert">
                <?php echo htmlspecialchars($errorFormulario, ENT_QUOTES, "UTF-8"); ?>
            </p>
        <?php endif; ?>
        <form class="gestion-formulario" action="guardar.php" method="POST">
            <label>Nombre de usuario</label>
            <input type="text" name="nombre_usuario" maxlength="50" required>

            <label>Contraseña</label>
            <input type="password" name="contrasena" required>

            <label>Nombre</label>
            <input type="text" name="nombre" maxlength="100" required>

            <label>Apellido Paterno</label>
            <input type="text" name="ap_pat" maxlength="50" required>

            <label>Apellido Materno</label>
            <input type="text" name="ap_mat" maxlength="50" required>

            <label>Rol</label>
            <select name="id_rol" required>
                <option value="">Seleccionar rol</option>
                <?php foreach ($roles as $rol): ?>
                    <option value="<?php echo (int)$rol["id_rol"]; ?>">
                        <?php echo htmlspecialchars($rol["nombre_rol"], ENT_QUOTES, "UTF-8"); ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <label>Unidad</label>
            <select name="id_unidad" required>
                <option value="">Seleccionar Unidad</option>
                <?php foreach ($unidades as $unidad): ?>
                    <option value="<?php echo (int)$unidad["id_unidad"]; ?>">
                        <?php echo htmlspecialchars($unidad["nombre_unidad"], ENT_QUOTES, "UTF-8"); ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <button type="submit" name="guardar">Guardar Usuario</button>
            <a class="btn gestion-btn-secundario" href="index.php">Cancelar</a>
        </form>
        </main>
    </body>
</html>
