<?php
require_once __DIR__ . "/../config/seguridad.php";

if (!isset($_SESSION["id_usuario"])) {
    header("Location: ../login.php");
    exit();
}
if (($_SESSION["nombre_rol"] ?? "") !== "Supervisor") {
    http_response_code(403);
    exit("La importación está disponible únicamente para el supervisor.");
}

require_once __DIR__ . "/../config/conexion.php";
require_once __DIR__ . "/../config/unidad_contexto.php";
require_once __DIR__ . "/importar_funciones.php";

$contextoUnidad = unidadObtenerContexto($conexion);
$idUsuarioCentral = (int)$_SESSION["id_usuario"];
$tokenImportacion = hash_hmac(
    "sha256",
    "importar_paquete|" . $contextoUnidad["id_unidad"],
    $_SESSION["form_guard_secret"]
);
$mensajeError = "";
$resumenImportacion = [];
$unidadOrigen = null;
$transaccionIniciada = false;

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $contrasenaPaquete = $_POST["contrasena_paquete"] ?? null;
    $tokenRecibido = $_POST["token_importacion"] ?? null;
    $confirmar = $_POST["confirmar_importacion"] ?? null;
    $archivo = $_FILES["paquete"] ?? null;

    if (!is_string($tokenRecibido) || !hash_equals($tokenImportacion, $tokenRecibido)) {
        http_response_code(400);
        $mensajeError = "La solicitud venció. Regresa e inténtalo de nuevo.";
    } elseif (
        !is_string($contrasenaPaquete)
        || strlen($contrasenaPaquete) < 14
        || strlen($contrasenaPaquete) > 128
    ) {
        http_response_code(400);
        $mensajeError = "La contraseña del paquete debe tener entre 14 y 128 caracteres.";
    } elseif (
        !is_array($archivo)
        || ($archivo["error"] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK
        || !is_string($archivo["tmp_name"] ?? null)
        || !is_uploaded_file($archivo["tmp_name"])
    ) {
        http_response_code(400);
        $mensajeError = "Selecciona un archivo .vax válido para importar.";
    } elseif (
        !is_string($archivo["name"] ?? null)
        || strtolower(pathinfo($archivo["name"], PATHINFO_EXTENSION)) !== "vax"
        || !isset($archivo["size"])
        || (int)$archivo["size"] < 1
        || (int)$archivo["size"] > 50 * 1024 * 1024
    ) {
        http_response_code(400);
        $mensajeError = "El archivo debe ser .vax y no superar 50 MB.";
    } elseif ($confirmar !== "1") {
        http_response_code(400);
        $mensajeError = "Confirma que deseas importar los datos en la unidad seleccionada.";
    } else {
        try {
            $contenidoSobre = file_get_contents($archivo["tmp_name"]);
            if ($contenidoSobre === false) {
                importacionLanzarError("No se pudo leer el archivo seleccionado.");
            }
            try {
                $sobre = json_decode($contenidoSobre, true, 32, JSON_THROW_ON_ERROR);
            } catch (JsonException $error) {
                importacionLanzarError("El archivo .vax no tiene un sobre JSON válido.");
            }
            if (!is_array($sobre)) {
                importacionLanzarError("El archivo .vax no tiene un sobre válido.");
            }

            $paquete = importacionValidarPaquete($sobre, $contrasenaPaquete);
            $unidadOrigen = $paquete["unidad_origen"];
            $contextoImportacion = importacionObtenerUnidad(
                $conexion,
                (int)$unidadOrigen["id_local"],
                (string)$unidadOrigen["uuid_instalacion"],
                (int)$contextoUnidad["id_unidad"]
            );
            if (!$conexion->begin_transaction()) {
                importacionLanzarError("No se pudo iniciar una transacción para importar los datos.");
            }
            $transaccionIniciada = true;
            $resumenImportacion = importacionEjecutar(
                $conexion,
                $paquete,
                $contextoImportacion,
                $idUsuarioCentral
            );
            if (!$conexion->commit()) {
                importacionLanzarError("No se pudo confirmar la importación.");
            }
            $transaccionIniciada = false;
        } catch (Throwable $error) {
            if ($transaccionIniciada) {
                $conexion->rollback();
                $transaccionIniciada = false;
            }
            $mensajeError = $error->getMessage();
            $resumenImportacion = [];
        }
    }
}

