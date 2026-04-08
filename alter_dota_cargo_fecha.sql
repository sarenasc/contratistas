-- Agregar columna fecha_ingreso a Dota_Cargo
-- Ejecutar una sola vez en la base de datos
ALTER TABLE [dbo].[Dota_Cargo]
    ADD [fecha_ingreso] DATETIME NULL DEFAULT GETDATE();
GO

-- Opcional: poblar la fecha en registros existentes
UPDATE [dbo].[Dota_Cargo] SET [fecha_ingreso] = GETDATE() WHERE [fecha_ingreso] IS NULL;
GO
