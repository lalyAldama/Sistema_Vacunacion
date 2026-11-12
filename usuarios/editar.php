<?php
require_once __DIR__ . "/../config/seguridad_administrador.php";
require_once __DIR__ . "/../config/conexion.php";
require_once __DIR__ . "/../config/conexion_unidad.php";

$conexionCentral = $conexion;
$conexionUnidad = conexionAbrirUnidad();
$fuente = $_GET["fuente"] ?? "";
if (!in_array($fuente, ["central", "unidad"], true)) {
    http_response_code(400);
    exit("La fuente del usuario no es válida.");
}
$conexionUsuario = $fuente === "central" ? $conexionCentral : $conexionUnidad;
$conexionOtraFuente = $fuente === "central" ? $conexionUnidad : $conexionCentral;
$idUsuario = filter_var($_GET["id"] ?? null, FILTER_VALIDATE_INT);
if ($idUsuario === false || $idUsuario < 1) {
    http_response_code(400);
    exit("El usuario indicado no es válido.");
}
$rolesPermitidos = $fuente === "central"
    ? ["Administrador", "Supervisor"]
    : ["Personal de unidad"];
$errorFormulario = "";

$stmtUsuario = $conexionUsuario->prepare(
    "SELECT u.id_usuario, u.nombre_usuario, u.nombre, u.ap_pat, u.ap_mat,
            u.id_rol, u.id_unidad, r.nombre_rol
     FROM USUARIO u
     INNER JOIN ROL r ON r.id_rol = u.id_rol
     WHERE u.id_usuario = ?
     LIMIT 1"
);
if (!$stmtUsuario) {
    die("No se pudo consultar el usuario: " . $conexionUsuario->error);
}
$stmtUsuario->bind_param("i", $idUsuario);
if (!$stmtUsuario->execute()) {
    die("No se pudo consultar el usuario: " . $stmtUsuario->error);
}
$usuario = $stmtUsuario->get_result()->fetch_assoc();
$stmtUsuario->close();
if (!$usuario || !in_array($usuario["nombre_rol"], $rolesPermitidos, true)) {
    http_response_code(404);
    exit("El usuario solicitado no se encontró en esa base.");
}

