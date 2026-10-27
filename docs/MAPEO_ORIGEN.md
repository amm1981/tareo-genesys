# Fuente definitiva: CASABLANCA.dbo.SPWConsumoMes

Inspección real de lectura del 26/10/2026: octubre de 2026, 19.820 filas recibidas, 19.490 conservadas por Fecha y 330 descartadas. No se publicaron ni guardaron filas. Esquema de 38 columnas: [source-schema.json](source-schema.json). SHA-256: fccdf733fd8125cbbd3ed54179713ec18f0305c775f2bf7646692218f4eb48f9.

El esquema anterior se conserva byte por byte en [schemas/spwconsumomesvf.v1.json](schemas/spwconsumomesvf.v1.json) con hash f3b5ba98326f724a41277cd9410b0658c71be750f23d9af17cd538ca57fefc0c, únicamente para auditoría. No existe fallback de extracción a VF.

| # | Nombre original | Tipo SQL | Longitud metadata | Precisión | Escala | NULL | Almacenamiento |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 1 | Campaña | VarChar | 102 | — | — | Sí | LONGTEXT |
| 2 | EstadoLote | VarChar | 10 | — | — | Sí | LONGTEXT |
| 3 | Fecha | DateTime | 8 | — | — | Sí | fecha_origen LONGTEXT |
| 4 | AñoMes | VarChar | 61 | — | — | Sí | LONGTEXT |
| 5 | Año | Int | 4 | — | — | Sí | INT |
| 6 | Mes | Int | 4 | — | — | Sí | mes_origen INT |
| 7 | NomMes | NVarChar | 60 | — | — | Sí | LONGTEXT |
| 8 | Operacion | VarChar | 14 | — | — | No | VARCHAR(14) |
| 9 | TablaOrigen | VarChar | 20 | — | — | No | VARCHAR(20) |
| 10 | UG | VarChar | 102 | — | — | Sí | LONGTEXT |
| 11 | UG1 | VarChar | 104 | — | — | Sí | LONGTEXT |
| 12 | UG2 | VarChar | 106 | — | — | Sí | LONGTEXT |
| 13 | UGDet | VarChar | 113 | — | — | Sí | LONGTEXT |
| 14 | CCosto | VarChar | 103 | — | — | Sí | LONGTEXT |
| 15 | CCosto1 | VarChar | 105 | — | — | Sí | LONGTEXT |
| 16 | CCDet | VarChar | 113 | — | — | Sí | LONGTEXT |
| 17 | UP | VarChar | 102 | — | — | Sí | LONGTEXT |
| 18 | UP1 | VarChar | 104 | — | — | Sí | LONGTEXT |
| 19 | UP2 | VarChar | 106 | — | — | Sí | LONGTEXT |
| 20 | UPDet | VarChar | 113 | — | — | Sí | LONGTEXT |
| 21 | Existencia | VarChar | 100 | — | — | No | LONGTEXT |
| 22 | C1 | VarChar | 100 | — | — | No | LONGTEXT |
| 23 | C2 | VarChar | 100 | — | — | No | LONGTEXT |
| 24 | C3 | VarChar | 100 | — | — | No | LONGTEXT |
| 25 | Kardex | Numeric | 17 | 18 | 0 | No | DECIMAL(18,0) |
| 26 | KardexNombre | VarChar | 200 | — | — | No | LONGTEXT |
| 27 | Concepto | VarChar | 231 | — | — | Sí | LONGTEXT |
| 28 | Medida | VarChar | 10 | — | — | No | LONGTEXT |
| 29 | Cantidad | Money | 8 | 19 | 4 | Sí | DECIMAL(19,4) |
| 30 | HAS | Money | 8 | 19 | 4 | Sí | DECIMAL(19,4) |
| 31 | CostoMPSoles | Float | 8 | — | — | Sí | DECIMAL(65,30), representación aproximada validada |
| 32 | CostoMPUS$ | Float | 8 | — | — | Sí | DECIMAL(65,30), representación aproximada validada |
| 33 | CostoMP1Soles | Float | 8 | — | — | Sí | DECIMAL(65,30), representación aproximada validada |
| 34 | CostoMP1US$ | Float | 8 | — | — | Sí | DECIMAL(65,30), representación aproximada validada |
| 35 | CostoHASSoles | Float | 8 | — | — | Sí | DECIMAL(65,30), representación aproximada validada |
| 36 | CostoHASUS$ | Float | 8 | — | — | Sí | DECIMAL(65,30), representación aproximada validada |
| 37 | CostoHAS1Soles | Float | 8 | — | — | Sí | DECIMAL(65,30), representación aproximada validada |
| 38 | CostoHAS1US$ | Float | 8 | — | — | Sí | DECIMAL(65,30), representación aproximada validada |

Las cuatro columnas nuevas son FLOAT de 8 bytes, nullable; no se inventa precisión decimal. DECIMAL(65,30) es un sobre de almacenamiento verificado sin redondeo. Los tokens originales se conservan en datos_json y se exponen como strings decimales. NULL permanece NULL y se preservan signos negativos. NUMERIC/MONEY siguen siendo strings exactos con sus escalas originales antes de MySQL.

Las otras diferencias observadas son UGDet, CCDet y UPDet: longitud de metadata 121 → 113. Su almacenamiento existente LONGTEXT sigue siendo compatible y no se modifica. Las otras 31 columnas mantienen metadatos idénticos. La migración incremental 2026_10_26_000001_add_has_costs_spwconsumomes.php comprueba las columnas existentes y añade exclusivamente los cuatro costos faltantes, nullable para preservar las filas antiguas. No tiene una reversión destructiva.

La Fecha original y Mes original mantienen fecha_origen/mes_origen para evitar colisiones de MySQL. El filtrado usa exclusivamente la Fecha real, no Año/Mes/AñoMes. Véase [CAMBIO_FUENTE.md](CAMBIO_FUENTE.md) para aprobación, checkpoints y reconstrucción histórica controlada.
