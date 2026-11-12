<?php
require_once __DIR__ . "/../config/seguridad_administrador.php";
require_once __DIR__ . "/../config/conexion.php";

$nombreUnidad = "";
$errorFormulario = "";
$mensajeExito = $_SESSION["unidad_central_mensaje"] ?? "";
unset($_SESSION["unidad_central_mensaje"]);

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $nombreUnidad = is_string($_POST["nombre_unidad"] ?? null)
        ? trim($_POST["nombre_unidad"])
        : "";
    if ($nombreUnidad === "" || mb_strlen($nombreUnidad, "UTF-8") > 150) {
        $errorFormulario = "Escribe un nombre de unidad de hasta 150 caracteres.";
    } else {
        try {
            $bloqueo = $conexion->query("SELECT GET_LOCK('vacunacion_alta_unidad_central', 5) AS obtenido");
            if (!$bloqueo || (int)$bloqueo->fetch_assoc()["obtenido"] !== 1) {
                throw new RuntimeException("No se pudo obtener el bloqueo del catálogo de unidades.");
            }
            $bloqueo->free();
            try {
                $stmt = $conexion->prepare("SELECT id_unidad FROM UNIDAD WHERE nombre_unidad = ? LIMIT 1");
                if (!$stmt || !$stmt->bind_param("s", $nombreUnidad) || !$stmt->execute()) {
                    throw new RuntimeException("No se pudo comprobar el nombre de la unidad.");
                }
                $existe = $stmt->get_result()->fetch_assoc() !== null;
                $stmt->close();
                if ($existe) {
                    $errorFormulario = "Ya existe una unidad con ese nombre en la base central.";
                } else {
                    $stmt = $conexion->prepare("INSERT INTO UNIDAD (nombre_unidad) VALUES (?)");
                    if (!$stmt || !$stmt->bind_param("s", $nombreUnidad) || !$stmt->execute()) {
                        throw new RuntimeException("No se pudo guardar la unidad.");
                    }
                    $stmt->close();
                    $_SESSION["unidad_central_mensaje"] = "La unidad se registró en la base central.";
                }
            } finally {
                $liberacion = $conexion->query("SELECT RELEASE_LOCK('vacunacion_alta_unidad_central') AS liberado");
                if (!$liberacion || (int)$liberacion->fetch_assoc()["liberado"] !== 1) {
                    throw new RuntimeException("No se pudo liberar el bloqueo del catálogo de unidades.");
                }
                $liberacion->free();
            }
        } catch (mysqli_sql_exception | RuntimeException $error) {
            error_log("Error al registrar unidad central: " . $error->getMessage());
            http_response_code(500);
            exit("No se pudo registrar la unidad. Inténtalo de nuevo o contacta al administrador del sistema.");
        }
        if ($errorFormulario === "") {
            header("Location: index.php");
            exit();
        }
    }
}

try {
    $resultado = $conexion->query("SELECT id_unidad, nombre_unidad FROM UNIDAD ORDER BY nombre_unidad, id_unidad");
    if (!$resultado) {
        throw new RuntimeException("No se pudo consultar el catálogo de unidades.");
    }
    $unidades = $resultado->fetch_all(MYSQLI_ASSOC);
    $resultado->free();
} catch (mysqli_sql_exception | RuntimeException $error) {
    error_log("Error al consultar unidades centrales: " . $error->getMessage());
    http_response_code(500);
    exit("No se pudo cargar el catálogo de unidades.");
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Unidades centrales</title>
    <link rel="stylesheet" href="../css/estilos.css">
    <link rel="stylesheet" href="../css/gestion_admin.css">
</head>
<body class="gestion-admin">
<main class="gestion-contenedor">
    <header class="gestion-encabezado">
        <div class="gestion-encabezado-texto">
            <span class="gestion-etiqueta">ADMINISTRACIÓN · UNIDADES</span>
            <h1>Unidades centrales</h1>
            <p>Registra unidades para su consulta e importación en la base central.</p>
        </div>
        <a class="gestion-volver" href="../menu.php">Volver al menú</a>
    </header>
    <?php if ($mensajeExito !== ""): ?>
        <p role="status"><?php echo htmlspecialchars($mensajeExito, ENT_QUOTES, "UTF-8"); ?></p>
    <?php endif; ?>
    <?php if ($errorFormulario !== ""): ?>
        <p class="gestion-mensaje-error" role="alert"><?php echo htmlspecialchars($errorFormulario, ENT_QUOTES, "UTF-8"); ?></p>
    <?php endif; ?>
    <form class="gestion-formulario" method="POST" action="index.php">
        <label for="nombre_unidad">Nombre de la unidad</label>
        <input id="nombre_unidad" name="nombre_unidad" type="text" maxlength="150" required
               value="<?php echo htmlspecialchars($nombreUnidad, ENT_QUOTES, "UTF-8"); ?>">
    </form>
    <h2>Unidades registradas</h2>
    <?php if (!$unidades): ?>
        <p>No hay unidades registradas en la base central.</p>
    <?php else: ?>
        <div class="gestion-tabla-contenedor">
            <table class="gestion-tabla">
                <thead><tr><th>ID</th><th>Nombre de la unidad</th></tr></thead>
                <tbody>
                <?php foreach ($unidades as $unidad): ?>
                    <tr>
                        <td><?php echo (int)$unidad["id_unidad"]; ?></td>
                        <td><?php echo htmlspecialchars($unidad["nombre_unidad"], ENT_QUOTES, "UTF-8"); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</main>
</body>
</html>
