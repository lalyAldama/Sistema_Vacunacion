<?php
if (PHP_SAPI !== "cli" || !function_exists("mb_strtolower")) {
    fwrite(STDERR, "Se requiere PHP CLI con mbstring.\n");
    exit(2);
}

$existente = [["id_unidad" => 1, "nombre_unidad" => "Unidad existente"]];
$pruebas = [
    ["Listado", ["filas" => $existente], [], "Unidad existente", 200],
    ["Alta válida", ["metodo" => "POST", "nombre" => "  Unidad nueva  "], ["Unidad nueva"], "", 302],
    ["Longitud máxima", ["metodo" => "POST", "nombre" => str_repeat("á", 150)], [str_repeat("á", 150)], "", 302],
    ["Duplicado", ["metodo" => "POST", "nombre" => "unidad existente", "filas" => $existente], [], "Ya existe una unidad", 200],
    ["Nombre vacío", ["metodo" => "POST", "nombre" => "   "], [], "Escribe un nombre", 200],
    ["Nombre demasiado largo", ["metodo" => "POST", "nombre" => str_repeat("á", 151)], [], "Escribe un nombre", 200],
    ["Nombre no textual", ["metodo" => "POST", "nombre" => ["inválido"]], [], "Escribe un nombre", 200],
    ["Supervisor no autorizado", ["rol" => "Supervisor", "metodo" => "POST", "nombre" => "Unidad"], [], "únicamente para el administrador", 403],
    ["Personal no autorizado", ["rol" => "Personal de unidad"], [], "únicamente para el administrador", 403],
    ["Sin sesión", ["sin_sesion" => true], [], "", 302],
    ["Formulario inválido", ["metodo" => "POST", "nombre" => "Unidad", "token_invalido" => true], [], "No fue posible validar el formulario", 400],
    ["Fallo de bloqueo", ["metodo" => "POST", "nombre" => "Unidad", "fallar_bloqueo" => true], [], "No se pudo registrar la unidad", 500],
    ["Fallo de inserción", ["metodo" => "POST", "nombre" => "Unidad", "fallar_insercion" => true], [], "No se pudo registrar la unidad", 500],
    ["Salida escapada", ["filas" => [["id_unidad" => 2, "nombre_unidad" => "<script>"]]], [], "&lt;script&gt;", 200]
];

$fallos = 0;
foreach ($pruebas as [$nombre, $entrada, $insertados, $texto, $status]) {
    $comando = [
        PHP_BINARY, "-n", "-d", "extension_dir=" . ini_get("extension_dir"),
        "-d", "extension=mbstring", __DIR__ . DIRECTORY_SEPARATOR . "unidades_entorno.php"
    ];
    $proceso = proc_open($comando, [0 => ["pipe", "r"], 1 => ["pipe", "w"], 2 => ["pipe", "w"]], $pipes);
    if (!is_resource($proceso)) {
        fwrite(STDERR, "No se pudo iniciar el entorno de prueba.\n");
        exit(2);
    }
    fwrite($pipes[0], json_encode($entrada, JSON_THROW_ON_ERROR));
    fclose($pipes[0]);
    $salida = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $errores = stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    $codigo = proc_close($proceso);
    $resultado = json_decode($salida, true);
    if ($codigo !== 0 || !is_array($resultado)) {
        fwrite(STDERR, "Error del entorno en $nombre: $errores $salida\n");
        exit(2);
    }
    $correcto = $resultado["insertados"] === $insertados
        && ($resultado["status"] ?: 200) === $status
        && str_contains($resultado["html"], $texto);
    if ($nombre === "Alta válida") {
        $correcto = $correcto && $resultado["mensaje"] === "La unidad se registró en la base central.";
    }
    if (!$correcto) {
        $fallos++;
        echo "FALLO: $nombre\n";
    }
}
echo "Pruebas de unidades: " . count($pruebas) . "; fallos: $fallos.\n";
exit($fallos ? 1 : 0);
