-- =====================================================
-- Agrega valor_empresa a dota_contratista
-- 1 = el contratista maneja sus propios valores (no usa tarifas del sistema)
-- En Pre-Factura estos contratistas muestran inputs manuales
-- =====================================================
ALTER TABLE dbo.dota_contratista
ADD valor_empresa BIT NOT NULL CONSTRAINT DF_contratista_valor_empresa DEFAULT 0;
GO
