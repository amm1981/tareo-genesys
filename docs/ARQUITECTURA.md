# Integridad y decisiones de implementación

## Extracción

`Source` se conecta con `mssql` a una base fija y comprueba `DB_NAME()`. La única operación de negocio es `execute('dbo.SPWConsumoMesVF')` con `Año` y `Mes` tipados como INT. No ejecuta DDL/DML de aplicación en SQL Server ni intenta acceder a otras bases. `readOnlyIntent` es una intención del controlador, no reemplaza los permisos mínimos del usuario ni la revisión de que el SP sea de solo lectura.

Se usa arrayRowMode para detectar nombres duplicados antes de convertir filas a objetos. El esquema proviene de los metadatos reales de cada resultset. Un segundo resultset se rechaza: no se descarta sin revisión. El consumidor pausa el stream mientras serializa/escribe; conserva como máximo un lote y buffers del controlador en memoria. La extracción normal escribe lotes en disco para poder conocer los conteos definitivos, pedir confirmación y reanudar sin volver a ejecutar el SP. Dry-run solo conserva contadores/esquema.

Los valores monetarios se decodifican desde los bytes TDS en strings usando BigInt antes de que tedious pueda redondearlos. Las pruebas invocan el export real del parser, incluidos buffers incompletos. Esta integración interna queda fijada a tedious 20.3.8. Los cambios de versión deben revisar el uso de `readValue` por los parsers row/NBCROW y ejecutar las pruebas. Los strings JSON mantienen Unicode, espacios, NULL y valores originales; los datos binarios tienen representación `base64:`. Los tipos no soportados fallan explícitamente.

## Recepción

`sync_runs` contiene UUID, propietario, período, esquema y manifiesto de cantidades/hashes. `sync_periods` tiene una fila única por año/mes, `pending_run_id` y `active_run_id`. El inicio bloquea los períodos dentro de una transacción: una ejecución anual reserva los doce meses. Un segundo envío solapado se rechaza. Los bloqueos persistentes se liberan al completar o cancelar manualmente; no existe un job de vencimiento.

Cada lote tiene una clave única `(sync_run_id, numero_lote)` y cada fila `(sync_run_id, numero_fila)`. Se exige orden de lotes y ordinales contiguos. El contenido no es identidad: dos filas iguales pueden ser válidas y se conservan. El checksum identifica el contenido de un reintento y detecta corrupción, sin reemplazar la autenticación HTTPS/Sanctum.

`payload` se conserva exactamente en `sync_batches`; `datos_json` se almacena como LONGTEXT válido JSON, no como JSON binario de MySQL que podría normalizar el texto. Esto permite verificar bytes UTF-8, Unicode, representación decimal y checksum sin depender de órdenes de propiedades entre PHP y JavaScript. Las columnas estructuradas son adicionales. Los cuatro costos FLOAT observados se extraen como tokens numéricos del texto JSON y se almacenan como strings en DECIMAL(65,30), expandiendo exponentes sin aritmética flotante ni redondeos. Los valores que no caben se rechazan. No hay sumas automáticas ni clasificación supuesta de costos. El [mapeo real](MAPEO_ORIGEN.md) conserva Fecha y Mes originales en columnas separadas de los campos técnicos del período.

## Publicación atómica

`complete` ejecuta una transacción InnoDB que:

1. Bloquea la ejecución y comprueba cantidades y esquema aprobado.
2. Recorre los lotes en bloques acotados, recalcula sus SHA-256 y contrasta ordinales/contenido con staging.
3. Valida nuevamente período, tipos y precisión e inserta la nueva versión estructurada.
4. Verifica número de filas y hash global, bloquea períodos y cambia sus referencias activas.
5. Marca la ejecución completada y confirma la transacción.

Cualquier error revierte todas las inserciones/publicación de esa transacción. Staging recibido y el snapshot anterior permanecen. La reejecución completa de un período crea una versión nueva; las consultas vigentes deben unir por `active_run_id`, año y mes. No se borran versiones antiguas al iniciar ni completar.

