<?php

session_start();

if(!isset($_SESSION["id_usuario"])) {
    header("Location: ../login.php");
    exit();
}

require_once "../config/conexion.php";


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

    $id_lote = $_POST["id_lote"] ?? "";
    $cantidad_dosis = $_POST["cantidad_dosis"] ?? "";
    $fecha_movimiento = $_POST["fecha_movimiento"] ?? "";


    // =====================================================
    // VALIDAR CAMPOS
    // =====================================================

    if(
        empty($id_lote) ||
        empty($cantidad_dosis) ||
        empty($fecha_movimiento)
    ) {

        die("Faltan datos obligatorios.");

    }


    if($cantidad_dosis <= 0) {

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
    // OBTENER UNIDAD DEL USUARIO
    // =====================================================

    $sqlUsuario = "SELECT id_unidad
                   FROM USUARIO
                   WHERE id_usuario = ?";

    $stmtUsuario = $conexion->prepare($sqlUsuario);

    if(!$stmtUsuario) {
        die("Error al consultar unidad: " . $conexion->error);
    }

    $stmtUsuario->bind_param(
        "i",
        $_SESSION["id_usuario"]
    );

    $stmtUsuario->execute();

    $resultadoUsuario = $stmtUsuario->get_result();

    $usuario = $resultadoUsuario->fetch_assoc();

    $stmtUsuario->close();


    if(empty($usuario["id_unidad"])) {

        die("El usuario no tiene una unidad asignada.");

    }


    // =====================================================
    // REGISTRAR DESECHO
    // =====================================================

    $tipo_movimiento = "Desecho";

    $necesidad = 0;

    $sqlMovimiento = "INSERT INTO MOVIMIENTO_BIOLOGICO (
                        id_unidad,
                        id_lote,
                        tipo_movimiento,
                        cantidad_frascos,
                        cantidad_dosis,
                        fecha_movimiento,
                        necesidad
                      )
                      VALUES (?, ?, ?, ?, ?, ?, ?)";

    $stmtMovimiento = $conexion->prepare($sqlMovimiento);

    if(!$stmtMovimiento) {
        die(
            "Error al preparar movimiento: "
            . $conexion->error
        );
    }


    $stmtMovimiento->bind_param(
        "iisdisi",
        $usuario["id_unidad"],
        $id_lote,
        $tipo_movimiento,
        $cantidad_frascos,
        $cantidad_dosis,
        $fecha_movimiento,
        $necesidad
    );


    if(!$stmtMovimiento->execute()) {

        die(
            "Error al registrar desecho: "
            . $stmtMovimiento->error
        );

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


    <!-- =================================================
         LOTE
    ================================================== -->

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