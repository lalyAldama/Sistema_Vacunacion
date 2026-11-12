<?php

if (PHP_SAPI !== "cli" || class_exists("mysqli", false) || !function_exists("mb_strtolower")) {
    fwrite(STDERR, "Ejecuta esta prueba con PHP CLI sin mysqli y con mbstring habilitado.\n");
    exit(2);
}

set_error_handler(function ($nivel, $mensaje, $archivo, $linea) {
    throw new ErrorException($mensaje, 0, $nivel, $archivo, $linea);
});

if (!class_exists("mysqli", false)) {
    class mysqli
    {
        public $connect_error = null;
        public $error = "";

        public function __construct(...$argumentos) {}
        public function set_charset($charset) { return true; }

        public function query($sql)
        {
            if (strpos($sql, "CREATE TABLE IF NOT EXISTS") === 0) {
                return true;
            }
            throw new RuntimeException("Consulta directa inesperada en el simulador: " . $sql);
        }

        public function prepare($sql)
        {
            return new SentenciaSimuladaPaloteo($sql);
        }
    }
}

class ResultadoSimuladoPaloteo
{
    private $filas;
    private $indice = 0;

    public function __construct($filas) { $this->filas = $filas; }
    public function fetch_assoc() { return $this->filas[$this->indice++] ?? null; }
}

class SentenciaSimuladaPaloteo
{
    private $sql;
    public $error = "";

    public function __construct($sql) { $this->sql = $sql; }
    public function bind_param($tipos, &...$valores) { return true; }
    public function execute() { return true; }
    public function close() {}

    public function get_result()
    {
        global $casoPaloteo;
        if (strpos($this->sql, "information_schema.COLUMNS") !== false) {
            $filas = [["existe" => 1]];
        } elseif (strpos($this->sql, "FROM UNIDAD") !== false) {
            $filas = [["id_unidad" => 1, "nombre_unidad" => "Unidad ficticia"]];
        } elseif (strpos($this->sql, "FROM USUARIO") !== false) {
            $filas = [["nombre" => "Prueba", "ap_pat" => "", "ap_mat" => ""]];
        } elseif (strpos($this->sql, "FROM PALOTEO_GENERAL") !== false) {
            $filas = [];
        } elseif (strpos($this->sql, "FROM APLICACION_VPH_ANONIMA") !== false) {
            $filas = $casoPaloteo["anonimas"] ?? [];
        } elseif (strpos($this->sql, "FROM APLICACION_VACUNA av") !== false) {
            $filas = $casoPaloteo["aplicaciones"] ?? [];
        } else {
            throw new RuntimeException("Consulta preparada inesperada en el simulador: " . $this->sql);
        }
        return new ResultadoSimuladoPaloteo($filas);
    }
}

$casoPaloteo = json_decode(stream_get_contents(STDIN), true);
if (!is_array($casoPaloteo) || json_last_error() !== JSON_ERROR_NONE) {
    throw new RuntimeException("No se pudo leer el caso ficticio.");
}
$_SERVER["REQUEST_METHOD"] = "GET";
$_GET = ["mes" => $casoPaloteo["mes"] ?? 5, "anio" => 2024];
session_start();
$_SESSION["id_usuario"] = 1;
$_SESSION["nombre_rol"] = "Administrador";
$_SESSION["id_unidad_seleccionada"] = 1;
define(
    !empty($casoPaloteo["anual"])
        ? "INFORME_ANUAL_SOLICITANDO_CONTEOS"
        : "INFORME_MENSUAL_SOLICITANDO_CONTEOS",
    true
);
ob_start();
if (!empty($casoPaloteo["informe"])) {
    require __DIR__ . "/../Informes/mensual_general.php";
    if (isset($casoPaloteo["conteos_prueba"])) {
        foreach ($casoPaloteo["conteos_prueba"] as $id => $cantidad) {
            if (!isset($datosPaloteo["automaticos"][$id])) {
                throw new RuntimeException("Renglón de prueba desconocido: " . $id);
            }
            $datosPaloteo["automaticos"][$id]["dias"][15] = $cantidad;
        }
        $reporteInforme = obtenerRenglonesOficialesInforme($plantillaInforme, $datosPaloteo);
        $vistaInforme = construirVistaOficialInforme(
            $plantillaInforme, $reporteInforme, $datosCabecera, $mesInforme, $anioInforme, $nombreMesesInforme
        );
    }
    $excel = prepararArchivoExcelInforme(
        $plantillaInforme, $reporteInforme, $datosPaloteo, $datosCabecera, $mesInforme, $anioInforme
    );
    $resultado = ["reporte" => $reporteInforme, "vista" => $vistaInforme, "excel" => $excel];
} else {
    $resultado = require __DIR__ . "/../paloteo/paloteo_general.php";
}
while (ob_get_level() > 0) {
    ob_end_clean();
}
if (!is_array($resultado)) {
    throw new RuntimeException("El paloteo no devolvió los conteos esperados.");
}
echo json_encode($resultado, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
