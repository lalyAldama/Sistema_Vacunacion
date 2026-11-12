<?php
require_once __DIR__ . "/../config/seguridad_administrador.php";
require_once __DIR__ . "/../config/conexion.php";
require_once __DIR__ . "/configuracion.php";

try {
    censoAsegurarTablaConfiguracion($conexion);
} catch (RuntimeException $e) {
    http_response_code(500);
    exit(htmlspecialchars($e->getMessage(), ENT_QUOTES, "UTF-8"));
}

$mensajeExito = "";
$mensajeError = "";

if (($_SERVER["REQUEST_METHOD"] ?? "") === "POST") {
    $validarEntero = static function ($valor) {
        if (!is_string($valor) && !is_int($valor)) {
            return false;
        }
        return filter_var($valor, FILTER_VALIDATE_INT);
    };
    $nombreEsquema = isset($_POST["nombre_esquema"]) && is_string($_POST["nombre_esquema"])
        ? trim($_POST["nombre_esquema"])
        : "";
    $nombreCenso = isset($_POST["nombre_censo"]) && is_string($_POST["nombre_censo"])
        ? trim($_POST["nombre_censo"])
        : "";
    $edadMinima = $validarEntero($_POST["edad_minima"] ?? null);
    $edadMaximaTexto = isset($_POST["edad_maxima"]) && is_string($_POST["edad_maxima"])
        ? trim($_POST["edad_maxima"])
        : "";
    $edadMaxima = $edadMaximaTexto === ""
        ? null
        : $validarEntero($edadMaximaTexto);
    $sexo = isset($_POST["sexo"]) && is_string($_POST["sexo"]) ? $_POST["sexo"] : "";

    $camposGrupo = [
        "embarazo",
        "poblacion_indigena_afromexicana",
        "migrante",
        "personal_salud",
        "jornalero_agricola"
    ];
    $criterios = [];
    foreach ($camposGrupo as $campo) {
        $valor = $_POST[$campo] ?? "";
        $criterios[$campo] = $valor === "" ? null : $validarEntero($valor);
    }

    $vacunas = isset($_POST["biologicos"]) && is_array($_POST["biologicos"])
        ? $_POST["biologicos"]
        : [];
    $dosisPorBiologico = isset($_POST["dosis_por_biologico"]) && is_array($_POST["dosis_por_biologico"])
        ? $_POST["dosis_por_biologico"]
        : [];

    $erroresValidacion = [];
    if ($nombreEsquema === "" || strlen($nombreEsquema) > 100) {
        $erroresValidacion[] = "El nombre del esquema es obligatorio y no debe exceder 100 caracteres.";
    }
    if ($nombreCenso === "" || strlen($nombreCenso) > 100) {
        $erroresValidacion[] = "El nombre del censo es obligatorio y no debe exceder 100 caracteres.";
    }
    if ($edadMinima === false || $edadMinima < 0 || $edadMinima > 120) {
        $erroresValidacion[] = "La edad mínima debe estar entre 0 y 120 años.";
    }
    if (
        $edadMaximaTexto !== ""
        && ($edadMaxima === false || $edadMaxima < 0 || $edadMaxima > 120)
    ) {
        $erroresValidacion[] = "La edad máxima debe estar entre 0 y 120 años o dejarse vacía.";
    }
    if (
        $edadMinima !== false
        && $edadMaxima !== null
        && $edadMaxima !== false
        && $edadMaxima < $edadMinima
    ) {
        $erroresValidacion[] = "La edad máxima no puede ser menor que la edad mínima.";
    }
    if (!in_array($sexo, ["", "F", "M"], true)) {
        $erroresValidacion[] = "Selecciona un valor válido para sexo.";
    }
    foreach ($criterios as $campo => $valor) {
        if ($valor !== null && $valor !== 0 && $valor !== 1) {
            $erroresValidacion[] = "Uno de los criterios de grupo poblacional no es válido.";
            break;
        }
    }
    if (!$vacunas) {
        $erroresValidacion[] = "Selecciona al menos un biológico y sus dosis para el esquema.";
    }

    $dosisSeleccionadas = [];
    foreach ($vacunas as $idBiologico) {
        $idBiologico = $validarEntero($idBiologico);
        $dosis = $idBiologico !== false
            ? ($dosisPorBiologico[(string)$idBiologico] ?? [])
            : [];
        if (!is_array($dosis) || !$dosis) {
            $erroresValidacion[] = "Cada biológico seleccionado debe incluir al menos una dosis.";
            break;
        }
        $dosisSeleccionadas[(int)$idBiologico] = [];
        foreach ($dosis as $idDosis) {
            $idDosis = $validarEntero($idDosis);
            if ($idDosis === false || $idDosis < 1) {
                $erroresValidacion[] = "Se recibió una dosis no válida.";
                break 2;
            }
            $dosisSeleccionadas[(int)$idBiologico][] = (int)$idDosis;
        }
    }

    if ($erroresValidacion) {
        $mensajeError = implode(" ", $erroresValidacion);
    } else {
        $idBiologicosValidos = [];
        $resultadoBiologicos = $conexion->query("SELECT id_biologico FROM BIOLOGICO");
        if (!$resultadoBiologicos) {
            http_response_code(500);
            exit("No se pudo consultar el catálogo de biológicos: " . htmlspecialchars($conexion->error, ENT_QUOTES, "UTF-8"));
        }
        while ($fila = $resultadoBiologicos->fetch_assoc()) {
            $idBiologicosValidos[(int)$fila["id_biologico"]] = true;
        }
        $resultadoBiologicos->free();

        $idDosisValidos = [];
        $resultadoDosis = $conexion->query("SELECT id_dosis FROM DOSIS");
        if (!$resultadoDosis) {
            http_response_code(500);
            exit("No se pudo consultar el catálogo de dosis: " . htmlspecialchars($conexion->error, ENT_QUOTES, "UTF-8"));
        }
        while ($fila = $resultadoDosis->fetch_assoc()) {
            $idDosisValidos[(int)$fila["id_dosis"]] = true;
        }
        $resultadoDosis->free();

        foreach ($dosisSeleccionadas as $idBiologico => $dosis) {
            if (!isset($idBiologicosValidos[$idBiologico])) {
                $mensajeError = "Uno de los biológicos seleccionados ya no está disponible.";
                break;
            }
            foreach ($dosis as $idDosis) {
                if (!isset($idDosisValidos[$idDosis])) {
                    $mensajeError = "Una de las dosis seleccionadas ya no está disponible.";
                    break 2;
                }
            }
        }

        if ($mensajeError === "") {
            try {
                if (!$conexion->begin_transaction()) {
                    throw new RuntimeException("No se pudo iniciar el registro del esquema y el censo.");
                }

                $stmtEsquema = $conexion->prepare(
                    "INSERT INTO ESQUEMA (nombre_esquema) VALUES (?)"
                );
                if (!$stmtEsquema) {
                    throw new RuntimeException("No se pudo preparar el nuevo esquema: " . $conexion->error);
                }
                $stmtEsquema->bind_param("s", $nombreEsquema);
                if (!$stmtEsquema->execute()) {
                    throw new RuntimeException("No se pudo guardar el esquema: " . $stmtEsquema->error);
                }
                $idEsquema = (int)$conexion->insert_id;
                $stmtEsquema->close();

                $stmtCenso = $conexion->prepare(
                    "INSERT INTO CENSO (nombre_censo, id_esquema) VALUES (?, ?)"
                );
                if (!$stmtCenso) {
                    throw new RuntimeException("No se pudo preparar el nuevo censo: " . $conexion->error);
                }
                $stmtCenso->bind_param("si", $nombreCenso, $idEsquema);
                if (!$stmtCenso->execute()) {
                    throw new RuntimeException("No se pudo guardar el censo: " . $stmtCenso->error);
                }
                $idCensoNuevo = (int)$conexion->insert_id;
                $stmtCenso->close();

                $edadMaximaGuardar = $edadMaximaTexto === "" ? null : (int)$edadMaxima;
                $sexoGuardar = $sexo === "" ? null : $sexo;
                $stmtConfiguracion = $conexion->prepare(
                    "INSERT INTO CENSO_CONFIGURACION (
                        id_censo, edad_minima, edad_maxima, sexo, embarazo,
                        poblacion_indigena_afromexicana, migrante,
                        personal_salud, jornalero_agricola
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
                );
                if (!$stmtConfiguracion) {
                    throw new RuntimeException("No se pudo preparar la regla del censo: " . $conexion->error);
                }
                $stmtConfiguracion->bind_param(
                    "iissiiiii",
                    $idCensoNuevo,
                    $edadMinima,
                    $edadMaximaGuardar,
                    $sexoGuardar,
                    $criterios["embarazo"],
                    $criterios["poblacion_indigena_afromexicana"],
                    $criterios["migrante"],
                    $criterios["personal_salud"],
                    $criterios["jornalero_agricola"]
                );
                if (!$stmtConfiguracion->execute()) {
                    throw new RuntimeException("No se pudo guardar la regla del censo: " . $stmtConfiguracion->error);
                }
                $stmtConfiguracion->close();

                $stmtEsquemaBiologico = $conexion->prepare(
                    "INSERT INTO ESQUEMA_BIOLOGICO (id_esquema, id_biologico) VALUES (?, ?)"
                );
                $stmtDosis = $conexion->prepare(
                    "INSERT INTO ESQUEMA_BIOLOGICO_DOSIS (id_esquema_biologico, id_dosis) VALUES (?, ?)"
                );
                if (!$stmtEsquemaBiologico || !$stmtDosis) {
                    throw new RuntimeException("No se pudieron preparar los biológicos y dosis del esquema: " . $conexion->error);
                }
                foreach ($dosisSeleccionadas as $idBiologico => $dosis) {
                    $stmtEsquemaBiologico->bind_param("ii", $idEsquema, $idBiologico);
                    if (!$stmtEsquemaBiologico->execute()) {
                        throw new RuntimeException("No se pudo asociar un biológico al esquema: " . $stmtEsquemaBiologico->error);
                    }
                    $idEsquemaBiologico = (int)$conexion->insert_id;
                    foreach ($dosis as $idDosis) {
                        $stmtDosis->bind_param("ii", $idEsquemaBiologico, $idDosis);
                        if (!$stmtDosis->execute()) {
                            throw new RuntimeException("No se pudo asociar una dosis al biológico: " . $stmtDosis->error);
                        }
                    }
                }
                $stmtEsquemaBiologico->close();
                $stmtDosis->close();

                $resultadoPacientes = $conexion->query(
                    "SELECT p.id_paciente, p.fecha_nacimiento, p.sexo, p.embarazo,
                            p.poblacion_indigena_afromexicana, p.estatus_migratorio,
                            p.personal_salud, p.jornalero_agricola, pc.id_censo
                     FROM PACIENTE p
                     INNER JOIN PACIENTE_CENSO pc ON pc.id_paciente = p.id_paciente"
                );
                if (!$resultadoPacientes) {
                    throw new RuntimeException(
                        "No se pudieron consultar los pacientes para aplicar la nueva regla: "
                        . $conexion->error
                    );
                }
                $stmtActualizarCenso = $conexion->prepare(
                    "UPDATE PACIENTE_CENSO SET id_censo = ? WHERE id_paciente = ?"
                );
                if (!$stmtActualizarCenso) {
                    throw new RuntimeException(
                        "No se pudo preparar la reasignación de pacientes: " . $conexion->error
                    );
                }
                $pacientesReasignados = 0;
                while ($paciente = $resultadoPacientes->fetch_assoc()) {
                    $nuevoIdCenso = censoDeterminarId(
                        $conexion,
                        (string)$paciente["fecha_nacimiento"],
                        (string)$paciente["sexo"],
                        (int)$paciente["embarazo"],
                        (int)$paciente["poblacion_indigena_afromexicana"],
                        $paciente["estatus_migratorio"] === "No aplica" ? 0 : 1,
                        (int)$paciente["personal_salud"],
                        (int)$paciente["jornalero_agricola"]
                    );
                    if ($nuevoIdCenso === (int)$paciente["id_censo"]) {
                        continue;
                    }
                    $idPaciente = (string)$paciente["id_paciente"];
                    $stmtActualizarCenso->bind_param("is", $nuevoIdCenso, $idPaciente);
                    if (!$stmtActualizarCenso->execute()) {
                        throw new RuntimeException(
                            "No se pudo reasignar un paciente al censo correspondiente: "
                            . $stmtActualizarCenso->error
                        );
                    }
                    $pacientesReasignados++;
                }
                $resultadoPacientes->free();
                $stmtActualizarCenso->close();

                if (!$conexion->commit()) {
                    throw new RuntimeException("No se pudo confirmar la creación del esquema y el censo.");
                }
                $mensajeExito = "Se creó el esquema «" . $nombreEsquema
                    . "» y el censo «" . $nombreCenso
                    . "». Se reasignaron " . $pacientesReasignados
                    . " pacientes según los rangos y criterios configurados.";
            } catch (Throwable $e) {
                $conexion->rollback();
                $mensajeError = $e->getMessage();
            }
        }
    }
}

