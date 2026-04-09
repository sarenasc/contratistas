-- =============================================================
-- setup.sql — Contratista System
-- Base de datos: Fact_contratista
--
-- Instrucciones:
--   1. Ejecutar conectado a master (para el CREATE DATABASE).
--   2. Todo el resto se ejecuta dentro de la BD creada.
--   3. La BD proforma_contratista NO se toca (sistema anterior).
-- =============================================================

-- =============================================================
-- BASE DE DATOS
-- =============================================================
IF NOT EXISTS (SELECT name FROM sys.databases WHERE name = N'Fact_contratista')
    CREATE DATABASE Fact_contratista
        COLLATE Latin1_General_CI_AS;
GO

USE Fact_contratista;
GO

-- =============================================================
-- TABLAS (en orden de dependencias)
-- =============================================================

-- -------------------------------------------------------------
-- Area
-- -------------------------------------------------------------
IF OBJECT_ID('dbo.Area','U') IS NULL
CREATE TABLE [dbo].[Area] (
    [id_area]  INT           IDENTITY(1,1) NOT NULL,
    [Area]     NVARCHAR(100) NOT NULL,
    [cod_fact] NVARCHAR(50)  NULL,
    CONSTRAINT [PK_Area] PRIMARY KEY ([id_area])
);
GO

-- -------------------------------------------------------------
-- dota_perfiles
-- Perfiles del sistema:
--   Administrador    → acceso total
--   Edicion          → puede crear/editar, sin aprobar
--   Aprobador-Edicion → puede aprobar y editar
--   Visualizacion    → solo lectura
-- -------------------------------------------------------------
IF OBJECT_ID('dbo.dota_perfiles','U') IS NULL
CREATE TABLE [dbo].[dota_perfiles] (
    [id_perfil]   INT           IDENTITY(1,1) NOT NULL,
    [nombre]      NVARCHAR(50)  NOT NULL,
    [descripcion] NVARCHAR(200) NULL,
    CONSTRAINT [PK_dota_perfiles] PRIMARY KEY ([id_perfil])
);
GO

IF NOT EXISTS (SELECT 1 FROM dbo.dota_perfiles WHERE nombre = 'Administrador')
INSERT INTO dbo.dota_perfiles (nombre, descripcion) VALUES
('Administrador',     'Acceso total al sistema'),
('Edicion',           'Puede crear y editar, sin aprobar'),
('Aprobador-Edicion', 'Puede aprobar asistencia y editar'),
('Visualizacion',     'Solo lectura, sin edición');
GO

-- -------------------------------------------------------------
-- dota_usuarios  (reemplaza a TRA_usuario)
-- email: usado para notificaciones al subir/rechazar asistencia
-- id_area: área propia del usuario (no confundir con áreas que puede aprobar)
-- -------------------------------------------------------------
IF OBJECT_ID('dbo.dota_usuarios','U') IS NULL
CREATE TABLE [dbo].[dota_usuarios] (
    [id_usuario]    INT           IDENTITY(1,1) NOT NULL,
    [usuario]       NVARCHAR(50)  NOT NULL,
    [password_hash] NVARCHAR(255) NOT NULL,
    [nombre]        NVARCHAR(100) NULL,
    [apellido]      NVARCHAR(100) NULL,
    [email]         NVARCHAR(150) NULL,
    [id_area]       INT           NULL,
    [id_perfil]     INT           NOT NULL,
    [activo]        BIT           NOT NULL DEFAULT 1,
    CONSTRAINT [PK_dota_usuarios]  PRIMARY KEY ([id_usuario]),
    CONSTRAINT [UQ_usuario_login]  UNIQUE      ([usuario]),
    CONSTRAINT [FK_usuario_area]   FOREIGN KEY ([id_area])   REFERENCES [dbo].[Area]          ([id_area]),
    CONSTRAINT [FK_usuario_perfil] FOREIGN KEY ([id_perfil]) REFERENCES [dbo].[dota_perfiles]  ([id_perfil])
);
GO

-- -------------------------------------------------------------
-- dota_usuario_modulos
-- Define a qué módulos tiene acceso cada usuario.
-- Módulos: 'configuraciones' | 'tarifas' | 'procesos' | 'contratista' | 'aprobacion'
-- Los Administradores tienen acceso a todo sin necesitar filas aquí.
-- -------------------------------------------------------------
IF OBJECT_ID('dbo.dota_usuario_modulos','U') IS NULL
CREATE TABLE [dbo].[dota_usuario_modulos] (
    [id]         INT          IDENTITY(1,1) NOT NULL,
    [id_usuario] INT          NOT NULL,
    [modulo]     NVARCHAR(50) NOT NULL,
    CONSTRAINT [PK_dota_usuario_modulos] PRIMARY KEY ([id]),
    CONSTRAINT [UQ_usuario_modulo]       UNIQUE      ([id_usuario], [modulo]),
    CONSTRAINT [FK_umod_usuario]         FOREIGN KEY ([id_usuario]) REFERENCES [dbo].[dota_usuarios] ([id_usuario])
);
GO

-- -------------------------------------------------------------
-- dota_usuario_areas
-- Áreas que un usuario aprobador puede aprobar.
-- Puede incluir áreas fuera de su propia área (id_area en dota_usuarios).
-- -------------------------------------------------------------
IF OBJECT_ID('dbo.dota_usuario_areas','U') IS NULL
CREATE TABLE [dbo].[dota_usuario_areas] (
    [id_usuario] INT NOT NULL,
    [id_area]    INT NOT NULL,
    CONSTRAINT [PK_dota_usuario_areas] PRIMARY KEY ([id_usuario], [id_area]),
    CONSTRAINT [FK_uarea_usuario]      FOREIGN KEY ([id_usuario]) REFERENCES [dbo].[dota_usuarios] ([id_usuario]),
    CONSTRAINT [FK_uarea_area]         FOREIGN KEY ([id_area])    REFERENCES [dbo].[Area]           ([id_area])
);
GO

