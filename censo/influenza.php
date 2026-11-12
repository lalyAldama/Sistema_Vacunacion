<?php
/**
 * Censo automático de influenza estacional.
 * Archivo independiente: no modifica index.php ni censo.php.
 * Requiere la conexión existente en ../config/conexion.php y mysqli $conexion.
 */
require_once __DIR__ . "/../config/seguridad.php";

if (!isset($_SESSION['id_usuario'])) {
    header('Location: ../index.php');
    exit;
}

require_once __DIR__ . '/../config/conexion.php';
require_once __DIR__ . '/../config/unidad_contexto.php';
$contextoUnidad = unidadObtenerContexto($conexion);
$soloConsultaCenso = !unidadEsPersonalDeUnidad();
$idUnidad = $contextoUnidad['id_unidad'];
unidadAsegurarUnidadEnPacienteCenso($conexion);

if (!isset($conexion) || !($conexion instanceof mysqli)) {
    http_response_code(500);
    exit('No se encontró la conexión mysqli $conexion en ../config/conexion.php.');
}
$conexion->set_charset('utf8mb4');
require_once __DIR__ . '/historial.php';
censoHistorialAsegurarTabla($conexion);

if (array_key_exists('historico_fecha', $_GET)) {
    censoHistorialMostrar(
        $conexion,
        'influenza',
        0,
        'CENSO NOMINAL DE INFLUENZA',
        'influenza.php',
        is_string($_GET['historico_fecha']) ? $_GET['historico_fecha'] : '',
        false,
        $idUnidad
    );
    exit;
}

$busqueda = isset($_GET['buscar']) && is_string($_GET['buscar'])
    ? trim($_GET['buscar'])
    : '';
$capturaHistorialActiva = $busqueda === '';
$tokenHistorial = $capturaHistorialActiva
    ? hash_hmac(
        'sha256',
        'censo_historial|influenza|0',
        $_SESSION['form_guard_secret']
    )
    : '';
$inicioFormularioHistorial = $capturaHistorialActiva ? time() - 3 : 0;
$nonceFormularioHistorial = $capturaHistorialActiva ? bin2hex(random_bytes(16)) : '';
$pruebaFormularioHistorial = $capturaHistorialActiva
    ? hash_hmac(
        'sha256',
        $inicioFormularioHistorial . '|' . $nonceFormularioHistorial,
        $_SESSION['form_guard_secret']
    )
    : '';

$mesActual = (int)date('n');
$temporadaActiva = ($mesActual >= 10 || $mesActual <= 3);
if (!$temporadaActiva) {
    ?>
    <!doctype html>
    <html lang="es">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Censo de influenza fuera de temporada</title>
        <link rel="stylesheet" href="css/censo.css">
        <link rel="stylesheet" href="../css/tema_reportes.css">
    </head>
    <body<?php if ($capturaHistorialActiva): ?>
        data-historial-tipo="influenza"
        data-historial-censo="0"
        data-historial-token="<?= htmlspecialchars($tokenHistorial, ENT_QUOTES, 'UTF-8') ?>"
        data-historial-form-inicio="<?= htmlspecialchars((string)$inicioFormularioHistorial, ENT_QUOTES, 'UTF-8') ?>"
        data-historial-form-nonce="<?= htmlspecialchars($nonceFormularioHistorial, ENT_QUOTES, 'UTF-8') ?>"
        data-historial-form-prueba="<?= htmlspecialchars($pruebaFormularioHistorial, ENT_QUOTES, 'UTF-8') ?>"
    <?php endif; ?>>
        <main class="pagina">
            <div class="barra-superior">
                <h1>CENSO NOMINAL DE INFLUENZA</h1>
            </div>
            <div class="panel-resultados sin-seleccion" role="status">
                No estamos en temporada invernal. El censo de influenza está disponible de octubre a marzo.
            </div>
            <section class="panel-filtros consulta-historica">
                <form method="get" action="influenza.php" class="buscador">
                    <label for="fecha-historica">Consultar el censo en una fecha:</label>
                    <input id="fecha-historica" type="date" name="historico_fecha" max="<?= date('Y-m-d') ?>" required>
                    <button class="btn btn-azul btn-icono" type="submit" aria-label="Consultar historial" title="Consultar historial">
                        <svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <circle cx="10.8" cy="10.8" r="6.8"></circle><path d="m16 16 5 5"></path>
                        </svg>
                    </button>
                </form>
            </section>
            <a class="btn btn-censo" href="index.php">VOLVER AL CENSO</a>
        </main>
        <?php if ($capturaHistorialActiva): ?>
            <script src="js/historial.js"></script>
        <?php endif; ?>
    </body>
    </html>
    <?php
    exit;
}

