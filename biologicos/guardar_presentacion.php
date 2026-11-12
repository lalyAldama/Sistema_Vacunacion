<?php
require_once __DIR__ . "/../config/seguridad_administrador.php";
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
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Nueva Presentación</title>
    <link rel="stylesheet" href="../css/estilos.css">
    <link rel="stylesheet" href="../css/gestion_admin.css">
</head>

<body class="gestion-admin">
    <main class="gestion-contenedor">
    <header class="gestion-encabezado">
        <div class="gestion-encabezado-texto">
            <span class="gestion-etiqueta">ADMINISTRACIÓN · BIOLÓGICOS</span>
            <h1>Nueva presentación</h1>
            <p>Define la presentación y el contenido de un biológico.</p>
        </div>
        <a class="gestion-volver" href="index.php">Volver al catálogo</a>
    </header>

    <form class="gestion-formulario" action="guardar_presentacion.php" method="POST">


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


        <a class="btn gestion-btn-secundario" href="index.php">Cancelar</a>


    </form>
    </main>
</body>

</html>