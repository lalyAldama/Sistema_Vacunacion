
<?php
session_start();

if (!isset($_SESSION["id_usuario"])) {
    header("Location: ../login.php");
    exit();
}

require_once "../config/conexion.php";

if($_SERVER["REQUEST_METHOD"] === "POST") {
    $id_paciente = $_GET["id"];
    $ap_pat = strtoupper(trim($_POST["ap_pat"]));
    $ap_mat = strtoupper(trim($_POST["ap_mat"]));
    $nombres = strtoupper(trim($_POST["nombres"]));

    $curp = !empty($_POST["curp"])
        ?strtoupper(trim($_POST["curp"]))
        : null;
    $derechoabiencia= $_POST["derechoabiencia"];
    $poblacion = $_POST["poblacion_indigena_afromexicana"];
    $es_migrante = $_POST["es_migrante"];

    if($es_migrante === "1"){
        $estatus_migratorio = $_POST["estatus_migratorio"];
    } else {
        $estatus_migratorio = "No aplica";
    }

    $calle = strtoupper(trim($_POST["calle"]));
    $num = trim($_POST["num"]);
    $colonia = strtoupper(trim($_POST["colonia"]));
    $telefono_paciente = !empty($_POST["telefono_paciente"])
        ? trim($_POST["telefono_paciente"])
        : null;
    
    $personal_salud = trim($_POST["personal_salud"]);
    $jornalero_agricola = trim($_POST["jornalero_agricola"]);

    $embarazo = isset($_POST["embarazo"]) && $_POST["embarazo"] !== ""
        ? (int)$_POST["embarazo"]
        : 0;

    $fecha_ultima_menstruacion = 
        !empty($_POST["fecha_ultima_menstruacion"])
        ? $_POST["fecha_ultima_menstruacion"]
        : null;
    $sqlTutorActual = "SELECT id_tutor FROM PACIENTE 
                        WHERE id_paciente = ?";
    $stmtTutorActual = $conexion->prepare($sqlTutorActual);
    $stmtTutorActual->bind_param("s", $id_paciente);
    $stmtTutorActual->execute();

    $resultado_tutorActual = $stmtTutorActual->get_result();
    $datos_tutorActual = $resultado_tutorActual->fetch_assoc();

    $id_tutor = $datos_tutorActual["id_tutor"] ?? null;
    $stmtTutorActual->close();
    
    //ACTUALIZAR TUTOR

    if(!empty($id_tutor)){
        $tutor_ap_pat= strtoupper(trim($_POST["tutor_ap_pat"] ?? ""));
        $tutor_ap_mat= strtoupper(trim($_POST["tutor_ap_mat"] ?? ""));
        $tutor_nombre= strtoupper(trim($_POST["tutor_nombre"] ?? ""));

        $tutor_fecha_nacimiento= $_POST["tutor_fecha_nacimiento"] ?? null;

        $calle_tutor = strtoupper(trim($_POST["calle_tutor"] ?? ""));
        $num_tutor= trim($_POST["num_tutor"] ?? "");
        $colonia_tutor= strtoupper(trim($_POST["colonia_tutor"] ?? ""));
        $tutor_sexo= $_POST["tutor_sexo"] ?? "";

        $tutor_curp = !empty ($_POST["tutor_curp"])
            ? strtoupper(trim($_POST["tutor_curp"]))
            : null;
        $tutor_telefono = trim($_POST["tutor_telefono"] ?? "");

        $sqlTutor= "UPDATE TUTOR SET ap_pat= ?, ap_mat = ?, nombres= ?, fecha_nacimiento = ?,
                    telefono_tutor= ?, calle_tutor= ?, num_tutor= ?, colonia_tutor= ?, sexo =?,
                    curp =? WHERE id_tutor = ? ";

        $stmtTutor = $conexion->prepare($sqlTutor);
        $stmtTutor->bind_param(
            "ssssssssssi",
            $tutor_ap_pat, $tutor_ap_mat, $tutor_nombre, $tutor_fecha_nacimiento, $tutor_telefono,
            $calle_tutor, $num_tutor, $colonia_tutor, $tutor_sexo, $tutor_curp, $id_tutor
        );

        $stmtTutor->execute();
        $stmtTutor->close();
    }

    //ACTUALIZAR PARENTESCO
    $parentesco = $_POST["parentesco"] ?? "No aplica";

    $sqlParentesco= "UPDATE PACIENTE SET parentesco = ? WHERE id_paciente= ?";
    $stmtParentesco = $conexion->prepare($sqlParentesco);

    $stmtParentesco->bind_param("ss", $parentesco, $id_paciente);
    $stmtParentesco->execute();
    $stmtParentesco->close();

    //ACTUALIZAR COMORBILIDADES

    $sqlEliminarComor= "DELETE FROM PACIENTE_COMORBILIDAD WHERE id_paciente = ?";
    $stmtEliminarComor= $conexion->prepare($sqlEliminarComor);
    $stmtEliminarComor->bind_param("s", $id_paciente);

    $stmtEliminarComor->execute();
    $stmtEliminarComor->close();

    if(!empty ($_POST["comorbilidades"])) {
        $sqlInsertarComor= "INSERT INTO PACIENTE_COMORBILIDAD (id_paciente, id_comorbilidad)
                            VALUES (?,?) ";
        $stmtInsertarComor= $conexion->prepare($sqlInsertarComor);
        foreach ($_POST["comorbilidades"] as $id_comorbilidad) {
            $id_comorbilidad = (int)$id_comorbilidad;

            $stmtInsertarComor->bind_param("si", $id_paciente, $id_comorbilidad);

            $stmtInsertarComor->execute();
        }
        $stmtInsertarComor->close();
    }

    //ACTUALIZAR PACIENTE
    $sqlActualizar = "UPDATE PACIENTE SET
                    ap_pat = ?, ap_mat=?, nombres =?, curp= ?, derechoabiencia= ?,
                    poblacion_indigena_afromexicana= ?, estatus_migratorio = ?,
                    calle= ?, num= ?, colonia= ?, telefono_paciente = ?, personal_salud = ?,
                    jornalero_agricola = ?, embarazo = ?, fecha_ultima_menstruacion= ?
                    WHERE id_paciente =  ?";
    $stmtActualizar = $conexion->prepare($sqlActualizar);

    $stmtActualizar->bind_param(
        "ssssssssssssssss",
        $ap_pat, $ap_mat, $nombres, $curp, $derechoabiencia, $poblacion, $estatus_migratorio,
        $calle, $num, $colonia, $telefono_paciente, $personal_salud, $jornalero_agricola,
        $embarazo, $fecha_ultima_menstruacion, $id_paciente);
    
    $stmtActualizar->execute();
    $stmtActualizar->close();

    echo "<script>
        alert('PACIENTE ACTUALIZADO CORRECTAMENTE');
        window.location.href = 'index.php';
        </script>";
    exit;

}