function h($valor): string {
    return htmlspecialchars((string)($valor ?? ''), ENT_QUOTES, 'UTF-8');
}
function edadDetalle(?string $fecha): array {
    if (!$fecha || $fecha === '0000-00-00') return [null, null];
    try {
        $nacimiento = new DateTime($fecha);
        $hoy = new DateTime('today');
        if ($nacimiento > $hoy) return [null, null];
        $d = $nacimiento->diff($hoy);
        return [$d->y, ($d->y * 12) + $d->m];
    } catch (Throwable $e) {
        return [null, null];
    }
}
function fechaDMY(?string $fecha): string {
    if (!$fecha || $fecha === '0000-00-00') return '';
    $t = strtotime($fecha);
    return $t ? date('d/m/Y', $t) : '';
}
function esFechaDeHoy(?string $fecha): bool {
    return $fecha !== null && substr($fecha, 0, 10) === date('Y-m-d');
}

$pacientes = [];
$error = '';

$sqlPacientes = "SELECT p.id_paciente, p.ap_pat, p.ap_mat, p.nombres,
                        p.fecha_nacimiento, p.sexo, p.calle, p.num, p.colonia,
                        p.telefono_paciente, p.embarazo, p.personal_salud,
                        p.derechoabiencia
                 FROM PACIENTE p
                 INNER JOIN PACIENTE_CENSO pc ON pc.id_paciente = p.id_paciente
                 WHERE pc.id_unidad = ?
                 ORDER BY p.ap_pat, p.ap_mat, p.nombres";
$stmtPacientes = $conexion->prepare($sqlPacientes);
if (!$stmtPacientes) {
    http_response_code(500);
    exit('No se pudo preparar la consulta de pacientes: ' . $conexion->error);
}
$stmtPacientes->bind_param('i', $idUnidad);
if (!$stmtPacientes->execute()) {
    http_response_code(500);
    exit('No se pudieron consultar los pacientes: ' . $stmtPacientes->error);
}
$resultado = $stmtPacientes->get_result();
if (!$resultado) {
    $error = 'No se pudieron consultar los pacientes: ' . $conexion->error;
} else {
    while ($p = $resultado->fetch_assoc()) {
        [$edadAnios, $edadMeses] = edadDetalle($p['fecha_nacimiento'] ?? null);
        $p['edad_anios'] = $edadAnios;
        $p['edad_meses'] = $edadMeses;
        $p['grupos_objetivo'] = [];
        $p['grupos_riesgo'] = [];
        $p['elegible'] = false;

        // Grupos objetivo tal como aparecen en el Excel de la temporada 2025-2026.
        if ($edadMeses !== null && $edadMeses >= 6 && $edadMeses <= 59) {
            $p['grupos_objetivo'][] = '6 a 59 meses';
        }
        if ($edadAnios !== null && $edadAnios >= 60) {
            $p['grupos_objetivo'][] = '60 y más';
        }
        if ((int)($p['embarazo'] ?? 0) === 1) {
            $p['grupos_objetivo'][] = 'Embarazadas';
        }
        if ((int)($p['personal_salud'] ?? 0) === 1) {
            $p['grupos_objetivo'][] = 'Personal de salud';
        }

        // Los nombres se obtienen del catálogo para respetar la información guardada.
        $sqlRiesgo = "SELECT c.id_comorbilidad, c.nombre_comorbilidad
                      FROM PACIENTE_COMORBILIDAD pc
                      INNER JOIN COMORBILIDAD c ON c.id_comorbilidad = pc.id_comorbilidad
                      WHERE pc.id_paciente = ?
                      ORDER BY c.nombre_comorbilidad";
        $stmtRiesgo = $conexion->prepare($sqlRiesgo);
        if ($stmtRiesgo) {
            $stmtRiesgo->bind_param('s', $p['id_paciente']);
            $stmtRiesgo->execute();
            $resRiesgo = $stmtRiesgo->get_result();
            while ($r = $resRiesgo->fetch_assoc()) {
                $p['grupos_riesgo'][] = $r['nombre_comorbilidad'];
            }
            $stmtRiesgo->close();
        }

        // Se incluye por grupo objetivo o por al menos un grupo de riesgo registrado.
        $p['elegible'] = !empty($p['grupos_objetivo']) || !empty($p['grupos_riesgo']);
        if ($p['elegible']) $pacientes[] = $p;
    }
    $resultado->free();
}
$stmtPacientes->close();

