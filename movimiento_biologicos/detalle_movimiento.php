<?php
require_once __DIR__ . "/../config/seguridad.php";

if (!isset($_SESSION["id_usuario"])) {
    header("Location: ../login.php");
    exit();
}

require_once "../config/conexion.php";

function h($valor) {
    return htmlspecialchars((string)$valor, ENT_QUOTES, "UTF-8");
}

$tipo = $_GET["tipo"] ?? "";
$idLote = filter_input(INPUT_GET, "lote", FILTER_VALIDATE_INT);
$mes = $_GET["mes"] ?? "";

if (!in_array($tipo, ["Desecho", "Donación"], true) || !$idLote) {
    http_response_code(400);
    exit("Parámetros no válidos.");
}

if (
    !is_string($mes) ||
    !preg_match('/^(\d{4})-(\d{2})$/', $mes, $partes) ||
    !checkdate((int)$partes[2], 1, (int)$partes[1])
) {
    http_response_code(400);
    exit("Mes no válido.");
}

$inicio = $mes . "-01";
$fin = date("Y-m-d", strtotime($inicio . " +1 month"));

$stmt = $conexion->prepare(
    "SELECT id_unidad FROM USUARIO WHERE id_usuario = ?"
);
$stmt->bind_param("i", $_SESSION["id_usuario"]);
$stmt->execute();
$usuario = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (empty($usuario["id_unidad"])) {
    exit("El usuario no tiene una unidad asignada.");
}

$idUnidad = (int)$usuario["id_unidad"];

$sql = "SELECT mb.fecha_movimiento, mb.cantidad_frascos,
               mb.cantidad_dosis, mb.observaciones,
               l.numero_lote, l.fecha_caducidad,
               b.nombre_biologico, p.nombre_presentacion,
               u.nombre_unidad AS unidad_receptora
        FROM MOVIMIENTO_BIOLOGICO mb
        INNER JOIN LOTE l ON l.id_lote = mb.id_lote
        INNER JOIN PRESENTACION p ON p.id_presentacion = l.id_presentacion
        INNER JOIN BIOLOGICO b ON b.id_biologico = p.id_biologico
        LEFT JOIN UNIDAD u ON u.id_unidad = mb.id_unidad_receptora
        WHERE mb.id_unidad = ?
          AND mb.id_lote = ?
          AND mb.tipo_movimiento = ?
          AND mb.fecha_movimiento >= ?
          AND mb.fecha_movimiento < ?
        ORDER BY mb.fecha_movimiento";

$stmt = $conexion->prepare($sql);
$stmt->bind_param("iisss", $idUnidad, $idLote, $tipo, $inicio, $fin);
$stmt->execute();
$movimientos = $stmt->get_result();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Detalles del movimiento</title>
    <link rel="stylesheet" href="../css/estilos.css">
</head>
<body>
    <h1>DETALLES DE <?php echo h(mb_strtoupper($tipo, "UTF-8")); ?></h1>
    <p><strong>Periodo:</strong> <?php echo h($mes); ?></p>

    <?php if ($movimientos->num_rows === 0): ?>
        <p>No se encontraron movimientos para este lote y periodo.</p>
    <?php else: ?>
        <?php while ($mov = $movimientos->fetch_assoc()): ?>
            <section>
                <p><strong>Biológico:</strong> <?php echo h($mov["nombre_biologico"]); ?></p>
                <p><strong>Presentación:</strong> <?php echo h($mov["nombre_presentacion"]); ?></p>
                <p><strong>Lote:</strong> <?php echo h($mov["numero_lote"]); ?></p>
                <p><strong>Caducidad:</strong> <?php echo h($mov["fecha_caducidad"]); ?></p>
                <p><strong>Fecha:</strong> <?php echo h($mov["fecha_movimiento"]); ?></p>

                <?php if ($tipo === "Desecho"): ?>
                    <p><strong>Cantidad desechada:</strong>
                        <?php echo h($mov["cantidad_dosis"]); ?>
                    </p>
                    <p><strong>Observaciones::</strong>
                        <?php echo h($mov["observaciones"] ?: "Sin observaciones"); ?>
                    </p>
                <?php else: ?>
                    <p><strong>Dosis donada:</strong>
                        <?php echo h($mov["cantidad_dosis"]); ?>
                    </p>
                    <p><strong>Unidad receptora:</strong>
                        <?php echo h($mov["unidad_receptora"] ?? ""); ?>
                    </p>
                    <p><strong>Observaciones:</strong>
                        <?php echo h($mov["observaciones"] ?: "Sin observaciones"); ?>
                    </p>
                <?php endif; ?>
                <hr>
            </section>
        <?php endwhile; ?>
    <?php endif; ?>

    <a class="volver-informe" href="index.php?mes=<?php echo urlencode($mes); ?>">
        ← Volver al informe
    </a>
</body>
</html>
<?php
$stmt->close();
?>

<style>
    .volver-informe {
        display: inline-flex;
        align-items: center;
        gap: 9px;
        margin: 20px 0;
        padding: 11px 20px;
        color: #fff;
        background: linear-gradient(135deg, #2878a8, #185477);
        border: 0;
        border-radius: 8px;
        box-shadow: 0 4px 10px rgba(24, 84, 119, 0.22);
        font-size: 15px;
        font-weight: 700;
        text-decoration: none;
        transition: transform 0.2s ease, box-shadow 0.2s ease;
    }

    .volver-informe:hover {
        color: #fff;
        transform: translateY(-2px);
        box-shadow: 0 7px 15px rgba(24, 84, 119, 0.3);
    }

    .volver-informe:focus-visible {
        outline: 3px solid #8fc7f0;
        outline-offset: 3px;
    }
</style>