-- =============================================================
-- Script de migración: Contratista System
-- Base de datos origen: SistGestion  (tablas principales)
-- Base de datos origen: ATT2000      (reloj de asistencia)
--
-- Instrucciones:
--   1. Reemplazar [NuevaBaseDeDatos] con el nombre real de la BD destino.
--   2. Ejecutar primero la sección SistGestion, luego ATT2000.
--   3. Las VISTAs se crean como stubs vacíos — deben ajustarse con la
--      lógica real antes de ejecutar en producción.
-- =============================================================


-- =============================================================
-- SECCIÓN 1: Base de datos SistGestion
-- =============================================================
USE [NuevaBaseDeDatos];
GO

-- -------------------------------------------------------------
-- Tabla: Area
-- -------------------------------------------------------------
CREATE TABLE [dbo].[Area] (
    [id_area]  INT           IDENTITY(1,1) NOT NULL,
    [Area]     NVARCHAR(100) NOT NULL,
    [cod_fact] NVARCHAR(50)  NULL,
    CONSTRAINT [PK_Area] PRIMARY KEY ([id_area])
);
GO

-- -------------------------------------------------------------
-- Tabla: TRA_usuario
-- -------------------------------------------------------------
CREATE TABLE [dbo].[TRA_usuario] (
    [id]       INT           IDENTITY(1,1) NOT NULL,
    [nom_usu]  NVARCHAR(50)  NOT NULL,
    [pass_usu] NVARCHAR(255) NOT NULL,
    [id_area]  INT           NULL,
    [Nombre]   NVARCHAR(100) NULL,
    [Apellido] NVARCHAR(100) NULL,
    [sistema1] NVARCHAR(50)  NULL,
    CONSTRAINT [PK_TRA_usuario] PRIMARY KEY ([id]),
    CONSTRAINT [FK_usuario_area] FOREIGN KEY ([id_area])
        REFERENCES [dbo].[Area] ([id_area])
);
GO

-- -------------------------------------------------------------
-- Tabla: dota_tipo_mo
-- -------------------------------------------------------------
CREATE TABLE [dbo].[dota_tipo_mo] (
    [id_mo]     INT          IDENTITY(1,1) NOT NULL,
    [nombre_mo] NVARCHAR(100) NOT NULL,
    [abrev]     NVARCHAR(20)  NULL,
    CONSTRAINT [PK_dota_tipo_mo] PRIMARY KEY ([id_mo])
);
GO

-- -------------------------------------------------------------
-- Tabla: Dota_Cargo
-- -------------------------------------------------------------
CREATE TABLE [dbo].[Dota_Cargo] (
    [id_cargo] INT           IDENTITY(1,1) NOT NULL,
    [cargo]    NVARCHAR(100) NOT NULL,
    [tipo_mo]  NVARCHAR(50)  NULL,
    [cod_fact] NVARCHAR(50)  NULL,
    [id_mo]    INT           NULL,
    CONSTRAINT [PK_Dota_Cargo] PRIMARY KEY ([id_cargo]),
    CONSTRAINT [FK_cargo_tipo_mo] FOREIGN KEY ([id_mo])
        REFERENCES [dbo].[dota_tipo_mo] ([id_mo])
);
GO

-- -------------------------------------------------------------
-- Tabla: dota_contratista
-- -------------------------------------------------------------
CREATE TABLE [dbo].[dota_contratista] (
    [id]           INT           IDENTITY(1,1) NOT NULL,
    [razon_social] NVARCHAR(150) NOT NULL,
    [rut]          NVARCHAR(20)  NULL,
    [nombre]       NVARCHAR(150) NULL,
    [cod_fact]     NVARCHAR(50)  NULL,
    CONSTRAINT [PK_dota_contratista] PRIMARY KEY ([id])
);
GO

-- -------------------------------------------------------------
-- Tabla: Dota_tipo_tarifa
-- -------------------------------------------------------------
CREATE TABLE [dbo].[Dota_tipo_tarifa] (
    [id_tipo_tarifa]   INT             IDENTITY(1,1) NOT NULL,
    [Tipo_tarifa]      NVARCHAR(100)   NOT NULL,
    [ValorContratista] DECIMAL(18,2)   NULL,
    [horasExtras]      DECIMAL(18,2)   NULL,
    [PorcContrastista] DECIMAL(10,4)   NULL,
    [porc_hhee]        DECIMAL(10,4)   NULL,
    [bono]             DECIMAL(18,2)   NULL,
    [fecha_desde]      DATE            NULL,
    [fecha_hasta]      DATE            NULL,
    [tarifa_activa]    BIT             NULL DEFAULT 0,
    [caja]             DECIMAL(18,2)   NULL,
    [kilo]             DECIMAL(18,4)   NULL,
    CONSTRAINT [PK_Dota_tipo_tarifa] PRIMARY KEY ([id_tipo_tarifa])
);
GO

