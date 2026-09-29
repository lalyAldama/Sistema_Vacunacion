<?php 
session_start();
if (!isset($_SESSION["id_usuario"])) {
    header("Location: ../login.php");
    exit();
}

require_once "../config/conexion.php";
?>


<!DOCTYPE html>
<html lang="es">
    <head>
        <meta charset="UTF-8">
        <Title> Registrar Paciente</Title>
    </head>

    <body>
        <h1>REGISTRO DE PACIENTES</h1>

        <form method="POST" action="censo.php">
            <h2>DATOS DEL PACIENTE</h2>

            <label>APELLIDO PATERNO: </label><br>
            <input type="text" name="ap_pat" required>
            <br><br>
            
            <label>APELLIDO MATERNO: </label><br>
            <input type="text" name="ap_mat" required>
            <br><br>

            <label>NOMBRE (S): </label><br>
            <input type="text" name="nombres" required>
            <br><br>

            <label>FECHA DE NACIMIENTO: </label><br>
            <input type="date" name="fecha_nacimiento" id="fecha_nacimiento" required>
            <p>
                Edad: <span id="edad">--</span>
            </p>
            <p>Censo: <span id="tipo_censo">--</span>
            </p>
            <br><br>

            <label> SEXO: </label><br>
            <select name="sexo" required>
                <option value="">SELECCIONE...</option>
                <option value="F">Femenino</option>
                <option value="M">Masculino</option>
            </select>
            <br><br>

            <label>CURP: </label><br>
            <input type="text" name="curp" maxlength="18">
            <br><br>

            <label>DERECHOHABIENCIA: </label><br>
            <select name="derechoabiencia" required>
                <option value="">SELECCIONE...</option>
                <option value="1">SECRETARÍA DE SALUD</option>
                <option value="2">IMSS</option>
                <option value="3">ISSSTE</option>
                <option value="4">IMMS BIENESTAR</option>
                <option value="5">SEDENA</option>
                <option value="6">SEMAR</option>
                <option value="7">PEMEX</option>
                <option value="8">DIF</option>
                <option value="9">OTROS</option>
            </select>
            <br><br>

            <label>¿Pertenece a población indígena o afromexicana?</label><br>
            <select name="poblacion_indigena_afromexicana" required>
                <option value="">SELECCIONE...</option>
                <option value="1">SI</option>
                <option value="0">NO</option>
            </select>
            <br><br>

            <label>¿Es migrante?</label><br>
            <select name="es_migrante" id="es_migrante" required>
                <option value="">SELECCIONE...</option>
                <option value="1">SI</option>
                <option value="0">NO</option>
            </select>
            <br><br>

            <div id="campo_estatus_migratorio" style="display:none;">
                <label>ESTATUS MIGRATORIO:</label><br>
                <select name="estatus_migratorio" id="estatus_migratorio">
                    <option value="">SELECCIONE...</option>
                    <option value="Regular">REGULAR</option>
                    <option value="Irregular">IRREGULAR</option>
                </select>
                <br><br>
            </div>

            <h2>DOMICILIO</h2>
            <label>CALLE:</label><br>
            <input type="text" name="calle" required>
            <br><br>

            <label>NUMERO:</label><br>
            <input type="text" name="num" required>
            <br><br>

            <label>COLONIA:</label><br>
            <input type="text" name="colonia" required>
            <br><br>

            <h2>TELEFONO:</h2>
            <input type="tel" name="telefono_paciente" id="telefono_paciente" maxlength="12" inputmode="numeric">
            <br><br>
            
            <h2>CARACTERISTICAS</h2>
            <label>¿Pertenece al personal de salud?</label><br>
            <select name="personal_salud" required>
                <option value="">SELECCIONE...</option>
                <option value="1">SI</option>
                <option value="0">NO</option>
            </select>
            <br><br>

            <label>¿Es jornalero agrícola?</label><br>
            <select name="jornalero_agricola" required>
                <option value="">SELECCIONE...</option>
                <option value="1">SI</option>
                <option value="0">NO</option>
            </select>
            <br><br>

            <div id="campo_embarazo" style="display: none;">
                <label>¿Esta embarazada?</label><br>
                <select name="embarazo" id="embarazo">
                    <option value="">SELECCIONE...</option>
                    <option value="1">SI</option>
                    <option value="0">NO</option>
                </select>
                <br><br>

                <div id="campo_fum" style="display:none;">
                    <label>FECHA DE ULTIMA MENSTRUACIÓN</label><br>
                    <input type="date" name="fecha_ultima_menstruacion">

                    <br><br>
                </div>
            </div>

            <h2>COMORBILIDADES</h2>
            <?php
            $sql_comorbilidades = "SELECT id_comorbilidad, nombre_comorbilidad FROM COMORBILIDAD
                                   ORDER BY nombre_comorbilidad";
            $resultadoComorbilidades = $conexion->query($sql_comorbilidades);
            ?>

            <?php while ($comorbilidad = $resultadoComorbilidades->fetch_assoc()): ?>

                <label>
                    <input type="checkbox" name="comorbilidades[]" value="<?= $comorbilidad["id_comorbilidad"] ?>" >
                    <?= htmlspecialchars($comorbilidad["nombre_comorbilidad"]) ?>
                </label>
                <br><br>
            <?php endwhile; ?>

            <div id="datos_tutor" style="display: none;">

                <h2>Datos del tutor</h2>

                <label>APELLIDO PATERNO:</label><br>
                <input type="text" name="tutor_ap_pat" >
                <br><br>

                <label>APELLIDO MATERNO: </label><br>
                <input type="text" name="tutor_ap_mat" >
                <br><br>

                <label>NOMBRE (S):</label><br>
                <input type="text" name="tutor_nombre" >
                <br><br>

                <label>FECHA DE NACIMIENTO:</label><br>
                <input type="date" name="tutor_fecha_nacimiento">
                <br><br>

                <label>CALLE:</label><br>
                <input type="text" name="calle_tutor">
                <br><br>

                <label>NUMERO: </label><br>
                <input type="text" name="num_tutor">
                <br><br>

                <label>COLONIA: </label><br>
                <input type="text" name="colonia_tutor">
                <br><br>

                <label>PARENTESCO: </label><br>
                <select name="parentesco" >
                    <option value="">SELECCIONE:</option>
                    <option value="1">MADRE</option>
                    <option value="2">PADRE</option>
                    <option value="3">ABUELO</option>
                    <option value="4">ABUELA</option>
                    <option value="5">TIO</option>
                    <option value="6">TIA</option>
                    <option value="7">HIJA</option>
                    <option value="8">HIJO</option>
                    <option value="9">PRIMA</option>
                    <option value="10">PRIMO</option>
                    <option value="11">VECINO(A)</option>
                    <option value="12">OTRO PARENTESCO</option>
                </select>
                <br><br>

                <label>SEXO:</label><br>
                <select name="tutor_sexo" >
                    <option value="">SELECCIONE...</option>
                    <option value="F">FEMENINO</option>
                    <option value="M">MASCULINO</option>
                </select>
                <br><br>

                <label>CURP:</label><br>
                <input type="text" name="tutor_curp" maxlength="18">
                <br><br>
                
                <label>TELÉFONO:</label> <br>
                <input type="tel" name="tutor_telefono" id="tutor_telefono" maxlength="12" inputmode="numeric">
                <br><br>
            </div>  
            <button type="submit">GUARDAR PACIENTE</button>
            <a href="index.php">
                <button>CANCELAR</button>
             </a>

        </form>
        <script src="js/censo.js"></script>
    </body>
</html>