$biologicos = [];
$resultadoBiologicos = $conexion->query(
    "SELECT id_biologico, nombre_biologico
     FROM BIOLOGICO
     ORDER BY nombre_biologico"
);
if (!$resultadoBiologicos) {
    http_response_code(500);
    exit("No se pudo consultar el catálogo de biológicos: " . htmlspecialchars($conexion->error, ENT_QUOTES, "UTF-8"));
}
while ($fila = $resultadoBiologicos->fetch_assoc()) {
    $biologicos[] = $fila;
}
$resultadoBiologicos->free();

$dosis = [];
$resultadoDosis = $conexion->query(
    "SELECT id_dosis, nombre_dosis
     FROM DOSIS
     ORDER BY id_dosis"
);
if (!$resultadoDosis) {
    http_response_code(500);
    exit("No se pudo consultar el catálogo de dosis: " . htmlspecialchars($conexion->error, ENT_QUOTES, "UTF-8"));
}
while ($fila = $resultadoDosis->fetch_assoc()) {
    $dosis[] = $fila;
}
$resultadoDosis->free();

$censos = [];
$resultadoCensos = $conexion->query(
    "SELECT c.id_censo, c.nombre_censo, e.nombre_esquema,
           cc.edad_minima, cc.edad_maxima, cc.sexo, cc.embarazo,
           cc.poblacion_indigena_afromexicana, cc.migrante,
           cc.personal_salud, cc.jornalero_agricola,
           COUNT(pc.id_paciente) AS pacientes
     FROM CENSO c
     INNER JOIN ESQUEMA e ON e.id_esquema = c.id_esquema
     LEFT JOIN CENSO_CONFIGURACION cc ON cc.id_censo = c.id_censo
     LEFT JOIN PACIENTE_CENSO pc ON pc.id_censo = c.id_censo
     GROUP BY c.id_censo, c.nombre_censo, e.nombre_esquema,
              cc.edad_minima, cc.edad_maxima, cc.sexo, cc.embarazo,
              cc.poblacion_indigena_afromexicana, cc.migrante,
              cc.personal_salud, cc.jornalero_agricola
     ORDER BY c.id_censo"
);
if (!$resultadoCensos) {
    http_response_code(500);
    exit("No se pudo consultar el listado de censos: " . htmlspecialchars($conexion->error, ENT_QUOTES, "UTF-8"));
}
while ($fila = $resultadoCensos->fetch_assoc()) {
    $censos[] = $fila;
}
$resultadoCensos->free();