Las transacciones reintentan hasta tres veces los deadlocks detectados por Laravel. Para volúmenes de millones de filas, la RAM está acotada, pero la publicación puede mantener una transacción larga y generar bastante undo/redo: medir el SP y el volumen antes de producción y dimensionar disco, timeouts y buffers de MySQL. No se habilitó purga automática. Ya existen las 34 columnas reales e índices por período, versión, ordinal, fecha, operación y tabla de origen; otros índices de dimensiones deben justificarse con consultas futuras.

## Seguridad y límites

La recepción exige un token personal Sanctum exclusivamente `sync:write`, sin comodín. Los estados se limitan al propietario. Las cuentas de consulta se habilitan por consola con usuario propio y hash de contraseña; su login recibe JSON del body y emite tokens exclusivamente `costs:read`, de 60 minutos. Los usuarios técnicos existentes no se habilitan con la migración. Logout revoca el token actual; cambio de contraseña o desactivación revoca los de consulta de la cuenta. Las rutas de lectura comprueban además la cuenta habilitada en cada petición. No existe registro público. Los errores y logs omiten cuerpos, SQL con bindings, tokens y contraseñas; se registra únicamente la clase técnica de errores internos.

`PublishedCosts` selecciona la publicación activa de un mes para la primera página, y su cursor cifrado fija publicación, último ordinal, usuario, filtros y vencimiento. Así una sincronización concurrente no mezcla versiones entre páginas. No se admite seleccionar libremente versiones históricas. Se leen los JSON publicados; los cuatro costos FLOAT se extraen del token original y se entregan como strings decimales, además de los NUMERIC/MONEY ya representados por strings. La lectura no ejecuta el SP, no devuelve staging ni calcula totales. Se documenta en [API_CONSULTA.md](API_CONSULTA.md).

El cliente exige HTTPS salvo localhost y rechaza redirecciones. El servidor exige HTTPS en producción. No habilita proxies confiables globales: primero debe conocerse la topología del VPS. Límites coordinados: 1000 filas/lote, 4 MiB por payload, 12 MiB por cuerpo HTTP escapado y 10 millones de filas por ejecución (configurable en servidor). La publicación vacía es posible tras confirmación explícita. El espacio en disco de `.runs`, staging, payload y versiones históricas debe supervisarse manualmente.

## Evidencia real obtenida

Conexión SQL autenticada a CASABLANCA y ejecución de septiembre 2026 verificadas: 35 354 filas leídas y conservadas, cero descartadas y 34 columnas. Se detectó certificado autofirmado; el usuario autorizó temporalmente confiar en él en la LAN, con cifrado activo. Los costos `CostoMPSoles`, `CostoMPUS$`, `CostoMP1Soles` y `CostoMP1US$` son FLOAT del origen; `Cantidad` y `HAS` son MONEY(19,4), y `Kardex` es NUMERIC(18,0). La migración de las 34 columnas está aplicada. La primera transferencia real fue confirmada por el usuario y completada: UUID `1eab833e-4cc8-473c-a90a-9cd35e744821`, 36 lotes y 35 354 filas activas. Su JSON coincide con staging y se contrastaron 247 478 campos numéricos estructurados con su JSON sin diferencias.

## Pendiente de evidencia real

- CA/certificado verificado y nombre TLS para reemplazar la excepción temporal autorizada.
- Contrastar la semántica y valores aproximados del SP con los usuarios responsables, sin interpretar DECIMAL(65,30) como precisión del origen.
- Conteos e importes por columna/operación de septiembre 2026, contrastados con SSMS.
- Repetición real del período y prueba de concurrencia con procesos separados; los escenarios correspondientes ya están cubiertos con datos sintéticos.
- Inventario y autorización del VPS antes de cualquier cambio remoto.
