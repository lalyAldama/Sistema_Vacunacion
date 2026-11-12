<?php
if (PHP_SAPI !== "cli" || class_exists("mysqli", false) || !function_exists("mb_strtolower")) {
    fwrite(STDERR, "Se requiere PHP CLI sin mysqli y con mbstring habilitado.\n");
    exit(2);
}

$caso = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
$filas = $caso["filas"] ?? [];
$insertados = [];

if (!class_exists("mysqli", false)) {
    class mysqli
    {
        public $connect_error = null;
        public function __construct(...$argumentos) {}
        public function set_charset($charset) { return true; }
        public function query($sql)
        {
            global $filas, $caso;
            if (strpos($sql, "GET_LOCK") !== false) {
                if (!empty($caso["fallar_bloqueo"])) {
                    return new ResultadoUnidadPrueba([["obtenido" => 0]]);
                }
                return new ResultadoUnidadPrueba([["obtenido" => 1]]);
            }
            if (strpos($sql, "RELEASE_LOCK") !== false) {
                return new ResultadoUnidadPrueba([["liberado" => 1]]);
            }
            if (strpos($sql, "SELECT id_unidad, nombre_unidad FROM UNIDAD") === 0) {
                return new ResultadoUnidadPrueba($filas);
            }
            throw new RuntimeException("Consulta inesperada: " . $sql);
        }
        public function prepare($sql) { return new SentenciaUnidadPrueba($sql); }
    }
}

class ResultadoUnidadPrueba
{
    private $filas;
    public function __construct($filas) { $this->filas = $filas; }
    public function fetch_assoc() { return array_shift($this->filas); }
    public function fetch_all($modo) { return $this->filas; }
    public function free() {}
}

class SentenciaUnidadPrueba
{
    private $sql;
    private $nombre;
    public function __construct($sql) { $this->sql = $sql; }
    public function bind_param($tipo, &$nombre) { $this->nombre = &$nombre; return true; }
    public function execute()
    {
        global $insertados, $caso;
        if (strpos($this->sql, "INSERT INTO UNIDAD") === 0) {
            if (!empty($caso["fallar_insercion"])) {
                throw new RuntimeException("Fallo de inserción simulado");
            }
            $insertados[] = $this->nombre;
        } elseif (strpos($this->sql, "SELECT id_unidad FROM UNIDAD") !== 0) {
            throw new RuntimeException("Sentencia inesperada: " . $this->sql);
        }
        return true;
    }
    public function get_result()
    {
        global $filas;
        foreach ($filas as $fila) {
            if (strcasecmp($fila["nombre_unidad"], $this->nombre) === 0) {
                return new ResultadoUnidadPrueba([$fila]);
            }
        }
        return new ResultadoUnidadPrueba([]);
    }
    public function close() {}
}

define("MYSQLI_ASSOC", 1);
$_SERVER["REQUEST_METHOD"] = $caso["metodo"] ?? "GET";
$_SERVER["SCRIPT_NAME"] = "/unidades/index.php";
session_start();
if (empty($caso["sin_sesion"])) {
    $_SESSION["id_usuario"] = 1;
}
$_SESSION["nombre_rol"] = $caso["rol"] ?? "Administrador";
$_SESSION["form_guard_secret"] = str_repeat("a", 64);
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $inicio = time() - 3;
    $nonce = str_repeat("b", 32);
    $_POST = [
        "nombre_unidad" => $caso["nombre"] ?? "",
        "_human_check" => "",
        "_form_started_at" => $inicio,
        "_form_proof" => $nonce . "." . hash_hmac("sha256", $inicio . "|" . $nonce, $_SESSION["form_guard_secret"])
    ];
    if (!empty($caso["token_invalido"])) {
        $_POST["_form_proof"] = "invalido";
    }
}
register_shutdown_function(function () {
    global $insertados;
    $salida = "";
    while (ob_get_level() > 0) {
        $salida = ob_get_clean() . $salida;
    }
    echo json_encode([
        "html" => $salida,
        "status" => http_response_code(),
        "insertados" => $insertados,
        "mensaje" => $_SESSION["unidad_central_mensaje"] ?? ""
    ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
});
ob_start();
require __DIR__ . "/../unidades/index.php";