-- -------------------------------------------------------------
-- dota_usuario_cargos
-- Cargos específicos que un usuario puede aprobar aunque no pertenezcan
-- a sus áreas asignadas.
-- Ejemplo: Orlando (Packing) puede aprobar "Armados de materiales" de Bodega.
-- -------------------------------------------------------------
IF OBJECT_ID('dbo.dota_usuario_cargos','U') IS NULL
CREATE TABLE [dbo].[dota_usuario_cargos] (
    [id_usuario] INT NOT NULL,
    [id_cargo]   INT NOT NULL,
    CONSTRAINT [PK_dota_usuario_cargos] PRIMARY KEY ([id_usuario], [id_cargo]),
    CONSTRAINT [FK_ucargo_usuario]      FOREIGN KEY ([id_usuario]) REFERENCES [dbo].[dota_usuarios] ([id_usuario])
    -- FK a Dota_Cargo se agrega luego de crear esa tabla
);
GO

-- -------------------------------------------------------------
-- dota_tipo_mo  (tipo de mano de obra)
-- -------------------------------------------------------------
IF OBJECT_ID('dbo.dota_tipo_mo','U') IS NULL
CREATE TABLE [dbo].[dota_tipo_mo] (
    [id_mo]     INT           IDENTITY(1,1) NOT NULL,
    [nombre_mo] NVARCHAR(100) NOT NULL,
    [abrev]     NVARCHAR(20)  NULL,
    CONSTRAINT [PK_dota_tipo_mo] PRIMARY KEY ([id_mo])
);
GO

-- -------------------------------------------------------------
-- Dota_Cargo  (labores/cargos)
-- -------------------------------------------------------------
IF OBJECT_ID('dbo.Dota_Cargo','U') IS NULL
CREATE TABLE [dbo].[Dota_Cargo] (
    [id_cargo]      INT           IDENTITY(1,1) NOT NULL,
    [cargo]         NVARCHAR(100) NOT NULL,
    [tipo_mo]       NVARCHAR(50)  NULL,
    [cod_fact]      NVARCHAR(50)  NULL,
    [id_mo]         INT           NULL,
    [fecha_ingreso] DATETIME      NULL DEFAULT GETDATE(),
    CONSTRAINT [PK_Dota_Cargo]    PRIMARY KEY ([id_cargo]),
    CONSTRAINT [FK_cargo_tipo_mo] FOREIGN KEY ([id_mo]) REFERENCES [dbo].[dota_tipo_mo] ([id_mo])
);
GO

-- Ahora que Dota_Cargo existe, agregamos la FK pendiente en dota_usuario_cargos
IF NOT EXISTS (
    SELECT 1 FROM sys.foreign_keys
    WHERE name = 'FK_ucargo_cargo'
)
ALTER TABLE [dbo].[dota_usuario_cargos]
    ADD CONSTRAINT [FK_ucargo_cargo] FOREIGN KEY ([id_cargo]) REFERENCES [dbo].[Dota_Cargo] ([id_cargo]);
GO

-- -------------------------------------------------------------
-- especie
-- -------------------------------------------------------------
IF OBJECT_ID('dbo.especie','U') IS NULL
CREATE TABLE [dbo].[especie] (
    [id_especie] INT           IDENTITY(1,1) NOT NULL,
    [especie]    NVARCHAR(100) NOT NULL,
    CONSTRAINT [PK_especie] PRIMARY KEY ([id_especie])
);
GO

-- -------------------------------------------------------------
-- temporada
-- -------------------------------------------------------------
IF OBJECT_ID('dbo.temporada','U') IS NULL
CREATE TABLE [dbo].[temporada] (
    [id_temporada] INT           IDENTITY(1,1) NOT NULL,
    [temporada]    NVARCHAR(100) NOT NULL,
    CONSTRAINT [PK_temporada] PRIMARY KEY ([id_temporada])
);
GO

-- -------------------------------------------------------------
-- dota_contratista
-- valor_empresa = 1 → el contratista ingresa sus propios valores
-- -------------------------------------------------------------
IF OBJECT_ID('dbo.dota_contratista','U') IS NULL
CREATE TABLE [dbo].[dota_contratista] (
    [id]            INT           IDENTITY(1,1) NOT NULL,
    [razon_social]  NVARCHAR(150) NOT NULL,
    [rut]           NVARCHAR(20)  NULL,
    [nombre]        NVARCHAR(150) NULL,
    [cod_fact]      NVARCHAR(50)  NULL,
    [valor_empresa] BIT           NOT NULL CONSTRAINT [DF_contratista_valor_empresa] DEFAULT 0,
    CONSTRAINT [PK_dota_contratista] PRIMARY KEY ([id])
);
GO

-- -------------------------------------------------------------
-- Dota_tipo_tarifa  (tarifas regulares)
-- -------------------------------------------------------------
IF OBJECT_ID('dbo.Dota_tipo_tarifa','U') IS NULL
CREATE TABLE [dbo].[Dota_tipo_tarifa] (
    [id_tipo_tarifa]   INT           IDENTITY(1,1) NOT NULL,
    [Tipo_tarifa]      NVARCHAR(100) NOT NULL,
    [ValorContratista] DECIMAL(18,2) NULL,
    [horasExtras]      DECIMAL(18,2) NULL,
    [PorcContrastista] DECIMAL(10,4) NULL,
    [porc_hhee]        DECIMAL(10,4) NULL,
    [bono]             DECIMAL(18,2) NULL,
    [fecha_desde]      DATE          NULL,
    [fecha_hasta]      DATE          NULL,
    [tarifa_activa]    BIT           NULL DEFAULT 0,
    [caja]             DECIMAL(18,2) NULL,
    [kilo]             DECIMAL(18,4) NULL,
    CONSTRAINT [PK_Dota_tipo_tarifa] PRIMARY KEY ([id_tipo_tarifa])
);
GO

