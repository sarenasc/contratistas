-- =====================================================
-- Cabecera de proforma/factura
-- Una cabecera puede tener varios contratistas.
-- version: autoincremental por semana+año (1, 2, 3…)
-- estado: 'proceso' | 'cerrado'
-- =====================================================
CREATE TABLE dbo.dota_factura (
    id              INT           IDENTITY(1,1) NOT NULL,
    semana          INT           NOT NULL,
    anio            INT           NOT NULL,
    version         INT           NOT NULL CONSTRAINT DF_factura_version DEFAULT 1,
    obs             NVARCHAR(500) NULL,
    estado          NVARCHAR(20)  NOT NULL CONSTRAINT DF_factura_estado  DEFAULT 'proceso',
    fecha_creacion  DATETIME      NOT NULL CONSTRAINT DF_factura_fecha   DEFAULT GETDATE(),
    fecha_cierre    DATETIME      NULL,
    usuario         NVARCHAR(100) NULL,
    tot_base_jorn   DECIMAL(18,2) NOT NULL CONSTRAINT DF_factura_bj   DEFAULT 0,
    tot_base_hhee   DECIMAL(18,2) NOT NULL CONSTRAINT DF_factura_bh   DEFAULT 0,
    tot_pct_jorn    DECIMAL(18,2) NOT NULL CONSTRAINT DF_factura_pj   DEFAULT 0,
    tot_pct_hhee    DECIMAL(18,2) NOT NULL CONSTRAINT DF_factura_ph   DEFAULT 0,
    tot_bono        DECIMAL(18,2) NOT NULL CONSTRAINT DF_factura_bono DEFAULT 0,
    tot_factura     DECIMAL(18,2) NOT NULL CONSTRAINT DF_factura_tot  DEFAULT 0,
    descuento       DECIMAL(18,2) NOT NULL CONSTRAINT DF_factura_desc DEFAULT 0,
    total_neto      DECIMAL(18,2) NOT NULL CONSTRAINT DF_factura_neto DEFAULT 0,
    CONSTRAINT PK_dota_factura PRIMARY KEY (id),
    CONSTRAINT UQ_factura_sem_anio_ver UNIQUE (semana, anio, version)
);
GO

-- =====================================================
-- Detalle de proforma: una fila por contratista+labor
-- =====================================================
CREATE TABLE dbo.dota_factura_detalle (
    id              INT           IDENTITY(1,1) NOT NULL,
    id_factura      INT           NOT NULL,
    id_contratista  INT           NOT NULL,
    cargo_nombre    NVARCHAR(200) NULL,
    tarifa_nombre   NVARCHAR(200) NULL,
    especial        BIT           NOT NULL CONSTRAINT DF_det_especial DEFAULT 0,
    esp_nom         NVARCHAR(200) NULL,
    registros       INT           NOT NULL CONSTRAINT DF_det_reg  DEFAULT 0,
    jornada         DECIMAL(18,2) NOT NULL CONSTRAINT DF_det_jorn DEFAULT 0,
    hhee            DECIMAL(18,2) NOT NULL CONSTRAINT DF_det_hhee DEFAULT 0,
    v_dia           DECIMAL(18,4) NOT NULL CONSTRAINT DF_det_vd   DEFAULT 0,
    v_hhee          DECIMAL(18,4) NOT NULL CONSTRAINT DF_det_vh   DEFAULT 0,
    porc_jorn       DECIMAL(18,6) NOT NULL CONSTRAINT DF_det_pj   DEFAULT 0,
    porc_hhee       DECIMAL(18,6) NOT NULL CONSTRAINT DF_det_ph   DEFAULT 0,
    base_jorn       DECIMAL(18,2) NOT NULL CONSTRAINT DF_det_bj   DEFAULT 0,
    base_hhee       DECIMAL(18,2) NOT NULL CONSTRAINT DF_det_bh   DEFAULT 0,
    pct_jorn        DECIMAL(18,2) NOT NULL CONSTRAINT DF_det_pcj  DEFAULT 0,
    pct_hhee        DECIMAL(18,2) NOT NULL CONSTRAINT DF_det_pch  DEFAULT 0,
    bono            DECIMAL(18,2) NOT NULL CONSTRAINT DF_det_bono DEFAULT 0,
    total           DECIMAL(18,2) NOT NULL CONSTRAINT DF_det_tot  DEFAULT 0,
    CONSTRAINT PK_dota_factura_detalle PRIMARY KEY (id),
    CONSTRAINT FK_det_factura     FOREIGN KEY (id_factura)     REFERENCES dbo.dota_factura(id)      ON DELETE CASCADE,
    CONSTRAINT FK_det_contratista FOREIGN KEY (id_contratista) REFERENCES dbo.dota_contratista(id)
);
GO
