-- =============================================================
-- Tabla: dota_asistencia_carga
-- Registra las filas del Excel de asistencia cargadas al sistema
-- Ejecutar DESPUÉS de: crear_tablas.sql y alter_jefe_area.sql
-- =============================================================

CREATE TABLE dbo.dota_asistencia_carga (
    id          INT           IDENTITY(1,1) NOT NULL,
    fecha       DATE          NOT NULL,
    semana      INT           NULL,
    responsable NVARCHAR(150) NULL,
    area        INT           NULL,
    empleador   INT           NULL,
    cargo       INT           NULL,
    rut         NVARCHAR(20)  NULL,
    nombre      NVARCHAR(150) NULL,
    sexo        NVARCHAR(10)  NULL,
    turno       INT           NULL,
    jornada     DECIMAL(10,4) NULL,
    hhee        DECIMAL(10,4) NULL,
    especie     NVARCHAR(100) NULL,
    obs         NVARCHAR(500) NULL,
    registro    NVARCHAR(200) NULL,       -- nombre del archivo Excel subido
    id_jefe     INT           NULL,
    fecha_carga DATETIME      NOT NULL DEFAULT GETDATE(),   -- fecha y hora de la subida

    CONSTRAINT PK_dota_asistencia_carga PRIMARY KEY (id),
    CONSTRAINT FK_asist_area      FOREIGN KEY (area)      REFERENCES dbo.Area(id_area),
    CONSTRAINT FK_asist_empleador FOREIGN KEY (empleador) REFERENCES dbo.dota_contratista(id),
    CONSTRAINT FK_asist_cargo     FOREIGN KEY (cargo)     REFERENCES dbo.Dota_Cargo(id_cargo),
    CONSTRAINT FK_asist_turno     FOREIGN KEY (turno)     REFERENCES dbo.dota_turno(id),
    CONSTRAINT FK_asist_jefe      FOREIGN KEY (id_jefe)   REFERENCES dbo.dota_jefe_area(id)
);
GO