// Buscar aplicaciones relacionadas con biológicos cuyo nombre incluya INFLUENZA, COVID o NEUMO.
// Los nombres se identifican desde BIOLOGICO, sin asumir IDs fijos del catálogo.
function obtenerAplicaciones(mysqli $conexion, string $idPaciente, int $idUnidad): array {
    $sql = "SELECT av.fecha_aplicacion, av.dosis_cero, av.observaciones,
                   b.nombre_biologico, d.nombre_dosis, l.numero_lote
            FROM APLICACION_VACUNA av
            INNER JOIN ESQUEMA_BIOLOGICO_DOSIS ebd
                ON ebd.id_esquema_biologico_dosis = av.id_esquema_biologico_dosis
            INNER JOIN ESQUEMA_BIOLOGICO eb
                ON eb.id_esquema_biologico = ebd.id_esquema_biologico
            INNER JOIN BIOLOGICO b ON b.id_biologico = eb.id_biologico
            INNER JOIN DOSIS d ON d.id_dosis = ebd.id_dosis
            LEFT JOIN LOTE l ON l.id_lote = av.id_lote
            WHERE av.id_paciente = ?
              AND av.id_unidad = ?
              AND (UPPER(b.nombre_biologico) LIKE '%INFLUENZA%'
                   OR UPPER(b.nombre_biologico) LIKE '%COVID%'
                   OR UPPER(b.nombre_biologico) LIKE '%NEUMO%')
            ORDER BY av.fecha_aplicacion DESC";
    $lista = [];
    $stmt = $conexion->prepare($sql);
    if (!$stmt) {
        http_response_code(500);
        exit('No se pudo preparar la consulta de aplicaciones: ' . $conexion->error);
    }
    $stmt->bind_param('si', $idPaciente, $idUnidad);
    if (!$stmt->execute()) {
        http_response_code(500);
        $error = $stmt->error;
        $stmt->close();
        exit('No se pudieron consultar las aplicaciones: ' . $error);
    }
    $res = $stmt->get_result();
    while ($fila = $res->fetch_assoc()) $lista[] = $fila;
    $stmt->close();
    return $lista;
}

if ($busqueda !== '') {
    $pacientes = array_values(array_filter($pacientes, function ($p) use ($busqueda) {
        $nombre = trim(($p['ap_pat'] ?? '') . ' ' . ($p['ap_mat'] ?? '') . ' ' . ($p['nombres'] ?? ''));
        $domicilio = trim(($p['calle'] ?? '') . ' ' . ($p['num'] ?? '') . ' ' . ($p['colonia'] ?? ''));
        return mb_stripos($nombre, $busqueda) !== false || mb_stripos($domicilio, $busqueda) !== false;
    }));
}

