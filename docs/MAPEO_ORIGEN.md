# Mapeo verificado de SPWConsumoMesVF

Origen: CASABLANCA, `dbo.SPWConsumoMesVF`, año 2026, mes 9. Resultado real: 35 354 filas; las 35 354 corresponden al período según su columna Fecha. La transferencia `1eab833e-4cc8-473c-a90a-9cd35e744821` ya se completó con confirmación manual del usuario: 36 lotes y 35 354 filas publicadas en MySQL local.

El esquema observado completo está en [source-schema.json](source-schema.json), sin valores de filas. Su SHA-256 es `f3b5ba98326f724a41277cd9410b0658c71be750f23d9af17cd538ca57fefc0c`. Coincide con el cliente y el archivo privado aprobado del servidor. La migración `2026_10_24_032705_add_source_columns_f3b5ba98.php` ya está aplicada en MySQL local. El nombre de archivo usa la hora UTC del proceso; la fecha local de este trabajo es 23 de octubre de 2026, Lima.

| Columna SQL Server | Origen | Destino estructurado MySQL |
| --- | --- | --- |
| Campaña, EstadoLote, AñoMes, NomMes | VARCHAR/NVARCHAR | LONGTEXT, nombres originales |
| Fecha | DATETIME | fecha_origen LONGTEXT ISO completo; fecha DATE del día real |
| Año | INT | Año INT, independiente del anio técnico filtrado |
| Mes | INT | mes_origen INT, independiente del mes técnico filtrado |
| Operacion | VARCHAR(14) | Operacion VARCHAR(14), índice con versión y fecha |
| TablaOrigen | VARCHAR(20) | TablaOrigen VARCHAR(20), índice con versión y fecha |
| UG, UG1, UG2, UGDet | VARCHAR | LONGTEXT, nombres originales |
| CCosto, CCosto1, CCDet | VARCHAR | LONGTEXT, nombres originales |
| UP, UP1, UP2, UPDet | VARCHAR | LONGTEXT, nombres originales |
| Existencia, C1, C2, C3 | VARCHAR | LONGTEXT, nombres originales |
| Kardex | NUMERIC(18,0) | Kardex DECIMAL(18,0) |
| KardexNombre, Concepto, Medida | VARCHAR | LONGTEXT, nombres originales |
| Cantidad, HAS | MONEY | DECIMAL(19,4), separados y sin recalcular |
| CostoMPSoles, CostoMPUS$, CostoMP1Soles, CostoMP1US$ | FLOAT de 8 bytes | Cuatro columnas DECIMAL(65,30), sin sumar ni redondear |

## Conservación de los costos FLOAT

El SP ya devuelve valores aproximados IEEE754 de 64 bits. Se conserva el número enviado por Node en `datos_json` completo. Para cada columna estructurada de costo, Laravel extrae el **token numérico de ese JSON original**, lo expande como texto decimal si usa exponente y valida un máximo de 35 dígitos enteros y 30 fraccionarios. No realiza operaciones monetarias en float ni redondea para que el valor quepa. El cliente verifica el mismo límite antes de enviar; todas las filas del dry-run real pasaron.

DECIMAL(65,30) representa el límite de almacenamiento elegido, no una precisión atribuida al SP. El valor canónico de Node permite volver al mismo valor IEEE754; no recupera decimales exactos que el procedimiento original no entregó. La precisión/escala de MONEY y NUMERIC se conserva directamente del origen. Los límites de DECIMAL están documentados por [MySQL 8.4](https://dev.mysql.com/doc/refman/8.4/en/precision-math-decimal-characteristics.html), y FLOAT/DOUBLE son [tipos aproximados](https://dev.mysql.com/doc/refman/8.4/en/floating-point-types.html).

## Procedimiento de transferencia manual

1. Contrastar conteos e importes por columna/operación con el SP original en SSMS, conectado únicamente a CASABLANCA.
2. Mantener Laravel iniciado en `127.0.0.1:8000`.
3. Ejecutar desde client: `npm run sync -- --anio 2026 --mes 9`.
4. Revisar el resumen y escribir `ENVIAR 2026-09` para confirmar la transferencia.
5. Comprobar el estado completado y las filas activas mediante `sync_periods`; conservar versiones anteriores.

El UUID mostrado por un dry-run es diagnóstico: no existe como transferencia en Laravel. Solo una ejecución normal confirmada crea la ejecución receptora.

## Verificación de la primera transferencia

Estado completada y snapshot activo confirmados por consultas directas a MySQL. Las fechas activas van del 1 al 30 de septiembre de 2026. Los 35 354 JSON publicados coinciden byte por byte con staging. Se compararon exactamente 247 478 valores estructurados (Kardex, Cantidad, HAS y cuatro costos, incluyendo NULL) con su JSON original, mediante comparación decimal sin sumas ni aritmética flotante: cero diferencias.

Los timestamps guardados en UTC muestran inicio `2026-10-24 03:32:13` y fin `2026-10-24 03:32:36`, equivalentes al 23 de octubre de 2026, 22:32:13–22:32:36 en Lima. La recepción/publicación tomó 23 segundos; este intervalo no incluye la extracción previa.
