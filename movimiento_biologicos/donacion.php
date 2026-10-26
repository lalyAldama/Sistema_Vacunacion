<?php
session_start();

if (!isset($_SESSION["id_usuario"])) {
    header("Location: ../login.php");
    exit();
}

require_once "../config/conexion.php";

function h($valor) {
    return htmlspecialchars((string)$valor, ENT_QUOTES, "UTF-8");
}

$stmt = $conexion->prepare(
    "SELECT id_unidad FROM USUARIO WHERE id_usuario = ?"
);
$stmt->bind_param("i", $_SESSION["id_usuario"]);
$stmt->execute();
$usuario = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (empty($usuario["id_unidad"])) {
    die("El usuario no tiene una unidad asignada.");
}

$idUnidad = (int)$usuario["id_unidad"];
$error = "";
$fechaHoy = date("Y-m-d");

function obtenerExistencia($conexion, $idLote, $idUnidad) {
    $sql = "SELECT p.dosis_por_frasco,
                   COALESCE((
                       SELECT SUM(
                           CASE
                               WHEN mb.tipo_movimiento = 'Entrada'
                                    AND mb.id_unidad = ? THEN mb.cantidad_frascos
                               WHEN mb.tipo_movimiento = 'Donación'
                                    AND mb.id_unidad_receptora = ? THEN mb.cantidad_frascos
                               WHEN mb.tipo_movimiento = 'Donación'
                                    AND mb.id_unidad = ? THEN -mb.cantidad_frascos
                               WHEN mb.tipo_movimiento = 'Desecho'
                                    AND mb.id_unidad = ?
                                   THEN -mb.cantidad_dosis / p.dosis_por_frasco
                               ELSE 0
                           END
                       )
                       FROM MOVIMIENTO_BIOLOGICO mb
                       WHERE mb.id_lote = l.id_lote
                   ), 0)
                   - COALESCE((
                       SELECT COUNT(*)
                       FROM APLICACION_VACUNA av
                       WHERE av.id_lote = l.id_lote
                         AND av.id_unidad = ?
                   ), 0) / p.dosis_por_frasco AS disponible
            FROM LOTE l
            INNER JOIN PRESENTACION p ON p.id_presentacion = l.id_presentacion
            WHERE l.id_lote = ?";

    $stmt = $conexion->prepare($sql);
    $stmt->bind_param(
        "iiiiii",
        $idUnidad,
        $idUnidad,
        $idUnidad,
        $idUnidad,
        $idUnidad,
        $idLote
    );
    $stmt->execute();
    $fila = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $fila;
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $idLote = filter_input(INPUT_POST, "id_lote", FILTER_VALIDATE_INT);
    $idReceptora = filter_input(INPUT_POST, "id_unidad_receptora", FILTER_VALIDATE_INT);
    $frascos = filter_input(INPUT_POST, "cantidad_frascos", FILTER_VALIDATE_INT);
    $fecha = $_POST["fecha_movimiento"] ?? "";
    $observaciones = trim($_POST["observaciones"] ?? "");

    if (!$idLote || !$idReceptora || !$frascos || $frascos < 1) {
        $error = "Revisa el lote, la unidad destinataria y la cantidad de frascos.";
    } elseif ($idReceptora === $idUnidad) {
        $error = "La unidad destinataria debe ser diferente a la unidad de origen.";
    } elseif (!DateTime::createFromFormat("Y-m-d", $fecha)) {
        $error = "La fecha no es válida.";
    } elseif (mb_strlen($observaciones, "UTF-8") > 500) {
        $error = "Las observaciones no deben superar 500 caracteres.";
    } else {
        try {
            $conexion->begin_transaction();

            // Bloquea el lote mientras se verifica y registra la donación.
            $stmtLote = $conexion->prepare(
                "SELECT id_lote FROM LOTE WHERE id_lote = ? FOR UPDATE"
            );
            $stmtLote->bind_param("i", $idLote);
            $stmtLote->execute();
            $loteExiste = $stmtLote->get_result()->fetch_assoc();
            $stmtLote->close();

            if (!$loteExiste) {
                throw new RuntimeException("El lote seleccionado no existe.");
            }

            $existencia = obtenerExistencia($conexion, $idLote, $idUnidad);

            if (!$existencia || (float)$existencia["disponible"] < $frascos) {
                throw new RuntimeException("No hay suficientes frascos disponibles para esa donación.");
            }

            $dosisPorFrasco = (int)$existencia["dosis_por_frasco"];
            $dosis = $frascos * $dosisPorFrasco;
            $tipo = "Donación";
            $necesidad = 0;

            $sql = "INSERT INTO MOVIMIENTO_BIOLOGICO (
                        id_unidad, id_lote, tipo_movimiento, cantidad_frascos,
                        cantidad_dosis, fecha_movimiento, necesidad,
                        id_unidad_receptora, observaciones
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";

            $stmt = $conexion->prepare($sql);
            $stmt->bind_param(
                "iisiisiis",
                $idUnidad,
                $idLote,
                $tipo,
                $frascos,
                $dosis,
                $fecha,
                $necesidad,
                $idReceptora,
                $observaciones
            );
            $stmt->execute();
            $stmt->close();

            $conexion->commit();

            header("Location: index.php");
            exit();
        } catch (Throwable $e) {
            $conexion->rollback();
            $error = $e->getMessage();
        }
    }
}

