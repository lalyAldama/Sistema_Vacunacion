<?php
require_once __DIR__ . "/../config/seguridad_administrador.php";
require_once "../config/conexion.php";

// Obtener el ID del biológico
$id_biologico = $_GET['id'];


// Actualizar biológico
if (isset($_POST['actualizar'])) {

    $nombre_biologico = $_POST['nombre_biologico'];

    $sql = "UPDATE BIOLOGICO
            SET nombre_biologico = ?
            WHERE id_biologico = ?";

    $stmt = $conexion->prepare($sql);

    $stmt->bind_param(
        "si",
        $nombre_biologico,
        $id_biologico
    );

    if ($stmt->execute()) {
        header("Location: index.php");
        exit;
    } else {
        echo "Error al actualizar el biológico: " . $stmt->error;
    }

    $stmt->close();
}


// Obtener los datos actuales del biológico
$sql = "SELECT id_biologico, nombre_biologico
        FROM BIOLOGICO
        WHERE id_biologico = ?";

$stmt = $conexion->prepare($sql);

$stmt->bind_param("i", $id_biologico);

$stmt->execute();

$resultado = $stmt->get_result();

$biologico = $resultado->fetch_assoc();

$stmt->close();
?>

<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Editar Biológico</title>
    <link rel="stylesheet" href="../css/estilos.css">
    <link rel="stylesheet" href="../css/gestion_admin.css">
</head>

<body class="gestion-admin">
    <main class="gestion-contenedor">
    <header class="gestion-encabezado">
        <div class="gestion-encabezado-texto">
            <span class="gestion-etiqueta">ADMINISTRACIÓN · BIOLÓGICOS</span>
            <h1>Editar biológico</h1>
            <p>Actualiza la información del catálogo.</p>
        </div>
        <a class="gestion-volver" href="index.php">Volver al catálogo</a>
    </header>
    <form class="gestion-formulario" action="editar.php?id=<?php echo $biologico['id_biologico']; ?>" method="POST">

        <label>Nombre del biológico</label>

        <input type="text"
               name="nombre_biologico"
               value="<?php echo $biologico['nombre_biologico']; ?>"
               required>

        <br><br>

        <button type="submit" name="actualizar">
            Guardar cambios
        </button>

        <a class="btn gestion-btn-secundario" href="index.php">Cancelar</a>

    </form>
    </main>
</body>

</html>