-- -------------------------------------------------------------
-- Dota_Valor_Dotacion  (asignación cargo → tarifa [+ especie + contratista])
-- id_contratista NULL = tarifa genérica para todos
-- -------------------------------------------------------------
IF OBJECT_ID('dbo.Dota_Valor_Dotacion','U') IS NULL
CREATE TABLE [dbo].[Dota_Valor_Dotacion] (
    [id]               INT IDENTITY(1,1) NOT NULL,
    [id_cargo]         INT NULL,
    [id_tipo_tarifa]   INT NULL,
    [id_especie]       INT NULL,
    [id_contratista]   INT NULL,
    CONSTRAINT [PK_Dota_Valor_Dotacion]     PRIMARY KEY ([id]),
    CONSTRAINT [FK_valor_cargo]             FOREIGN KEY ([id_cargo])       REFERENCES [dbo].[Dota_Cargo]       ([id_cargo]),
    CONSTRAINT [FK_valor_tipo_tarifa]       FOREIGN KEY ([id_tipo_tarifa]) REFERENCES [dbo].[Dota_tipo_tarifa] ([id_tipo_tarifa]),
    CONSTRAINT [FK_valor_especie]           FOREIGN KEY ([id_especie])     REFERENCES [dbo].[especie]           ([id_especie]),
    CONSTRAINT [FK_valor_dotacion_cont]     FOREIGN KEY ([id_contratista]) REFERENCES [dbo].[dota_contratista] ([id])
);
GO

-- -------------------------------------------------------------
-- Dota_Tarifa_Especiales  (cabecera de tarifas especiales por fecha)
-- -------------------------------------------------------------
IF OBJECT_ID('dbo.Dota_Tarifa_Especiales','U') IS NULL
CREATE TABLE [dbo].[Dota_Tarifa_Especiales] (
    [id_tipo]          INT           IDENTITY(1,1) NOT NULL,
    [tipo_Tarifa]      NVARCHAR(100) NOT NULL,
    [valor_base]       DECIMAL(18,2) NULL,
    [HH_EE_base]       DECIMAL(18,2) NULL,
    [porc_contratista] DECIMAL(10,4) NULL,
    [porc_hhee]        DECIMAL(10,4) NULL,
    [fecha]            DATE          NULL,
    CONSTRAINT [PK_Dota_Tarifa_Especiales] PRIMARY KEY ([id_tipo])
);
GO

-- -------------------------------------------------------------
-- Dota_ValorEspecial_Dotacion  (valores especiales por cargo + fecha)
-- -------------------------------------------------------------
IF OBJECT_ID('dbo.Dota_ValorEspecial_Dotacion','U') IS NULL
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
    CONSTRAINT [FK_vesp_cargo]       FOREIGN KEY ([cargo])       REFERENCES [dbo].[Dota_Cargo]             ([id_cargo]),
    CONSTRAINT [FK_vesp_especie]     FOREIGN KEY ([especie])     REFERENCES [dbo].[especie]                ([id_especie]),
    CONSTRAINT [FK_vesp_temporada]   FOREIGN KEY ([temporada])   REFERENCES [dbo].[temporada]              ([id_temporada]),
    CONSTRAINT [FK_vesp_tipo_tarifa] FOREIGN KEY ([tipo_tarifa]) REFERENCES [dbo].[Dota_Tarifa_Especiales] ([id_tipo])
);
GO

-- -------------------------------------------------------------
-- dota_descuento
-- -------------------------------------------------------------
IF OBJECT_ID('dbo.dota_descuento','U') IS NULL
CREATE TABLE [dbo].[dota_descuento] (
    [id]             INT           IDENTITY(1,1) NOT NULL,
    [id_contratista] INT           NOT NULL,
    [valor]          DECIMAL(18,2) NULL,
    [fecha]          DATE          NULL,
    [observacion]    NVARCHAR(500) NULL,
    CONSTRAINT [PK_dota_descuento]          PRIMARY KEY ([id]),
    CONSTRAINT [FK_descuento_contratista]   FOREIGN KEY ([id_contratista]) REFERENCES [dbo].[dota_contratista] ([id])
);
GO

-- -------------------------------------------------------------
-- dota_solicitud_contratista
-- id_turno: turno solicitado (agregado en rediseño 2026-04)
-- -------------------------------------------------------------
IF OBJECT_ID('dbo.dota_solicitud_contratista','U') IS NULL
CREATE TABLE [dbo].[dota_solicitud_contratista] (
    [id]          INT  IDENTITY(1,1) NOT NULL,
    [contratista] INT  NULL,
    [cargo]       INT  NULL,
    [area]        INT  NULL,
    [id_turno]    INT  NULL,
    [cantidad]    INT  NULL,
    [version]     INT  NULL DEFAULT 1,
    [fecha]       DATE NULL,
    CONSTRAINT [PK_dota_solicitud_contratista] PRIMARY KEY ([id]),
    CONSTRAINT [FK_sol_contratista] FOREIGN KEY ([contratista]) REFERENCES [dbo].[dota_contratista] ([id]),
    CONSTRAINT [FK_sol_cargo]       FOREIGN KEY ([cargo])       REFERENCES [dbo].[Dota_Cargo]        ([id_cargo]),
    CONSTRAINT [FK_sol_area]        FOREIGN KEY ([area])        REFERENCES [dbo].[Area]              ([id_area])
);
GO

