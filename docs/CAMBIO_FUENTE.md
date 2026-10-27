# Transición definitiva a CASABLANCA.dbo.SPWConsumoMes

Entrega del 26/10/2026. La única ejecución SQL de nuevas extracciones manuales, BACKFILL y DAILY es `dbo.SPWConsumoMes`; no existe selector, variable de procedimiento ni fallback. La lectura SQL sigue limitada a CASABLANCA y usa `@Año`, `@Mes` INT, streaming, un resultset y el decodificador numérico exacto existente.

## Inspección real y diferencias

Se ejecutó la inspección de lectura de octubre de 2026 sin publicar ni guardar filas: **19.820 leídas, 19.490 conservadas y 330 descartadas por Fecha**. Se confirmaron las 38 columnas, su orden, tipos, longitudes, precisión, escala y nulabilidad. Ver [el manifiesto completo](source-schema.json), [el reporte de inspección](schema-inspection-spwconsumomes.json) y [el mapeo](MAPEO_ORIGEN.md).

Hash del nuevo esquema: `fccdf733fd8125cbbd3ed54179713ec18f0305c775f2bf7646692218f4eb48f9`.

| Campos nuevos | SQL real | NULL | Almacenamiento |
| --- | --- | --- | --- |
| CostoHASSoles, CostoHASUS$, CostoHAS1Soles, CostoHAS1US$ | FLOAT, longitud 8 | Sí | DECIMAL(65,30), validado sin redondear |

Este DECIMAL conserva la representación transmitida del FLOAT aproximado; no inventa precisión del origen. Los ocho costos se conservan en `datos_json` y se entregan como strings decimales mediante la API. MONEY/NUMERIC mantienen strings y escala exacta; NULL, signo negativo, Unicode y nombres con `$` se conservan.

`UGDet`, `CCDet` y `UPDet` cambian longitud de metadata 121 → 113 y continúan en LONGTEXT. Las otras 31 columnas coinciden con el esquema anterior. No se modifican migraciones históricas ni sus tipos. El filtro sigue usando `Fecha` real: para octubre, `[2026-10-01, 2026-11-01)`, sin confiar en Año/Mes/AñoMes. La inspección y el dry-run no envían HTTP ni escriben lotes.

## Versionado y compatibilidad histórica

Se conserva el protocolo `version: 1`; la identificación de fuente es explícita mediante procedimiento, hash y `source_id`:

- `spwconsumomes-v1`: fuente definitiva, 38 columnas.
- `spwconsumomesvf-v1`: fuente histórica, 34 columnas; solo consulta/auditoría.

El manifiesto VF permanece byte por byte con hash `f3b5ba98326f724a41277cd9410b0658c71be750f23d9af17cd538ca57fefc0c` en `docs/schemas/spwconsumomesvf.v1.json` y `server/resources/schemas/spwconsumomesvf.v1.json`. No se reescriben los `schema_json`, checksums o payloads existentes. `SourceSchema` registra ambos hashes con funciones diferentes: aceptar lecturas históricas no autoriza nuevas publicaciones VF. Un esquema desconocido o una incompatibilidad de almacenamiento sigue siendo error.

`sync:verification` reconoce una publicación histórica registrada aunque el esquema vigente sea distinto; no la marca corrupta por esa diferencia. La metadata de inspección incorpora `source` con procedimiento, versión, clasificación, número de columnas y hash. El estado completado, cantidades y ledger siguen verificándose.

Los 45 períodos históricos y el checkpoint bloqueado informados por el usuario **no fueron consultados ni modificados en producción** en esta entrega. Su reconstrucción no se deriva automáticamente del despliegue.

## Migración incremental

Archivo nuevo: `server/database/migrations/2026_10_26_000001_add_has_costs_spwconsumomes.php`.

Antes de añadir campos, compara las 34 columnas existentes con los metadatos capturados. Si detecta incompatibilidad, falla sin adaptar o truncar sus valores. Añade solo las cuatro columnas faltantes, nullable; si ya existen exige tipos compatibles. Para los FLOAT conocidos exige DECIMAL(65,30), para los exactos conserva precisión/escala, y no reduce LONGTEXT. La migración no contiene DROP, TRUNCATE o DELETE. `down()` impide un rollback destructivo.

El generador `sync:schema:prepare` puede verificar y omitir columnas existentes compatibles. Para este manifiesto no genera una segunda copia: señala la migración incremental ya incluida. No aprueba sobrescribiendo un esquema anterior. La aprobación actual usa un archivo privado nuevo: `storage/app/private/source-schema-spwconsumomes-v1.json`. El archivo privado anterior se conserva.

La validación MySQL de tipos exige ejecución posterior en staging/servidor: SQLite prueba lógica y conservación pero no acredita DECIMAL de MySQL. No se aplicó esta migración a MySQL real en la entrega.

## API de consulta

Se conservan login, Sanctum, permisos exclusivos, filtros y paginación. Por defecto `/api/v1/costos` continúa leyendo únicamente la publicación activa del mes. Se añade metadata `meta.source` compatible con los campos existentes. Cada página devuelve un solo manifiesto: los históricos VF mantienen 34 claves y la fuente actual devuelve 38; no se añaden valores falsos de costo por hectárea a las filas antiguas.

