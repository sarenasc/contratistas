-- Tabla: Jefes de Área
-- nivel_aprobacion: reservado para el flujo de aprobación de asistencia (a implementar)

CREATE TABLE dbo.dota_jefe_area (
    id               INT IDENTITY(1,1) PRIMARY KEY,
    nombre           NVARCHAR(150) NOT NULL,
    rut              NVARCHAR(20)  NULL,
    id_area          INT           NOT NULL REFERENCES dbo.Area(id_area),
    nivel_aprobacion TINYINT       NOT NULL DEFAULT 1,
    activo           BIT           NOT NULL DEFAULT 1,
    fecha_reg        DATETIME      NOT NULL DEFAULT GETDATE()
);

-- id_turno: opcional, para áreas con un jefe por turno (ej: packing turno mañana / tarde)
ALTER TABLE dbo.dota_jefe_area
    ADD id_turno INT NULL REFERENCES dbo.dota_turno(id);

-- Agregar columna id_jefe a dota_asistencia_carga para enlazar con jefe de área
ALTER TABLE dbo.dota_asistencia_carga
    ADD id_jefe INT NULL REFERENCES dbo.dota_jefe_area(id);