-- -------------------------------------------------------------
-- dota_Registro_Marcacion  (registros legacy del reloj de marcación)
-- -------------------------------------------------------------
IF OBJECT_ID('dbo.dota_Registro_Marcacion','U') IS NULL
CREATE TABLE [dbo].[dota_Registro_Marcacion] (
    [id]             INT           IDENTITY(1,1) NOT NULL,
    [Rut_Empresa]    NVARCHAR(20)  NULL,
    [C_Sucursal]     NVARCHAR(20)  NULL,
    [Tipo]           NVARCHAR(20)  NULL,
    [n_departamento] NVARCHAR(100) NULL,
    [APROBADOR]      NVARCHAR(100) NULL,
    [Mes]            INT           NULL,
    [Año]            INT           NULL,
    [Fecha]          DATE          NULL,
    [Rut]            NVARCHAR(20)  NULL,
    [codigo]         NVARCHAR(50)  NULL,
    [nombre]         NVARCHAR(150) NULL,
    [Contratista]    NVARCHAR(150) NULL,
    [Dia]            NVARCHAR(20)  NULL,
    [C_CC]           NVARCHAR(50)  NULL,
    [N_CC]           NVARCHAR(100) NULL,
    [C_SU]           NVARCHAR(50)  NULL,
    [N_SU]           NVARCHAR(100) NULL,
    [SUC_CC]         NVARCHAR(100) NULL,
    [Labor]          NVARCHAR(100) NULL,
    [C_Labor]        NVARCHAR(50)  NULL,
    [id_Turno]       NVARCHAR(50)  NULL,
    [HoraExtra]      DECIMAL(10,2) NULL DEFAULT 0,
    [Jornada]        DECIMAL(10,2) NULL DEFAULT 0,
    CONSTRAINT [PK_dota_Registro_Marcacion] PRIMARY KEY ([id])
);
GO

-- -------------------------------------------------------------
-- dota_turno
-- -------------------------------------------------------------
IF OBJECT_ID('dbo.dota_turno','U') IS NULL
CREATE TABLE [dbo].[dota_turno] (
    [id]           INT           IDENTITY(1,1) NOT NULL,
    [nombre_turno] NVARCHAR(100) NOT NULL,
    CONSTRAINT [PK_dota_turno] PRIMARY KEY ([id])
);
GO

-- -------------------------------------------------------------
-- dota_jefe_area
-- id_usuario: vínculo con dota_usuarios (mismo registro de persona)
-- nivel_aprobacion: 1=jefe área, 2=jefe operaciones
-- Un área puede tener múltiples jefes (uno por turno).
-- Si un jefe cubre más de un turno, se replica la fila o se deja id_turno NULL.
-- -------------------------------------------------------------
IF OBJECT_ID('dbo.dota_jefe_area','U') IS NULL
CREATE TABLE [dbo].[dota_jefe_area] (
    [id]               INT           IDENTITY(1,1) NOT NULL,
    [nombre]           NVARCHAR(150) NOT NULL,
    [rut]              NVARCHAR(20)  NULL,
    [id_area]          INT           NOT NULL,
    [id_turno]         INT           NULL,
    [id_usuario]       INT           NULL,
    [nivel_aprobacion] TINYINT       NOT NULL DEFAULT 1,
    [activo]           BIT           NOT NULL DEFAULT 1,
    [fecha_reg]        DATETIME      NOT NULL DEFAULT GETDATE(),
    CONSTRAINT [PK_dota_jefe_area]   PRIMARY KEY ([id]),
    CONSTRAINT [FK_jefe_area]        FOREIGN KEY ([id_area])    REFERENCES [dbo].[Area]           ([id_area]),
    CONSTRAINT [FK_jefe_turno]       FOREIGN KEY ([id_turno])   REFERENCES [dbo].[dota_turno]     ([id]),
    CONSTRAINT [FK_jefe_usuario]     FOREIGN KEY ([id_usuario]) REFERENCES [dbo].[dota_usuarios]  ([id_usuario])
);
GO

-- -------------------------------------------------------------
-- dota_asistencia_lote
-- Representa un archivo de asistencia subido (agrupa todos los registros
-- con el mismo valor de campo "registro" en dota_asistencia_carga).
-- estado: 'pendiente' → 'aprobado_area' → 'aprobado_ops' → 'listo_factura'
--         o 'rechazado_area' / 'rechazado_ops' para rechazos
-- El Pre-Factura solo puede ejecutarse cuando estado = 'listo_factura'.
-- -------------------------------------------------------------
IF OBJECT_ID('dbo.dota_asistencia_lote','U') IS NULL
CREATE TABLE [dbo].[dota_asistencia_lote] (
    [registro]          NVARCHAR(200) NOT NULL,
    [fecha_carga]       DATETIME      NOT NULL DEFAULT GETDATE(),
    [id_usuario_carga]  INT           NULL,
    [semana]            INT           NULL,
    [anio]              INT           NULL,
    [estado]            NVARCHAR(30)  NOT NULL DEFAULT 'pendiente',
    -- Estados: pendiente | aprobado_area | rechazado_area | aprobado_ops | rechazado_ops | listo_factura
    CONSTRAINT [PK_dota_asistencia_lote]    PRIMARY KEY ([registro]),
    CONSTRAINT [FK_lote_usuario]            FOREIGN KEY ([id_usuario_carga]) REFERENCES [dbo].[dota_usuarios] ([id_usuario])
);
GO

