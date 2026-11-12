<?php
require_once __DIR__ . "/../config/seguridad.php";

if (!isset($_SESSION["id_usuario"])) {
    header("Location: ../login.php");
    exit();
}

require_once __DIR__ . "/../config/conexion.php";
require_once __DIR__ . "/../config/unidad_contexto.php";
unidadExigirPersonalDeUnidad();
$contextoUnidad = unidadObtenerContexto($conexion);
$idUnidad = $contextoUnidad["id_unidad"];
require_once __DIR__ . "/funciones_vph.php";
asegurarEsquemaVph($conexion);

function escaparVphConfidencial($valor)
{
    return htmlspecialchars((string)$valor, ENT_QUOTES, "UTF-8");
}

$idUsuario = (int)$_SESSION["id_usuario"];

$resultadoVph = $conexion->query(
    "SELECT id_biologico FROM BIOLOGICO WHERE nombre_biologico = 'VPH' LIMIT 1"
);
if (!$resultadoVph) {
    die("Error al consultar el biológico VPH: " . $conexion->error);
}
$filaVph = $resultadoVph->fetch_assoc();
$resultadoVph->free();
if (!$filaVph) {
    die("El biológico VPH no está registrado en el catálogo.");
}
$idBiologicoVph = (int)$filaVph["id_biologico"];
$categorias = categoriasVph();

$sqlDosis = "SELECT MIN(ebd.id_esquema_biologico_dosis) AS id_esquema_biologico_dosis,
                    d.nombre_dosis
             FROM ESQUEMA_BIOLOGICO eb
             INNER JOIN ESQUEMA_BIOLOGICO_DOSIS ebd
                 ON ebd.id_esquema_biologico = eb.id_esquema_biologico
             INNER JOIN DOSIS d ON d.id_dosis = ebd.id_dosis
             WHERE eb.id_biologico = ?
             GROUP BY d.id_dosis, d.nombre_dosis
             ORDER BY d.id_dosis";
$stmtDosis = $conexion->prepare($sqlDosis);
if (!$stmtDosis) {
    die("Error al consultar las dosis VPH: " . $conexion->error);
}
$stmtDosis->bind_param("i", $idBiologicoVph);
if (!$stmtDosis->execute()) {
    die("Error al consultar las dosis VPH: " . $stmtDosis->error);
}
$dosisDisponibles = $stmtDosis->get_result()->fetch_all(MYSQLI_ASSOC);
$stmtDosis->close();

$sqlLotes = "SELECT l.id_lote, l.numero_lote, l.fecha_caducidad, p.nombre_presentacion
             FROM LOTE l
             INNER JOIN PRESENTACION p ON p.id_presentacion = l.id_presentacion
             WHERE p.id_biologico = ? AND l.fecha_caducidad >= CURDATE()
             ORDER BY l.fecha_caducidad, l.numero_lote";
$stmtLotes = $conexion->prepare($sqlLotes);
if (!$stmtLotes) {
    die("Error al consultar lotes VPH: " . $conexion->error);
}
$stmtLotes->bind_param("i", $idBiologicoVph);
if (!$stmtLotes->execute()) {
    die("Error al consultar lotes VPH: " . $stmtLotes->error);
}
$lotesConsulta = $stmtLotes->get_result();
$lotesDisponibles = [];
while ($lote = $lotesConsulta->fetch_assoc()) {
    $existencia = obtenerExistenciaVph($conexion, (int)$lote["id_lote"], $idUnidad);
    if ($existencia && (float)$existencia["disponible"] > 0) {
        $lote["dosis_disponibles"] = (float)$existencia["disponible"]
            * (int)$existencia["dosis_por_frasco"];
        $lotesDisponibles[] = $lote;
    }
}
$stmtLotes->close();