if (empty($_GET["id"])) {
    die("No se seleccionó ningún paciente.");
}

$id_paciente = $_GET["id"];

function mostrar($valor) {
    return htmlspecialchars((string)($valor ?? ""), ENT_QUOTES, "UTF-8");
}

$sql = "SELECT p.*, 
               t.id_tutor AS tutor_id,
               t.ap_pat AS tutor_ap_pat,
               t.ap_mat AS tutor_ap_mat,
               t.nombres AS tutor_nombres,
               t.fecha_nacimiento AS tutor_fecha_nacimiento,
               t.telefono_tutor,
               t.calle_tutor,
               t.num_tutor,
               t.colonia_tutor,
               t.sexo AS tutor_sexo,
               t.curp AS tutor_curp
        FROM PACIENTE p
        LEFT JOIN TUTOR t ON p.id_tutor = t.id_tutor
        WHERE p.id_paciente = ?";

$stmt = $conexion->prepare($sql);
$stmt->bind_param("s", $id_paciente);
$stmt->execute();
$resultado = $stmt->get_result();
$paciente = $resultado->fetch_assoc();
$stmt->close();

if (!$paciente) {
    die("No se encontró el paciente.");
}

// Obtener las comorbilidades que ya tiene el paciente
$comorbilidadesPaciente = [];

$sqlPacienteComorb = "SELECT id_comorbilidad
                      FROM PACIENTE_COMORBILIDAD
                      WHERE id_paciente = ?";

$stmtComorb = $conexion->prepare($sqlPacienteComorb);
$stmtComorb->bind_param("s", $id_paciente);
$stmtComorb->execute();
$resultadoComorb = $stmtComorb->get_result();