-- -------------------------------------------------------------
-- dota_asistencia_carga  (filas del Excel de asistencia)
-- registro: clave que identifica el lote (FK lógica a dota_asistencia_lote)
-- -------------------------------------------------------------
IF OBJECT_ID('dbo.dota_asistencia_carga','U') IS NULL
CREATE TABLE [dbo].[dota_asistencia_carga] (
    [id]          INT           IDENTITY(1,1) NOT NULL,
    [fecha]       DATE          NOT NULL,
    [semana]      INT           NULL,
    [responsable] NVARCHAR(150) NULL,
    [area]        INT           NULL,
    [empleador]   INT           NULL,
    [cargo]       INT           NULL,
    [rut]         NVARCHAR(20)  NULL,
    [nombre]      NVARCHAR(150) NULL,
    [sexo]        NVARCHAR(10)  NULL,
    [turno]       INT           NULL,
    [jornada]     DECIMAL(10,4) NULL,
    [hhee]        DECIMAL(10,4) NULL,
    [especie]     NVARCHAR(100) NULL,
    [obs]         NVARCHAR(500) NULL,
    [registro]    NVARCHAR(200) NULL,
    [id_jefe]     INT           NULL,
    [fecha_carga] DATETIME      NOT NULL DEFAULT GETDATE(),
    CONSTRAINT [PK_dota_asistencia_carga] PRIMARY KEY ([id]),
    CONSTRAINT [FK_asist_area]      FOREIGN KEY ([area])      REFERENCES [dbo].[Area]             ([id_area]),
    CONSTRAINT [FK_asist_empleador] FOREIGN KEY ([empleador]) REFERENCES [dbo].[dota_contratista] ([id]),
    CONSTRAINT [FK_asist_cargo]     FOREIGN KEY ([cargo])     REFERENCES [dbo].[Dota_Cargo]        ([id_cargo]),
    CONSTRAINT [FK_asist_turno]     FOREIGN KEY ([turno])     REFERENCES [dbo].[dota_turno]        ([id]),
    CONSTRAINT [FK_asist_jefe]      FOREIGN KEY ([id_jefe])   REFERENCES [dbo].[dota_jefe_area]    ([id])
);
GO

-- -------------------------------------------------------------
-- dota_asistencia_aprobacion
-- Log de todas las acciones sobre un lote: aprobaciones, rechazos, ediciones.
-- id_area + id_turno: especifican qué parte del lote se aprobó/rechazó.
-- Un jefe de área puede aprobar/rechazar solo su área y turno.
-- El jefe de operaciones aprueba/rechaza el lote completo (id_area NULL).
-- -------------------------------------------------------------
IF OBJECT_ID('dbo.dota_asistencia_aprobacion','U') IS NULL
CREATE TABLE [dbo].[dota_asistencia_aprobacion] (
    [id]          INT           IDENTITY(1,1) NOT NULL,
    [registro]    NVARCHAR(200) NOT NULL,
    [id_usuario]  INT           NOT NULL,
    [accion]      NVARCHAR(20)  NOT NULL,
    -- Valores: 'aprobado' | 'rechazado' | 'editado'
    [observacion] NVARCHAR(500) NULL,
    [fecha]       DATETIME      NOT NULL DEFAULT GETDATE(),
    [id_area]     INT           NULL,
    [id_turno]    INT           NULL,
    CONSTRAINT [PK_dota_asistencia_aprobacion] PRIMARY KEY ([id]),
    CONSTRAINT [FK_aprobacion_lote]    FOREIGN KEY ([registro])   REFERENCES [dbo].[dota_asistencia_lote] ([registro]),
    CONSTRAINT [FK_aprobacion_usuario] FOREIGN KEY ([id_usuario]) REFERENCES [dbo].[dota_usuarios]        ([id_usuario]),
    CONSTRAINT [FK_aprobacion_area]    FOREIGN KEY ([id_area])    REFERENCES [dbo].[Area]                 ([id_area]),
    CONSTRAINT [FK_aprobacion_turno]   FOREIGN KEY ([id_turno])   REFERENCES [dbo].[dota_turno]           ([id])
);
GO

-- -------------------------------------------------------------
-- dota_asistencia_mapa  (memorización de mapeos Excel → sistema)
-- tipo: 'area' | 'empleador' | 'cargo' | 'turno'
-- -------------------------------------------------------------
IF OBJECT_ID('dbo.dota_asistencia_mapa','U') IS NULL
CREATE TABLE [dbo].[dota_asistencia_mapa] (
    [id]          INT           IDENTITY(1,1) NOT NULL,
    [tipo]        NVARCHAR(20)  NOT NULL,
    [valor_excel] NVARCHAR(300) NOT NULL,
    [id_sistema]  INT           NOT NULL,
    CONSTRAINT [PK_asistencia_mapa] PRIMARY KEY ([id]),
    CONSTRAINT [UQ_mapa_tipo_valor] UNIQUE      ([tipo], [valor_excel])
);
GO

