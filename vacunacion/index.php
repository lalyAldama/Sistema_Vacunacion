<?php
session_start();

if(!isset($_SESSION["id_usuario"])){
    header("Location: ../login.php");
    exit();
}

require_once "../config/conexion.php";

$resultados= null;
$busqueda= "";
$stmt = null;

if(isset($_GET["buscar"])) {
    $busqueda = trim($_GET["buscar"]);

    if($busqueda !== ""){
        $termino = "%" . $busqueda . "%";

        $sql = "SELECT id_paciente, ap_pat, ap_mat, nombres, fecha_nacimiento, curp
                FROM PACIENTE WHERE ap_pat LIKE ? OR ap_mat LIKE ? OR nombres LIKE ? OR curp LIKE ?
                ORDER BY  ap_pat, ap_mat, nombres";
        $stmt = $conexion->prepare($sql);

        $stmt->bind_param( "ssss", $termino, $termino, $termino, $termino);
        $stmt->execute();
        $resultados = $stmt->get_result();
    }
}
?>

<!DOCTYPE html>
<html lang="es">
    <head>
        <meta charset="UTF-8">
        <title>REGISTRO DE APLICACIÓN DE VACUNAS</title>
    </head>

    <body>
        <h1>REGISTRAR APLICACIÓN DE VACUNAS</h1>
        <h2>BUSCAR PACIENTE</h2>

        <form method="GET" action="index.php">
            <input type="text" name="buscar" placeholder="Nombre, apellidos", 
            value="<?php echo htmlspecialchars($busqueda); ?>" required>

            <button type="submit">BUSCAR</button>
        </form>

       <?php if ($resultados !== null): ?>
        <h2>RESULTADOS</h2>
        <?php if($resultados->num_rows > 0): ?>
            <table border="1" cellpadding="8">
                <tr>
                    <th>Nombre Completo:</th>
                    <th>Fecha de Nacimiento</th>
                    <th>CURP</th>
                    <th>ACCION</th>
                </tr>

                <?php while($paciente = $resultados->fetch_assoc()) : ?>
                    <tr>
                        <td>
                            <?php
                            echo htmlspecialchars(
                                $paciente["ap_pat"] .  ""  . $paciente["ap_mat"] .  ""  .  $paciente["nombres"]
                            );
                            ?>
                        </td>

                        <td><?php echo htmlspecialchars($paciente["fecha_nacimiento"]);
                        ?>
                        </td>

                        <td>
                            <?php echo htmlspecialchars($paciente["curp"] ?? ""); ?>
                        </td>

                        <td>
                            <a href="registrar_vacuna.php?id=<?php echo urlencode($paciente["id_paciente"]);?>">
                                REGISTRAR APLICACIÓN
                            </a>
                        </td>
                    </tr>
                    <?php endwhile; ?>
            </table>

            <?php else: ?>
                <p>No se encontraron pacientes</p>
            <?php endif; ?>

            <?php $stmt->close(); ?>

            <?php endif; ?>

            <br>
            <a href="../censo/index.php">
                <button type="button"> REGRESAR AL CENSO </button>
            </a>
    </body>
</html>