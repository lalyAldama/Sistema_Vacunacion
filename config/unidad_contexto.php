<?php
require_once __DIR__ . "/seguridad.php";

function unidadObtenerContexto(mysqli $conexion): array
{
    $rol = $_SESSION["nombre_rol"] ?? "";
    if ($rol === "Personal de unidad") {
        $stmt = $conexion->prepare(
            "SELECT u.id_unidad, un.nombre_unidad
             FROM USUARIO u
             INNER JOIN UNIDAD un ON un.id_unidad = u.id_unidad
             WHERE u.id_usuario = ?
             LIMIT 1"
        );
        if (!$stmt) {
            http_response_code(500);
            exit("No se pudo consultar la unidad del usuario: " . $conexion->error);
        }
        $idUsuario = (int)$_SESSION["id_usuario"];
        $stmt->bind_param("i", $idUsuario);
        if (!$stmt->execute()) {
            http_response_code(500);
            exit("No se pudo consultar la unidad del usuario: " . $stmt->error);
        }
        $unidad = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    } elseif (in_array($rol, ["Administrador", "Supervisor"], true)) {
        $idUnidadSeleccionada = filter_var(
            $_SESSION["id_unidad_seleccionada"] ?? null,
            FILTER_VALIDATE_INT
        );
        if ($idUnidadSeleccionada === false || $idUnidadSeleccionada < 1) {
            $raizAplicacion = realpath(__DIR__ . "/..");
            $raizDocumentos = realpath($_SERVER["DOCUMENT_ROOT"] ?? "");
            $rutaBase = "";
            if ($raizAplicacion && $raizDocumentos) {
                $rutaBase = str_replace(
                    "\\",
                    "/",
                    substr($raizAplicacion, strlen($raizDocumentos))
                );
            }
            header("Location: " . rtrim($rutaBase, "/") . "/menu.php?seleccionar_unidad=1");
            exit();
        }
        $stmt = $conexion->prepare(
            "SELECT id_unidad, nombre_unidad
             FROM UNIDAD
             WHERE id_unidad = ?
             LIMIT 1"
        );
        if (!$stmt) {
            http_response_code(500);
            exit("No se pudo validar la unidad seleccionada: " . $conexion->error);
        }
        $stmt->bind_param("i", $idUnidadSeleccionada);
        if (!$stmt->execute()) {
            http_response_code(500);
            exit("No se pudo validar la unidad seleccionada: " . $stmt->error);
        }
        $unidad = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$unidad) {
            unset($_SESSION["id_unidad_seleccionada"]);
            http_response_code(400);
            exit("La unidad seleccionada ya no existe. Regresa al menú y elige otra.");
        }
    } else {
        http_response_code(403);
        exit("Este módulo no está disponible para el rol actual.");
    }

    if (!$unidad || empty($unidad["id_unidad"])) {
        http_response_code(400);
        exit("El usuario no tiene una unidad de salud asignada.");
    }

    return [
        "id_unidad" => (int)$unidad["id_unidad"],
        "nombre_unidad" => (string)$unidad["nombre_unidad"],
        "solo_consulta" => $rol === "Administrador"
    ];
}

function unidadExigirPermisoEscritura(): void
{
    if (($_SESSION["nombre_rol"] ?? "") === "Administrador") {
        http_response_code(403);
        exit("El administrador solo puede consultar la información de la unidad.");
    }
}

function unidadEsPersonalDeUnidad(): bool
{
    return ($_SESSION["nombre_rol"] ?? "") === "Personal de unidad";
}

function unidadExigirPersonalDeUnidad(): void
{
    if (!unidadEsPersonalDeUnidad()) {
        http_response_code(403);
        exit("Esta función está disponible únicamente para el personal de unidad.");
    }
}

function unidadAsegurarUnidadEnPacienteCenso(mysqli $conexion): void
{
    $resultado = $conexion->query(
        "SELECT id_unidad FROM UNIDAD WHERE id_unidad = 1 LIMIT 1"
    );
    if (!$resultado) {
        http_response_code(500);
        exit("No se pudo verificar la unidad inicial para los pacientes: " . $conexion->error);
    }
    $unidadInicialExiste = $resultado->fetch_assoc() !== null;
    $resultado->free();
    if (!$unidadInicialExiste) {
        http_response_code(500);
        exit("No existe la unidad con ID 1, necesaria para asignar los pacientes ya registrados.");
    }

    $stmtColumna = $conexion->prepare(
        "SELECT COUNT(*) AS cantidad
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'PACIENTE_CENSO'
           AND COLUMN_NAME = 'id_unidad'"
    );
    if (!$stmtColumna) {
        http_response_code(500);
        exit("No se pudo verificar la relación unidad-paciente: " . $conexion->error);
    }
    if (!$stmtColumna->execute()) {
        http_response_code(500);
        exit("No se pudo verificar la relación unidad-paciente: " . $stmtColumna->error);
    }
    $columnaExiste = (int)$stmtColumna->get_result()->fetch_assoc()["cantidad"] > 0;
    $stmtColumna->close();

    if (!$columnaExiste && !$conexion->query(
        "ALTER TABLE PACIENTE_CENSO
         ADD COLUMN id_unidad INT NOT NULL DEFAULT 1,
         ADD KEY idx_paciente_censo_unidad (id_unidad),
         ADD CONSTRAINT FK_PACIENTE_CENSO_UNIDAD
             FOREIGN KEY (id_unidad) REFERENCES UNIDAD (id_unidad)"
    )) {
        http_response_code(500);
        exit("No se pudo agregar la unidad a las asignaciones de pacientes: " . $conexion->error);
    }
}