while ($fila = $resultadoComorb->fetch_assoc()) {
    $comorbilidadesPaciente[] = (int)$fila["id_comorbilidad"];
}
$stmtComorb->close();

// Obtener todas las comorbilidades disponibles
$sqlComorb = "SELECT id_comorbilidad, nombre_comorbilidad
              FROM COMORBILIDAD
              ORDER BY nombre_comorbilidad";
$resultadoComorb = $conexion->query($sqlComorb);

// El formulario de registro no guarda es_migrante directamente.
// Se deduce a partir del estatus migratorio.
$esMigrante = (
    !empty($paciente["estatus_migratorio"]) &&
    strtolower(trim($paciente["estatus_migratorio"])) !== "no aplica"
) ? "1" : "0";

?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Editar paciente</title>
    <style>
        body {
            text-transform: uppercase;
        }
    </style>
</head>

<body>

<h1>EDITAR PACIENTE</h1>

<form method="POST" action="editar.php?id=<?php echo urlencode($id_paciente); ?>">

    <h2>DATOS DEL PACIENTE</h2>

    <label>APELLIDO PATERNO:</label><br>
    <input type="text" name="ap_pat" value="<?php echo mostrar($paciente["ap_pat"]); ?>" required>
    <br><br>

    <label>APELLIDO MATERNO:</label><br>
    <input type="text" name="ap_mat" value="<?php echo mostrar($paciente["ap_mat"]); ?>" required>
    <br><br>

    <label>NOMBRE(S):</label><br>
    <input type="text" name="nombres" value="<?php echo mostrar($paciente["nombres"]); ?>" required>
    <br><br>

    <label>FECHA DE NACIMIENTO:</label><br>
    <input disabled type="date" name="fecha_nacimiento" id="fecha_nacimiento"
           value="<?php echo mostrar($paciente["fecha_nacimiento"]); ?>"  readonly>

    <p>Edad: <span id="edad">--</span></p>
    <p>Censo: <span id="tipo_censo">--</span></p>

    <label>SEXO:</label><br>
    <select name="sexo" disabled required >
        <option value="">SELECCIONE...</option>
        <option value="F" <?php echo $paciente["sexo"] === "F" ? "selected" : ""; ?>>FEMENINO</option>
        <option value="M" <?php echo $paciente["sexo"] === "M" ? "selected" : ""; ?>>MASCULINO</option>
    </select>
    <br><br>

    <label>CURP:</label><br>
    <input type="text" name="curp" maxlength="18" value="<?php echo mostrar($paciente["curp"]); ?>">
    <br><br>

    <label>DERECHOHABIENCIA:</label><br>
    <select name="derechoabiencia" required>
        <option value="">SELECCIONE...</option>
        <option value="1" <?php echo (string)$paciente["derechoabiencia"] === "1" ? "selected" : ""; ?>>SECRETARÍA DE SALUD</option>
        <option value="2" <?php echo (string)$paciente["derechoabiencia"] === "2" ? "selected" : ""; ?>>IMSS</option>
        <option value="3" <?php echo (string)$paciente["derechoabiencia"] === "3" ? "selected" : ""; ?>>ISSSTE</option>
        <option value="4" <?php echo (string)$paciente["derechoabiencia"] === "4" ? "selected" : ""; ?>>IMSS BIENESTAR</option>
        <option value="5" <?php echo (string)$paciente["derechoabiencia"] === "5" ? "selected" : ""; ?>>SEDENA</option>
        <option value="6" <?php echo (string)$paciente["derechoabiencia"] === "6" ? "selected" : ""; ?>>SEMAR</option>
        <option value="7" <?php echo (string)$paciente["derechoabiencia"] === "7" ? "selected" : ""; ?>>PEMEX</option>
        <option value="8" <?php echo (string)$paciente["derechoabiencia"] === "8" ? "selected" : ""; ?>>DIF</option>
        <option value="9" <?php echo (string)$paciente["derechoabiencia"] === "9" ? "selected" : ""; ?>>OTROS</option>
    </select>
    <br><br>

    <label>¿PERTENECE A POBLACIÓN INDÍGENA O AFROMEXICANA?</label><br>
    <select name="poblacion_indigena_afromexicana" required>
        <option value="">SELECCIONE...</option>
        <option value="1" <?php echo (string)$paciente["poblacion_indigena_afromexicana"] === "1" ? "selected" : ""; ?>>SÍ</option>
        <option value="0" <?php echo (string)$paciente["poblacion_indigena_afromexicana"] === "0" ? "selected" : ""; ?>>NO</option>
    </select>
    <br><br>

    <label>¿ES MIGRANTE?</label><br>
    <select name="es_migrante" id="es_migrante" required>
        <option value="">SELECCIONE...</option>
        <option value="1" <?php echo $esMigrante === "1" ? "selected" : ""; ?>>SÍ</option>
        <option value="0" <?php echo $esMigrante === "0" ? "selected" : ""; ?>>NO</option>
    </select>
    <br><br>

    <div id="campo_estatus_migratorio" style="display:none;">
        <label>ESTATUS MIGRATORIO:</label><br>
        <select name="estatus_migratorio" id="estatus_migratorio">
            <option value="">SELECCIONE...</option>
            <option value="Regular" <?php echo $paciente["estatus_migratorio"] === "Regular" ? "selected" : ""; ?>>REGULAR</option>
            <option value="Irregular" <?php echo $paciente["estatus_migratorio"] === "Irregular" ? "selected" : ""; ?>>IRREGULAR</option>
        </select>
        <br><br>
    </div>

    <h2>DOMICILIO</h2>

    <label>CALLE:</label><br>
    <input type="text" name="calle" value="<?php echo mostrar($paciente["calle"]); ?>" required>
    <br><br>

    <label>NÚMERO:</label><br>
    <input type="text" name="num" inputmode="numeric" value="<?php echo mostrar($paciente["num"]); ?>" required>
    <br><br>

    <label>COLONIA:</label><br>
    <input type="text" name="colonia" value="<?php echo mostrar($paciente["colonia"]); ?>" required>
    <br><br>

    <h2>TELÉFONO</h2>
    <input type="tel" name="telefono_paciente" id="telefono_paciente"
           maxlength="12" inputmode="numeric"
           value="<?php echo mostrar($paciente["telefono_paciente"]); ?>">
    <br><br>

    <h2>CARACTERÍSTICAS</h2>

    <label>¿PERTENECE AL PERSONAL DE SALUD?</label><br>
    <select name="personal_salud" required>
        <option value="">SELECCIONE...</option>
        <option value="1" <?php echo (string)$paciente["personal_salud"] === "1" ? "selected" : ""; ?>>SÍ</option>
        <option value="0" <?php echo (string)$paciente["personal_salud"] === "0" ? "selected" : ""; ?>>NO</option>
    </select>
    <br><br>

    <label>¿ES JORNALERO AGRÍCOLA?</label><br>
    <select name="jornalero_agricola" required>
        <option value="">SELECCIONE...</option>
        <option value="1" <?php echo (string)$paciente["jornalero_agricola"] === "1" ? "selected" : ""; ?>>SÍ</option>
        <option value="0" <?php echo (string)$paciente["jornalero_agricola"] === "0" ? "selected" : ""; ?>>NO</option>
    </select>
    <br><br>

    <div id="campo_embarazo" style="display:none;">
        <label>¿ESTÁ EMBARAZADA?</label><br>
        <select name="embarazo" id="embarazo">
            <option value="">SELECCIONE...</option>
            <option value="1" <?php echo (string)$paciente["embarazo"] === "1" ? "selected" : ""; ?>>SÍ</option>
            <option value="0" <?php echo (string)$paciente["embarazo"] === "0" ? "selected" : ""; ?>>NO</option>
        </select>
        <br><br>

        <div id="campo_fum" style="display:none;">
            <label>FECHA DE ÚLTIMA MENSTRUACIÓN:</label><br>
            <input type="date" name="fecha_ultima_menstruacion"
                   value="<?php echo mostrar($paciente["fecha_ultima_menstruacion"]); ?>">
            <br><br>
        </div>
    </div>

    <h2>COMORBILIDADES</h2>

    <?php while ($comorb = $resultadoComorb->fetch_assoc()): ?>
        <label>
            <input type="checkbox" name="comorbilidades[]"
                   value="<?php echo (int)$comorb["id_comorbilidad"]; ?>"
                   <?php echo in_array((int)$comorb["id_comorbilidad"], $comorbilidadesPaciente, true) ? "checked" : ""; ?>>
            <?php echo htmlspecialchars($comorb["nombre_comorbilidad"]); ?>
        </label>
        <br><br>
    <?php endwhile; ?>

    <div id="datos_tutor" style="display:none;">
        <h2>DATOS DEL TUTOR</h2>

        <label>APELLIDO PATERNO:</label><br>
        <input type="text" name="tutor_ap_pat" value="<?php echo mostrar($paciente["tutor_ap_pat"]); ?>">
        <br><br>

        <label>APELLIDO MATERNO:</label><br>
        <input type="text" name="tutor_ap_mat" value="<?php echo mostrar($paciente["tutor_ap_mat"]); ?>">
        <br><br>

        <label>NOMBRE(S):</label><br>
        <input type="text" name="tutor_nombre" value="<?php echo mostrar($paciente["tutor_nombres"]); ?>">
        <br><br>

        <label>FECHA DE NACIMIENTO:</label><br>
        <input type="date" name="tutor_fecha_nacimiento" value="<?php echo mostrar($paciente["tutor_fecha_nacimiento"]); ?>">
        <br><br>

        <label>CALLE:</label><br>
        <input type="text" name="calle_tutor" value="<?php echo mostrar($paciente["calle_tutor"]); ?>">
        <br><br>

        <label>NÚMERO:</label><br>
        <input type="text" name="num_tutor" inputmode="numeric" value="<?php echo mostrar($paciente["num_tutor"]); ?>">
        <br><br>

        <label>COLONIA:</label><br>
        <input type="text" name="colonia_tutor" value="<?php echo mostrar($paciente["colonia_tutor"]); ?>">
        <br><br>

        <label>PARENTESCO:</label><br>
        <select name="parentesco">
            <option value="">SELECCIONE...</option>
            <option value="1" <?php echo (string)$paciente["parentesco"] === "1" ? "selected" : ""; ?>>MADRE</option>
            <option value="2" <?php echo (string)$paciente["parentesco"] === "2" ? "selected" : ""; ?>>PADRE</option>
            <option value="3" <?php echo (string)$paciente["parentesco"] === "3" ? "selected" : ""; ?>>ABUELO</option>
            <option value="4" <?php echo (string)$paciente["parentesco"] === "4" ? "selected" : ""; ?>>ABUELA</option>
            <option value="5" <?php echo (string)$paciente["parentesco"] === "5" ? "selected" : ""; ?>>TÍO</option>
            <option value="6" <?php echo (string)$paciente["parentesco"] === "6" ? "selected" : ""; ?>>TÍA</option>
            <option value="7" <?php echo (string)$paciente["parentesco"] === "7" ? "selected" : ""; ?>>HIJA</option>
            <option value="8" <?php echo (string)$paciente["parentesco"] === "8" ? "selected" : ""; ?>>HIJO</option>
            <option value="9" <?php echo (string)$paciente["parentesco"] === "9" ? "selected" : ""; ?>>PRIMA</option>
            <option value="10" <?php echo (string)$paciente["parentesco"] === "10" ? "selected" : ""; ?>>PRIMO</option>
            <option value="11" <?php echo (string)$paciente["parentesco"] === "11" ? "selected" : ""; ?>>VECINO(A)</option>
            <option value="12" <?php echo (string)$paciente["parentesco"] === "12" ? "selected" : ""; ?>>OTRO PARENTESCO</option>
        </select>
        <br><br>

        <label>SEXO:</label><br>
        <select name="tutor_sexo">
            <option value="">SELECCIONE...</option>
            <option value="F" <?php echo $paciente["tutor_sexo"] === "F" ? "selected" : ""; ?>>FEMENINO</option>
            <option value="M" <?php echo $paciente["tutor_sexo"] === "M" ? "selected" : ""; ?>>MASCULINO</option>
        </select>
        <br><br>

        <label>CURP:</label><br>
        <input type="text" name="tutor_curp" maxlength="18" value="<?php echo mostrar($paciente["tutor_curp"]); ?>">
        <br><br>

        <label>TELÉFONO:</label><br>
        <input type="tel" name="tutor_telefono" id="tutor_telefono"
               maxlength="12" inputmode="numeric"
               value="<?php echo mostrar($paciente["telefono_tutor"]); ?>">
        <br><br>
    </div>

    <button type="submit">GUARDAR CAMBIOS</button>
    <a href="index.php"><button type="button">CANCELAR</button></a>

</form>

<script src="js/censo.js"></script>
</body>
</html>

