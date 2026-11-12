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
$idUnidad = (int)$contextoUnidad["id_unidad"];
$vistaProductividad = ($_GET["vista"] ?? "") === "productividad";
$idEnfermero = filter_var($_GET["enfermero"] ?? null, FILTER_VALIDATE_INT);
$idEnfermero = $idEnfermero !== false && $idEnfermero > 0 ? $idEnfermero : 0;
$enfermeros = [];
$totalProductividad = null;
$nombreEnfermero = "";

if ($vistaProductividad) {
    $stmtEnfermeros = $conexion->prepare(
        "SELECT u.id_usuario, u.nombre, u.ap_pat, u.ap_mat
         FROM USUARIO u
         INNER JOIN ROL r ON r.id_rol = u.id_rol
         WHERE u.id_unidad = ?
           AND r.nombre_rol = 'Personal de unidad'
         ORDER BY u.ap_pat, u.ap_mat, u.nombre"
    );
    if (!$stmtEnfermeros) {
        die("No se pudo preparar la lista de enfermeros: " . $conexion->error);
    }
    $stmtEnfermeros->bind_param("i", $idUnidad);
    if (!$stmtEnfermeros->execute()) {
        die("No se pudo consultar la lista de enfermeros: " . $stmtEnfermeros->error);
    }
    $enfermeros = $stmtEnfermeros->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmtEnfermeros->close();

    if ($idEnfermero > 0) {
        foreach ($enfermeros as $enfermero) {
            if ((int)$enfermero["id_usuario"] === $idEnfermero) {
                $nombreEnfermero = trim(
                    $enfermero["nombre"] . " " . $enfermero["ap_pat"] . " " . $enfermero["ap_mat"]
                );
                break;
            }
        }

        if ($nombreEnfermero !== "") {
            $sqlTotal = "
                SELECT COUNT(*) AS total
                FROM (
                    SELECT av.id_usuario
                    FROM APLICACION_VACUNA av
                    WHERE av.id_unidad = ? AND av.id_usuario = ?
                    UNION ALL
                    SELECT va.id_usuario
                    FROM APLICACION_VPH_ANONIMA va
                    WHERE va.id_unidad = ? AND va.id_usuario = ?
                ) AS aplicaciones
            ";
            $stmtTotal = $conexion->prepare($sqlTotal);
            if (!$stmtTotal) {
                die("No se pudo preparar el total de aplicaciones: " . $conexion->error);
            }
            $stmtTotal->bind_param("iiii", $idUnidad, $idEnfermero, $idUnidad, $idEnfermero);
            if (!$stmtTotal->execute()) {
                die("No se pudo calcular el total: " . $stmtTotal->error);
            }
            $totalProductividad = (int)$stmtTotal->get_result()->fetch_assoc()["total"];
            $stmtTotal->close();
        }
    }
}

$sql = "
    SELECT
        av.fecha_aplicacion,
        CONCAT_WS(' ', b.nombre_biologico, d.nombre_dosis) AS vacuna,
        CONCAT_WS(' ', p.nombres, p.ap_pat, p.ap_mat) AS paciente,
        CONCAT_WS(' ', u.nombre, u.ap_pat, u.ap_mat) AS enfermero,
        'Aplicación registrada' AS tipo
    FROM APLICACION_VACUNA av
    INNER JOIN PACIENTE p
        ON p.id_paciente = av.id_paciente
    INNER JOIN ESQUEMA_BIOLOGICO_DOSIS ebd
        ON ebd.id_esquema_biologico_dosis = av.id_esquema_biologico_dosis
    INNER JOIN ESQUEMA_BIOLOGICO eb
        ON eb.id_esquema_biologico = ebd.id_esquema_biologico
    INNER JOIN BIOLOGICO b
        ON b.id_biologico = eb.id_biologico
    INNER JOIN DOSIS d
        ON d.id_dosis = ebd.id_dosis
    LEFT JOIN USUARIO u
        ON u.id_usuario = av.id_usuario
    WHERE av.id_unidad = ?

    UNION ALL

    SELECT
        va.fecha_aplicacion,
        CONCAT_WS(' ', b.nombre_biologico, d.nombre_dosis) AS vacuna,
        'Registro confidencial' AS paciente,
        CONCAT_WS(' ', u.nombre, u.ap_pat, u.ap_mat) AS enfermero,
        'VPH confidencial' AS tipo
    FROM APLICACION_VPH_ANONIMA va
    INNER JOIN ESQUEMA_BIOLOGICO_DOSIS ebd
        ON ebd.id_esquema_biologico_dosis = va.id_esquema_biologico_dosis
    INNER JOIN ESQUEMA_BIOLOGICO eb
        ON eb.id_esquema_biologico = ebd.id_esquema_biologico
    INNER JOIN BIOLOGICO b
        ON b.id_biologico = eb.id_biologico
    INNER JOIN DOSIS d
        ON d.id_dosis = ebd.id_dosis
    LEFT JOIN USUARIO u
        ON u.id_usuario = va.id_usuario
    WHERE va.id_unidad = ?

    ORDER BY fecha_aplicacion DESC