$unidades = $conexion->query(
    "SELECT id_unidad, nombre_unidad
     FROM UNIDAD
     WHERE id_unidad <> " . $idUnidad . "
     ORDER BY nombre_unidad"
);

$sqlLotes = "SELECT l.id_lote, l.numero_lote, l.fecha_caducidad,
                    b.nombre_biologico, p.nombre_presentacion,
                    p.dosis_por_frasco
             FROM LOTE l
             INNER JOIN PRESENTACION p ON p.id_presentacion = l.id_presentacion
             INNER JOIN BIOLOGICO b ON b.id_biologico = p.id_biologico
             ORDER BY b.nombre_biologico, l.fecha_caducidad";

$lotes = $conexion->query($sqlLotes);
if (!$lotes) {
    die("Error al consultar lotes: " . h($conexion->error));
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Registrar donación</title>
    <link rel="stylesheet" href="../css/estilos.css">
</head>
<body>
    <h1>REGISTRAR DONACIÓN DE BIOLÓGICO</h1>

    <?php if ($error !== ""): ?>
        <p role="alert"><?php echo h($error); ?></p>
    <?php endif; ?>

    <form method="POST">
        <label for="id_lote"><strong>LOTE:</strong></label><br>
        <select name="id_lote" id="id_lote" required>
            <option value="">Seleccione un lote</option>
            <?php while ($lote = $lotes->fetch_assoc()):
                $existencia = obtenerExistencia(
                    $conexion,
                    (int)$lote["id_lote"],
                    $idUnidad
                );
                if (!$existencia || (float)$existencia["disponible"] <= 0) {
                    continue;
                }
            ?>
                <option value="<?php echo (int)$lote["id_lote"]; ?>">
                    <?php echo h(
                        $lote["nombre_biologico"] . " - " .
                        $lote["nombre_presentacion"] . " - Lote: " .
                        $lote["numero_lote"] . " - Disponible: " .
                        number_format((float)$existencia["disponible"], 2)
                    ); ?>
                </option>
            <?php endwhile; ?>
        </select><br><br>

        <label for="id_unidad_receptora"><strong>UNIDAD DESTINATARIA:</strong></label><br>
        <select name="id_unidad_receptora" id="id_unidad_receptora" required>
            <option value="">Seleccione una unidad</option>
            <?php while ($unidad = $unidades->fetch_assoc()): ?>
                <option value="<?php echo (int)$unidad["id_unidad"]; ?>">
                    <?php echo h($unidad["nombre_unidad"]); ?>
                </option>
            <?php endwhile; ?>
        </select><br><br>

        <label for="cantidad_frascos"><strong>CANTIDAD DE FRASCOS:</strong></label><br>
        <input type="number" name="cantidad_frascos" id="cantidad_frascos"
               min="1" step="1" required><br><br>

        <label for="fecha_movimiento"><strong>FECHA:</strong></label><br>
        <input type="date" name="fecha_movimiento" id="fecha_movimiento"
               value="<?php echo h($fechaHoy); ?>" required><br><br>

        <label for="observaciones"><strong>OBSERVACIONES:</strong></label><br>
        <textarea name="observaciones" id="observaciones"
                  rows="5" cols="50" maxlength="500"></textarea><br><br>

        <button type="submit">GUARDAR DONACIÓN</button>
    </form>

    <br>
    <a href="index.php"><button type="button">REGRESAR</button></a>
</body>
</html>