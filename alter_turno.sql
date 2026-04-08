-- Crear tabla dota_turno
-- Ejecutar en SistGestion

CREATE TABLE [dbo].[dota_turno] (
    [id]           INT           IDENTITY(1,1) NOT NULL,
    [nombre_turno] NVARCHAR(100) NOT NULL,
    CONSTRAINT [PK_dota_turno] PRIMARY KEY ([id])
);
GO