if (isset($_POST["actualizar"])) {
    $nombreUsuario = is_string($_POST["nombre_usuario"] ?? null)
        ? trim($_POST["nombre_usuario"])
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
        $nombreRol = "";
    } elseif (!$stmtRol->bind_param("i", $idRol) || !$stmtRol->execute()) {
        die("No se pudo validar el rol: " . $stmtRol->error);
    } else {
        $nombreRol = (string)($stmtRol->get_result()->fetch_assoc()["nombre_rol"] ?? "");
    }
    $stmtRol->close();

    if (
        !in_array($nombreRol, $rolesPermitidos, true)
        || $nombreUsuario === ""
        || strlen($nombreUsuario) > 50
        || $nombre === ""
        || mb_strlen($nombre, "UTF-8") > 100
        || $apellidoPaterno === ""
        || mb_strlen($apellidoPaterno, "UTF-8") > 50
        || $apellidoMaterno === ""
        || mb_strlen($apellidoMaterno, "UTF-8") > 50
        || $idUnidad === false
    ) {
        $errorFormulario = "Revisa los datos del usuario y su asignación.";
    }

    if ($errorFormulario === "") {
        $stmtDuplicado = $conexionOtraFuente->prepare(
            "SELECT 1 FROM USUARIO WHERE nombre_usuario = ? LIMIT 1"
        );
        if (!$stmtDuplicado) {
            die("No se pudo validar el nombre de usuario: " . $conexionOtraFuente->error);
        }
        $stmtDuplicado->bind_param("s", $nombreUsuario);
        if (!$stmtDuplicado->execute()) {
            die("No se pudo validar el nombre de usuario: " . $stmtDuplicado->error);
        }
        $nombreDuplicado = $stmtDuplicado->get_result()->num_rows > 0;
        $stmtDuplicado->close();
        if ($nombreDuplicado) {
            $errorFormulario = "Ese nombre de usuario ya está registrado en la otra base.";
        }
    }

    if ($errorFormulario === "") {
        $stmtUnidad = $conexionUsuario->prepare(
            "SELECT 1 FROM UNIDAD WHERE id_unidad = ? LIMIT 1"
        );
        if (!$stmtUnidad) {
            die("No se pudo validar la unidad: " . $conexionUsuario->error);
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
        if ($fuente === "unidad") {
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
        $stmtActualizar = $conexionUsuario->prepare(
            "UPDATE USUARIO
             SET nombre_usuario = ?, nombre = ?, ap_pat = ?, ap_mat = ?,
                 id_rol = ?, id_unidad = ?
             WHERE id_usuario = ?"
        );
        if (!$stmtActualizar) {
            die("No se pudo preparar la actualización del usuario: " . $conexionUsuario->error);
        }
        $stmtActualizar->bind_param(
            "ssssiii",
            $nombreUsuario,
            $nombre,
            $apellidoPaterno,
            $apellidoMaterno,
            $idRolDestino,
            $idUnidad,
            $idUsuario
        );
        if (!$stmtActualizar->execute()) {
            $errorFormulario = "No se pudo actualizar el usuario: " . $stmtActualizar->error;
        } else {
            $stmtActualizar->close();
            header("Location: index.php");
            exit();
        }
        $stmtActualizar->close();
    }

    if ($errorFormulario !== "") {
        $usuario = array_merge($usuario, [
            "nombre_usuario" => $nombreUsuario,
            "nombre" => $nombre,
            "ap_pat" => $apellidoPaterno,
            "ap_mat" => $apellidoMaterno,
            "id_rol" => $idRol === false ? $usuario["id_rol"] : $idRol,
            "id_unidad" => $idUnidad === false ? $usuario["id_unidad"] : $idUnidad
        ]);
    }
}

$stmtRoles = $conexionCentral->prepare(
    "SELECT id_rol, nombre_rol FROM ROL WHERE nombre_rol IN (?, ?) ORDER BY id_rol"
);
if (!$stmtRoles) {
    die("No se pudieron cargar los roles: " . $conexionCentral->error);
}
if ($fuente === "central") {
    $rolUno = "Administrador";
    $rolDos = "Supervisor";
} else {
    $rolUno = "Personal de unidad";
    $rolDos = "Personal de unidad";
}
$stmtRoles->bind_param("ss", $rolUno, $rolDos);
if (!$stmtRoles->execute()) {
    die("No se pudieron cargar los roles: " . $stmtRoles->error);
}
$roles = $stmtRoles->get_result()->fetch_all(MYSQLI_ASSOC);
$stmtRoles->close();

$resultadoUnidades = $conexionUsuario->query(
    "SELECT id_unidad, nombre_unidad FROM UNIDAD ORDER BY nombre_unidad"
);
if (!$resultadoUnidades) {
    die("No se pudieron cargar las unidades: " . $conexionUsuario->error);
}
$unidades = $resultadoUnidades->fetch_all(MYSQLI_ASSOC);
$resultadoUnidades->free();
$conexionUnidad->close();

$escapar = static function ($valor): string {
    return htmlspecialchars((string)$valor, ENT_QUOTES, "UTF-8");
};
?>

<!DOCTYPE html>
<html lang="es">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Editar usuario</title>
        <link rel="stylesheet" href="../css/estilos.css">
        <link rel="stylesheet" href="../css/gestion_admin.css">
    </head>
    <body class="gestion-admin">
        <main class="gestion-contenedor">
        <header class="gestion-encabezado">
            <div class="gestion-encabezado-texto">
                <span class="gestion-etiqueta">ADMINISTRACIÓN · USUARIOS</span>
                <h1>Editar usuario</h1>
                <p>Actualiza los datos y permisos de esta cuenta.</p>
            </div>
            <a class="gestion-volver" href="index.php">Volver a usuarios</a>
        </header>
        <?php if ($errorFormulario !== ""): ?>
            <p class="gestion-mensaje-error" role="alert">
                <?php echo $escapar($errorFormulario); ?>
            </p>
        <?php endif; ?>

        <form class="gestion-formulario"
              action="editar.php?id=<?php echo (int)$usuario["id_usuario"]; ?>&fuente=<?php echo urlencode($fuente); ?>"
              method="POST">
            <label>Nombre de usuario</label>
            <input type="text" name="nombre_usuario" maxlength="50"
                   value="<?php echo $escapar($usuario["nombre_usuario"]); ?>" required>

            <label>Nombre</label>
            <input type="text" name="nombre" maxlength="100"
                   value="<?php echo $escapar($usuario["nombre"]); ?>" required>

            <label>Apellido Paterno</label>
            <input type="text" name="ap_pat" maxlength="50"
                   value="<?php echo $escapar($usuario["ap_pat"]); ?>" required>

            <label>Apellido Materno</label>
            <input type="text" name="ap_mat" maxlength="50"
                   value="<?php echo $escapar($usuario["ap_mat"]); ?>" required>

            <label>Rol</label>
            <select name="id_rol" required>
                <?php foreach ($roles as $rol): ?>
                    <option value="<?php echo (int)$rol["id_rol"]; ?>"
                        <?php echo (int)$rol["id_rol"] === (int)$usuario["id_rol"] ? "selected" : ""; ?>>
                        <?php echo $escapar($rol["nombre_rol"]); ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <label>Unidad</label>
            <select name="id_unidad" required>
                <?php foreach ($unidades as $unidad): ?>
                    <option value="<?php echo (int)$unidad["id_unidad"]; ?>"
                        <?php echo (int)$unidad["id_unidad"] === (int)$usuario["id_unidad"] ? "selected" : ""; ?>>
                        <?php echo $escapar($unidad["nombre_unidad"]); ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <button type="submit" name="actualizar">Guardar cambios</button>
            <a class="btn gestion-btn-secundario" href="index.php">Cancelar</a>
        </form>
        </main>
    </body>
</html>
