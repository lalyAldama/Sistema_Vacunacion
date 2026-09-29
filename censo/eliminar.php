<?php
session_start();

if(!isset($_SESSION["id_usuario"])) {
    header("Location: ../login.php ");
    exit();
}

require_once "../config/conexion.php";

$resultados = null;
$busqueda= "";
$paciente_seleccionado = null;

if(isset($_GET["id"])){
    $id_paciente= $_GET["id"];
    $sql_paciente = "SELECT id_paciente, ap_pat, ap_mat, nombres, fecha_nacimiento, sexo, curp
                    FROM PACIENTE 
                    WHERE id_paciente= ?";
    $stmt_paciente = $conexion->prepare($sql_paciente);
    $stmt_paciente->bind_param(
        "s",
        $id_paciente
    );

    $stmt_paciente->execute();
    $resultado_paciente = $stmt_paciente->get_result();
    
    if($resultado_paciente->num_rows > 0){
        $paciente_seleccionado= $resultado_paciente->fetch_assoc();

    }
    $stmt_paciente->close();
}

if(isset($_GET["buscar"])){
    $busqueda = trim($_GET["buscar"]);

    if($busqueda !== ""){

    $sql = "SELECT 
            id_paciente,ap_pat,ap_mat,nombres, fecha_nacimiento, curp
            FROM PACIENTE
            WHERE ap_pat LIKE ?
            OR ap_mat LIKE ?
            OR nombres LIKE ? 
            OR curp LIKE ?
            ORDER BY ap_pat, ap_mat, nombres";
    $stmt = $conexion->prepare($sql);
    $termino = "%". $busqueda. "%";

    $stmt->bind_param(
        "ssss",
        $termino,
        $termino,
        $termino,
        $termino
    );
    $stmt->execute();
    $resultados = $stmt->get_result();

    $stmt->close();
    }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>ELIMINAR PACIENTE</title>
</head>

<body>
    <h1>ELIMINAR PACIENTE</h1>
    <form method="GET">
        <label>BUSCAR PACIENTE</label><br>

        <input type="text" name="buscar" value="<?=htmlspecialchars($busqueda)?>" placeholder="Nombre, apellido">
        <button type="submit"> BUSCAR</button>
    </form>
    <br>

    <?php if($paciente_seleccionado !== null): ?>
        <h2>PACIENTE SELECCIONADO</h2>

        <p>
            <strong>NOMBRE</strong>
            <?= htmlspecialchars(
                $paciente_seleccionado["nombres"]. " ". $paciente_seleccionado["ap_pat"]. " " . $paciente_seleccionado["ap_mat"]
            ) ?>
        </p>

        <p>
            <strong>FECHA DE NACIMIENTO</strong>
            <?= htmlspecialchars(
                $paciente_seleccionado["fecha_nacimiento"]
            ) ?>
        </p>

        <p>
            <strong>SEXO: </strong>
            <?= htmlspecialchars(
                $paciente_seleccionado["sexo"]
            ) ?>
        </p>

        <p>
            <strong>CURP: </strong>
            <?= htmlspecialchars(
                $paciente_seleccionado["curp"] ?? "NO REGISTRADA"
            ) ?> 
        </p>
        <br>

        <a href="eliminar.php?id=<?= urlencode($paciente_seleccionado["id_paciente"]) ?>&confirmar=1">
            <button type="button">ELIMINAR PACIENTE</button>
        </a>
    <?php endif; ?>

    <?php if ($resultados !== null): ?>
        <?php if ($resultados->num_rows >0): ?>

        <h2>RESULTADOS</h2>
        <?php while ($paciente = $resultados->fetch_assoc()): ?>
            <div>
                <strong>
                    <?= htmlspecialchars(
                        $paciente["nombres"] . " ". $paciente["ap_pat"]. " ". $paciente["ap_mat"]
                    ) ?>
                </strong>
                <br>

                Fecha de nacimiento:
                <?= htmlspecialchars($paciente["fecha_nacimiento"]) ?>
                <br>

                CURP:
                <?=  htmlspecialchars($paciente["curp"] ?? "NO REGISTRADA") ?>
                <br><br>

                <a href="eliminar.php?id=<?= urlencode($paciente["id_paciente"]) ?>">
                    <button type="button">SELECCIONAR PACIENTE</button>
                </a>
            </div>
            <hr>
        <?php endwhile; ?>

        <?php else: ?>
            <p>NO SE ENCONTRARON PACIENTES.</p>
        <?php endif; ?>
    <?php endif; ?>

    <br>
    
    <a href="index.php">
        <button type="button">CANCELAR</button>
    </a>
</body>
 
</html>
<?php
$conexion->close();
?>