";

$stmt = $conexion->prepare($sql);

if (!$stmt) {
    die("No se pudo preparar la bitácora: " . $conexion->error);
}

$stmt->bind_param("ii", $idUnidad, $idUnidad);

if (!$stmt->execute()) {
    die("No se pudo consultar la bitácora: " . $stmt->error);
}

$aplicaciones = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

function escapar($valor)
{
    return htmlspecialchars((string)$valor, ENT_QUOTES, "UTF-8");
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bitácora de aplicaciones</title>
    <link rel="stylesheet" href="../css/estilos.css">
</head>
<body>

    <header class="encabezado-pagina">
        <h1>Bitácora de aplicaciones</h1>
        <p>
            Aplicaciones registradas en la unidad:
            <strong><?php echo escapar($contextoUnidad["nombre_unidad"]); ?></strong>
        </p>
        <div class="acciones">
            <?php if ($vistaProductividad): ?>
                <a class="btn btn-secundario" href="index.php">Bitácora general</a>
            <?php else: ?>
                <a class="btn" href="index.php?vista=productividad">Productividad</a>
            <?php endif; ?>
        </div>
    </header>

    <main class="panel">
        <?php if ($vistaProductividad): ?>
            <h2>Productividad por enfermero</h2>
            <form method="get" action="index.php" class="fila-formulario">
                <input type="hidden" name="vista" value="productividad">
                <div class="campo">
                    <label for="enfermero">Selecciona un enfermero</label>
                    <select name="enfermero" id="enfermero" required>
                        <option value="">Selecciona</option>
                        <?php foreach ($enfermeros as $enfermero): ?>
                            <?php $nombreCompleto = trim($enfermero["nombre"] . " " . $enfermero["ap_pat"] . " " . $enfermero["ap_mat"]); ?>
                            <option value="<?php echo (int)$enfermero["id_usuario"]; ?>"
                                <?php echo (int)$enfermero["id_usuario"] === $idEnfermero ? "selected" : ""; ?>>
                                <?php echo escapar($nombreCompleto); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="campo acciones">
                    <button type="submit">Consultar total</button>
                </div>
            </form>

            <?php if ($nombreEnfermero !== ""): ?>
                <section class="seccion" aria-live="polite">
                    <h3><?php echo escapar($nombreEnfermero); ?></h3>
                    <p>Total de vacunas aplicadas: <strong><?php echo $totalProductividad; ?></strong></p>
                </section>
            <?php elseif ($idEnfermero > 0): ?>
                <p class="alerta alerta-info">No se encontró ese enfermero en esta unidad.</p>
            <?php endif; ?>
        <?php else: ?>
        <div class="tabla-contenedor">
            <table>
                <thead>
                    <tr>
                        <th>Fecha de aplicación</th>
                        <th>Vacuna y dosis</th>
                        <th>Paciente</th>
                        <th>Enfermero</th>
                        <th>Tipo de registro</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($aplicaciones) === 0): ?>
                        <tr>
                            <td colspan="5">Todavía no hay aplicaciones registradas.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($aplicaciones as $aplicacion): ?>
                            <tr>
                                <td><?php echo escapar($aplicacion["fecha_aplicacion"]); ?></td>
                                <td><?php echo escapar($aplicacion["vacuna"]); ?></td>
                                <td><?php echo escapar($aplicacion["paciente"]); ?></td>
                                <td>
                                    <?php
                                    echo escapar(
                                        $aplicacion["enfermero"] !== ""
                                            ? $aplicacion["enfermero"]
                                            : "Sin usuario atribuido"
                                    );
                                    ?>
                                </td>
                                <td><?php echo escapar($aplicacion["tipo"]); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>

        <div class="acciones">
            <a class="btn btn-secundario" href="../menu.php">Volver al menú</a>
        </div>
    </main>

</body>
</html>