-- -------------------------------------------------------------
-- dota_factura  (cabecera de proforma/factura)
-- estado: 'proceso' | 'cerrado'
-- -------------------------------------------------------------
IF OBJECT_ID('dbo.dota_factura','U') IS NULL
CREATE TABLE [dbo].[dota_factura] (
    [id]             INT           IDENTITY(1,1) NOT NULL,
    [semana]         INT           NOT NULL,
    [anio]           INT           NOT NULL,
    [version]        INT           NOT NULL CONSTRAINT [DF_factura_version] DEFAULT 1,
    [obs]            NVARCHAR(500) NULL,
    [estado]         NVARCHAR(20)  NOT NULL CONSTRAINT [DF_factura_estado]  DEFAULT 'proceso',
    [fecha_creacion] DATETIME      NOT NULL CONSTRAINT [DF_factura_fecha]   DEFAULT GETDATE(),
    [fecha_cierre]   DATETIME      NULL,
    [usuario]        NVARCHAR(100) NULL,
    [tot_base_jorn]  DECIMAL(18,2) NOT NULL CONSTRAINT [DF_factura_bj]   DEFAULT 0,
    [tot_base_hhee]  DECIMAL(18,2) NOT NULL CONSTRAINT [DF_factura_bh]   DEFAULT 0,
    [tot_pct_jorn]   DECIMAL(18,2) NOT NULL CONSTRAINT [DF_factura_pj]   DEFAULT 0,
    [tot_pct_hhee]   DECIMAL(18,2) NOT NULL CONSTRAINT [DF_factura_ph]   DEFAULT 0,
    [tot_bono]       DECIMAL(18,2) NOT NULL CONSTRAINT [DF_factura_bono] DEFAULT 0,
    [tot_factura]    DECIMAL(18,2) NOT NULL CONSTRAINT [DF_factura_tot]  DEFAULT 0,
    [descuento]      DECIMAL(18,2) NOT NULL CONSTRAINT [DF_factura_desc] DEFAULT 0,
    [total_neto]     DECIMAL(18,2) NOT NULL CONSTRAINT [DF_factura_neto] DEFAULT 0,
    CONSTRAINT [PK_dota_factura]         PRIMARY KEY ([id]),
    CONSTRAINT [UQ_factura_sem_anio_ver] UNIQUE      ([semana], [anio], [version])
);
GO

-- -------------------------------------------------------------
-- dota_factura_descuento  (descuentos por contratista dentro de una proforma)
-- -------------------------------------------------------------
IF OBJECT_ID('dbo.dota_factura_descuento','U') IS NULL
CREATE TABLE [dbo].[dota_factura_descuento] (
    [id]             INT           IDENTITY(1,1) NOT NULL,
    [id_factura]     INT           NOT NULL,
    [id_contratista] INT           NOT NULL,
    [valor]          DECIMAL(18,2) NOT NULL,
    [observacion]    NVARCHAR(500) NULL,
    [fecha_reg]      DATETIME      NOT NULL DEFAULT GETDATE(),
    CONSTRAINT [PK_factura_descuento] PRIMARY KEY ([id]),
    CONSTRAINT [FK_fdc_factura]       FOREIGN KEY ([id_factura])     REFERENCES [dbo].[dota_factura]     ([id]) ON DELETE CASCADE,
    CONSTRAINT [FK_fdc_contratista]   FOREIGN KEY ([id_contratista]) REFERENCES [dbo].[dota_contratista] ([id])
);
GO

-- -------------------------------------------------------------
-- dota_factura_detalle  (una fila por contratista+labor en la proforma)
-- -------------------------------------------------------------
IF OBJECT_ID('dbo.dota_factura_detalle','U') IS NULL
CREATE TABLE [dbo].[dota_factura_detalle] (
    [id]             INT           IDENTITY(1,1) NOT NULL,
    [id_factura]     INT           NOT NULL,
    [id_contratista] INT           NOT NULL,
    [cargo_nombre]   NVARCHAR(200) NULL,
    [tarifa_nombre]  NVARCHAR(200) NULL,
    [especial]       BIT           NOT NULL CONSTRAINT [DF_det_especial] DEFAULT 0,
    [esp_nom]        NVARCHAR(200) NULL,
    [registros]      INT           NOT NULL CONSTRAINT [DF_det_reg]  DEFAULT 0,
    [jornada]        DECIMAL(18,2) NOT NULL CONSTRAINT [DF_det_jorn] DEFAULT 0,
    [hhee]           DECIMAL(18,2) NOT NULL CONSTRAINT [DF_det_hhee] DEFAULT 0,
    [v_dia]          DECIMAL(18,4) NOT NULL CONSTRAINT [DF_det_vd]   DEFAULT 0,
    [v_hhee]         DECIMAL(18,4) NOT NULL CONSTRAINT [DF_det_vh]   DEFAULT 0,
    [porc_jorn]      DECIMAL(18,6) NOT NULL CONSTRAINT [DF_det_pj]   DEFAULT 0,
    [porc_hhee]      DECIMAL(18,6) NOT NULL CONSTRAINT [DF_det_ph]   DEFAULT 0,
    [base_jorn]      DECIMAL(18,2) NOT NULL CONSTRAINT [DF_det_bj]   DEFAULT 0,
    [base_hhee]      DECIMAL(18,2) NOT NULL CONSTRAINT [DF_det_bh]   DEFAULT 0,
    [pct_jorn]       DECIMAL(18,2) NOT NULL CONSTRAINT [DF_det_pcj]  DEFAULT 0,
    [pct_hhee]       DECIMAL(18,2) NOT NULL CONSTRAINT [DF_det_pch]  DEFAULT 0,
    [bono]           DECIMAL(18,2) NOT NULL CONSTRAINT [DF_det_bono] DEFAULT 0,
    [total]          DECIMAL(18,2) NOT NULL CONSTRAINT [DF_det_tot]  DEFAULT 0,
    CONSTRAINT [PK_dota_factura_detalle] PRIMARY KEY ([id]),
    CONSTRAINT [FK_det_factura]     FOREIGN KEY ([id_factura])     REFERENCES [dbo].[dota_factura]     ([id]) ON DELETE CASCADE,
    CONSTRAINT [FK_det_contratista] FOREIGN KEY ([id_contratista]) REFERENCES [dbo].[dota_contratista] ([id])
);
GO