?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Censo Nominal, vacunación para la temporada invernal 2026-2027</title>
<link rel="stylesheet" href="css/censo.css">
<style>
.encabezado-influenza{margin:12px 0 16px;color:#111;font-family:Arial,sans-serif}
.cabecera-superior-influenza{display:flex;flex-direction:column;align-items:stretch;gap:5px}
.titulos-influenza{text-align:center;font-size:10px;font-weight:bold;line-height:1.35;padding:0 4px}
.titulos-influenza strong{display:block;margin-bottom:3px}
.logos-institucionales-influenza{display:block;width:48%;max-width:620px;height:auto;max-height:38px;object-fit:contain;object-position:right center;align-self:flex-end}
.datos-administrativos{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));border:1px solid #777;font-size:9px}
.bloque-admin-influenza{min-width:0;padding:3px 5px}
.dato-admin-influenza{display:flex;align-items:center;gap:4px;min-width:0;min-height:22px;border-bottom:1px solid #777;padding:2px 1px}
.bloque-admin-influenza .dato-admin-influenza:last-child{border-bottom:0}
.dato-admin-influenza>span{flex:0 0 68px;font-size:8px;font-weight:bold}
.dato-admin-influenza .admin-valor{flex:1;min-width:0;min-height:17px;border-bottom:1px solid #555;padding:1px 3px;overflow-wrap:anywhere}
.datos-inferiores-influenza{grid-column:1/-1;display:grid;grid-template-columns:.8fr .8fr 1.3fr 1fr;border-top:1px solid #777;padding:3px 5px;gap:5px}
.datos-inferiores-influenza .dato-admin-influenza{border:0}
.tabla-scroll{overflow-x:auto}
.censo-influenza{border-collapse:collapse;width:max-content;min-width:2600px;table-layout:fixed;font-size:10px;background:#fff}
.censo-influenza th,.censo-influenza td{border:1px solid #555;padding:3px;text-align:center;vertical-align:middle;overflow-wrap:anywhere}
.censo-influenza th{background:#d9e2f3;font-weight:bold}
.censo-influenza .grupo-principal{background:#d9e2f3}
.censo-influenza .grupo-objetivo{background:#e2f0d9}
.censo-influenza .grupo-riesgo{background:#fce4d6}
.censo-influenza .grupo-vacuna{background:#e2f0d9}
.censo-influenza .nombre{text-align:left;min-width:115px}
.censo-influenza .domicilio{text-align:left;min-width:120px}
.censo-influenza .curp-nota td{height:15px;text-align:left;background:#f7f7f7}
.censo-influenza .marca{font-weight:bold}
.censo-influenza .sin-dato{color:#777}
.censo-influenza .celda-vacuna{min-width:130px;text-align:left}
.censo-influenza .aplicacion-hoy{color:#c00;font-weight:700}
.acciones-influenza{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
.aviso-influenza{margin-top:12px;padding:10px;background:#fff8e1;border:1px solid #d6b656}
.encabezado-editable [contenteditable="true"]{outline:none;cursor:text;border-radius:2px}
.encabezado-editable [contenteditable="true"]:hover{background:#fffbea;outline:1px dashed #aaa}
.encabezado-editable [contenteditable="true"]:focus{background:#fffbea;outline:1px dashed #4472c4}
@media(max-width:800px){.datos-administrativos{grid-template-columns:1fr}.bloque-admin-influenza,.datos-inferiores-influenza{grid-column:1}.datos-inferiores-influenza{grid-template-columns:1fr 1fr}.logos-institucionales-influenza{width:72%;max-width:100%;max-height:34px}}
@media print{body{background:#fff;padding:0}.barra-superior,.panel-filtros,.aviso-influenza,.pie-influenza{display:none!important}.encabezado-influenza{break-inside:avoid}.tabla-scroll{overflow:visible}.censo-influenza{font-size:8px}}
</style>
<link rel="stylesheet" href="../css/tema_reportes.css">
</head>
<body<?php if ($capturaHistorialActiva): ?>
    data-historial-tipo="influenza"
    data-historial-censo="0"
    data-historial-token="<?= h($tokenHistorial) ?>"
    data-historial-form-inicio="<?= h($inicioFormularioHistorial) ?>"
    data-historial-form-nonce="<?= h($nonceFormularioHistorial) ?>"
    data-historial-form-prueba="<?= h($pruebaFormularioHistorial) ?>"
    data-capturar-influenza="0"
<?php endif; ?>>
<div class="pagina">
    <div class="barra-superior">
        <div>
            <h1>CENSO NOMINAL DE INFLUENZA</h1>
        </div>
        <div class="acciones-influenza">
            <a class="btn btn-censo" href="index.php">VOLVER AL CENSO</a>
        </div>
    </div>

    <?php if ($error !== ''): ?>
        <div class="sin-resultados"><?= h($error) ?></div>
    <?php endif; ?>

    <div class="panel-filtros">
        <form class="buscador" method="get" action="influenza.php">
            <input type="text" name="buscar" value="<?= h($busqueda) ?>" placeholder="Buscar por nombre o apellido">
            <button class="btn btn-azul btn-icono" type="submit" aria-label="Buscar" title="Buscar">
                <svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <circle cx="10.8" cy="10.8" r="6.8"></circle><path d="m16 16 5 5"></path>
                </svg>
            </button>
        </form>
    </div>
    <section class="panel-filtros consulta-historica">
        <form method="get" action="influenza.php" class="buscador">
            <input id="fecha-historica" type="date" name="historico_fecha" max="<?= date('Y-m-d') ?>" required>
            <button type="submit" class="btn btn-azul btn-icono" aria-label="Consultar historial" title="Consultar historial">
                <svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <circle cx="10.8" cy="10.8" r="6.8"></circle><path d="m16 16 5 5"></path>
                </svg>
            </button>
        </form>
    </section>

    <section class="encabezado-influenza encabezado-editable" id="encabezadoEditable">
        <div class="cabecera-superior-influenza">
            <div class="titulos-influenza">
                <strong>Subsecretaría de Políticas de Salud y Bienestar Poblacional</strong>
                <div>Centro Nacional para la Salud de la Infancia y la Adolescencia</div>
                <div>Programa de Vacunación Universal</div>
                <strong>Censo Nominal, vacunación para la temporada invernal 2026-2027</strong>
            </div>
            <img class="logos-institucionales-influenza" src="img/encabezado-censia.png" alt="Logotipos institucionales de salud">
        </div>
        <div class="datos-administrativos">
            <div class="bloque-admin-influenza">
                <div class="dato-admin-influenza"><span>ENTIDAD FEDERATIVA</span><strong class="admin-valor" contenteditable="true" data-edit-key="entidad">CHIHUAHUA</strong></div>
                <div class="dato-admin-influenza"><span>INSTITUCIÓN</span><strong class="admin-valor" contenteditable="true" data-edit-key="institucion">SSCH</strong></div>
            </div>
            <div class="bloque-admin-influenza">
                <div class="dato-admin-influenza"><span>JURISDICCIÓN</span><strong class="admin-valor" contenteditable="true" data-edit-key="jurisdiccion">3 PARRAL</strong></div>
                <div class="dato-admin-influenza"><span>MUNICIPIO</span><strong class="admin-valor" contenteditable="true" data-edit-key="municipio">HIDALGO DEL PARRAL</strong></div>
            </div>
            <div class="bloque-admin-influenza">
                <div class="dato-admin-influenza"><span>LOCALIDAD</span><strong class="admin-valor" contenteditable="true" data-edit-key="localidad">HIDALGO DEL PARRAL</strong></div>
                <div class="dato-admin-influenza"><span>UNIDAD DE SALUD</span><strong class="admin-valor" contenteditable="true" data-edit-key="unidad"><?= h($contextoUnidad['nombre_unidad']) ?></strong></div>
            </div>
            <div class="datos-inferiores-influenza">
                <div class="dato-admin-influenza"><span>AGEB</span><strong class="admin-valor" contenteditable="true" data-edit-key="ageb">__________</strong></div>
                <div class="dato-admin-influenza"><span>SECTOR</span><strong class="admin-valor" contenteditable="true" data-edit-key="sector">__________</strong></div>
                <div class="dato-admin-influenza"><span>FECHA</span><strong class="admin-valor" contenteditable="true" data-edit-key="fecha"><?= date('d/m/Y') ?></strong></div>
                <div class="dato-admin-influenza"><span>NOMBRE DEL VACUNADOR</span><strong class="admin-valor" contenteditable="true" data-edit-key="vacunador">____________________</strong></div>
            </div>
        </div>
    </section>

    <div class="tabla-scroll">
    <table class="censo-influenza">
        <thead>
        <tr>
            <th rowspan="2">No.</th>
            <th colspan="3" class="grupo-principal">NOMBRE</th>
            <th colspan="3" class="grupo-principal">FECHA DE NACIMIENTO</th>
            <th colspan="2" class="grupo-principal">EDAD</th>
            <th rowspan="2">SEXO</th>
            <th rowspan="2">FECHA DE APLICACIÓN</th>
            <th colspan="3" class="grupo-principal">DOMICILIO</th>
            <th colspan="2" class="grupo-objetivo">GRUPO OBJETIVO</th>
            <th colspan="15" class="grupo-riesgo">GRUPO DE RIESGO***</th>
            <th rowspan="2" class="grupo-riesgo">OTROS GRUPOS DE RIESGO</th>
            <th colspan="3" class="grupo-vacuna">ESQUEMA DE VACUNACIÓN ANTI INFLUENZA ESTACIONAL</th>
            <th class="grupo-vacuna">ESQUEMA DE VACUNACIÓN CONTRA LA COVID-19</th>
            <th class="grupo-vacuna">ESQUEMA DE VACUNACIÓN ANTINEUMOCÓCICA</th>
            <th rowspan="2">NO. DE LOTE</th>
            <th rowspan="2">DERECHOHABIENCIA</th>
        </tr>
        <tr>
            <th>PATERNO</th><th>MATERNO</th><th>NOMBRE</th>
            <th>DÍA</th><th>MES</th><th>AÑO</th>
            <th>AÑOS</th><th>MESES</th>
            <th>CALLE</th><th>No.</th><th>COLONIA</th>
            <th>6 A 59 MESES*</th><th>60 Y MÁS</th>
            <th>EMBARAZADAS</th><th>PERSONAL DE SALUD</th><th>VIH/sida</th>
            <th>DIABETES MELLITUS</th><th>OBESIDAD MÓRBIDA</th><th>CARDIOPATÍAS AGUDAS O CRÓNICAS</th>
            <th>ENFERMEDAD PULMONAR CRÓNICA, INCLUYE EPOC Y ASMA</th><th>CÁNCER</th>
            <th>ENFERMEDADES CARDIACAS O PULMONARES CONGÉNITAS U OTROS PADECIMIENTOS CRÓNICOS QUE REQUIERAN CONSUMO PROLONGADO DE SALICILATOS*</th>
            <th>INSUFICIENCIA RENAL</th><th>INMUNOSUPRESIÓN ADQUIRIDA POR ENFERMEDAD O TRATAMIENTO, EXCEPTO VIH/sida</th>
            <th>HIPERTENSIÓN ARTERIAL ESENCIAL**</th><th>PERSONAS CON DISCAPACIDAD**</th>
            <th>PERSONAS CON TRASPLANTE DE ÓRGANOS**</th><th>FIBROSIS QUÍSTICA**</th>
            <th>1A DOSIS</th><th>2A DOSIS</th><th>DOSIS ANUAL</th>
            <th>DOSIS ÚNICA</th><th>DOSIS ÚNICA</th>
        </tr>
        </thead>
        <tbody>
        <?php if (!$pacientes): ?>
            <tr><td colspan="39" class="sin-dato">No hay pacientes que cumplan los criterios registrados<?= $busqueda !== '' ? ' para esta búsqueda' : '' ?>.</td></tr>
        <?php else: ?>
            <?php foreach ($pacientes as $i => $p): ?>
                <?php
                    $apps = obtenerAplicaciones($conexion, $p['id_paciente'], $idUnidad);
                    $fechaNac = $p['fecha_nacimiento'] ?? '';
                    $tsNac = $fechaNac ? strtotime($fechaNac) : false;
                    $diaNac = $tsNac ? date('d', $tsNac) : '';
                    $mesNac = $tsNac ? date('m', $tsNac) : '';
                    $anioNac = $tsNac ? date('Y', $tsNac) : '';
                    $riesgosTexto = mb_strtolower(implode(' | ', $p['grupos_riesgo'] ?? []));
                    $objetivosTexto = mb_strtolower(implode(' | ', $p['grupos_objetivo'] ?? []));
                    $aplicacionInfluenza = null;
                    $aplicacionCovid = null;
                    $aplicacionNeumo = null;
                    foreach ($apps as $app) {
                        $bio = mb_strtolower($app['nombre_biologico'] ?? '');
                        if (str_contains($bio, 'influenza') && $aplicacionInfluenza === null) $aplicacionInfluenza = $app;
                        if (str_contains($bio, 'covid') && $aplicacionCovid === null) $aplicacionCovid = $app;
                        if ((str_contains($bio, 'neumo') || str_contains($bio, 'neumoc')) && $aplicacionNeumo === null) $aplicacionNeumo = $app;
                    }
                    $fechaAplicacion = $aplicacionInfluenza['fecha_aplicacion'] ?? ($aplicacionCovid['fecha_aplicacion'] ?? ($aplicacionNeumo['fecha_aplicacion'] ?? ''));
                    $lotes = [];
                    foreach ($apps as $app) {
                        $bio = mb_strtolower($app['nombre_biologico'] ?? '');
                        if (str_contains($bio, 'influenza') || str_contains($bio, 'covid') || str_contains($bio, 'neumo')) {
                            if (!empty($app['numero_lote'])) $lotes[] = $app['numero_lote'];
                        }
                    }
                    $tieneRiesgo = function(array $palabras) use ($riesgosTexto): bool {
                        foreach ($palabras as $palabra) if (str_contains($riesgosTexto, mb_strtolower($palabra))) return true;
                        return false;
                    };
                    $tieneObjetivo = function(array $palabras) use ($objetivosTexto): bool {
                        foreach ($palabras as $palabra) if (str_contains($objetivosTexto, mb_strtolower($palabra))) return true;
                        return false;
                    };
                    $marcar = function(bool $valor): string { return $valor ? '<strong class="marca-grupo">X</strong>' : ''; };
                    $edadAnios = $p['edad_anios'];
                    $edadMesesRestantes = $edadAnios !== null ? ((int)$p['edad_meses'] % 12) : null;
                    $appsInfluenza = array_values(array_filter($apps, fn($a) => str_contains(mb_strtolower($a['nombre_biologico'] ?? ''), 'influenza')));
                    $dosisInfluenza = ['primera'=>null, 'segunda'=>null, 'anual'=>null];
                    foreach ($appsInfluenza as $app) {
                        $dosisTxt = mb_strtolower($app['nombre_dosis'] ?? '');
                        if (str_contains($dosisTxt, '2') || str_contains($dosisTxt, 'segunda')) $dosisInfluenza['segunda'] = $app;
                        elseif (str_contains($dosisTxt, '1') || str_contains($dosisTxt, 'primera')) $dosisInfluenza['primera'] = $app;
                        else $dosisInfluenza['anual'] = $app;
                    }
                    $mostrarApp = function($app): string {
                        if (!$app) return '';
                        $contenido = h(fechaDMY($app['fecha_aplicacion'] ?? '')) . ' ' . h($app['numero_lote'] ?? '');
                        return esFechaDeHoy($app['fecha_aplicacion'] ?? null)
                            ? '<span class="aplicacion-hoy">' . $contenido . '</span>'
                            : $contenido;
                    };
                ?>
                <tr>
                    <td><?= $i + 1 ?></td>
                    <td class="nombre"><?= h($p['ap_pat'] ?? '') ?></td>
                    <td class="nombre"><?= h($p['ap_mat'] ?? '') ?></td>
                    <td class="nombre"><?= h($p['nombres'] ?? '') ?></td>
                    <td><?= h($diaNac) ?></td><td><?= h($mesNac) ?></td><td><?= h($anioNac) ?></td>
                    <td><?= $edadAnios === null ? '' : (int)$edadAnios ?></td>
                    <td><?= $edadMesesRestantes === null ? '' : (int)$edadMesesRestantes ?></td>
                    <td><?= h($p['sexo'] ?? '') ?></td>
                    <td><?= esFechaDeHoy($fechaAplicacion) ? '<span class="aplicacion-hoy">' . h(fechaDMY($fechaAplicacion)) . '</span>' : h(fechaDMY($fechaAplicacion)) ?></td>
                    <td class="domicilio"><?= h($p['calle'] ?? '') ?></td><td><?= h($p['num'] ?? '') ?></td><td class="domicilio"><?= h($p['colonia'] ?? '') ?></td>
                    <td><?= $marcar($tieneObjetivo(['6 a 59', '6-59', 'meses'])) ?></td>
                    <td><?= $marcar($tieneObjetivo(['60 y más', '60+', 'adultos mayores'])) ?></td>
                    <td><?= $marcar($tieneObjetivo(['embaraz'])) ?></td>
                    <td><?= $marcar($tieneObjetivo(['personal de salud'])) ?></td>
                    <td><?= $marcar($tieneRiesgo(['vih', 'sida'])) ?></td>
                    <td><?= $marcar($tieneRiesgo(['diabetes'])) ?></td>
                    <td><?= $marcar($tieneRiesgo(['obesidad'])) ?></td>
                    <td><?= $marcar($tieneRiesgo(['cardiopat', 'afecciones cardíacas', 'enfermedad cardiovascular'])) ?></td>
                    <td><?= $marcar($tieneRiesgo(['pulmonar', 'epoc', 'asma'])) ?></td>
                    <td><?= $marcar($tieneRiesgo(['cáncer', 'cancer'])) ?></td>
                    <td><?= $marcar($tieneRiesgo(['congénit', 'congenit', 'salicilatos'])) ?></td>
                    <td><?= $marcar($tieneRiesgo(['renal'])) ?></td>
                    <td><?= $marcar($tieneRiesgo(['inmunosupresión', 'inmunosupresion'])) ?></td>
                    <td><?= $marcar($tieneRiesgo(['hipertensión', 'hipertension'])) ?></td>
                    <td><?= $marcar($tieneRiesgo(['discapacidad'])) ?></td>
                    <td><?= $marcar($tieneRiesgo(['trasplante', 'transplante'])) ?></td>
                    <td><?= $marcar($tieneRiesgo(['fibrosis quística', 'fibrosis quistica'])) ?></td>
                    <td><?= h(implode(', ', $p['grupos_riesgo'] ?? [])) ?></td>
                    <td class="celda-vacuna"><?= $mostrarApp($dosisInfluenza['primera']) ?></td>
                    <td class="celda-vacuna"><?= $mostrarApp($dosisInfluenza['segunda']) ?></td>
                    <td class="celda-vacuna"><?= $mostrarApp($dosisInfluenza['anual']) ?></td>
                    <td class="celda-vacuna"><?= $mostrarApp($aplicacionCovid) ?></td>
                    <td class="celda-vacuna"><?= $mostrarApp($aplicacionNeumo) ?></td>
                    <td><?= h(implode(', ', array_unique($lotes))) ?></td>
                    <td><?= h($p['derechoabiencia'] ?? '') ?></td>
                </tr>
                <tr class="curp-nota"><td colspan="39">CURP/ENTIDAD DE NACIMIENTO: <?= h($p['CURP'] ?? $p['curp'] ?? '') ?></td></tr>
            <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
    </table>
    </div>
</div>
<script>
document.querySelectorAll("#encabezadoEditable [data-edit-key]").forEach(function (campo) {
    if (<?php echo $soloConsultaCenso ? "true" : "false"; ?>) {
        campo.contentEditable = "false";
        campo.setAttribute("aria-readonly", "true");
        return;
    }
    var clave = "censo_admin_influenza_<?php echo $idUnidad; ?>_" + campo.getAttribute("data-edit-key");
    try {
        var guardado = localStorage.getItem(clave);
        if (guardado !== null) campo.textContent = guardado;
    } catch (error) {}
    campo.addEventListener("input", function () {
        try { localStorage.setItem(clave, campo.textContent); } catch (error) {}
    });
});
</script>
<?php if ($capturaHistorialActiva): ?>
    <script src="js/historial.js"></script>
<?php endif; ?>
</body>
</html>
