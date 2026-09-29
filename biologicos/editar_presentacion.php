<?php
require_once "../config/conexion.php";

// Obtener el ID de la presentación
$id_presentacion = $_GET['id'];


// Actualizar presentación
if (isset($_POST['actualizar'])) {

    $id_biologico = $_POST['id_biologico'];
    $nombre_presentacion = $_POST['nombre_presentacion'];
    $dosis_por_frasco = $_POST['dosis_por_frasco'];
    $ml_por_dosis = $_POST['ml_por_dosis'];

    $sql = "UPDATE PRESENTACION
            SET id_biologico = ?,
                nombre_presentacion = ?,
                dosis_por_frasco = ?,
                ml_por_dosis = ?
            WHERE id_presentacion = ?";

    $stmt = $conexion->prepare($sql);

    $stmt->bind_param(
        "isidi",
        $id_biologico,
        $nombre_presentacion,
        $dosis_por_frasco,
        $ml_por_dosis,
        $id_presentacion
    );

    if ($stmt->execute()) {
        header("Location: index.php");
        exit;
    } else {
        echo "Error al actualizar la presentación: " . $stmt->error;
    }

    $stmt->close();
}


// Obtener los datos actuales de la presentación
$sql = "SELECT
            id_presentacion,
            id_biologico,
            nombre_presentacion,
            dosis_por_frasco,
            ml_por_dosis
        FROM PRESENTACION
        WHERE id_presentacion = ?";

$stmt = $conexion->prepare($sql);

$stmt->bind_param("i", $id_presentacion);

$stmt->execute();

$resultado = $stmt->get_result();

$presentacion = $resultado->fetch_assoc();

$stmt->close();


// Obtener los biológicos
$sql_biologicos = "SELECT
                       id_biologico,
                       nombre_biologico
                   FROM BIOLOGICO
                   ORDER BY nombre_biologico";

$biologicos = $conexion->query($sql_biologicos);

?>


<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <title>Editar Presentación</title>
</head>

<body>

    <h1>Editar presentación</h1>

    <form action="editar_presentacion.php?id=<?php echo $presentacion['id_presentacion']; ?>" method="POST">


        <!-- Biológico -->

        <label>Biológico</label>

        <select name="id_biologico" required>

            <?php while ($biologico = $biologicos->fetch_assoc()) { ?>

                <option
                    value="<?php echo $biologico['id_biologico']; ?>"
                    <?php
                    if ($biologico['id_biologico'] == $presentacion['id_biologico']) {
                        echo "selected";
                    }
                    ?>
                >

                    <?php echo $biologico['nombre_biologico']; ?>

                </option>

            <?php } ?>

        </select>

        <br><br>


        <!-- Nombre de presentación -->

        <label>Nombre de presentación</label>

        <input
            type="text"
            name="nombre_presentacion"
            value="<?php echo $presentacion['nombre_presentacion']; ?>"
            required
        >

        <br><br>


        <!-- Dosis por frasco -->

        <label>Dosis por frasco</label>

        <input
            type="number"
            name="dosis_por_frasco"
            value="<?php echo $presentacion['dosis_por_frasco']; ?>"
            min="1"
            required
        >

        <br><br>


        <!-- ML por dosis -->

        <label>ML por dosis</label>

        <input
            type="number"
            name="ml_por_dosis"
            value="<?php echo $presentacion['ml_por_dosis']; ?>"
            step="0.01"
            min="0"
            required
        >

        <br><br>


        <button type="submit" name="actualizar">
            Guardar cambios
        </button>


        <a href="index.php">
            <button type="button">
                Cancelar
            </button>
        </a>

    </form>

</body>

</html>