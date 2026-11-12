<?php
require_once __DIR__ . "/../config/seguridad.php";

if (!isset($_SESSION["id_usuario"])) {
    header("Location: ../login.php");
    exit();
}

if (($_SESSION["nombre_rol"] ?? "") !== "Personal de unidad") {
    http_response_code(403);
    exit("Esta función está disponible solo para personal de unidad.");
}

require_once __DIR__ . "/../config/conexion.php";
require_once __DIR__ . "/../config/unidad_contexto.php";
require_once __DIR__ . "/funciones.php";
require_once __DIR__ . "/importar_funciones.php";

$contextoUnidad = unidadObtenerContexto($conexion);
$mensaje = "";
$tipo_mensaje = "";
$historial_correcciones = [];

// Crear tabla de historial si no existe
$sqlHistorial = "CREATE TABLE IF NOT EXISTS HISTORIAL_CORRECCIONES_SUPERVISOR (
    id_historial INT AUTO_INCREMENT PRIMARY KEY,
    fecha_importacion DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    nombre_archivo VARCHAR(255) NOT NULL,
    cantidad_registros_actualizados INT NOT NULL,
    tablas_afectadas VARCHAR(500) NOT NULL,
    usuario_importador VARCHAR(100) NOT NULL,
    detalles_importacion LONGTEXT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

if (!$conexion->query($sqlHistorial)) {
    $tipo_mensaje = "error";
    $mensaje = "Error al preparar tabla de historial: " . $conexion->error;
}

// Obtener historial
$stmtHistorial = $conexion->prepare(
    "SELECT id_historial, fecha_importacion, nombre_archivo, cantidad_registros_actualizados, 
            tablas_afectadas, usuario_importador
     FROM HISTORIAL_CORRECCIONES_SUPERVISOR
     ORDER BY fecha_importacion DESC
     LIMIT 50"
);

if ($stmtHistorial && $stmtHistorial->execute()) {
    $resultado = $stmtHistorial->get_result();
    while ($fila = $resultado->fetch_assoc()) {
        $historial_correcciones[] = $fila;
    }
    $stmtHistorial->close();
}

