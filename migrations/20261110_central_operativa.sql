-- Run once after backing up both databases.
-- Adds operational columns while retaining nullable origin fields for synchronization.

ALTER TABLE bd_central_vacunacion.unidad
    MODIFY COLUMN clues VARCHAR(20) NULL;

INSERT INTO bd_central_vacunacion.unidad (id_unidad, nombre_unidad, clues)
SELECT id_unidad, nombre_unidad, NULL
FROM bd_unidad_vacunacion.unidad
ON DUPLICATE KEY UPDATE nombre_unidad = VALUES(nombre_unidad);

ALTER TABLE bd_central_vacunacion.aplicacion_vacuna
    MODIFY COLUMN id_aplicacion_origen INT NULL,
    MODIFY COLUMN id_unidad_origen INT NULL,
    ADD COLUMN id_unidad INT NOT NULL DEFAULT 1 AFTER id_lote,
    ADD COLUMN clasificacion_vph VARCHAR(40) NULL AFTER fecha_aplicacion,
    ADD COLUMN clasificacion_hepatitis_b VARCHAR(30) NULL AFTER clasificacion_vph,
    ADD COLUMN clasificacion_sr VARCHAR(24) NULL AFTER clasificacion_hepatitis_b,
    ADD COLUMN clasificacion_varicela_hepatitis_a VARCHAR(16) NULL AFTER clasificacion_sr,
    ADD KEY FK_AV_UNIDAD (id_unidad),
    ADD CONSTRAINT FK_AV_UNIDAD
        FOREIGN KEY (id_unidad) REFERENCES bd_central_vacunacion.unidad (id_unidad);

ALTER TABLE bd_central_vacunacion.lote
    MODIFY COLUMN id_lote_origen INT NULL,
    MODIFY COLUMN id_unidad_origen INT NULL;

ALTER TABLE bd_central_vacunacion.movimiento_biologico
    MODIFY COLUMN id_movimiento_origen INT NULL,
    MODIFY COLUMN id_unidad_origen INT NULL,
    ADD COLUMN id_unidad INT NOT NULL DEFAULT 1 AFTER id_unidad_origen,
    ADD KEY FK_MB_UNIDAD (id_unidad),
    ADD CONSTRAINT FK_MB_UNIDAD
        FOREIGN KEY (id_unidad) REFERENCES bd_central_vacunacion.unidad (id_unidad);

ALTER TABLE bd_central_vacunacion.movimiento_jeringa
    MODIFY COLUMN id_movimiento_origen INT NULL,
    MODIFY COLUMN id_unidad_origen INT NULL,
    ADD COLUMN id_unidad INT NOT NULL DEFAULT 1 AFTER id_unidad_origen,
    ADD KEY FK_MJ_UNIDAD (id_unidad),
    ADD CONSTRAINT FK_MJ_UNIDAD
        FOREIGN KEY (id_unidad) REFERENCES bd_central_vacunacion.unidad (id_unidad);

ALTER TABLE bd_central_vacunacion.paciente
    MODIFY COLUMN id_paciente_origen CHAR(36) NULL,
    MODIFY COLUMN id_unidad_origen INT NULL;

ALTER TABLE bd_central_vacunacion.tutor
    MODIFY COLUMN id_tutor_origen INT NULL,
    MODIFY COLUMN id_unidad_origen INT NULL;

ALTER TABLE bd_central_vacunacion.paciente_censo
    ADD COLUMN id_unidad INT NOT NULL DEFAULT 1 AFTER id_censo,
    ADD KEY idx_paciente_censo_unidad (id_unidad),
    ADD CONSTRAINT FK_PACIENTE_CENSO_UNIDAD
        FOREIGN KEY (id_unidad) REFERENCES bd_central_vacunacion.unidad (id_unidad);

ALTER TABLE bd_central_vacunacion.usuario
    ADD COLUMN id_unidad INT NULL AFTER id_rol,
    ADD KEY FK_USUARIO_UNIDAD (id_unidad),
    ADD CONSTRAINT FK_USUARIO_UNIDAD
        FOREIGN KEY (id_unidad) REFERENCES bd_central_vacunacion.unidad (id_unidad);