-- -------------------------------------------------------------
-- Tabla: especie
-- -------------------------------------------------------------
CREATE TABLE [dbo].[especie] (
    [id_especie] INT          IDENTITY(1,1) NOT NULL,
    [especie]    NVARCHAR(100) NOT NULL,
    CONSTRAINT [PK_especie] PRIMARY KEY ([id_especie])
);
GO

-- -------------------------------------------------------------
-- Tabla: temporada
-- -------------------------------------------------------------
CREATE TABLE [dbo].[temporada] (
    [id_temporada] INT          IDENTITY(1,1) NOT NULL,
    [temporada]    NVARCHAR(100) NOT NULL,
    CONSTRAINT [PK_temporada] PRIMARY KEY ([id_temporada])
);
GO

-- -------------------------------------------------------------
-- Tabla: Dota_Valor_Dotacion
-- -------------------------------------------------------------
CREATE TABLE [dbo].[Dota_Valor_Dotacion] (
    [id]             INT IDENTITY(1,1) NOT NULL,
    [id_cargo]       INT NULL,
    [id_tipo_tarifa] INT NULL,
    [id_especie]     INT NULL,
    CONSTRAINT [PK_Dota_Valor_Dotacion] PRIMARY KEY ([id]),
    CONSTRAINT [FK_valor_cargo]       FOREIGN KEY ([id_cargo])       REFERENCES [dbo].[Dota_Cargo]       ([id_cargo]),
    CONSTRAINT [FK_valor_tipo_tarifa] FOREIGN KEY ([id_tipo_tarifa]) REFERENCES [dbo].[Dota_tipo_tarifa] ([id_tipo_tarifa]),
    CONSTRAINT [FK_valor_especie]     FOREIGN KEY ([id_especie])     REFERENCES [dbo].[especie]           ([id_especie])
);
GO

-- -------------------------------------------------------------
-- Tabla: Dota_Tarifa_Especiales
-- -------------------------------------------------------------
CREATE TABLE [dbo].[Dota_Tarifa_Especiales] (
    [id_tipo]     INT          IDENTITY(1,1) NOT NULL,
    [tipo_Tarifa] NVARCHAR(100) NOT NULL,
    CONSTRAINT [PK_Dota_Tarifa_Especiales] PRIMARY KEY ([id_tipo])
);
GO

-- -------------------------------------------------------------
-- Tabla: Dota_ValorEspecial_Dotacion
-- -------------------------------------------------------------
CREATE TABLE [dbo].[Dota_ValorEspecial_Dotacion] (
    [id]          INT           IDENTITY(1,1) NOT NULL,
    [cargo]       INT           NULL,
    [valor]       DECIMAL(18,2) NULL,
    [especie]     INT           NULL,
    [temporada]   INT           NULL,
    [tipo_tarifa] INT           NULL,
    [fecha]       DATE          NULL,
    [valor_HHEE]  DECIMAL(18,2) NULL,
    CONSTRAINT [PK_Dota_ValorEspecial_Dotacion] PRIMARY KEY ([id]),
    CONSTRAINT [FK_vesp_cargo]       FOREIGN KEY ([cargo])       REFERENCES [dbo].[Dota_Cargo]            ([id_cargo]),
    CONSTRAINT [FK_vesp_especie]     FOREIGN KEY ([especie])     REFERENCES [dbo].[especie]               ([id_especie]),
    CONSTRAINT [FK_vesp_temporada]   FOREIGN KEY ([temporada])   REFERENCES [dbo].[temporada]             ([id_temporada]),
    CONSTRAINT [FK_vesp_tipo_tarifa] FOREIGN KEY ([tipo_tarifa]) REFERENCES [dbo].[Dota_Tarifa_Especiales]([id_tipo])
);
GO

-- -------------------------------------------------------------
-- Tabla: dota_descuento
-- -------------------------------------------------------------
CREATE TABLE [dbo].[dota_descuento] (
    [id]             INT           IDENTITY(1,1) NOT NULL,
    [id_contratista] INT           NOT NULL,
    [valor]          DECIMAL(18,2) NULL,
    [fecha]          DATE          NULL,
    [observacion]    NVARCHAR(500) NULL,
    CONSTRAINT [PK_dota_descuento] PRIMARY KEY ([id]),
    CONSTRAINT [FK_descuento_contratista] FOREIGN KEY ([id_contratista])
        REFERENCES [dbo].[dota_contratista] ([id])
);
GO

