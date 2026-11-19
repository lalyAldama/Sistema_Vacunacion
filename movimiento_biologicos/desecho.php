<?php

require_once __DIR__ . "/../config/seguridad.php";

if(!isset($_SESSION["id_usuario"])) {
    header("Location: ../login.php");
    exit();
}

require_once "../config/conexion.php";
require_once __DIR__ . "/../config/unidad_contexto.php";
require_once __DIR__ . "/../exportar_importar/correcciones_funciones.php";
$contextoUnidad = unidadObtenerContexto($conexion);
unidadExigirPermisoEscritura();
$idUnidad = $contextoUnidad["id_unidad"];
correccionesAsegurarRegistroCentral($conexion);


// =========================================================
// OBTENER LOTES REGISTRADOS
// =========================================================

$sqlLotes = "SELECT
                l.id_lote,
                l.numero_lote,
                l.fecha_caducidad,
                b.nombre_biologico,
                p.nombre_presentacion,
                p.dosis_por_frasco,
                p.ml_por_dosis
             FROM LOTE l

             INNER JOIN PRESENTACION p
                ON l.id_presentacion = p.id_presentacion

             INNER JOIN BIOLOGICO b
                ON p.id_biologico = b.id_biologico

             ORDER BY
                b.nombre_biologico,
                l.fecha_caducidad";

$lotes = $conexion->query($sqlLotes);

if(!$lotes) {
    die("Error al consultar lotes: " . $conexion->error);
}


// =========================================================
// GUARDAR DESECHO
// =========================================================

if($_SERVER["REQUEST_METHOD"] == "POST") {
    unidadExigirPermisoEscritura();

    $id_lote = $_POST["id_lote"] ?? "";
    $cantidadDosisTexto = trim((string)($_POST["cantidad_dosis"] ?? ""));
    $cantidad_dosis = ctype_digit($cantidadDosisTexto)
        ? filter_var($cantidadDosisTexto, FILTER_VALIDATE_INT)
        : false;
    $fecha_movimiento = $_POST["fecha_movimiento"] ?? "";
    $observaciones = trim($_POST["observaciones"] ?? "");

    // =====================================================
    // VALIDAR CAMPOS
    // =====================================================

    if(
        empty($id_lote) ||
        $cantidad_dosis === false ||
        empty($fecha_movimiento)
    ) {

        die("Faltan datos obligatorios.");

    }


    if($cantidad_dosis < 1) {

        die("La cantidad de dosis debe ser mayor a cero.");

    }


    // =====================================================
    // OBTENER DATOS DEL LOTE
    // =====================================================

    $sqlLote = "SELECT
                    l.id_lote,
                    p.dosis_por_frasco,
                    p.ml_por_dosis
                FROM LOTE l

                INNER JOIN PRESENTACION p
                    ON l.id_presentacion = p.id_presentacion

                WHERE l.id_lote = ?";

    $stmtLote = $conexion->prepare($sqlLote);

    if(!$stmtLote) {
        die("Error al preparar consulta del lote: " . $conexion->error);
    }

    $stmtLote->bind_param("i", $id_lote);

    $stmtLote->execute();

    $resultadoLote = $stmtLote->get_result();

    if($resultadoLote->num_rows === 0) {

        die("Lote no encontrado.");

    }

    $lote = $resultadoLote->fetch_assoc();

    $stmtLote->close();


    // =====================================================
    // CALCULAR EQUIVALENTE EN FRASCOS
    // =====================================================

    $cantidad_frascos =0;


    // =====================================================
    // REGISTRAR DESECHO
    // =====================================================

    $tipo_movimiento = "Desecho";

    $necesidad = 0;

    $sqlMovimiento = "INSERT INTO MOVIMIENTO_BIOLOGICO (
                    id_unidad, id_lote, tipo_movimiento, cantidad_frascos,
                    cantidad_dosis, fecha_movimiento, necesidad, observaciones
                  )
                  VALUES (?, ?, ?, ?, ?, ?, ?, ?)";

    $stmtMovimiento = $conexion->prepare($sqlMovimiento);

    if (!$stmtMovimiento) {
        die("Error al preparar movimiento: " . $conexion->error);
    }

    $stmtMovimiento->bind_param(
        "iisdisis",
        $idUnidad,
        $id_lote,
        $tipo_movimiento,
        $cantidad_frascos,
        $cantidad_dosis,
        $fecha_movimiento,
        $necesidad,
        $observaciones
    );


    $conexion->begin_transaction();
    if(!$stmtMovimiento->execute()) {
        $conexion->rollback();

        die(
            "Error al registrar desecho: "
            . $stmtMovimiento->error
        );

    }
    $idMovimientoCentral = (int)$conexion->insert_id;

    correccionesRegistrarCambio($conexion, (int)$idUnidad, "MOVIMIENTO_BIOLOGICO", [
        "id_movimiento_central" => $idMovimientoCentral,
        "id_unidad_movimiento" => (int)$idUnidad,
        "id_lote_central" => (int)$id_lote,
        "tipo_movimiento" => $tipo_movimiento,
        "cantidad_frascos" => (int)$cantidad_frascos,
        "cantidad_dosis" => (int)$cantidad_dosis,
        "fecha_movimiento" => (string)$fecha_movimiento,
        "necesidad" => (int)$necesidad,
        "id_unidad_receptora" => null,
        "observaciones" => (string)$observaciones
    ]);
    if (!$conexion->commit()) {
        $conexion->rollback();
        die("No se pudo confirmar el registro del movimiento.");
    }

    $stmtMovimiento->close();


    echo "<script>

            alert('DESECHO REGISTRADO CORRECTAMENTE');

            window.location.href='index.php';

          </script>";

    exit();

}

