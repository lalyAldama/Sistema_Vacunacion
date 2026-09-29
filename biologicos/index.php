<?php
require_once "../config/conexion.php";

$sql = "SELECT 
            b.id_biologico,
            b.nombre_biologico,
            p.id_presentacion,
            p.nombre_presentacion,
            p.dosis_por_frasco,
            p.ml_por_dosis
        FROM BIOLOGICO b
        LEFT JOIN PRESENTACION p
            ON b.id_biologico = p.id_biologico
        ORDER BY b.nombre_biologico, p.nombre_presentacion";

$resultado = $conexion->query($sql);
?>

<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <title>Gestión de Biológicos</title>
</head>

<body>

    <h1>Gestión de Biológicos</h1>

    <!-- Botones principales -->

    <a href="guardar.php">
        <button type="button">+ Nuevo biológico</button>
    </a>

    <a href="guardar_presentacion.php">
        <button type="button">+ Nueva presentación</button>
    </a>

    <br><br>

    <table border="1">

        <thead>
            <tr>
                <th>Biológico</th>
                <th>Presentación</th>
                <th>Dosis por frasco</th>
                <th>ML por dosis</th>
                <th>Acciones</th>
            </tr>
        </thead>

        <tbody>

            <?php while ($biologico = $resultado->fetch_assoc()) { ?>

                <tr>

                    <td>
                        <?php echo $biologico['nombre_biologico']; ?>
                    </td>

                    <td>
                        <?php
                        if ($biologico['nombre_presentacion'] !== null) {
                            echo $biologico['nombre_presentacion'];
                        } else {
                            echo "Sin presentación";
                        }
                        ?>
                    </td>

                    <td>
                        <?php
                        if ($biologico['dosis_por_frasco'] !== null) {
                            echo $biologico['dosis_por_frasco'];
                        } else {
                            echo "-";
                        }
                        ?>
                    </td>

                    <td>
                        <?php
                        if ($biologico['ml_por_dosis'] !== null) {
                            echo $biologico['ml_por_dosis'] . " ml";
                        } else {
                            echo "-";
                        }
                        ?>
                    </td>

                    <td>

                        <!-- Editar biológico -->
                        <a href="editar.php?id=<?php echo $biologico['id_biologico']; ?>">
                            Editar biológico
                        </a>

                        <br><br>

                        <!-- Eliminar biológico -->
                        <a href="eliminar.php?id=<?php echo $biologico['id_biologico']; ?>"
                           onclick="return confirm('¿Seguro que quieres eliminar este biológico?');">
                            Eliminar biológico
                        </a>

                        <?php if ($biologico['id_presentacion'] !== null) { ?>

                            <br><br>

                            <!-- Editar presentación -->
                            <a href="editar_presentacion.php?id=<?php echo $biologico['id_presentacion']; ?>">
                                Editar presentación
                            </a>

                            <br><br>

                            <!-- Eliminar presentación -->
                            <a href="eliminar_presentacion.php?id=<?php echo $biologico['id_presentacion']; ?>"
                               onclick="return confirm('¿Seguro que quieres eliminar esta presentación?');">
                                Eliminar presentación
                            </a>

                        <?php } ?>

                    </td>

                </tr>

            <?php } ?>

        </tbody>

    </table>

</body>

</html>