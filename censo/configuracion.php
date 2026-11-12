<?php
function censoAsegurarTablaConfiguracion(mysqli $conexion): void
{
    $sql = "CREATE TABLE IF NOT EXISTS CENSO_CONFIGURACION (
        id_censo INT NOT NULL,
        edad_minima SMALLINT UNSIGNED NOT NULL,
        edad_maxima SMALLINT UNSIGNED NULL,
        sexo CHAR(1) NULL,
        embarazo TINYINT(1) NULL,
        poblacion_indigena_afromexicana TINYINT(1) NULL,
        migrante TINYINT(1) NULL,
        personal_salud TINYINT(1) NULL,
        jornalero_agricola TINYINT(1) NULL,
        PRIMARY KEY (id_censo),
        CONSTRAINT FK_CENSO_CONFIGURACION_CENSO
            FOREIGN KEY (id_censo) REFERENCES CENSO (id_censo)
            ON DELETE CASCADE,
        CONSTRAINT CK_CENSO_CONFIGURACION_EDAD
            CHECK (edad_maxima IS NULL OR edad_maxima >= edad_minima)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

    if (!$conexion->query($sql)) {
        throw new RuntimeException(
            "No se pudo preparar la configuración de censos: " . $conexion->error
        );
    }
}

function censoEdadEnAnios(string $fechaNacimiento): int
{
    $nacimiento = new DateTimeImmutable($fechaNacimiento);
    return $nacimiento->diff(new DateTimeImmutable("today"))->y;
}

function censoDeterminarId(
    mysqli $conexion,
    string $fechaNacimiento,
    string $sexo,
    int $embarazo,
    int $poblacionIndigena,
    int $migrante,
    int $personalSalud,
    int $jornaleroAgricola
): int {
    $edad = censoEdadEnAnios($fechaNacimiento);
    $valoresPaciente = [
        "sexo" => strtoupper($sexo),
        "embarazo" => $embarazo,
        "poblacion_indigena_afromexicana" => $poblacionIndigena,
        "migrante" => $migrante,
        "personal_salud" => $personalSalud,
        "jornalero_agricola" => $jornaleroAgricola
    ];

    static $configuracionesPorConexion = [];
    $claveConexion = spl_object_id($conexion);
    if (!isset($configuracionesPorConexion[$claveConexion])) {
        $sql = "SELECT cc.id_censo, cc.edad_minima, cc.edad_maxima,
                       cc.sexo, cc.embarazo, cc.poblacion_indigena_afromexicana,
                       cc.migrante, cc.personal_salud, cc.jornalero_agricola
                FROM CENSO_CONFIGURACION cc
                INNER JOIN CENSO c ON c.id_censo = cc.id_censo";
        $resultado = $conexion->query($sql);
        if (!$resultado) {
            throw new RuntimeException(
                "No se pudieron consultar las reglas de asignación de censos: " . $conexion->error
            );
        }
        $configuraciones = $resultado->fetch_all(MYSQLI_ASSOC);
        $resultado->free();

        $censosPredeterminados = [];
        $resultado = $conexion->query(
            "SELECT id_censo FROM CENSO WHERE id_censo IN (1, 2, 3)"
        );
        if (!$resultado) {
            throw new RuntimeException(
                "No se pudieron consultar los censos predeterminados: " . $conexion->error
            );
        }
        while ($fila = $resultado->fetch_assoc()) {
            $censosPredeterminados[(int)$fila["id_censo"]] = true;
        }
        $resultado->free();
        $configuracionesPorConexion[$claveConexion] = [
            "reglas" => $configuraciones,
            "predeterminados" => $censosPredeterminados
        ];
    }
    $reglasCenso = $configuracionesPorConexion[$claveConexion]["reglas"];
    $censosPredeterminados = $configuracionesPorConexion[$claveConexion]["predeterminados"];

    $camposGrupo = [
        "sexo",
        "embarazo",
        "poblacion_indigena_afromexicana",
        "migrante",
        "personal_salud",
        "jornalero_agricola"
    ];
    $candidatos = [];
    foreach ($reglasCenso as $regla) {
        if (
            $edad < (int)$regla["edad_minima"]
            || ($regla["edad_maxima"] !== null && $edad > (int)$regla["edad_maxima"])
        ) {
            continue;
        }

        $esCoincidencia = true;
        $especificidad = 0;
        foreach ($camposGrupo as $campo) {
            if ($regla[$campo] === null) {
                continue;
            }
            $especificidad++;
            if ((string)$regla[$campo] !== (string)$valoresPaciente[$campo]) {
                $esCoincidencia = false;
                break;
            }
        }

        if ($esCoincidencia) {
            $candidatos[] = [
                "id_censo" => (int)$regla["id_censo"],
                "especificidad" => $especificidad
            ];
        }
    }

    if (!$candidatos) {
        $idCensoAnterior = $embarazo === 1 || $edad >= 20
            ? 3
            : ($edad >= 10 ? 2 : 1);
        if (isset($censosPredeterminados[$idCensoAnterior])) {
            $candidatos[] = [
                "id_censo" => $idCensoAnterior,
                "especificidad" => 0
            ];
        }
    }

    if (!$candidatos) {
        throw new RuntimeException(
            "No hay un censo configurado que coincida con la edad y los grupos poblacionales del paciente."
        );
    }

    usort($candidatos, function ($a, $b) {
        return $b["especificidad"] <=> $a["especificidad"];
    });
    $mejorEspecificidad = $candidatos[0]["especificidad"];
    $mejores = array_values(array_filter(
        $candidatos,
        function ($candidato) use ($mejorEspecificidad) {
            return $candidato["especificidad"] === $mejorEspecificidad;
        }
    ));

    if (count($mejores) !== 1) {
        throw new RuntimeException(
            "Las reglas de censos coinciden en más de un censo para este paciente. Ajusta los rangos o grupos poblacionales."
        );
    }

    return $mejores[0]["id_censo"];
}

function censoTipoFormato(mysqli $conexion, int $idCenso): string
{
    censoAsegurarTablaConfiguracion($conexion);
    $stmt = $conexion->prepare(
        "SELECT edad_minima, edad_maxima
         FROM CENSO_CONFIGURACION
         WHERE id_censo = ?
         LIMIT 1"
    );
    if (!$stmt) {
        throw new RuntimeException(
            "No se pudo consultar el formato del censo: " . $conexion->error
        );
    }
    $stmt->bind_param("i", $idCenso);
    if (!$stmt->execute()) {
        throw new RuntimeException(
            "No se pudo consultar el formato del censo: " . $stmt->error
        );
    }
    $configuracion = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($configuracion) {
        return $configuracion["edad_maxima"] !== null
            && (int)$configuracion["edad_maxima"] <= 9
            ? "0-9"
            : "adultos";
    }

    if ($idCenso === 1) {
        return "0-9";
    }
    return "adultos";
}