function administrarEsc($valor): string
{
    return htmlspecialchars((string)($valor ?? ""), ENT_QUOTES, "UTF-8");
}

function administrarOpcionGrupo(string $nombre, string $etiqueta, string $seleccionado = ""): void
{
    ?>
    <label class="configuracion-campo">
        <span><?php echo administrarEsc($etiqueta); ?></span>
        <select name="<?php echo administrarEsc($nombre); ?>">
            <option value="" <?php echo $seleccionado === "" ? "selected" : ""; ?>>Cualquier grupo</option>
            <option value="1" <?php echo $seleccionado === "1" ? "selected" : ""; ?>>Sí</option>
            <option value="0" <?php echo $seleccionado === "0" ? "selected" : ""; ?>>No</option>
        </select>
    </label>
    <?php
}

function administrarReglasCenso(array $censo): string
{
    if ($censo["edad_minima"] === null) {
        return "Asignación predeterminada del sistema";
    }

    $partes = [
        "Edad " . (int)$censo["edad_minima"] . " a "
            . ($censo["edad_maxima"] === null ? "sin límite" : (int)$censo["edad_maxima"])
    ];
    $etiquetas = [
        "sexo" => ["F" => "Femenino", "M" => "Masculino"],
        "embarazo" => "Embarazo",
        "poblacion_indigena_afromexicana" => "Población indígena/afromexicana",
        "migrante" => "Migrante",
        "personal_salud" => "Personal de salud",
        "jornalero_agricola" => "Jornalero agrícola"
    ];
    foreach ($etiquetas as $campo => $etiqueta) {
        if ($censo[$campo] === null) {
            continue;
        }
        if ($campo === "sexo") {
            $partes[] = $etiqueta[$censo[$campo]] ?? "Sexo";
        } else {
            $partes[] = $etiqueta . ": " . ((int)$censo[$campo] === 1 ? "Sí" : "No");
        }
    }
    return implode(" · ", $partes);
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Esquemas y censos</title>
    <link rel="stylesheet" href="../css/estilos.css">
    <link rel="stylesheet" href="../css/gestion_admin.css">
</head>
<body class="gestion-admin">
<main class="gestion-contenedor">
    <header class="gestion-encabezado">
        <div class="gestion-encabezado-texto">
            <span class="gestion-etiqueta">ADMINISTRACIÓN</span>
            <h1>Esquemas y censos</h1>
            <p>Crea un esquema de vacunación y configura a qué población se asignará su censo.</p>
        </div>
    </header>

    <?php if ($mensajeError !== ""): ?>
        <div class="configuracion-alerta error" role="alert"><?php echo administrarEsc($mensajeError); ?></div>
    <?php endif; ?>
    <?php if ($mensajeExito !== ""): ?>
        <div class="configuracion-alerta exito" role="status"><?php echo administrarEsc($mensajeExito); ?></div>
    <?php endif; ?>

    <form class="gestion-formulario configuracion-formulario" method="post" action="administrar.php">
        <section class="configuracion-seccion">
            <h2>1. Crear esquema y censo</h2>
            <p class="configuracion-ayuda">El esquema se asociará al nuevo censo que configures aquí.</p>
            <div class="configuracion-grid">
                <label class="configuracion-campo">
                    <span>Nombre del esquema</span>
                    <input type="text" name="nombre_esquema" maxlength="100" required>
                </label>
                <label class="configuracion-campo">
                    <span>Nombre del censo</span>
                    <input type="text" name="nombre_censo" maxlength="100" required>
                </label>
            </div>
        </section>

        <section class="configuracion-seccion">
            <h2>2. Configurar rango de edad</h2>
            <p class="configuracion-ayuda">La edad se calcula automáticamente al registrar o actualizar a cada paciente. Deja la edad máxima vacía si no tiene límite.</p>
            <div class="configuracion-grid">
                <label class="configuracion-campo">
                    <span>Edad mínima</span>
                    <input type="number" name="edad_minima" min="0" max="120" required>
                </label>
                <label class="configuracion-campo">
                    <span>Edad máxima (opcional)</span>
                    <input type="number" name="edad_maxima" min="0" max="120">
                </label>
            </div>
        </section>

        <section class="configuracion-seccion">
            <h2>3. Personalizar grupos poblacionales</h2>
            <p class="configuracion-ayuda">Los criterios seleccionados se combinan: el paciente debe cumplirlos todos. «Cualquier grupo» no agrega una restricción. Si coinciden varios censos personalizados, se elige el más específico; si hay empate, se cancela la operación para evitar asignaciones ambiguas.</p>
            <div class="configuracion-grupos">
                <label class="configuracion-campo">
                    <span>Sexo</span>
                    <select name="sexo">
                        <option value="">Cualquier sexo</option>
                        <option value="F">Femenino</option>
                        <option value="M">Masculino</option>
                    </select>
                </label>
                <?php
                administrarOpcionGrupo("embarazo", "Embarazo");
                administrarOpcionGrupo("poblacion_indigena_afromexicana", "Población indígena o afromexicana");
                administrarOpcionGrupo("migrante", "Población migrante");
                administrarOpcionGrupo("personal_salud", "Personal de salud");
                administrarOpcionGrupo("jornalero_agricola", "Jornalero agrícola");
                ?>
            </div>
        </section>

        <section class="configuracion-seccion">
            <h2>4. Biológicos y dosis del esquema</h2>
            <p class="configuracion-ayuda">Selecciona al menos un biológico y una o más dosis para cada uno.</p>
            <?php if (!$biologicos || !$dosis): ?>
                <div class="configuracion-alerta error">
                    Para crear un esquema necesitas tener al menos un biológico y una dosis registrados en sus catálogos.
                </div>
            <?php else: ?>
                <div class="configuracion-biologicos">
                    <?php foreach ($biologicos as $biologico): ?>
                        <fieldset class="configuracion-biologico">
                            <label>
                                <input type="checkbox" name="biologicos[]" value="<?php echo (int)$biologico["id_biologico"]; ?>">
                                <strong><?php echo administrarEsc($biologico["nombre_biologico"]); ?></strong>
                            </label>
                            <div class="configuracion-dosis">
                                <?php foreach ($dosis as $dosisFila): ?>
                                    <label>
                                        <input
                                            type="checkbox"
                                            name="dosis_por_biologico[<?php echo (int)$biologico["id_biologico"]; ?>][]"
                                            value="<?php echo (int)$dosisFila["id_dosis"]; ?>"
                                        >
                                        <?php echo administrarEsc($dosisFila["nombre_dosis"]); ?>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </fieldset>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>

        <div class="acciones">
            <button type="submit" <?php echo (!$biologicos || !$dosis) ? "disabled" : ""; ?>>Crear esquema y censo</button>
        </div>
    </form>

    <section class="configuracion-seccion">
        <h2>Censos configurados</h2>
        <?php if (!$censos): ?>
            <p class="configuracion-ayuda">Todavía no hay censos registrados.</p>
        <?php else: ?>
            <?php foreach ($censos as $censo): ?>
                <article class="configuracion-censo">
                    <h3><?php echo administrarEsc($censo["nombre_censo"]); ?></h3>
                    <p>
                        Esquema: <strong><?php echo administrarEsc($censo["nombre_esquema"]); ?></strong>
                        · <?php echo administrarEsc(administrarReglasCenso($censo)); ?>
                        · Pacientes: <?php echo (int)$censo["pacientes"]; ?>
                    </p>
                </article>
            <?php endforeach; ?>
        <?php endif; ?>
    </section>
</main>
</body>
</html>
