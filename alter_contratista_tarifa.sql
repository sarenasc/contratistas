-- =====================================================
-- Agrega id_contratista a Dota_Valor_Dotacion
-- NULL = tarifa genérica (aplica a todos)
-- Con valor = tarifa específica para ese contratista
-- =====================================================
ALTER TABLE dbo.Dota_Valor_Dotacion
ADD id_contratista INT NULL
    CONSTRAINT FK_valor_dotacion_contratista
    FOREIGN KEY REFERENCES dbo.dota_contratista(id);
GO