CREATE TABLE IF NOT EXISTS bd_central_vacunacion.aplicacion_vph_anonima (
    id_aplicacion_vph_anonima INT NOT NULL AUTO_INCREMENT,
    id_esquema_biologico_dosis INT NOT NULL,
    id_lote INT NOT NULL,
    id_unidad INT NOT NULL,
    id_usuario INT NOT NULL,
    fecha_aplicacion DATE NOT NULL,
    clasificacion_vph VARCHAR(40) NOT NULL,
    es_migrante TINYINT(1) NOT NULL,
    creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id_aplicacion_vph_anonima),
    KEY idx_vph_anonimo_periodo (id_unidad, fecha_aplicacion),
    KEY idx_vph_anonimo_lote (id_lote, id_unidad, fecha_aplicacion),
    KEY fk_vph_anonimo_esquema_dosis (id_esquema_biologico_dosis),
    KEY fk_vph_anonimo_usuario (id_usuario),
    CONSTRAINT fk_vph_anonimo_esquema_dosis
        FOREIGN KEY (id_esquema_biologico_dosis)
        REFERENCES bd_central_vacunacion.esquema_biologico_dosis (id_esquema_biologico_dosis),
    CONSTRAINT fk_vph_anonimo_lote
        FOREIGN KEY (id_lote) REFERENCES bd_central_vacunacion.lote (id_lote),
    CONSTRAINT fk_vph_anonimo_unidad
        FOREIGN KEY (id_unidad) REFERENCES bd_central_vacunacion.unidad (id_unidad),
    CONSTRAINT fk_vph_anonimo_usuario
        FOREIGN KEY (id_usuario) REFERENCES bd_central_vacunacion.usuario (id_usuario)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS bd_central_vacunacion.censo_configuracion (
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
        FOREIGN KEY (id_censo) REFERENCES bd_central_vacunacion.censo (id_censo)
        ON DELETE CASCADE,
    CONSTRAINT CK_CENSO_CONFIGURACION_EDAD
        CHECK (edad_maxima IS NULL OR edad_maxima >= edad_minima)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS bd_central_vacunacion.censo_historial (
    id_historial BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tipo_reporte VARCHAR(20) NOT NULL,
    id_censo TINYINT UNSIGNED NOT NULL,
    id_unidad INT NOT NULL DEFAULT 1,
    fecha_captura DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    hash_contenido CHAR(64) NOT NULL,
    contenido LONGBLOB NOT NULL,
    PRIMARY KEY (id_historial),
    KEY idx_censo_historial_fecha (id_unidad, tipo_reporte, id_censo, fecha_captura),
    KEY idx_censo_historial_expiracion (fecha_captura)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS bd_central_vacunacion.informe_anual_encabezado (
    id_unidad INT NOT NULL,
    clave VARCHAR(8) NOT NULL,
    valor VARCHAR(150) NOT NULL,
    id_usuario INT NOT NULL,
    actualizado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id_unidad, clave)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS bd_central_vacunacion.informe_anual_meta (
    id_unidad INT NOT NULL,
    anio SMALLINT UNSIGNED NOT NULL,
    clave VARCHAR(40) NOT NULL,
    meta DECIMAL(12,2) NOT NULL DEFAULT 0,
    id_usuario INT NOT NULL,
    actualizado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id_unidad, anio, clave)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS bd_central_vacunacion.instalacion_sincronizacion (
    id_instalacion TINYINT UNSIGNED NOT NULL,
    uuid_instalacion CHAR(36) NOT NULL,
    creada_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id_instalacion),
    UNIQUE KEY uq_uuid_instalacion (uuid_instalacion)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS bd_central_vacunacion.paloteo_covid (
    id_paloteo INT NOT NULL AUTO_INCREMENT,
    id_unidad INT NOT NULL,
    anio SMALLINT UNSIGNED NOT NULL,
    mes TINYINT UNSIGNED NOT NULL,
    datos_json LONGTEXT NOT NULL,
    id_usuario INT NOT NULL,
    actualizado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id_paloteo),
    UNIQUE KEY uq_paloteo_covid_periodo (id_unidad, anio, mes)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS bd_central_vacunacion.paloteo_general (
    id_paloteo INT NOT NULL AUTO_INCREMENT,
    id_unidad INT NOT NULL,
    anio SMALLINT UNSIGNED NOT NULL,
    mes TINYINT UNSIGNED NOT NULL,
    datos_json LONGTEXT NOT NULL,
    id_usuario INT NOT NULL,
    actualizado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id_paloteo),
    UNIQUE KEY uq_paloteo_general_periodo (id_unidad, anio, mes)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS bd_central_vacunacion.paloteo_influenza (
    id_paloteo INT NOT NULL AUTO_INCREMENT,
    id_unidad INT NOT NULL,
    anio SMALLINT UNSIGNED NOT NULL,
    mes TINYINT UNSIGNED NOT NULL,
    datos_json LONGTEXT NOT NULL,
    id_usuario INT NOT NULL,
    actualizado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id_paloteo),
    UNIQUE KEY uq_paloteo_influenza_periodo (id_unidad, anio, mes)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO bd_central_vacunacion.usuario (
    id_usuario, nombre_usuario, contrasena, ap_pat, ap_mat, nombre, id_rol, id_unidad
)
SELECT u.id_usuario, u.nombre_usuario, u.contrasena, u.ap_pat, u.ap_mat, u.nombre,
       rc.id_rol, u.id_unidad
FROM bd_unidad_vacunacion.usuario u
INNER JOIN bd_unidad_vacunacion.rol rl ON rl.id_rol = u.id_rol
INNER JOIN bd_central_vacunacion.rol rc ON rc.nombre_rol = rl.nombre_rol
WHERE rl.nombre_rol IN ('Administrador', 'Supervisor')
ON DUPLICATE KEY UPDATE
    nombre_usuario = VALUES(nombre_usuario),
    contrasena = VALUES(contrasena),
    ap_pat = VALUES(ap_pat),
    ap_mat = VALUES(ap_mat),
    nombre = VALUES(nombre),
    id_rol = VALUES(id_rol),
    id_unidad = VALUES(id_unidad);