-- =============================================================
-- VISTAS
-- =============================================================

-- -------------------------------------------------------------
-- view_Solicitud_Contratista
-- -------------------------------------------------------------
CREATE OR ALTER VIEW [dbo].[view_Solicitud_Contratista] AS
SELECT
    s.[id],
    s.[fecha],
    s.[cantidad],
    c.[razon_social] AS contratista,
    g.[cargo]        AS cargo,
    a.[Area]         AS area,
    t.[nombre_turno] AS turno,
    s.[version]
FROM [dbo].[dota_solicitud_contratista] s
LEFT JOIN [dbo].[dota_contratista] c ON c.[id]       = s.[contratista]
LEFT JOIN [dbo].[Dota_Cargo]       g ON g.[id_cargo] = s.[cargo]
LEFT JOIN [dbo].[Area]             a ON a.[id_area]  = s.[area]
LEFT JOIN [dbo].[dota_turno]       t ON t.[id]       = s.[id_turno];
GO

-- -------------------------------------------------------------
-- vw_proformas_detalle
-- Detalle completo de todas las proformas: granularidad una fila
-- por asistencia (trabajador × día) asociada a una proforma.
-- Solo incluye asistencia de lotes con estado = 'listo_factura'.
-- -------------------------------------------------------------
CREATE OR ALTER VIEW dbo.vw_proformas_detalle
AS
WITH raw AS (

    SELECT
        /* ── Proforma cabecera ── */
        f.id                    AS id_factura,
        f.semana,
        f.anio,
        f.version,
        f.estado                AS estado_proforma,
        f.obs                   AS obs_proforma,
        f.fecha_creacion        AS proforma_creada,
        f.fecha_cierre          AS proforma_cerrada,
        f.usuario               AS proforma_usuario,
        f.tot_factura           AS proforma_tot_factura,
        f.descuento             AS proforma_descuento,
        f.total_neto            AS proforma_total_neto,

        /* ── Contratista ── */
        c.id                    AS id_contratista,
        c.nombre                AS contratista,

        /* ── Asistencia ── */
        a.fecha,
        a.rut,
        a.nombre                AS trabajador,
        a.sexo,
        a.jornada,
        a.hhee,
        a.especie,
        a.registro,
        a.obs                   AS obs_asistencia,

        /* ── Área ── */
        ar.id_area,
        ar.Area                 AS area,
        ar.cod_fact             AS area_cod_fact,

        /* ── Cargo / Labor ── */
        dc.id_cargo,
        dc.cargo                AS labor,
        dc.cod_fact             AS labor_cod_fact,

        /* ── Tipo MO ── */
        mo.id_mo,
        mo.nombre_mo            AS tipo_mo,
        mo.abrev                AS tipo_mo_abrev,

        /* ── Turno ── */
        tr.id                   AS id_turno,
        tr.nombre_turno         AS turno,

        /* ── Jefe de Área ── */
        j.id                    AS id_jefe,
        j.nombre                AS jefe_area,

        /* ── Tarifa regular ── */
        tt.id_tipo_tarifa,
        tt.Tipo_tarifa          AS tarifa_nombre,
        tt.ValorContratista     AS tar_v_dia,
        tt.horasExtras          AS tar_v_hhee,
        tt.PorcContrastista     AS tar_porc_jorn,
        tt.porc_hhee            AS tar_porc_hhee,
        tt.bono                 AS tar_bono,
        tt.fecha_desde          AS tar_desde,
        tt.fecha_hasta          AS tar_hasta,

        /* ── Tarifa especial ── */
        te.esp_id,
        te.esp_nom              AS tarifa_especial,
        te.esp_valor            AS esp_v_dia,
        te.esp_hhee             AS esp_v_hhee,
        te.esp_porc             AS esp_porc_jorn,
        te.esp_porc_hhee,
        te.esp_bono,
        CAST(CASE WHEN te.esp_id IS NOT NULL THEN 1 ELSE 0 END AS BIT) AS es_especial,

        /* ── Valores efectivos (especial tiene prioridad si no es NULL) ── */
        CASE WHEN te.esp_id IS NOT NULL AND te.esp_valor     IS NOT NULL
             THEN te.esp_valor     ELSE ISNULL(tt.ValorContratista, 0) END  AS v_dia_ef,
        CASE WHEN te.esp_id IS NOT NULL AND te.esp_hhee      IS NOT NULL
             THEN te.esp_hhee      ELSE ISNULL(tt.horasExtras, 0)      END  AS v_hhee_ef,
        CASE WHEN te.esp_id IS NOT NULL AND te.esp_porc      IS NOT NULL
             THEN te.esp_porc      ELSE ISNULL(tt.PorcContrastista, 0) END  AS porc_jorn_ef,
        CASE WHEN te.esp_id IS NOT NULL AND te.esp_porc_hhee IS NOT NULL
             THEN te.esp_porc_hhee ELSE ISNULL(tt.porc_hhee, 0)        END  AS porc_hhee_ef,
        CASE WHEN te.esp_id IS NOT NULL
             THEN ISNULL(te.esp_bono, 0) ELSE ISNULL(tt.bono, 0)       END  AS bono_ef

    FROM dbo.dota_factura f

    /* Contratistas que participan en esta proforma */
    JOIN (
        SELECT DISTINCT id_factura, id_contratista
        FROM   dbo.dota_factura_detalle
    ) fd ON fd.id_factura = f.id

    JOIN dbo.dota_contratista c ON c.id = fd.id_contratista

    /* Asistencia de esa semana/año para ese contratista
       Solo lotes con aprobación final completa */
    JOIN dbo.dota_asistencia_carga a
        ON  a.empleador     = c.id
        AND a.semana        = f.semana
        AND YEAR(a.fecha)   = f.anio
        AND (a.jornada > 0 OR a.hhee > 0)

    /* Solo incluir registros de lotes aprobados */
    JOIN dbo.dota_asistencia_lote l
        ON  l.registro = a.registro
        AND l.estado   = 'listo_factura'

    /* Lookups dimensionales */
    LEFT JOIN dbo.Area             ar ON ar.id_area      = a.area
    LEFT JOIN dbo.Dota_Cargo       dc ON dc.id_cargo     = a.cargo
    LEFT JOIN dbo.dota_tipo_mo     mo ON mo.id_mo        = dc.id_mo
    LEFT JOIN dbo.dota_turno       tr ON tr.id           = a.turno
    LEFT JOIN dbo.dota_jefe_area    j ON  j.id           = a.id_jefe

    /* Tarifa regular: prioriza especie específica */
    OUTER APPLY (
        SELECT TOP 1 vd2.id_tipo_tarifa
        FROM dbo.Dota_Valor_Dotacion vd2
        WHERE vd2.id_cargo = a.cargo
          AND (
                vd2.id_especie IS NULL
                OR vd2.id_especie IN (
                    SELECT e.id_especie FROM dbo.especie e WHERE e.especie = a.especie
                )
              )
        ORDER BY CASE WHEN vd2.id_especie IS NOT NULL THEN 0 ELSE 1 END
    ) vd

    LEFT JOIN dbo.Dota_tipo_tarifa tt
        ON  tt.id_tipo_tarifa = vd.id_tipo_tarifa
        AND tt.tarifa_activa  = 1

    /* Tarifa especial: dos fuentes, prioridad al registro por cargo */
    OUTER APPLY (
        SELECT TOP 1
            src.esp_id, src.esp_nom,
            src.esp_valor, src.esp_hhee,
            src.esp_porc, src.esp_porc_hhee, src.esp_bono
        FROM (
            SELECT
                dte.id_tipo             AS esp_id,
                dte.tipo_Tarifa         AS esp_nom,
                ved.valor               AS esp_valor,
                ved.valor_HHEE          AS esp_hhee,
                CAST(NULL AS DECIMAL(18,6)) AS esp_porc,
                CAST(NULL AS DECIMAL(18,6)) AS esp_porc_hhee,
                CAST(NULL AS DECIMAL(18,2)) AS esp_bono,
                0                       AS prioridad
            FROM dbo.Dota_ValorEspecial_Dotacion ved
            JOIN dbo.Dota_Tarifa_Especiales dte ON dte.id_tipo = ved.tipo_tarifa
            WHERE CAST(ved.fecha AS DATE) = CAST(a.fecha AS DATE)
              AND ved.cargo = a.cargo
              AND (
                    ved.especie IS NULL
                    OR ved.especie IN (
                        SELECT id_especie FROM dbo.especie WHERE especie = a.especie
                    )
                  )
            UNION ALL
            SELECT
                id_tipo, tipo_tarifa,
                valor_base, HH_EE_base,
                porc_contratista, porc_hhee,
                CAST(NULL AS DECIMAL(18,2)),
                1
            FROM dbo.Dota_Tarifa_Especiales
            WHERE fecha IS NOT NULL
              AND CAST(fecha AS DATE) = CAST(a.fecha AS DATE)
        ) src
        ORDER BY src.prioridad
    ) te
)
SELECT
    id_factura, semana, anio, version,
    estado_proforma, obs_proforma,
    proforma_creada, proforma_cerrada, proforma_usuario,
    proforma_tot_factura, proforma_descuento, proforma_total_neto,

    id_contratista, contratista,

    fecha, rut, trabajador, sexo, jornada, hhee, especie, registro, obs_asistencia,

    id_area, area, area_cod_fact,
    id_cargo, labor, labor_cod_fact,
    id_mo, tipo_mo, tipo_mo_abrev,
    id_turno, turno,
    id_jefe, jefe_area,

    id_tipo_tarifa, tarifa_nombre,
    tar_v_dia, tar_v_hhee, tar_porc_jorn, tar_porc_hhee, tar_bono, tar_desde, tar_hasta,

    esp_id, tarifa_especial, esp_v_dia, esp_v_hhee, esp_porc_jorn, esp_porc_hhee, esp_bono,
    es_especial,

    v_dia_ef, v_hhee_ef, porc_jorn_ef, porc_hhee_ef, bono_ef,

    CAST(jornada * v_dia_ef                                      AS DECIMAL(18,2)) AS base_jorn,
    CAST(hhee    * v_hhee_ef                                     AS DECIMAL(18,2)) AS base_hhee,
    CAST(jornada * v_dia_ef  * porc_jorn_ef                      AS DECIMAL(18,2)) AS pct_jorn,
    CAST(hhee    * v_hhee_ef * porc_hhee_ef                      AS DECIMAL(18,2)) AS pct_hhee,
    CAST(jornada * v_dia_ef  * (1 + porc_jorn_ef)                AS DECIMAL(18,2)) AS emp_jorn,
    CAST(hhee    * v_hhee_ef * (1 + porc_hhee_ef)                AS DECIMAL(18,2)) AS emp_hhee,
    CAST(jornada * v_dia_ef  * (1 + porc_jorn_ef)
       + hhee    * v_hhee_ef * (1 + porc_hhee_ef)
       + bono_ef                                                 AS DECIMAL(18,2)) AS total_factura

FROM raw;
GO

-- =============================================================
-- Fin del script
-- =============================================================
