-- =====================================================================
-- Vista: vw_proformas_detalle
-- Descripción: Detalle completo de todas las proformas creadas,
--              enriquecido con tarifas, área, turno, jefe de área,
--              tipo MO y cálculos de facturación por registro.
-- Granularidad: una fila por asistencia (trabajador × día) asociada
--               a una proforma activa para esa semana/año/contratista.
-- =====================================================================
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

    /* Contratistas que participan en esta proforma (sin duplicar filas por cargo) */
    JOIN (
        SELECT DISTINCT id_factura, id_contratista
        FROM   dbo.dota_factura_detalle
    ) fd ON fd.id_factura = f.id

    JOIN dbo.dota_contratista c ON c.id = fd.id_contratista

    /* Asistencia de esa semana/año para ese contratista */
    JOIN dbo.dota_asistencia_carga a
        ON  a.empleador     = c.id
        AND a.semana        = f.semana
        AND YEAR(a.fecha)   = f.anio
        AND (a.jornada > 0 OR a.hhee > 0)

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
            /* Fuente 1 – Dota_ValorEspecial_Dotacion (por cargo+fecha, más específica) */
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
            /* Fuente 2 – Dota_Tarifa_Especiales global por fecha */
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
/* ── Capa final: agrega los montos calculados derivados ── */
SELECT
    /* Identificadores y dimensiones */
    id_factura,
    semana,
    anio,
    version,
    estado_proforma,
    obs_proforma,
    proforma_creada,
    proforma_cerrada,
    proforma_usuario,
    proforma_tot_factura,
    proforma_descuento,
    proforma_total_neto,

    id_contratista,
    contratista,

    fecha,
    rut,
    trabajador,
    sexo,
    jornada,
    hhee,
    especie,
    registro,
    obs_asistencia,

    id_area,
    area,
    area_cod_fact,

    id_cargo,
    labor,
    labor_cod_fact,

    id_mo,
    tipo_mo,
    tipo_mo_abrev,

    id_turno,
    turno,

    id_jefe,
    jefe_area,

    id_tipo_tarifa,
    tarifa_nombre,
    tar_v_dia,
    tar_v_hhee,
    tar_porc_jorn,
    tar_porc_hhee,
    tar_bono,
    tar_desde,
    tar_hasta,

    esp_id,
    tarifa_especial,
    esp_v_dia,
    esp_v_hhee,
    esp_porc_jorn,
    esp_porc_hhee,
    esp_bono,
    es_especial,

    /* Valores efectivos */
    v_dia_ef,
    v_hhee_ef,
    porc_jorn_ef,
    porc_hhee_ef,
    bono_ef,

    /* Montos calculados */
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