$error = "";
$valores = [
    "categoria" => "",
    "id_esquema_biologico_dosis" => "",
    "id_lote" => "",
    "fecha_aplicacion" => date("Y-m-d"),
    "es_migrante" => ""
];
$guardado = ($_GET["guardado"] ?? "") === "1";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    unidadExigirPersonalDeUnidad();
    foreach ($valores as $campo => $predeterminado) {
        $valor = $_POST[$campo] ?? $predeterminado;
        $valores[$campo] = is_string($valor) ? $valor : $predeterminado;
    }

    $idEbd = filter_var($valores["id_esquema_biologico_dosis"], FILTER_VALIDATE_INT);
    $idLote = filter_var($valores["id_lote"], FILTER_VALIDATE_INT);
    $fechaValida = DateTimeImmutable::createFromFormat("!Y-m-d", $valores["fecha_aplicacion"]);
    $fechaAplicacion = $fechaValida
        && $fechaValida->format("Y-m-d") === $valores["fecha_aplicacion"]
        ? $fechaValida
        : null;

    if (!array_key_exists($valores["categoria"], $categorias)) {
        $error = "Selecciona el grupo de población correspondiente.";
    } elseif (!$idEbd || !$idLote) {
        $error = "Selecciona la dosis y un lote con existencias.";
    } elseif (!$fechaAplicacion || $fechaAplicacion > new DateTimeImmutable("today")) {
        $error = "La fecha de aplicación no es válida.";
    } elseif (!in_array($valores["es_migrante"], ["0", "1"], true)) {
        $error = "Indica si la persona es migrante.";
    } else {
        $stmtDosisValidar = $conexion->prepare(
            "SELECT d.nombre_dosis
             FROM ESQUEMA_BIOLOGICO_DOSIS ebd
             INNER JOIN ESQUEMA_BIOLOGICO eb
                 ON eb.id_esquema_biologico = ebd.id_esquema_biologico
             INNER JOIN DOSIS d ON d.id_dosis = ebd.id_dosis
             WHERE ebd.id_esquema_biologico_dosis = ? AND eb.id_biologico = ?"
        );
        if (!$stmtDosisValidar) {
            die("Error al validar la dosis VPH: " . $conexion->error);
        }
        $stmtDosisValidar->bind_param("ii", $idEbd, $idBiologicoVph);
        if (!$stmtDosisValidar->execute()) {
            die("Error al validar la dosis VPH: " . $stmtDosisValidar->error);
        }
        $filaDosis = $stmtDosisValidar->get_result()->fetch_assoc();
        $stmtDosisValidar->close();

        if (!$filaDosis) {
            $error = "La dosis seleccionada no corresponde al biológico VPH.";
        } elseif (
            obtenerRenglonVph($valores["categoria"], $filaDosis["nombre_dosis"]) === null
        ) {
            $error = "La dosis seleccionada no corresponde al grupo de población VPH.";
        } else {
            $conexion->begin_transaction();
            $stmtBloquearLote = $conexion->prepare(
                "SELECT l.id_lote
                 FROM LOTE l
                 INNER JOIN PRESENTACION p ON p.id_presentacion = l.id_presentacion
                 WHERE l.id_lote = ? AND p.id_biologico = ? AND l.fecha_caducidad >= CURDATE()
                 FOR UPDATE"
            );
            if (!$stmtBloquearLote) {
                $conexion->rollback();
                die("Error al validar el lote VPH: " . $conexion->error);
            }
            $stmtBloquearLote->bind_param("ii", $idLote, $idBiologicoVph);
            if (!$stmtBloquearLote->execute()) {
                $conexion->rollback();
                die("Error al validar el lote VPH: " . $stmtBloquearLote->error);
            }
            $loteValido = $stmtBloquearLote->get_result()->fetch_assoc();
            $stmtBloquearLote->close();

            $existencia = $loteValido
                ? obtenerExistenciaVph($conexion, $idLote, $idUnidad)
                : null;
            if (!$existencia || (float)$existencia["disponible"] <= 0) {
                $conexion->rollback();
                $error = "El lote no corresponde a VPH, está vencido o ya no tiene existencias.";
            } else {
                $stmtInsertar = $conexion->prepare(
                    "INSERT INTO APLICACION_VPH_ANONIMA
                         (id_esquema_biologico_dosis, id_lote, id_unidad, id_usuario,
                          fecha_aplicacion, clasificacion_vph, es_migrante)
                     VALUES (?, ?, ?, ?, ?, ?, ?)"
                );
                if (!$stmtInsertar) {
                    $conexion->rollback();
                    die("Error al preparar el registro confidencial VPH: " . $conexion->error);
                }
                $esMigrante = (int)$valores["es_migrante"];
                $stmtInsertar->bind_param(
                    "iiiissi",
                    $idEbd,
                    $idLote,
                    $idUnidad,
                    $idUsuario,
                    $valores["fecha_aplicacion"],
                    $valores["categoria"],
                    $esMigrante
                );
                if (!$stmtInsertar->execute()) {
                    $conexion->rollback();
                    die("Error al guardar la aplicación confidencial VPH: " . $stmtInsertar->error);
                }
                $stmtInsertar->close();
                if (!$conexion->commit()) {
                    die("No fue posible confirmar el registro confidencial VPH: " . $conexion->error);
                }
                header("Location: registrar_vph_confidencial.php?guardado=1");
                exit();
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Aplicación confidencial de VPH</title>
    <link rel="stylesheet" href="../css/estilos.css">
    <style>
        main { max-width: 760px; margin: 24px auto; padding: 24px; background: #fff; }
        label { display: block; margin-top: 16px; font-weight: 700; }
        select, input, button { max-width: 100%; padding: 9px; }
        .aviso { padding: 12px; background: #fff7df; border-left: 4px solid #d29b00; }
        .error { color: #8a1f11; }
        .exito { color: #176b54; font-weight: 700; }
    </style>
</head>
<body>
<main>
    <h1>APLICACIÓN CONFIDENCIAL DE VPH</h1>
    <p class="aviso">
        Este registro no solicita ni guarda nombre, CURP o expediente del paciente.
        Guarda únicamente la categoría, dosis, lote, fecha, unidad, persona usuaria que registra y estatus migratorio sí/no
        para el paloteo y el control de existencias.
    </p>
    <?php if ($guardado): ?>
        <p class="exito" role="status">La aplicación anónima de VPH se registró correctamente.</p>
    <?php endif; ?>
    <?php if ($error !== ""): ?>
        <p class="error" role="alert"><?php echo escaparVphConfidencial($error); ?></p>
    <?php endif; ?>
    <form method="post">
        <label for="categoria">Grupo de población</label>
        <select name="categoria" id="categoria" required>
            <option value="">Selecciona el grupo</option>
            <?php foreach ($categorias as $clave => $etiqueta): ?>
                <option value="<?php echo escaparVphConfidencial($clave); ?>"
                    <?php echo $valores["categoria"] === $clave ? "selected" : ""; ?>>
                    <?php echo escaparVphConfidencial($etiqueta); ?>
                </option>
            <?php endforeach; ?>
        </select>

        <label for="id_esquema_biologico_dosis">Dosis</label>
        <select name="id_esquema_biologico_dosis" id="id_esquema_biologico_dosis" required>
            <option value="">Selecciona la dosis</option>
            <?php foreach ($dosisDisponibles as $dosis): ?>
                <option value="<?php echo (int)$dosis["id_esquema_biologico_dosis"]; ?>"
                    <?php echo $valores["id_esquema_biologico_dosis"] === (string)$dosis["id_esquema_biologico_dosis"] ? "selected" : ""; ?>>
                    <?php echo escaparVphConfidencial($dosis["nombre_dosis"]); ?>
                </option>
            <?php endforeach; ?>
        </select>

        <label for="id_lote">Lote VPH disponible</label>
        <select name="id_lote" id="id_lote" required>
            <option value="">Selecciona el lote aplicado</option>
            <?php foreach ($lotesDisponibles as $lote): ?>
                <option value="<?php echo (int)$lote["id_lote"]; ?>"
                    <?php echo $valores["id_lote"] === (string)$lote["id_lote"] ? "selected" : ""; ?>>
                    <?php echo escaparVphConfidencial(
                        $lote["nombre_presentacion"] . " — Lote " . $lote["numero_lote"]
                        . " — Caduca " . $lote["fecha_caducidad"]
                        . " — " . $lote["dosis_disponibles"] . " dosis disponibles"
                    ); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <?php if ($lotesDisponibles === []): ?>
            <p>No hay lotes VPH vigentes con existencias para esta unidad.</p>
        <?php endif; ?>

        <label for="fecha_aplicacion">Fecha de aplicación</label>
        <input type="date" name="fecha_aplicacion" id="fecha_aplicacion"
               max="<?php echo date("Y-m-d"); ?>"
               value="<?php echo escaparVphConfidencial($valores["fecha_aplicacion"]); ?>" required>

        <label for="es_migrante">¿La persona es migrante?</label>
        <select name="es_migrante" id="es_migrante" required>
            <option value="">Selecciona</option>
            <option value="0" <?php echo $valores["es_migrante"] === "0" ? "selected" : ""; ?>>No</option>
            <option value="1" <?php echo $valores["es_migrante"] === "1" ? "selected" : ""; ?>>Sí</option>
        </select>

        <p>En los grupos de mujeres u hombres que viven con VIH, registra la primera, segunda o tercera dosis según corresponda.</p>
        <button type="submit">Guardar aplicación VPH</button>
    </form>
    <p><a href="index.php">Volver a buscar paciente</a></p>
</main>
</body>
</html>