Consulta explícita histórica, con un Bearer `costs:read`:

```http
GET /api/v1/costos?anio=2026&mes=10&publicacion_uuid=UUID_HISTORICO
```

Listado de publicaciones completadas:

```http
GET /api/v1/costos/publicaciones?anio=2026&mes=10&limite=50
```

El listado entrega origen por UUID; para continuar usar `meta.next_before_uuid` como `antes_de_uuid`. Los cursores de filas siguen fijando publicación, usuario, filtros y límite. Los cursores anteriores sin el parámetro nuevo conservan su fingerprint y pueden terminar su lectura aun cuando cambie la activa. No se exponen pendientes o staging mediante `costs:read`.

## Transición de checkpoints: preparar, revisar, aplicar después

**No se borran ni editan** `client/.automation/state.json` o `client/.runs`. El UUID `237818a9-4870-4cfa-92e5-f5a2a448dbb3` queda reservado en cliente y servidor; no se aprueba su vacío ni se usa para la nueva fuente.

La nueva fuente utiliza exclusivamente:

```text
client/.automation/spwconsumomes-v1/state.json
client/.automation/spwconsumomes-v1/empty-approvals.json
client/.runs/spwconsumomes-v1/<UUID_NUEVO>/
```

Si hay un estado antiguo en la raíz, el runner exige primero un recibo de transición. No sustituye su binding ni ignora errores. El comando de planificación valida el binding antiguo contra destino, servidor, CASABLANCA, VF y su hash conocido; valida el plan vacío pendiente, su UUID/período, cero filas/lotes y checksums. Si hay otro tipo de pendiente, detiene la transición para revisión. Nunca cancela una reserva del VPS.

Desde `client/`, este comando **solo planifica** y no consulta SQL ni el VPS:

```powershell
npm run sync:transition -- --schema-file schema.spwconsumomes.json
```

Después de revisión y autorización específica, el operador podrá repetir con `--apply`. Esa opción no se ejecutó sobre el estado operativo en esta entrega. Copia íntegramente el JSON antiguo a `legacy-state.snapshot.json` en el espacio nuevo y escribe un recibo con hashes de ambos bindings y del esquema. Conserva el original y todos los lotes; la siguiente ejecución crea su checkpoint independiente. Si el estado antiguo cambia después, el recibo deja de ser válido y el proceso se detiene. Deshabilitar la tarea de código antiguo antes de la transición, sin iniciar la nueva todavía.

La carpeta operativa `C:\Apps\GET_Genesys` no se reubica ni se sincroniza automáticamente con la carpeta de desarrollo; el despliegue del cliente y la preservación de sus carpetas persistentes requieren revisión del operador.

## Históricos: autorización por períodos, no por cambio de hash

BACKFILL y DAILY distinguen activa actual, activa legacy, pendiente y ausente. Una publicación legacy verificada se conserva y se registra `legacy_period_preserved`; no se reconstruye por tener otro hash. Una activa desconocida/no verificada o una reserva remota no rastreada sigue bloqueando el proceso.

Para una reconstrucción posterior, preparar y aprobar un JSON por períodos:

```json
{
  "version": 1,
  "source_id": "spwconsumomes-v1",
  "destination": "https://api.labour.agrocalera.app",
  "schema_checksum": "fccdf733fd8125cbbd3ed54179713ec18f0305c775f2bf7646692218f4eb48f9",
  "periods": ["2023-01"]
}
```

El futuro comando `npm run sync:backfill -- --rebuild-plan RUTA_JSON --max-periods 1` limita el trabajo nuevo a los períodos de esa aprobación; antes recupera cualquier UUID propio ya autorizado que hubiera quedado pendiente y usa exclusivamente SPWConsumoMes. No se ejecutó. Los UUID serán nuevos; los anteriores quedan en MySQL. Sin `--rebuild-plan`, los históricos VF se omiten y no se sobrescriben.

Comparación posterior de lectura, por cada par de versiones y período:

```bash
php8.3 artisan sync:compare UUID_ANTERIOR UUID_NUEVO --anio=2023 --mes=1
```

El reporte separa operación y columna; entrega filas, disponibilidad de campos, NULL, negativos, min/max decimal y hash de secuencia. No suma costos ni tasas por hectárea, no asocia ordinales como si fueran claves de negocio y no altera importes. Un cambio de orden puede cambiar el hash de secuencia: revisarlo, no interpretarlo automáticamente como diferencia contable. Los campos ausentes en VF no se asumen cero. Obtener este reporte y validación funcional antes de autorizar el siguiente período; no hay purga de versiones o respaldos.

## Despliegue: backend antes de cliente

