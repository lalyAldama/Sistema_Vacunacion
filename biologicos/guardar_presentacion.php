<?php
require_once "../config/conexion.php";


// Obtener los biológicos
$sql_biologicos = "SELECT id_biologico, nombre_biologico
                   FROM BIOLOGICO
                   ORDER BY nombre_biologico";

$biologicos = $conexion->query($sql_biologicos);


// Guardar nueva presentación
if (isset($_POST['guardar'])) {

    $id_biologico = $_POST['id_biologico'];
    $nombre_presentacion = $_POST['nombre_presentacion'];
    $dosis_por_frasco = $_POST['dosis_por_frasco'];
    $ml_por_dosis = $_POST['ml_por_dosis'];


    $sql = "INSERT INTO PRESENTACION
            (id_biologico, nombre_presentacion, dosis_por_frasco, ml_por_dosis)
            VALUES (?, ?, ?, ?)";

    $stmt = $conexion->prepare($sql);

    $stmt->bind_param(
        "isid",
        $id_biologico,
        $nombre_presentacion,
        $dosis_por_frasco,
        $ml_por_dosis
    );


    if ($stmt->execute()) {

        header("Location: index.php");
        exit;

    } else {

        echo "Error al guardar la presentación: " . $stmt->error;

    }

    $stmt->close();
}
?>


<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <title>Nueva Presentación</title>
</head>

<body>

    <h1>Nueva presentación</h1>


    <form action="guardar_presentacion.php" method="POST">


        <!-- Biológico -->

        <label>Biológico</label>

        <select name="id_biologico" required>

            <option value="">
                Seleccionar biológico
            </option>

            <?php while ($biologico = $biologicos->fetch_assoc()) { ?>

                <option value="<?php echo $biologico['id_biologico']; ?>">

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
            required
        >

        <br><br>


        <!-- Dosis por frasco -->

        <label>Dosis por frasco</label>

        <input
            type="number"
            name="dosis_por_frasco"
            min="1"
            required
        >

        <br><br>


        <!-- ML por dosis -->

        <label>ML por dosis</label>

        <input
            type="number"
            name="ml_por_dosis"
            step="0.01"
            min="0"
            required
        >

        <br><br>


        <!-- Botones -->

        <button type="submit" name="guardar">
            Guardar presentación
        </button>


        <a href="index.php">
            <button type="button">
                Cancelar
            </button>
        </a>


    </form>

</body>

</html>