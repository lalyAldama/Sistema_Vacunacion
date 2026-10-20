<?php
session_start();

if(!isset($_SESSION["id_usuario"])) {
    header("Location: ../login.php");
    exit();
}

require_once "../config/conexion.php";

// =========================================================
// VERIFICAR PACIENTE
// =========================================================

if(!isset($_GET["id"]) || empty($_GET["id"])) {
    header("Location: index.php");
    exit();
}

$id_paciente = $_GET["id"];


// =========================================================
// OBTENER PACIENTE + CENSO + ESQUEMA
// =========================================================

$sqlPaciente = "SELECT
                    p.id_paciente,
                    p.ap_pat,
                    p.ap_mat,
                    p.nombres,
                    p.fecha_nacimiento,
                    p.sexo,
                    p.curp,
                    c.id_censo,
                    c.nombre_censo,
                    e.id_esquema,
                    e.nombre_esquema
                FROM PACIENTE p
                INNER JOIN PACIENTE_CENSO pc
                    ON p.id_paciente = pc.id_paciente
                INNER JOIN CENSO c
                    ON pc.id_censo = c.id_censo
                INNER JOIN ESQUEMA e
                    ON c.id_esquema = e.id_esquema
                WHERE p.id_paciente = ?";

$stmtPaciente = $conexion->prepare($sqlPaciente);

if(!$stmtPaciente) {
    die("Error al preparar consulta del paciente: " . $conexion->error);
}

$stmtPaciente->bind_param("s", $id_paciente);
$stmtPaciente->execute();

$resultadoPaciente = $stmtPaciente->get_result();

if($resultadoPaciente->num_rows === 0) {
    die("Paciente no encontrado o no tiene un censo asignado.");
}

$paciente = $resultadoPaciente->fetch_assoc();

$stmtPaciente->close();


// =========================================================
// OBTENER VACUNAS DEL ESQUEMA DEL PACIENTE
// =========================================================

$sqlVacunas = "SELECT DISTINCT
                    b.id_biologico,
                    b.nombre_biologico
               FROM ESQUEMA_BIOLOGICO eb
               INNER JOIN BIOLOGICO b
                    ON eb.id_biologico = b.id_biologico
               WHERE eb.id_esquema = ?
               ORDER BY b.nombre_biologico";

$stmtVacunas = $conexion->prepare($sqlVacunas);

if(!$stmtVacunas) {
    die("Error al preparar consulta de vacunas: " . $conexion->error);
}

$stmtVacunas->bind_param("i", $paciente["id_esquema"]);
$stmtVacunas->execute();

$resultadoVacunas = $stmtVacunas->get_result();


// =========================================================
// OBTENER TODAS LAS RELACIONES ESQUEMA-BIOLOGICO-DOSIS
// =========================================================

$sqlDosis = "SELECT
                ebd.id_esquema_biologico_dosis,
                eb.id_esquema,
                eb.id_biologico,
                d.id_dosis,
                d.nombre_dosis
             FROM ESQUEMA_BIOLOGICO_DOSIS ebd
             INNER JOIN ESQUEMA_BIOLOGICO eb
                  ON ebd.id_esquema_biologico = eb.id_esquema_biologico
             INNER JOIN DOSIS d
                  ON ebd.id_dosis = d.id_dosis
             WHERE eb.id_esquema = ?
             ORDER BY eb.id_biologico, ebd.id_esquema_biologico_dosis";

$stmtDosis = $conexion->prepare($sqlDosis);

if(!$stmtDosis) {
    die("Error al preparar consulta de dosis: " . $conexion->error);
}

$stmtDosis->bind_param("i", $paciente["id_esquema"]);
$stmtDosis->execute();

$resultadoDosis = $stmtDosis->get_result();


// =========================================================
// OBTENER LOTES
// =========================================================

