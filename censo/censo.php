<?php
session_start();

if(!isset($_SESSION["id_usuario"])){
    header("Location: ../login.php");
    exit();
}

require_once "../config/conexion.php";

if($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: registrar.php");
    exit();
}

$conexion->begin_transaction();

try {

    // =========================
    // DATOS DEL PACIENTE
    // =========================

    $ap_pat = $_POST["ap_pat"];
    $ap_mat = $_POST["ap_mat"];
    $nombres = $_POST["nombres"];
    $fecha_nacimiento = $_POST["fecha_nacimiento"];
    $sexo = $_POST["sexo"];

    $curp = !empty($_POST["curp"])
        ? $_POST["curp"]
        : NULL;

    $derechoabiencia = $_POST["derechoabiencia"];

    $poblacion_indigena_afromexicana =
        $_POST["poblacion_indigena_afromexicana"];

    $es_migrante = $_POST["es_migrante"];

    $estatus_migratorio = ($es_migrante == "1")
        ? $_POST["estatus_migratorio"]
        : "No aplica";

    $calle = $_POST["calle"];
    $num = $_POST["num"];
    $colonia = $_POST["colonia"];

    $telefono_paciente = !empty($_POST["telefono_paciente"])
        ? $_POST["telefono_paciente"]
        : NULL;

    $personal_salud = $_POST["personal_salud"];
    $jornalero_agricola = $_POST["jornalero_agricola"];


    // =========================
    // EMBARAZO
    // =========================

    if(isset($_POST["embarazo"]) && $_POST["embarazo"] !== "") {
        $embarazo = $_POST["embarazo"];
    } else {
        $embarazo = 0;
    }

    $fecha_ultima_menstruacion =
        !empty($_POST["fecha_ultima_menstruacion"])
        ? $_POST["fecha_ultima_menstruacion"]
        : NULL;


    // =========================
    // CALCULAR EDAD
    // =========================

    $id_tutor = NULL;

    $fecha_nacimientoObj = new DateTime($fecha_nacimiento);
    $hoy = new DateTime();

    $edad = $hoy->diff($fecha_nacimientoObj)->y;


    // =========================
    // TUTOR
    // SOLO PARA 0-9 AÑOS
    // =========================

    if($edad <= 9){

        $tutor_ap_pat = $_POST["tutor_ap_pat"];
        $tutor_ap_mat = $_POST["tutor_ap_mat"];
        $tutor_nombres = $_POST["tutor_nombre"];
        $tutor_fecha_nacimiento = $_POST["tutor_fecha_nacimiento"];
        $tutor_telefono = $_POST["tutor_telefono"];
        $tutor_calle = $_POST["calle_tutor"];
        $tutor_num = $_POST["num_tutor"];
        $tutor_colonia = $_POST["colonia_tutor"];
        $parentesco = $_POST["parentesco"];
        $tutor_sexo = $_POST["tutor_sexo"];

        $tutor_curp = !empty($_POST["tutor_curp"])
            ? $_POST["tutor_curp"]
            : NULL;


        $sqlTutor = "INSERT INTO TUTOR (
            ap_pat,
            ap_mat,
            nombres,
            fecha_nacimiento,
            telefono_tutor,
            calle_tutor,
            num_tutor,
            colonia_tutor,
            parentesco,
            sexo,
            curp
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?,?,?,?)";


        $stmtTutor = $conexion->prepare($sqlTutor);

        if(!$stmtTutor){
            throw new Exception(
                "Error al preparar tutor: " . $conexion->error
            );
        }


        $stmtTutor->bind_param(
            "sssssssssss",
            $tutor_ap_pat,
            $tutor_ap_mat,
            $tutor_nombres,
            $tutor_fecha_nacimiento,
            $tutor_telefono,
            $tutor_calle,
            $tutor_num,
            $tutor_colonia,
            $parentesco,
            $tutor_sexo,
            $tutor_curp
        );


        if(!$stmtTutor->execute()){
            throw new Exception(
                "Error al guardar tutor: " . $stmtTutor->error
            );
        }


        // Obtener ID del tutor recién creado
        $id_tutor = $conexion->insert_id;

        $stmtTutor->close();
    }


    // =========================
    // INSERTAR PACIENTE
    // =========================
    $id_paciente = $conexion->query("SELECT UUID()") ->fetch_row()[0];
    $sqlPaciente = "INSERT INTO PACIENTE (
        id_paciente,
        ap_pat,
        ap_mat,
        nombres,
        fecha_nacimiento,
        sexo,
        curp,
        derechoabiencia,
        poblacion_indigena_afromexicana,
        estatus_migratorio,
        calle,
        num,
        colonia,
        telefono_paciente,
        embarazo,
        fecha_ultima_menstruacion,
        personal_salud,
        jornalero_agricola,
        id_tutor
    ) VALUES (?,?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";


    $stmtPaciente = $conexion->prepare($sqlPaciente);

    if(!$stmtPaciente){
        throw new Exception(
            "Error al preparar paciente: " . $conexion->error
        );
    }


  

    $stmtPaciente->bind_param(
        "sssssssiisssssisiii",
        $id_paciente,
        $ap_pat,
        $ap_mat,
        $nombres,
        $fecha_nacimiento,
        $sexo,
        $curp,
        $derechoabiencia,
        $poblacion_indigena_afromexicana,
        $estatus_migratorio,
        $calle,
        $num,
        $colonia,
        $telefono_paciente,
        $embarazo,
        $fecha_ultima_menstruacion,
        $personal_salud,
        $jornalero_agricola,
        $id_tutor
    );


    if(!$stmtPaciente->execute()){
        throw new Exception(
            "Error al guardar paciente: " . $stmtPaciente->error
        );
    }

    $stmtPaciente->close();

    //comorbilidades
    if(isset($_POST["comorbilidades"]) && is_array($_POST["comorbilidades"])) {
        $sqlComorbilidad = "INSERT INTO PACIENTE_COMORBILIDAD (
                            id_paciente, id_comorbilidad)
                            VALUES (?,?)";
        $stmtComorbilidad= $conexion->prepare($sqlComorbilidad);

        if(!$stmtComorbilidad){
            throw new Exception("Error al preparar comorbilidad". $conexion->error);
        }

        foreach($_POST["comorbilidades"] as $id_comorbilidad){
            $id_comorbilidad = (int)$id_comorbilidad;
            $stmtComorbilidad->bind_param(
                "si",
                $id_paciente,
                $id_comorbilidad
            );

            if(!$stmtComorbilidad->execute()){
                throw new Exception(
                    "Error al guardar comorbilidad" . $stmtComorbilidad->error 
                );
            }
        }
        $stmtComorbilidad->close();
    }


    // =========================
    // CONFIRMAR TRANSACCIÓN
    // =========================

    $conexion->commit();


    echo "<script>
        alert('Paciente registrado correctamente');
        window.location.href = 'index.php';
    </script>";


} catch (Exception $e) {

    $conexion->rollback();

    echo "<script>
        alert('Error al registrar: " . addslashes($e->getMessage()) . "' );
        window.history.back();
    </script>";
}


$conexion->close();

?>