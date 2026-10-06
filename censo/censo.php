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

    $ap_pat = strtoupper($_POST["ap_pat"]);
    $ap_mat = strtoupper($_POST["ap_mat"]);
    $nombres = strtoupper($_POST["nombres"]);
    $fecha_nacimiento = $_POST["fecha_nacimiento"];
    $sexo = strtoupper($_POST["sexo"]);

    $curp = !empty($_POST["curp"])
        ? strtoupper($_POST["curp"])
        : NULL;

    $derechoabiencia = $_POST["derechoabiencia"];

    $poblacion_indigena_afromexicana =
        $_POST["poblacion_indigena_afromexicana"];

    $es_migrante = $_POST["es_migrante"];

    $estatus_migratorio = ($es_migrante == "1")
        ? $_POST["estatus_migratorio"]
        : "No aplica";

    $calle = strtoupper($_POST["calle"]);
    $num = strtoupper($_POST["num"]);
    $colonia = strtoupper($_POST["colonia"]);

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
    $parentesco= "No aplica";

    $fecha_nacimientoObj = new DateTime($fecha_nacimiento);
    $hoy = new DateTime();

    $edad = $hoy->diff($fecha_nacimientoObj)->y;
    

    // =========================
    // TUTOR
    // SOLO PARA 0-9 AÑOS
    // =========================

    if($edad <= 9){

        $tutor_ap_pat = strtoupper($_POST["tutor_ap_pat"]);
        $tutor_ap_mat = strtoupper($_POST["tutor_ap_mat"]);
        $tutor_nombres = strtoupper($_POST["tutor_nombre"]);
        $tutor_fecha_nacimiento = $_POST["tutor_fecha_nacimiento"];
        $tutor_telefono = $_POST["tutor_telefono"];
        $tutor_calle = strtoupper($_POST["calle_tutor"]);
        $tutor_num = strtoupper($_POST["num_tutor"]);
        $tutor_colonia = strtoupper($_POST["colonia_tutor"]);
        $parentesco = $_POST["parentesco"];
        $tutor_sexo = strtoupper($_POST["tutor_sexo"]);

        $tutor_curp = !empty($_POST["tutor_curp"])
            ? strtoupper($_POST["tutor_curp"])
            : NULL;

        //Buscar Tutor existente 
        $sql_BuscarTutor= "SELECT id_tutor FROM TUTOR where ap_pat = ? AND ap_mat= ? AND nombres= ? AND fecha_nacimiento =?
                           LIMIT 1";
        $stmtBuscarTutor= $conexion->prepare($sql_BuscarTutor);
        if(!$stmtBuscarTutor){
            throw new Exception (
                "Error al buscar tutor" . $conexion->error
            );
        }

        $stmtBuscarTutor->bind_param(
            "ssss",
            $tutor_ap_pat,
            $tutor_ap_mat,
            $tutor_nombres,
            $tutor_fecha_nacimiento
        );

        if (!$stmtBuscarTutor->execute()){
            throw new Exception(
                "Error al buscar tutor" . $conexion->error
            );
        }

        $stmtBuscarTutor->bind_result($id_tutor_encontrado);
        $tutorEncontrado = $stmtBuscarTutor->fetch();
         $stmtBuscarTutor->close();

        
        if($tutorEncontrado){
            $id_tutor = $id_tutor_encontrado;

            //Actualizar datos del tutor
            $sql_ActualizarTutor = "UPDATE TUTOR SET telefono_tutor = ?, calle_tutor = ?, num_tutor= ?, colonia_tutor = ?, curp= COALESCE(?, curp)
                                    WHERE id_tutor= ?";
            $stmt_ActualizarTutor = $conexion->prepare($sql_ActualizarTutor);
            if (!$stmt_ActualizarTutor){
                throw new Exception( "Error al preparar actualizacion " . $conexion->error);
            }
            $stmt_ActualizarTutor->bind_param(
                "sssssi",
                $tutor_telefono, $tutor_calle, $tutor_num, $tutor_colonia, $tutor_curp, $id_tutor
            );

            if(!$stmt_ActualizarTutor->execute()){
                throw new Exception( "Error al actualizar tutor: " . $conexion->error);
            }
            $stmt_ActualizarTutor->close();
        } else {
            $sqlTutor= "INSERT INTO TUTOR (
                        ap_pat, ap_mat, nombres, fecha_nacimiento, telefono_tutor, sexo, curp,
                        calle_tutor, num_tutor, colonia_tutor)
                        VALUES (?,?,?,?,?,?,?,?,?,?)";
            
            $stmtTutor= $conexion->prepare($sqlTutor);
            if(!$stmtTutor) {
                throw new Exception(
                    "Error al preparar tutor" . $conexion->error
                );
            }

            $stmtTutor->bind_param(
                "ssssssssss",
                $tutor_ap_pat,
                $tutor_ap_mat,
                $tutor_nombres,
                $tutor_fecha_nacimiento,
                $tutor_telefono,
                $tutor_sexo,
                $tutor_curp,
                $tutor_calle,
                $tutor_num,
                $tutor_colonia
            );

            if(!$stmtTutor->execute()){
                throw new ErrorException("Error al guardar tutor" . $conexion->error);
            }

            $id_tutor = $conexion->insert_id;
            $stmtTutor->close();
        }
       



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
        id_tutor,
        parentesco
    ) VALUES (?,?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";


    $stmtPaciente = $conexion->prepare($sqlPaciente);

    if(!$stmtPaciente){
        throw new Exception(
            "Error al preparar paciente: " . $conexion->error
        );
    }


  

    $stmtPaciente->bind_param(
        "sssssssiisssssisiiis",
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
        $id_tutor,
        $parentesco
    );


    if(!$stmtPaciente->execute()){
        throw new Exception(
            "Error al guardar paciente: " . $stmtPaciente->error
        );
    }

    $stmtPaciente->close();

    //ASIGNAR PACIENTE AL CENSO QUE LE CORRESPONDE.
    if ($embarazo == 1 || $edad >=20){
        $id_censo = 3;
    } elseif ($edad >=10){
        $id_censo = 2;
    } else {
        $id_censo= 1;
    }

    $sqlPacienteCenso = "INSERT INTO PACIENTE_CENSO (id_paciente, id_censo) VALUES(?,?)";

    $stmtPacienteCenso = $conexion->prepare($sqlPacienteCenso);

    if (!$stmtPacienteCenso){
        throw new Exception("Error al preparar" . $conexion->error);
    }

    $stmtPacienteCenso->bind_param(
        "si", $id_paciente, $id_censo
    );

    if(!$stmtPacienteCenso->execute()){
        throw new Exception(
            "Error al asignar paciente al censo " . $conexion->error
        );
    }

    $stmtPacienteCenso->close();


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