<?php
require_once "../config/conexion.php";

//Biologicos y presentaciones

$sql = "SELECT
        p.id_presentacion,
        b.nombre_biologico,
        p.nombre_presentacion,
        p.dosis_por_frasco,
        p.ml_por_dosis
        FROM PRESENTACION p
        INNER JOIN BIOLOGICO b
            ON p.id_biologico = b.id_biologico
        ORDER BY b.nombre_biologico, p.nombre_presentacion";

$resultado = $conexion->query($sql);

//unidades

$sql_unidades= "SELECT 
                id_unidad,
                nombre_unidad
                FROM UNIDAD
                ORDER BY nombre_unidad";
$unidades= $conexion->query($sql_unidades);

//Guardar nueva entrada de biologico

if($_SERVER["REQUEST_METHOD"] == "POST") {
    $id_presentacion = $_POST['id_presentacion'];
    $numero_lote = $_POST['numero_lote'];
    $fecha_caducidad = $_POST['fecha_caducidad'];
    $cantidad_frascos = $_POST['cantidad_frascos'];
    $fecha_movimiento = $_POST['fecha_movimiento'];
    $necesidad = 0;
    $id_unidad = $_POST['id_unidad'];

//Dosis por frasco
    $sql_dosis = "SELECT dosis_por_frasco
                    FROM PRESENTACION
                    WHERE id_presentacion = ?";
    $stmt_dosis = $conexion->prepare($sql_dosis);
    $stmt_dosis->bind_param("i", $id_presentacion);
    $stmt_dosis->execute();

    $resultado_dosis= $stmt_dosis->get_result();
    $presentacion= $resultado_dosis->fetch_assoc();

    $dosis_por_frasco= $presentacion['dosis_por_frasco'];

    //calculo de dosis totales
    $cantidad_dosis = $cantidad_frascos * $dosis_por_frasco;

    $conexion->begin_transaction();


    try {
        $sql_lote = "INSERT INTO LOTE (id_presentacion, numero_lote, fecha_caducidad)
        VALUES (?,?,?)";

        $stmt_lote= $conexion->prepare($sql_lote);
        
        $stmt_lote->bind_param(
            "iss",
            $id_presentacion,
            $numero_lote,
            $fecha_caducidad

        );

        $stmt_lote->execute();

        $id_lote= $conexion ->insert_id;

        $sql_movimiento = "INSERT INTO MOVIMIENTO_BIOLOGICO(
                            id_unidad, id_lote, tipo_movimiento, cantidad_frascos, cantidad_dosis,
                            fecha_movimiento, necesidad)
                            VALUES (?, ?, 'Entrada', ?,?,?,?)";
        $stmt_movimiento = $conexion->prepare($sql_movimiento);

        $stmt_movimiento->bind_param(
            "iiiisi",
            $id_unidad,
            $id_lote,
            $cantidad_frascos,
            $cantidad_dosis,
            $fecha_movimiento, 
            $necesidad
        );

        $stmt_movimiento->execute();

        $conexion->commit();

        echo "<script>
                alert('ENTRADA REGISTRADA CORRECTAMENTE');
                window.location.href='index.php';
              </script>";
        exit;
    } catch (Exception $e) {
        $conexion->rollback();
        echo "ERROR AL REGISTRAR LA ENTRADA" . $e->getMessage();
    }
}
?>


<!DOCTYPE html>
<html lang="es">
    <head>
        <meta charset="UTF-8">
        <title>NUEVA ENTRADA DE BIOLÓGICO</title>
    </head>

    <body>
        <h1>NUEVA ENTRADA DE BIOLÓGICO</h1>

        <form method="POST">
            <label>Biologico/Presentacion</label>

            <select name="id_presentacion" required>
                <option value="">
                    SELECCIONA UNA PRESENTACION  
                </option>

                <?php while ($presentacion = $resultado->fetch_assoc()){ ?>
                    <option value="<?php echo $presentacion['id_presentacion']; ?>">
                        <?php
                        echo $presentacion['nombre_biologico']
                        . " - "
                        . $presentacion['nombre_presentacion']
                        . "("
                        . $presentacion['dosis_por_frasco'] 
                        . " DOSIS POR FRASCO)";
                        ?>
                    </option>
                <?php }?>
            </select>
            <br><br>

            <!LOTE>

            <label>
                NÚMERO DE LOTE:
            </label>
            <input type="text" name="numero_lote" required>
            <br><br>

            <label> FECHA DE CADUCIDAD </label>
            <input type="date" name="fecha_caducidad" required>
            <br><br>

            <label>CANTIDAD DE FRASCOS </label>
            <input type="text" name="cantidad_frascos" min="1" required>
            <br><br>

            <label>FECHA DE ENTRADA</label>
            <input type="date" name="fecha_movimiento" required>
            <br><br>

            <label>UNIDAD</label>
            <select name="id_unidad" required>
                <option value="">
                    SELECCIONA UNA UNIDAD
                </option>

                <?php while ($unidad = $unidades->fetch_assoc()) { ?>
                    <option value="<?php echo $unidad['id_unidad']; ?>">
                        <?php echo $unidad['nombre_unidad']; ?>
                    </option>
                <?php }  ?> 
            </select>
            <br><br>

            <button type="submit">
                GUARDAR ENTRADA
            </button>

            <a href="index.php">
                <button type="button">
                    CANCELAR
                </button>
            </a>
        </form>
    </body>
</html>