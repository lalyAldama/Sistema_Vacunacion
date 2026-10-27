<?php
require_once __DIR__ . "/../config/seguridad.php";

if (!isset($_SESSION["id_usuario"])) {
    header("Location: ../login.php");
    exit();
}

require_once "../config/conexion.php";

$stmtUnidad = $conexion->prepare("SELECT id_unidad FROM USUARIO WHERE id_usuario = ?");
if (!$stmtUnidad) {
    die("Error al preparar la consulta de unidad: " . htmlspecialchars($conexion->error, ENT_QUOTES, "UTF-8"));
}
$stmtUnidad->bind_param("i", $_SESSION["id_usuario"]);
$stmtUnidad->execute();
$unidad = $stmtUnidad->get_result()->fetch_assoc();
$stmtUnidad->close();

if (empty($unidad["id_unidad"])) {
    die("El usuario no tiene una unidad asignada.");
}
$idUnidad = (int)$unidad["id_unidad"];

$resultadoJeringas = $conexion->query("SELECT id_jeringa, tipo FROM JERINGA ORDER BY tipo");
if (!$resultadoJeringas) {
    die("Error al consultar el catálogo de jeringas: " . htmlspecialchars($conexion->error, ENT_QUOTES, "UTF-8"));
}
$jeringas = $resultadoJeringas->fetch_all(MYSQLI_ASSOC);

$errores = [];
$idJeringa = 0;
$cantidad = "";
$necesidad = "0";
$fecha = (new DateTimeImmutable("today", new DateTimeZone("America/Mexico_City")))->format("Y-m-d");
$tipoMovimiento = "Uso";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $idJeringa = filter_var($_POST["id_jeringa"] ?? null, FILTER_VALIDATE_INT) ?: 0;
    $cantidad = trim((string)($_POST["cantidad_jeringas"] ?? ""));
    $necesidad = trim((string)($_POST["necesidad"] ?? ""));
    $fecha = trim((string)($_POST["fecha_movimiento"] ?? ""));
    $tipoMovimiento = (string)($_POST["tipo_movimiento"] ?? "");
    $cantidadValidada = filter_var($cantidad, FILTER_VALIDATE_INT);
    $necesidadValidada = filter_var($necesidad, FILTER_VALIDATE_INT);
    $fechaValidada = DateTimeImmutable::createFromFormat("!Y-m-d", $fecha);

    if ($idJeringa < 1) $errores[] = "Selecciona un tipo de jeringa.";
    if (!in_array($tipoMovimiento, ["Uso", "Desecho"], true)) $errores[] = "Selecciona un tipo de salida válido.";
    if ($cantidadValidada === false || $cantidadValidada < 1) $errores[] = "La cantidad debe ser un número entero mayor a cero.";
    if ($necesidadValidada === false || $necesidadValidada < 0) $errores[] = "La necesidad debe ser un número entero igual o mayor a cero.";
    if (!$fechaValidada || $fechaValidada->format("Y-m-d") !== $fecha) $errores[] = "Indica una fecha válida.";

    if (!$errores) {
        $stmt = $conexion->prepare("INSERT INTO MOVIMIENTO_JERINGA
            (id_jeringa, id_unidad, tipo_movimiento, fecha_movimiento, cantidad_jeringas, necesidad)
            VALUES (?, ?, ?, ?, ?, ?)");
        if (!$stmt) {
            die("Error al preparar el registro de salida: " . htmlspecialchars($conexion->error, ENT_QUOTES, "UTF-8"));
        }
        $stmt->bind_param("iissii", $idJeringa, $idUnidad, $tipoMovimiento, $fecha, $cantidadValidada, $necesidadValidada);
        if (!$stmt->execute()) {
            die("Error al registrar la salida de jeringas: " . htmlspecialchars($stmt->error, ENT_QUOTES, "UTF-8"));
        }
        $stmt->close();
        header("Location: index.php?jeringas=registrado#jeringas");
        exit();
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Nueva salida de jeringas</title>
    <link rel="stylesheet" href="../css/estilos.css">
</head>
<body>
    <h1>NUEVA SALIDA DE JERINGAS</h1>
    <?php if ($errores): ?>
        <div role="alert">
            <?php foreach ($errores as $error): ?>
                <p><?php echo htmlspecialchars($error, ENT_QUOTES, "UTF-8"); ?></p>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
    <form method="post">
        <label for="id_jeringa">TIPO DE JERINGA</label>
        <select id="id_jeringa" name="id_jeringa" required>
            <option value="">SELECCIONA UNA JERINGA</option>
            <?php foreach ($jeringas as $jeringa): ?>
                <option value="<?php echo (int)$jeringa["id_jeringa"]; ?>" <?php echo $idJeringa === (int)$jeringa["id_jeringa"] ? "selected" : ""; ?>>
                    <?php echo htmlspecialchars($jeringa["tipo"], ENT_QUOTES, "UTF-8"); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <br><br>
        <label for="tipo_movimiento">TIPO DE SALIDA</label>
        <select id="tipo_movimiento" name="tipo_movimiento" required>
            <option value="Uso" <?php echo $tipoMovimiento === "Uso" ? "selected" : ""; ?>>USO</option>
            <option value="Desecho" <?php echo $tipoMovimiento === "Desecho" ? "selected" : ""; ?>>DESECHO</option>
        </select>
        <br><br>
        <label for="cantidad_jeringas">CANTIDAD DE JERINGAS</label>
        <input id="cantidad_jeringas" type="number" name="cantidad_jeringas" min="1" step="1" value="<?php echo htmlspecialchars($cantidad, ENT_QUOTES, "UTF-8"); ?>" required>
        <br><br>
        <label for="fecha_movimiento">FECHA DE SALIDA</label>
        <input id="fecha_movimiento" type="date" name="fecha_movimiento" value="<?php echo htmlspecialchars($fecha, ENT_QUOTES, "UTF-8"); ?>" required>
        <br><br>
        <label for="necesidad">NECESIDAD</label>
        <input id="necesidad" type="number" name="necesidad" min="0" step="1" value="<?php echo htmlspecialchars($necesidad, ENT_QUOTES, "UTF-8"); ?>" required>
        <br><br>
        <button type="submit">REGISTRAR SALIDA</button>
        <a href="index.php#jeringas">CANCELAR</a>
    </form>
</body>
</html>