// Procesar importación
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_FILES["archivo_correcciones"])) {
    
    if (!isset($_POST["token_importacion"]) || 
        $_POST["token_importacion"] !== hash_hmac(
            "sha256",
            "importar_correcciones|" . (int)$contextoUnidad["id_unidad"],
            $_SESSION["form_guard_secret"]
        )) {
        $tipo_mensaje = "error";
        $mensaje = "Token de seguridad inválido.";
    } elseif ($_FILES["archivo_correcciones"]["error"] !== UPLOAD_ERR_OK) {
        $tipo_mensaje = "error";
        $mensaje = "Error al subir el archivo. Intenta nuevamente.";
    } else {
        $archivo = $_FILES["archivo_correcciones"]["tmp_name"];
        $nombre_archivo = basename($_FILES["archivo_correcciones"]["name"]);
        
        try {
            // Leer y validar el archivo
            $contenido = file_get_contents($archivo);
            if ($contenido === false) {
                throw new Exception("No se pudo leer el archivo.");
            }
            
            // Decodificar JSON
            $datos = json_decode($contenido, true, 512, JSON_THROW_ON_ERROR);
            
            if (!is_array($datos) || !isset($datos["correcciones"])) {
                throw new Exception("Formato de archivo inválido.");
            }
            
            $correcciones = $datos["correcciones"];
            $registros_actualizados = 0;
            $tablas_afectadas = [];
            
            // Procesar correcciones
            foreach ($correcciones as $tabla => $registros) {
                if (!is_array($registros)) {
                    continue;
                }
                
                foreach ($registros as $registro) {
                    if (!isset($registro["id"], $registro["datos"])) {
                        continue;
                    }
                    
                    // Construir query de actualización
                    $campos = [];
                    $valores = [];
                    foreach ($registro["datos"] as $campo => $valor) {
                        $campos[] = "`" . str_replace("`", "``", $campo) . "` = ?";
                        $valores[] = $valor;
                    }
                    
                    if (empty($campos)) {
                        continue;
                    }
                    
                    $valores[] = $registro["id"];
                    $id_campo = $tabla === "PACIENTE" ? "id_paciente" : "id_" . strtolower($tabla);
                    
                    $sql = "UPDATE `" . str_replace("`", "``", $tabla) . "` SET " . 
                           implode(", ", $campos) . " WHERE `" . $id_campo . "` = ?";
                    
                    $stmt = $conexion->prepare($sql);
                    if ($stmt) {
                        $tipos = str_repeat("s", count($valores));
                        $stmt->bind_param($tipos, ...$valores);
                        if ($stmt->execute()) {
                            if ($stmt->affected_rows > 0) {
                                $registros_actualizados++;
                                if (!in_array($tabla, $tablas_afectadas)) {
                                    $tablas_afectadas[] = $tabla;
                                }
                            }
                        }
                        $stmt->close();
                    }
                }
            }
            
            // Registrar en historial
            $tablas_str = implode(", ", $tablas_afectadas);
            $usuario = $_SESSION["nombre"] ?? "Unknown";
            $detalles = json_encode([
                "tablas_afectadas" => $tablas_afectadas,
                "registros_por_tabla" => count($correcciones),
                "total_registros_actualizados" => $registros_actualizados
            ]);
            
            $stmtRegistro = $conexion->prepare(
                "INSERT INTO HISTORIAL_CORRECCIONES_SUPERVISOR 
                 (nombre_archivo, cantidad_registros_actualizados, tablas_afectadas, usuario_importador, detalles_importacion)
                 VALUES (?, ?, ?, ?, ?)"
            );
            
            if ($stmtRegistro) {
                $stmtRegistro->bind_param("sisss", $nombre_archivo, $registros_actualizados, $tablas_str, $usuario, $detalles);
                $stmtRegistro->execute();
                $stmtRegistro->close();
            }
            
            $tipo_mensaje = "exito";
            $mensaje = "Correcciones importadas exitosamente. Se actualizaron $registros_actualizados registros en las tablas: $tablas_str";
            
            // Recargar historial
            $stmtHistorial = $conexion->prepare(
                "SELECT id_historial, fecha_importacion, nombre_archivo, cantidad_registros_actualizados, 
                        tablas_afectadas, usuario_importador
                 FROM HISTORIAL_CORRECCIONES_SUPERVISOR
                 ORDER BY fecha_importacion DESC
                 LIMIT 50"
            );
            
            if ($stmtHistorial && $stmtHistorial->execute()) {
                $resultado = $stmtHistorial->get_result();
                $historial_correcciones = [];
                while ($fila = $resultado->fetch_assoc()) {
                    $historial_correcciones[] = $fila;
                }
                $stmtHistorial->close();
            }
            
        } catch (Exception $e) {
            $tipo_mensaje = "error";
            $mensaje = "Error al procesar correcciones: " . $e->getMessage();
        }
    }
    
    @unlink($archivo);
}

