<?php
require_once __DIR__ . "/../config/seguridad.php";

if (!isset($_SESSION["id_usuario"])) {
    header("Location: ../login.php");
    exit();
}

if (($_SESSION["nombre_rol"] ?? "") !== "Supervisor") {
    http_response_code(403);
    exit("Esta función está disponible solo para supervisores.");
}

require_once __DIR__ . "/../config/conexion.php";

$unidadSeleccionada = null;
$mensaje = "";
$tipo_mensaje = "";

// Obtener unidades disponibles
$stmtUnidades = $conexion->prepare("SELECT id_unidad, nombre_unidad FROM UNIDAD ORDER BY nombre_unidad");
if (!$stmtUnidades || !$stmtUnidades->execute()) {
    die("Error al obtener unidades: " . $conexion->error);
}

$resultadoUnidades = $stmtUnidades->get_result();
$unidades = $resultadoUnidades->fetch_all(MYSQLI_ASSOC);
$stmtUnidades->close();

// Si se selecciona una unidad y se envía el formulario
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["id_unidad_destino"])) {
    $idUnidadDestino = filter_var($_POST["id_unidad_destino"], FILTER_VALIDATE_INT);
    
    if ($idUnidadDestino === false) {
        $tipo_mensaje = "error";
        $mensaje = "Unidad destino inválida.";
    } else {
        // Validar que la unidad existe
        $stmtVerify = $conexion->prepare("SELECT id_unidad, nombre_unidad FROM UNIDAD WHERE id_unidad = ? LIMIT 1");
        if (!$stmtVerify || !$stmtVerify->bind_param("i", $idUnidadDestino) || !$stmtVerify->execute()) {
            die("Error al verificar unidad: " . $conexion->error);
        }
        
        $resultado = $stmtVerify->get_result();
        $unidadSeleccionada = $resultado->fetch_assoc();
        $stmtVerify->close();
        
        if (!$unidadSeleccionada) {
            $tipo_mensaje = "error";
            $mensaje = "La unidad seleccionada no existe.";
        }
    }
}

$tokenExportacion = hash_hmac(
    "sha256",
    "exportar_correcciones|" . ($unidadSeleccionada ? (int)$unidadSeleccionada["id_unidad"] : 0),
    $_SESSION["form_guard_secret"]
);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Exportar correcciones a unidad</title>
    <link rel="stylesheet" href="../css/tema_reportes.css">
    <style>
        :root { font-family: Arial, Helvetica, sans-serif; color: #233843; background: #f2f8fa; }
        * { box-sizing: border-box; }
        body { margin: 0; padding: 20px; }
        main { max-width: 760px; margin: 24px auto; padding: 24px; border: 1px solid #dce9ed; border-radius: 12px; background: #fff; }
        h1 { margin-top: 0; color: #285f70; }
        p, li { line-height: 1.55; }
        .mensaje { padding: 12px; border-left: 4px solid #d79b36; background: #fff8e9; border-radius: 4px; margin-bottom: 20px; }
        .mensaje.error { border-left-color: #dc3545; background: #ffebee; color: #c62828; }
        form { display: grid; gap: 14px; margin-top: 22px; }
        label { display: grid; gap: 6px; font-weight: 700; }
        select { min-height: 42px; padding: 8px 10px; border: 1px solid #9bb0b7; border-radius: 6px; font: inherit; }
        button, a { display: inline-flex; min-height: 42px; align-items: center; justify-content: center; padding: 8px 14px; border: 0; border-radius: 7px; background: #347f91; color: white; font: inherit; font-weight: 700; text-decoration: none; cursor: pointer; }
        a { background: #e3f0f5; color: #285f70; }
        .acciones { display: flex; flex-wrap: wrap; gap: 10px; }
        .unidad { padding: 12px; border-radius: 8px; background: #edf6f7; }
        .aviso { padding: 12px; border-left: 4px solid #d79b36; background: #fff8e9; }
    </style>
</head>
<body>
<main>
    <h1>Exportar Correcciones a Unidad</h1>
    
    <?php if ($mensaje) { ?>
        <div class="mensaje <?php echo htmlspecialchars($tipo_mensaje, ENT_QUOTES, "UTF-8"); ?>">
            <?php echo htmlspecialchars($mensaje, ENT_QUOTES, "UTF-8"); ?>
        </div>
    <?php } ?>
    
    <?php if (!$unidadSeleccionada) { ?>
        <p>Selecciona la unidad que recibirá las correcciones que has realizado:</p>
        
        <form method="post">
            <label for="id_unidad_destino">Unidad Destino
                <select id="id_unidad_destino" name="id_unidad_destino" required>
                    <option value="">-- Selecciona una unidad --</option>
                    <?php foreach ($unidades as $unidad) { ?>
                        <option value="<?php echo (int)$unidad["id_unidad"]; ?>">
                            <?php echo htmlspecialchars($unidad["nombre_unidad"], ENT_QUOTES, "UTF-8"); ?>
                        </option>
                    <?php } ?>
                </select>
            </label>
            
            <div class="acciones">
                <button type="submit">Continuar</button>
                <a href="../menu.php">Regresar al menú</a>
            </div>
        </form>
    
    <?php } else { ?>
        
        <p class="unidad">
            Unidad Destino: <strong><?php echo htmlspecialchars($unidadSeleccionada["nombre_unidad"], ENT_QUOTES, "UTF-8"); ?></strong>
            (ID <?php echo (int)$unidadSeleccionada["id_unidad"]; ?>)
            <br>
            <a href="exportar_correcciones.php" style="font-size: 0.9em; margin-top: 8px; display: inline-block;">← Cambiar unidad</a>
        </p>
        
        <p>
            Se generará un paquete con las correcciones que has realizado en los datos de esta unidad.
            El paquete incluirá solo los registros que han sido modificados desde la última importación.
        </p>
        
        <div class="aviso">
            El archivo contiene datos de salud. Guárdalo de forma segura y entrégalo 
            a la persona responsable de la unidad por USB u otro medio seguro.
        </div>
        
        <ul>
            <li>Solo se incluyen registros que han sido modificados.</li>
            <li>La exportación no incluye nuevos pacientes (solo correcciones).</li>
            <li>El archivo se cifrará para proteger los datos de salud.</li>
        </ul>
        
        <form method="post" action="exportar_correcciones_proceso.php" autocomplete="off">
            <input type="hidden" name="token_exportacion" value="<?php echo htmlspecialchars($tokenExportacion, ENT_QUOTES, "UTF-8"); ?>">
            <input type="hidden" name="id_unidad_destino" value="<?php echo (int)$unidadSeleccionada["id_unidad"]; ?>">
            
            <label for="contrasena_paquete">Contraseña del paquete
                <input id="contrasena_paquete" name="contrasena_paquete" type="password" minlength="14" maxlength="128" required autocomplete="new-password">
            </label>
            
            <label for="confirmar_contrasena">Confirma la contraseña
                <input id="confirmar_contrasena" name="confirmar_contrasena" type="password" minlength="14" maxlength="128" required autocomplete="new-password">
            </label>
            
            <div class="acciones">
                <button type="submit">Generar paquete de correcciones</button>
                <a href="exportar_correcciones.php">Cambiar unidad</a>
                <a href="../menu.php">Regresar al menú</a>
            </div>
        </form>
    
    <?php } ?>
</main>
</body>
</html>