$sqlLotes = "SELECT
                l.id_lote,
                l.numero_lote,
                l.fecha_caducidad,
                p.id_biologico,
                b.nombre_biologico,
                p.nombre_presentacion
             FROM LOTE l
             INNER JOIN PRESENTACION p
                  ON l.id_presentacion = p.id_presentacion
             INNER JOIN BIOLOGICO b
                  ON p.id_biologico = b.id_biologico
             ORDER BY b.nombre_biologico, l.fecha_caducidad";

$resultadoLotes = $conexion->query($sqlLotes);

if(!$resultadoLotes) {
    die("Error al consultar lotes: " . $conexion->error);
}


// =========================================================
// OBTENER BIOLOGICOS
// =========================================================

$sqlBiologicos = "SELECT
                    id_biologico,
                    nombre_biologico
                  FROM BIOLOGICO
                  ORDER BY nombre_biologico";

$resultadoBiologicos = $conexion->query($sqlBiologicos);

if(!$resultadoBiologicos) {
    die("Error al consultar biológicos: " . $conexion->error);
}


// =========================================================
// OBTENER UNIDAD DEL USUARIO
// =========================================================

$sqlUsuario = "SELECT
                    u.id_unidad,
                    un.nombre_unidad
               FROM USUARIO u
               LEFT JOIN UNIDAD un
                    ON u.id_unidad = un.id_unidad
               WHERE u.id_usuario = ?";

$stmtUsuario = $conexion->prepare($sqlUsuario);

if(!$stmtUsuario) {
    die("Error al consultar unidad: " . $conexion->error);
}

$stmtUsuario->bind_param("i", $_SESSION["id_usuario"]);
$stmtUsuario->execute();

$resultadoUsuario = $stmtUsuario->get_result();

$usuario = $resultadoUsuario->fetch_assoc();

$stmtUsuario->close();

if(empty($usuario["id_unidad"])) {
    die("El usuario no tiene una unidad asignada.");
}


// =========================================================
// GUARDAR APLICACIÓN
// =========================================================