$tokenImportacion = hash_hmac(
    "sha256",
    "importar_correcciones|" . (int)$contextoUnidad["id_unidad"],
    $_SESSION["form_guard_secret"]
);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Importar correcciones del supervisor</title>
    <link rel="stylesheet" href="../css/tema_reportes.css">
    <style>
        :root { font-family: Arial, Helvetica, sans-serif; color: #233843; background: #f2f8fa; }
        * { box-sizing: border-box; }
        body { margin: 0; padding: 20px; }
        main { max-width: 1000px; margin: 24px auto; padding: 24px; border: 1px solid #dce9ed; border-radius: 12px; background: #fff; }
        h1 { margin-top: 0; color: #285f70; }
        h2 { color: #347f91; margin-top: 24px; border-bottom: 2px solid #e3f0f5; padding-bottom: 8px; }
        p, li { line-height: 1.55; }
        .unidad { padding: 12px; border-radius: 8px; background: #edf6f7; }
        form { display: grid; gap: 14px; margin-top: 22px; padding: 20px; border: 1px solid #e3f0f5; border-radius: 8px; background: #f9fafb; }
        label { display: grid; gap: 6px; font-weight: 700; }
        input[type="file"] { min-height: 42px; padding: 8px 10px; border: 2px dashed #9bb0b7; border-radius: 6px; }
        button, a { display: inline-flex; min-height: 42px; align-items: center; justify-content: center; padding: 8px 14px; border: 0; border-radius: 7px; background: #347f91; color: white; font: inherit; font-weight: 700; text-decoration: none; cursor: pointer; }
        a { background: #e3f0f5; color: #285f70; }
        .acciones { display: flex; flex-wrap: wrap; gap: 10px; }
        .mensaje { padding: 12px; border-left: 4px solid #d79b36; background: #fff8e9; border-radius: 4px; margin-bottom: 20px; }
        .mensaje.exito { border-left-color: #28a745; background: #e8f5e9; color: #1b5e20; }
        .mensaje.error { border-left-color: #dc3545; background: #ffebee; color: #c62828; }
        
        table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        th { background: #347f91; color: white; padding: 12px; text-align: left; }
        td { padding: 12px; border-bottom: 1px solid #e3f0f5; }
        tr:hover { background: #f9fafb; }
        
        .fecha-small { font-size: 0.9em; color: #666; }
        .tablas-afectadas { display: inline-block; background: #e3f0f5; padding: 4px 8px; border-radius: 4px; font-size: 0.9em; }
    </style>
</head>
<body>
<main>
    <h1>Importar Correcciones del Supervisor</h1>
    
    <p class="unidad">
        Unidad: <strong><?php echo htmlspecialchars($contextoUnidad["nombre_unidad"], ENT_QUOTES, "UTF-8"); ?></strong>
        (ID <?php echo (int)$contextoUnidad["id_unidad"]; ?>)
    </p>
    
    <?php if ($mensaje) { ?>
        <div class="mensaje <?php echo htmlspecialchars($tipo_mensaje, ENT_QUOTES, "UTF-8"); ?>">
            <?php echo htmlspecialchars($mensaje, ENT_QUOTES, "UTF-8"); ?>
        </div>
    <?php } ?>
    
    <section>
        <h2>Subir Paquete de Correcciones</h2>
        <p>
            El supervisor ha revisado y corregido la información. 
            Sube el paquete (archivo .json) que te proporciona el supervisor con las correcciones.
        </p>
        
        <form method="post" enctype="multipart/form-data">
            <input type="hidden" name="token_importacion" value="<?php echo htmlspecialchars($tokenImportacion, ENT_QUOTES, "UTF-8"); ?>">
            
            <label for="archivo_correcciones">
                Selecciona el archivo de correcciones
                <input id="archivo_correcciones" name="archivo_correcciones" type="file" accept=".json" required>
            </label>
            
            <div class="acciones">
                <button type="submit">Importar Correcciones</button>
                <a href="../menu.php">Regresar al menú</a>
            </div>
        </form>
    </section>
    
    <section>
        <h2>Historial de Correcciones Importadas</h2>
        
        <?php if (empty($historial_correcciones)) { ?>
            <p>No hay correcciones importadas aún.</p>
        <?php } else { ?>
            <table>
                <thead>
                    <tr>
                        <th>Fecha de Importación</th>
                        <th>Archivo</th>
                        <th>Registros Actualizados</th>
                        <th>Tablas Afectadas</th>
                        <th>Usuario</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($historial_correcciones as $registro) { ?>
                        <tr>
                            <td>
                                <strong><?php echo htmlspecialchars(date("Y-m-d H:i", strtotime($registro["fecha_importacion"])), ENT_QUOTES, "UTF-8"); ?></strong>
                                <br><span class="fecha-small"><?php echo htmlspecialchars($registro["fecha_importacion"], ENT_QUOTES, "UTF-8"); ?></span>
                            </td>
                            <td><?php echo htmlspecialchars($registro["nombre_archivo"], ENT_QUOTES, "UTF-8"); ?></td>
                            <td><?php echo (int)$registro["cantidad_registros_actualizados"]; ?></td>
                            <td>
                                <?php 
                                $tablas = explode(", ", $registro["tablas_afectadas"]);
                                foreach ($tablas as $tabla) {
                                    echo '<span class="tablas-afectadas">' . htmlspecialchars(trim($tabla), ENT_QUOTES, "UTF-8") . '</span> ';
                                }
                                ?>
                            </td>
                            <td><?php echo htmlspecialchars($registro["usuario_importador"], ENT_QUOTES, "UTF-8"); ?></td>
                        </tr>
                    <?php } ?>
                </tbody>
            </table>
        <?php } ?>
    </section>
</main>
</body>
</html>