-- -------------------------------------------------------------
-- Tabla: dota_solicitud_contratista
-- -------------------------------------------------------------
CREATE TABLE [dbo].[dota_solicitud_contratista] (
    [id]          INT           IDENTITY(1,1) NOT NULL,
    [contratista] INT           NULL,
    [cargo]       INT           NULL,
    [area]        INT           NULL,
    [cantidad]    INT           NULL,
    [version]     INT           NULL DEFAULT 1,
    [fecha]       DATE          NULL,
    CONSTRAINT [PK_dota_solicitud_contratista] PRIMARY KEY ([id]),
    CONSTRAINT [FK_sol_contratista] FOREIGN KEY ([contratista]) REFERENCES [dbo].[dota_contratista] ([id]),
    CONSTRAINT [FK_sol_cargo]       FOREIGN KEY ([cargo])       REFERENCES [dbo].[Dota_Cargo]        ([id_cargo]),
    CONSTRAINT [FK_sol_area]        FOREIGN KEY ([area])        REFERENCES [dbo].[Area]              ([id_area])
);
GO

-- -------------------------------------------------------------
-- Tabla: dota_Registro_Marcacion
-- (tabla principal de registros de asistencia/marcación)
-- -------------------------------------------------------------
CREATE TABLE [dbo].[dota_Registro_Marcacion] (
    [id]            INT           IDENTITY(1,1) NOT NULL,
    [Rut_Empresa]   NVARCHAR(20)  NULL,
    [C_Sucursal]    NVARCHAR(20)  NULL,
    [Tipo]          NVARCHAR(20)  NULL,
    [n_departamento]NVARCHAR(100) NULL,
    [APROBADOR]     NVARCHAR(100) NULL,
    [Mes]           INT           NULL,
    [Año]           INT           NULL,
    [Fecha]         DATE          NULL,
    [Rut]           NVARCHAR(20)  NULL,
    [codigo]        NVARCHAR(50)  NULL,
    [nombre]        NVARCHAR(150) NULL,
    [Contratista]   NVARCHAR(150) NULL,
    [Dia]           NVARCHAR(20)  NULL,
    [C_CC]          NVARCHAR(50)  NULL,
    [N_CC]          NVARCHAR(100) NULL,
    [C_SU]          NVARCHAR(50)  NULL,
    [N_SU]          NVARCHAR(100) NULL,
    [SUC_CC]        NVARCHAR(100) NULL,
    [Labor]         NVARCHAR(100) NULL,
    [C_Labor]       NVARCHAR(50)  NULL,
    [id_Turno]      NVARCHAR(50)  NULL,
    [HoraExtra]     DECIMAL(10,2) NULL DEFAULT 0,
    [Jornada]       DECIMAL(10,2) NULL DEFAULT 0,
    CONSTRAINT [PK_dota_Registro_Marcacion] PRIMARY KEY ([id])
);
GO

-- =============================================================
-- VISTAS — stubs (ajustar lógica antes de ejecutar)
-- =============================================================

-- Vista: view_PreFactura
-- Combina marcaciones con tarifas para calcular pre-factura por contratista.
CREATE VIEW [dbo].[view_PreFactura] AS
SELECT
    r.[Contratista],
    r.[Labor],
    r.[Fecha],
    r.[Jornada],
    r.[HoraExtra]
    -- TODO: agregar JOINs con Dota_tipo_tarifa / Dota_ValorEspecial_Dotacion
FROM [dbo].[dota_Registro_Marcacion] r;
GO

-- Vista: view_Solicitud_Contratista
-- Muestra solicitudes con nombre de contratista, cargo y área expandidos.
CREATE VIEW [dbo].[view_Solicitud_Contratista] AS
SELECT
    s.[id],
    s.[fecha],
    s.[cantidad],
    c.[razon_social]  AS contratista,
    g.[cargo]         AS cargo,
    a.[Area]          AS area,
    s.[version]
FROM [dbo].[dota_solicitud_contratista] s
LEFT JOIN [dbo].[dota_contratista] c ON c.[id]       = s.[contratista]
LEFT JOIN [dbo].[Dota_Cargo]       g ON g.[id_cargo] = s.[cargo]
LEFT JOIN [dbo].[Area]             a ON a.[id_area]  = s.[area];
GO

-- Vista: V_DotacionTrabajadores2
-- Vista de dotación activa de trabajadores con datos de cargo y contratista.
CREATE VIEW [dbo].[V_DotacionTrabajadores2] AS
SELECT
    r.[id],
    r.[nombre],
    r.[Rut],
    r.[Contratista],
    r.[Labor],
    r.[Fecha],
    r.[Jornada],
    r.[HoraExtra]
    -- TODO: agregar lógica real de dotación / filtros de fecha activa
FROM [dbo].[dota_Registro_Marcacion] r;
GO


-- =============================================================
-- SECCIÓN 2: Base de datos ATT2000 (Reloj de asistencia)
-- =============================================================
-- NOTA: Ejecutar conectado a la BD ATT2000 (o reemplazar USE)
-- =============================================================


-- =============================================================
-- Fin del script
-- =============================================================