?>

<!DOCTYPE html>

<html lang="es">

<head>

    <meta charset="UTF-8">

    <title>REGISTRAR DESECHO</title>
    <link rel="stylesheet" href="../css/estilos.css">

</head>

<body>


<h1>REGISTRAR DESECHO DE BIOLÓGICO</h1>


<form method="POST">


    <label>

        <strong>LOTE:</strong>

    </label>

    <br>

    <select
        name="id_lote"
        required
    >

        <option value="">

            SELECCIONE UN LOTE

        </option>


        <?php while($lote = $lotes->fetch_assoc()): ?>

            <option
                value="<?php echo $lote["id_lote"]; ?>"
            >

                <?php

                echo htmlspecialchars(

                    $lote["nombre_biologico"]
                    . " - "
                    . $lote["nombre_presentacion"]
                    . " - LOTE: "
                    . $lote["numero_lote"]
                    . " - CAD: "
                    . $lote["fecha_caducidad"]

                );

                ?>

            </option>

        <?php endwhile; ?>

    </select>


    <br>
    <br>


    <!-- =================================================
         DOSIS A DESECHAR
    ================================================== -->

    <label>

        <strong>DOSIS A DESECHAR:</strong>

    </label>

    <br>

    <input
        type="number"
        name="cantidad_dosis"
        min="1"
        step="1"
        inputmode="numeric"
        required
    >


    <br>
    <br>


    <!-- =================================================
         FECHA
    ================================================== -->

    <label>

        <strong>FECHA DEL MOVIMIENTO:</strong>

    </label>

    <br>

    <input
        type="date"
        name="fecha_movimiento"
        value="<?php echo date('Y-m-d'); ?>"
        required
    >


    <br>
    <br>
    <label>MOTIVO DEL DESECHO</label>
    <textarea
        name="observaciones"
        rows="5"
        cols="50"
        maxlength="500"
    ></textarea>
    <br>
    <br>


    <button type="submit">

        GUARDAR DESECHO

    </button>


</form>


<br>


<a href="index.php">

    <button type="button">

        REGRESAR

    </button>

</a>


</body>

</html>