if($_SERVER["REQUEST_METHOD"] == "POST") {

    $id_biologico = $_POST["id_biologico"] ?? "";
    $id_esquema_biologico_dosis = $_POST["id_esquema_biologico_dosis"] ?? "";
    $id_lote = $_POST["id_lote"] ?? "";
    $fecha_aplicacion = $_POST["fecha_aplicacion"] ?? "";
    $dosis_cero = $_POST["dosis_cero"] ?? "";
    $id_biologico_indicado = $_POST["id_biologico_indicado"] ?? "";
    $observaciones = $_POST["observaciones"] ?? "";


    // =====================================================
    // VALIDAR DATOS OBLIGATORIOS
    // =====================================================

    if(
        empty($id_biologico) ||
        empty($id_esquema_biologico_dosis) ||
        empty($id_lote) ||
        empty($fecha_aplicacion) ||
        $dosis_cero === ""
    ) {
        die("Faltan datos obligatorios para registrar la aplicación.");
    }


    // =====================================================
    // BIOLOGICO INDICADO
    // =====================================================

    if($id_biologico_indicado === "") {
        $id_biologico_indicado = null;
    }


    // =====================================================
    // VALIDAR QUE LA VACUNA PERTENEZCA AL ESQUEMA
    // =====================================================

    $sqlValidar = "SELECT
                        ebd.id_esquema_biologico_dosis
                   FROM ESQUEMA_BIOLOGICO_DOSIS ebd
                   INNER JOIN ESQUEMA_BIOLOGICO eb
                        ON ebd.id_esquema_biologico = eb.id_esquema_biologico
                   WHERE ebd.id_esquema_biologico_dosis = ?
                   AND eb.id_esquema = ?
                   AND eb.id_biologico = ?";

    $stmtValidar = $conexion->prepare($sqlValidar);

    if(!$stmtValidar) {
        die("Error al validar vacuna: " . $conexion->error);
    }

    $stmtValidar->bind_param(
        "iii",
        $id_esquema_biologico_dosis,
        $paciente["id_esquema"],
        $id_biologico
    );

    $stmtValidar->execute();

    $resultadoValidar = $stmtValidar->get_result();

    if($resultadoValidar->num_rows === 0) {
        die("La vacuna o dosis seleccionada no corresponde al esquema del paciente.");
    }

    $stmtValidar->close();


    // =====================================================
    // VALIDAR QUE EL LOTE CORRESPONDA A LA VACUNA
    // =====================================================

    $sqlValidarLote = "SELECT
                            l.id_lote
                       FROM LOTE l
                       INNER JOIN PRESENTACION p
                            ON l.id_presentacion = p.id_presentacion
                       WHERE l.id_lote = ?
                       AND p.id_biologico = ?";

    $stmtValidarLote = $conexion->prepare($sqlValidarLote);

    if(!$stmtValidarLote) {
        die("Error al validar lote: " . $conexion->error);
    }

    $stmtValidarLote->bind_param(
        "ii",
        $id_lote,
        $id_biologico
    );

    $stmtValidarLote->execute();

    $resultadoValidarLote = $stmtValidarLote->get_result();

    if($resultadoValidarLote->num_rows === 0) {
        die("El lote seleccionado no corresponde a la vacuna seleccionada.");
    }

    $stmtValidarLote->close();


    // =====================================================
    // INSERTAR APLICACIÓN
    // =====================================================

    $sqlInsertar = "INSERT INTO APLICACION_VACUNA (
                        id_paciente,
                        id_esquema_biologico_dosis,
                        id_lote,
                        id_unidad,
                        id_biologico_indicado,
                        fecha_aplicacion,
                        dosis_cero,
                        observaciones
                    )
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)";

    $stmtInsertar = $conexion->prepare($sqlInsertar);

    if(!$stmtInsertar) {
        die("Error al preparar registro: " . $conexion->error);
    }

    $stmtInsertar->bind_param(
        "siiiisis",
        $id_paciente,
        $id_esquema_biologico_dosis,
        $id_lote,
        $usuario["id_unidad"],
        $id_biologico_indicado,
        $fecha_aplicacion,
        $dosis_cero,
        $observaciones
    );

    if(!$stmtInsertar->execute()) {
        die("Error al registrar aplicación: " . $stmtInsertar->error);
    }

    $stmtInsertar->close();

    echo "<script>
            alert('APLICACIÓN REGISTRADA CORRECTAMENTE');
            window.location.href='index.php';
          </script>";

    exit();
}
?>

<!DOCTYPE html>

<html lang="es">

<head>

    <meta charset="UTF-8">

    <title>REGISTRAR APLICACIÓN</title>
    <link rel="stylesheet" href="../css/estilos.css">

</head>

<body>

<h1>REGISTRAR APLICACIÓN DE VACUNA</h1>


<!-- =====================================================
     DATOS DEL PACIENTE
===================================================== -->

<h2>DATOS DEL PACIENTE</h2>

<p>

    <strong>NOMBRE:</strong>

    <?php

    echo htmlspecialchars(
        $paciente["ap_pat"] . " " .
        $paciente["ap_mat"] . " " .
        $paciente["nombres"]
    );

    ?>

</p>


<p>

    <strong>FECHA DE NACIMIENTO:</strong>

    <?php

    echo htmlspecialchars(
        $paciente["fecha_nacimiento"]
    );

    ?>

</p>


<p>

    <strong>SEXO:</strong>

    <?php

    echo htmlspecialchars(
        $paciente["sexo"]
    );

    ?>

</p>


<p>

    <strong>CURP:</strong>

    <?php

    echo htmlspecialchars(
        $paciente["curp"] ?? ""
    );

    ?>

</p>
<hr>


<!-- =====================================================
     DATOS DE LA APLICACIÓN
===================================================== -->

<h2>DATOS DE LA APLICACIÓN</h2>