1. Detener o deshabilitar la tarea antigua mediante el procedimiento operativo aprobado. Guardar un respaldo verificable de MySQL y de estado/spool/configuración Windows, sin borrarlos.
2. Preparar un release nuevo del backend. Conservar secretos, APP_KEY, tokens, permisos y archivos anteriores. Incluir los dos manifiestos de resources/schemas y el registro legacy; no copiar `.env` local.
3. Revisar `php8.3 artisan migrate --pretend`, motor InnoDB, tipos y nulabilidad. Tras aprobación, aplicar la migración incremental y copiar el manifiesto actual al archivo privado nuevo. Si `SYNC_SCHEMA_FILE` está definido, revisar su ruta explícita sin sobrescribir el archivo anterior. No usar `migrate:fresh` o rollback de datos.
4. Verificar login, consulta de una publicación VF, inspección `verificado=true` para VF, permisos y ausencia de datos del nuevo esquema todavía. Desplegar código no equivale a ejecutar BACKFILL.
5. Desplegar después el cliente Windows del mismo commit; conservar `.env`, `.automation` y `.runs`. Copiar/inspeccionar el esquema nuevo con nombre distinto y verificar su SHA. No reinstalar ni activar tareas todavía.
6. Ejecutar planificación de transición, revisar recibo propuesto y solo después autorizar `--apply`. Ejecutar inspección/dry-run de octubre; comprobar conteos y columnas sin HTTP de escritura.
7. Tras otra autorización, publicar octubre con UUID nuevo y comprobar filas, ocho costos y API. Después habilitar DAILY; los VF históricos se conservan por defecto.
8. La reconstrucción desde enero de 2023 exige su plan separado, por etapas con comparación y aprobación posterior. No hay carga histórica, DAILY o despliegue productivo en esta entrega.

Rollback: volver al release de código anterior y detener tareas nuevas, conservar todas las columnas/filas/UUID/hashes. El estado antiguo queda intacto y el nuevo permanece aparte. No volver a ejecutar VF como fallback; una reversión de código sirve para mantener consulta/auditoría mientras se corrige la fuente nueva.

## Comandos exactos: octubre 2026, sin publicar

```powershell
Set-Location 'C:\Users\AlonsoMendoza\Documents\Proyectos\GET_Genesys\client'
npm run inspect:schema -- --anio 2026 --mes 10 --schema-file schema.spwconsumomes.json
npm run sync -- --anio 2026 --mes 10 --dry-run --schema-file schema.spwconsumomes.json
```

En la instalación operativa sustituir únicamente Set-Location por `C:\Apps\GET_Genesys\client`. La inspección conserva metadatos, nunca filas; si el archivo existe con contenido diferente se detiene y no lo sobrescribe. El dry-run verifica el manifiesto observado contra el revisado y filtra Fecha sin publicar. Ninguno aprueba el vacío anterior ni modifica checkpoints.

## Validaciones y límites pendientes

La línea base pasó antes de cambiar código: 46 Vitest, 51 PHPUnit y pruebas Windows. La suite ampliada registra 50 Vitest y 57 PHPUnit, además de build y pruebas Windows. La suite ampliada cubre la fuente definitiva, 38 columnas, negativos/NULL, exactitud, filtros, conservación del estado histórico, rechazo de UUID anterior, omisión/reconstrucción autorizada, verificación VF, consulta histórica y publicación nueva atómica. Las pruebas solo usan datos sintéticos y SQLite/HTTP local; las consultas reales de octubre fueron inspecciones de lectura separadas.

Pendientes operativos: revisar/aplicar la migración en MySQL real, validar servidor/configuración privada, inspeccionar el estado real en C:\Apps, aprobar transición y cualquier publicación. No se cambiaron contraseñas ni configuración global del VPS, no se publicaron datos, no se modificó SQL Server y no se aplicó una transición al checkpoint productivo.

## Componentes y archivos

- Cliente: extractor, contrato del manifiesto, validación de los ocho costos, CLI manual y rutas persistentes por fuente.
- Automatización: runner/store/transfer/retención, plan de reconstrucción acotado y nuevo comando de transición validada.
- Backend: registro de esquemas, verificación histórica, compatibilidad de almacenamiento y consulta por UUID; reporte comparativo de solo lectura.
- Migración nueva y dos manifiestos versionados incluidos en resources/schemas, con hashes comprobados.
- Pruebas, scripts Windows, bundler y documentación actualizados.

La [lista completa de archivos de esta entrega](CAMBIO_FUENTE_ARCHIVOS.txt) permite revisar el diff. Build correcto; 50 Vitest y 57 PHPUnit, además de pruebas Windows. El dry-run real de octubre confirmó nuevamente 19.820/19.490/330 y el hash inspeccionado, sin HTTP de escritura ni filas locales. Las migraciones y comparaciones reales de MySQL quedan pendientes de autorización/entorno operativo.

Las CLI migradas usan por defecto schema.spwconsumomes.json o la opción --schema-file; no reescriben el SQL_SCHEMA_FILE anterior del .env. No se aplicó la transición real: el estado operativo en C:\Apps y su checkpoint no están presentes en este equipo de desarrollo.

Comprobación adicional de metadatos de MySQL local: no disponible el 26/10 (127.0.0.1:3306); no se iniciaron servicios y no se aplicó DDL. La revisión física de compatibilidad en MySQL sigue pendiente. La suite final pasó con 50 Vitest, 57 PHPUnit (398 aserciones), Pint y pruebas Windows PowerShell 5.1/7.