$escapar = static function ($valor): string {
    return htmlspecialchars((string)$valor, ENT_QUOTES, "UTF-8");
};
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Importar información</title>
    <link rel="stylesheet" href="../css/tema_reportes.css">
    <style>
        :root { font-family: Arial, Helvetica, sans-serif; color: #233843; background: #f2f8fa; }
        * { box-sizing: border-box; }
        body { margin: 0; padding: 20px; }
        main { max-width: 760px; margin: 24px auto; padding: 24px; border: 1px solid #dce9ed; border-radius: 12px; background: #fff; }
        h1 { margin-top: 0; color: #285f70; }
        p, li { line-height: 1.55; }
        .unidad { padding: 12px; border-radius: 8px; background: #edf6f7; }
        form { display: grid; gap: 14px; margin-top: 22px; }
        label { display: grid; gap: 6px; font-weight: 700; }
        input { min-height: 42px; padding: 8px 10px; border: 1px solid #9bb0b7; border-radius: 6px; font: inherit; }
        button, a { display: inline-flex; min-height: 42px; align-items: center; justify-content: center; padding: 8px 14px; border: 0; border-radius: 7px; background: #347f91; color: white; font: inherit; font-weight: 700; text-decoration: none; cursor: pointer; }
        a { background: #e3f0f5; color: #285f70; }
        .acciones { display: flex; flex-wrap: wrap; gap: 10px; }
        .aviso { padding: 12px; border-left: 4px solid #d79b36; background: #fff8e9; }
        .error { padding: 12px; border-left: 4px solid #b53c3c; background: #fff0ef; }
        .exito { padding: 12px; border-left: 4px solid #37815b; background: #effaf3; }
        .confirmacion { display: flex; align-items: flex-start; gap: 10px; font-weight: 400; }
        .confirmacion input { min-height: auto; margin-top: 4px; }
        .conteos { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 8px 18px; padding-left: 20px; }
    </style>
</head>
<body>
<main>
    <h1>Importar información de una unidad</h1>
    <p class="unidad">
        Destino central:
        <strong><?php echo $escapar($contextoUnidad["nombre_unidad"]); ?></strong>
        (Unidad <?php echo (int)$contextoUnidad["id_unidad"]; ?>)
    </p>
    <p>
        Selecciona el paquete cifrado que generó la unidad y escribe la contraseña
        que te compartieron. La información se agregará al destino seleccionado.
    </p>
    <div class="aviso">
        El paquete contiene datos de salud. La importación es transaccional:
        si algún registro no puede integrarse, no se conserva ningún cambio parcial.
        Puedes volver a importar el mismo paquete; sus registros se reconocen por
        la identidad de la instalación y no se duplican.
    </div>

    <?php if ($mensajeError !== ""): ?>
        <p class="error" role="alert"><?php echo $escapar($mensajeError); ?></p>
    <?php endif; ?>

    <?php if ($resumenImportacion): ?>
        <section class="exito" aria-live="polite">
            <h2>Importación completada</h2>
            <p>
                Se integró la información de
                <strong><?php echo $escapar($unidadOrigen["nombre"] ?? "la unidad de origen"); ?></strong>
                en
                <strong><?php echo $escapar($contextoUnidad["nombre_unidad"]); ?></strong>.
            </p>
            <ul class="conteos">
                <?php foreach ($resumenImportacion as $tabla => $cantidad): ?>
                    <li><?php echo $escapar($tabla); ?>: <?php echo (int)$cantidad; ?></li>
                <?php endforeach; ?>
            </ul>
        </section>
    <?php endif; ?>

    <form method="post" enctype="multipart/form-data" autocomplete="off">
        <input type="hidden" name="token_importacion" value="<?php echo $escapar($tokenImportacion); ?>">
        <input type="hidden" name="MAX_FILE_SIZE" value="<?php echo 50 * 1024 * 1024; ?>">
        <label for="paquete">Paquete de exportación (.vax)
            <input id="paquete" name="paquete" type="file" accept=".vax,application/vnd.sistema-vacunacion.exportacion+json" required>
        </label>
        <label for="contrasena_paquete">Contraseña del paquete
            <input id="contrasena_paquete" name="contrasena_paquete" type="password"
                   minlength="14" maxlength="128" required autocomplete="off">
        </label>
        <label class="confirmacion">
            <input type="checkbox" name="confirmar_importacion" value="1" required>
            <span>Confirmo que quiero importar el contenido a la unidad central indicada.</span>
        </label>
        <div class="acciones">
            <button type="submit">Importar paquete</button>
            <a href="../menu.php">Regresar al menú</a>
        </div>
    </form>
</main>
</body>
</html>