<form method="POST">


    <!-- =================================================
         VACUNA
    ================================================== -->

    <label>

        <strong>VACUNA:</strong>

    </label>

    <br>


    <select
        name="id_biologico"
        id="vacuna"
        required
    >

        <option value="">

            SELECCIONE UNA VACUNA

        </option>


        <?php while($vacuna = $resultadoVacunas->fetch_assoc()): ?>

            <option
                value="<?php echo $vacuna["id_biologico"]; ?>"
            >

                <?php

                echo htmlspecialchars(
                    $vacuna["nombre_biologico"]
                );

                ?>

            </option>

        <?php endwhile; ?>

    </select>


    <br>
    <br>


    <!-- =================================================
         DOSIS
    ================================================== -->

    <label>

        <strong>DOSIS:</strong>

    </label>

    <br>


    <select
        name="id_esquema_biologico_dosis"
        id="dosis"
        required
        disabled
    >

        <option value="">

            PRIMERO SELECCIONE UNA VACUNA

        </option>

    </select>


    <br>
    <br>


    <!-- =================================================
         LOTE
    ================================================== -->

    <label>

        <strong>LOTE:</strong>

    </label>

    <br>


    <select
        name="id_lote"
        id="lote"
        required
        disabled
    >

        <option value="">

            PRIMERO SELECCIONE UNA VACUNA

        </option>

    </select>


    <br>
    <br>


    <!-- =================================================
         FECHA DE APLICACIÓN
    ================================================== -->

    <label>

        <strong>FECHA DE APLICACIÓN:</strong>

    </label>

    <br>


    <input
        type="date"
        name="fecha_aplicacion"
        value="<?php echo date('Y-m-d'); ?>"
        required
    >


    <br>
    <br>


    <!-- =================================================
         DOSIS CERO
    ================================================== -->

    <label>

        <strong>¿DOSIS CERO?</strong>

    </label>

    <br>


    <select
        name="dosis_cero"
        required
    >

        <option value="">

            SELECCIONE

        </option>


        <option value="0">

            NO

        </option>


        <option value="1">

            SÍ

        </option>

    </select>


    <br>
    <br>


    <!-- =================================================
         BIOLOGICO INDICADO
    ================================================== -->

    <label>

        <strong>BIOLOGICO INDICADO:</strong>

    </label>

    <br>


    <select name="id_biologico_indicado">

        <option value="">

            NO APLICA

        </option>


        <?php while($biologico = $resultadoBiologicos->fetch_assoc()): ?>

            <option
                value="<?php echo $biologico["id_biologico"]; ?>"
            >

                <?php

                echo htmlspecialchars(
                    $biologico["nombre_biologico"]
                );

                ?>

            </option>

        <?php endwhile; ?>

    </select>


    <br>
    <br>


    <!-- =================================================
         OBSERVACIONES
    ================================================== -->

    <label>

        <strong>OBSERVACIONES:</strong>

    </label>

    <br>


    <textarea
        name="observaciones"
        rows="5"
        cols="50"
        maxlength="500"
    ></textarea>


    <br>
    <br>


    <button type="submit">

        GUARDAR APLICACIÓN

    </button>


</form>


<br>


<a href="index.php">

    <button type="button">

        REGRESAR

    </button>

</a>

<script>
    const datosDosis = <?php
        $dosisArray = [];

        while($dosis = $resultadoDosis->fetch_assoc()) {
            $dosisArray[] = [
                "id_esquema_biologico_dosis" => $dosis["id_esquema_biologico_dosis"],
                "id_biologico" => $dosis["id_biologico"],
                "id_dosis" => $dosis["id_dosis"],
                "nombre_dosis" => $dosis["nombre_dosis"]
            ];
        }

        echo json_encode($dosisArray);
    ?>;

    const datosLotes = <?php
        $lotesArray = [];

        while($lote = $resultadoLotes->fetch_assoc()) {
            $lotesArray[] = [
                "id_lote" => $lote["id_lote"],
                "id_biologico" => $lote["id_biologico"],
                "numero_lote" => $lote["numero_lote"],
                "fecha_caducidad" => $lote["fecha_caducidad"],
                "nombre_biologico" => $lote["nombre_biologico"],
                "nombre_presentacion" => $lote["nombre_presentacion"]
            ];
        }

        echo json_encode($lotesArray);
    ?>;
</script>

<script src="js/vacunacion.js"></script>




</body>

</html>