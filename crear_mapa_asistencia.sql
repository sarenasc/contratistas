-- =====================================================
-- Tabla de memorización de mapeos para carga_asistencia
-- Guarda la última vez que el usuario enlazó un valor
-- del Excel a un ID del sistema, por tipo de campo.
-- =====================================================
IF OBJECT_ID('dbo.dota_asistencia_mapa','U') IS NULL
CREATE TABLE dbo.dota_asistencia_mapa (
    id           INT           IDENTITY(1,1) NOT NULL,
    tipo         NVARCHAR(20)  NOT NULL,   -- 'area' | 'empleador' | 'cargo' | 'turno'
    valor_excel  NVARCHAR(300) NOT NULL,   -- valor normalizado (UPPER TRIM) del Excel
    id_sistema   INT           NOT NULL,   -- ID del catálogo del sistema
    CONSTRAINT PK_asistencia_mapa PRIMARY KEY (id),
    CONSTRAINT UQ_mapa_tipo_valor UNIQUE (tipo, valor_excel)
);
GO
