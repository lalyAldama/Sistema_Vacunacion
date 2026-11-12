<?php
require_once __DIR__ . "/../config/seguridad_administrador.php";
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
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestión de Biológicos</title>
    <link rel="stylesheet" href="../css/estilos.css">
    <link rel="stylesheet" href="../css/gestion_admin.css">
</head>

<body class="gestion-admin">
    <main class="gestion-contenedor">
        <header class="gestion-encabezado">
            <div class="gestion-encabezado-texto">
                <span class="gestion-etiqueta">ADMINISTRACIÓN</span>
                <h1>Gestión de biológicos</h1>
                <p>Administra los biológicos y sus presentaciones.</p>
            </div>
        </header>

        <nav class="gestion-acciones" aria-label="Acciones de biológicos">
            <a class="btn" href="guardar.php">+ Nuevo biológico</a>
            <a class="btn gestion-btn-menta" href="guardar_presentacion.php">+ Nueva presentación</a>
        </nav>

        <div class="gestion-tabla-contenedor">
        <table class="gestion-tabla">

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
                        <?php echo htmlspecialchars($biologico['nombre_biologico'], ENT_QUOTES, "UTF-8"); ?>
                    </td>

                    <td>
                        <?php
                        if ($biologico['nombre_presentacion'] !== null) {
                            echo htmlspecialchars($biologico['nombre_presentacion'], ENT_QUOTES, "UTF-8");
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
                        <div class="gestion-tabla-acciones">
                        <a class="btn" href="editar.php?id=<?php echo (int)$biologico['id_biologico']; ?>">
                            Editar biológico
                        </a>

                        <a class="btn btn-peligro" href="eliminar.php?id=<?php echo (int)$biologico['id_biologico']; ?>"
                           onclick="return confirm('¿Seguro que quieres eliminar este biológico?');">
                            Eliminar biológico
                        </a>

                        <?php if ($biologico['id_presentacion'] !== null) { ?>
                            <a class="btn" href="editar_presentacion.php?id=<?php echo (int)$biologico['id_presentacion']; ?>">
                                Editar presentación
                            </a>
                            <a class="btn btn-peligro" href="eliminar_presentacion.php?id=<?php echo (int)$biologico['id_presentacion']; ?>"
                               onclick="return confirm('¿Seguro que quieres eliminar esta presentación?');">
                                Eliminar presentación
                            </a>
                        <?php } ?>
                        </div>

                    </td>

                </tr>

            <?php } ?>

        </tbody>

        </table>
        </div>
    </main>

</body>

</html>