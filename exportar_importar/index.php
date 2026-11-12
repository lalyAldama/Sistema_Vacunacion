<?php
require_once __DIR__ . "/../config/seguridad.php";

if (!isset($_SESSION["id_usuario"])) {
    header("Location: ../login.php");
    exit();
}

if (!in_array($_SESSION["nombre_rol"] ?? "", ["Personal de unidad", "Supervisor"], true)) {
    http_response_code(403);
    exit("Esta función está disponible para personal de unidad y supervisores.");
}
if (($_SESSION["nombre_rol"] ?? "") === "Supervisor") {
    header("Location: importar.php");
    exit();
}

require_once __DIR__ . "/../config/conexion.php";
require_once __DIR__ . "/../config/unidad_contexto.php";
$contextoUnidad = unidadObtenerContexto($conexion);
$unidadOrigen = [
    "id_unidad" => $contextoUnidad["id_unidad"],
    "nombre_unidad" => $contextoUnidad["nombre_unidad"]
];
require_once __DIR__ . "/funciones.php";
$uuidInstalacion = asegurarEsquemaExportacionUnidad($conexion);

$tokenExportacion = hash_hmac(
    "sha256",
    "exportar_unidad|" . (int)$unidadOrigen["id_unidad"],
    $_SESSION["form_guard_secret"]
);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Exportar datos de la unidad</title>
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
    </style>
</head>
<body>
<main>
    <h1>Exportar datos de la unidad</h1>
    <p class="unidad">
        Unidad: <strong><?php echo htmlspecialchars($unidadOrigen["nombre_unidad"], ENT_QUOTES, "UTF-8"); ?></strong>
        (ID local <?php echo (int)$unidadOrigen["id_unidad"]; ?>)
        <br>Identificador de instalación: <code><?php echo htmlspecialchars($uuidInstalacion, ENT_QUOTES, "UTF-8"); ?></code>
    </p>
    <p>
        Se generará un paquete cifrado con pacientes, vacunas aplicadas, existencias,
        movimientos y datos operativos de la unidad. La sede seleccionará la unidad
        receptora al importar el archivo.
    </p>
    <div class="aviso">
        El archivo contiene datos de salud. Define una contraseña de al menos 14 caracteres
        y compártela con la sede por un medio distinto al archivo. No se exportan cuentas
        de usuario ni contraseñas.
    </div>
    <ul>
        <li>La exportación no borra ni modifica datos de esta unidad.</li>
        <li>Usa una contraseña nueva y guárdala hasta que la sede confirme la importación.</li>
        <li>El paquete incluye una identidad UUID permanente de esta instalación para distinguirla de otras unidades.</li>
        <li>Guarda los encabezados anuales con «Guardar metas» para que queden incluidos en la exportación.</li>
    </ul>
    <form method="post" action="exportar.php" autocomplete="off">
        <input type="hidden" name="token_exportacion" value="<?php echo htmlspecialchars($tokenExportacion, ENT_QUOTES, "UTF-8"); ?>">
        <label for="contrasena_paquete">Contraseña del paquete
            <input id="contrasena_paquete" name="contrasena_paquete" type="password" minlength="14" maxlength="128" required autocomplete="new-password">
        </label>
        <label for="confirmar_contrasena">Confirma la contraseña
            <input id="confirmar_contrasena" name="confirmar_contrasena" type="password" minlength="14" maxlength="128" required autocomplete="new-password">
        </label>
        <div class="acciones">
            <button type="submit">Generar paquete cifrado</button>
            <a href="../menu.php">Regresar al menú</a>
        </div>
    </form>
</main>
</body>
</html>
