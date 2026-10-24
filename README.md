# tareo-genesys

Proyecto **GET_Genesys**. Backend de ejecución **manual**: SQL Server **CASABLANCA** → cliente Node.js/TypeScript → API Laravel 12 con Sanctum → MySQL 8. Sin frontend, Docker, cron ni sincronización automática. El nombre del proyecto no identifica la base de origen.

## Estado verificado del desarrollo

Comprobaciones del 23 de octubre de 2026, hora de Lima:

| Componente | Resultado real |
| --- | --- |
| Node.js / PHP / Composer | Node 24.22.0, PHP 8.4.15, Composer 2.10.3 disponibles |
| WampServer | Apache, MySQL y MariaDB en ejecución; se utilizó únicamente MySQL |
| MySQL | 8.4.7, `127.0.0.1:3306`, conexión correcta; `get_genesys` creada y migrada |
| SQL Server | Conexión SQL autenticada y `DB_NAME() = CASABLANCA` verificados |
| TLS SQL | Certificado autofirmado (`DEPTH_ZERO_SELF_SIGNED_CERT`); excepción temporal autorizada por el usuario: cifrado activo y `SQL_TRUST_SERVER_CERTIFICATE=true` solo en `.env` local |
| Esquema y SP reales | 34 columnas inspeccionadas, esquema guardado y migración aplicada en MySQL local |
| Dry-run septiembre 2026 | Correcto: 35 354 filas leídas, 35 354 conservadas, 0 descartadas; sin envío a API |
| API por HTTP local | Salud 200; token inválido 401; token local válido accede al estado y recibe 404 para un UUID inexistente |
| Primera transferencia real | UUID `1eab833e-4cc8-473c-a90a-9cd35e744821`, completada: 35 354/35 354 filas y 36/36 lotes; snapshot activo de septiembre 2026 verificado |
| Verificación posterior | 35 354 filas activas del 1 al 30 de septiembre; JSON idéntico a staging y 247 478 campos numéricos comparados sin diferencias |
| Pruebas | 20 Vitest, 25 PHPUnit; integración sintética Node → kernel HTTP Laravel → MySQL con `DECIMAL(38,8)` y costos `DECIMAL(65,30)` correcta |
| PHP de producción elegido | Ubuntu 24.04 LTS, paquete PHP `8.3.6-0ubuntu0.24.04.13`; dependencias resueltas con plataforma Composer `8.3.6` |

El desarrollo está implementado y la primera transferencia real ya está publicada en MySQL local. El usuario confirmó el envío de septiembre de 2026 y los 36 lotes se completaron. Se verificaron las 35 354 filas activas, su JSON completo frente a staging y sus siete campos numéricos estructurados frente al JSON, sin diferencias. La comparación independiente con SSMS no se da por realizada automáticamente: sigue siendo una validación del usuario responsable. Las cuatro columnas de costos son FLOAT aproximado del origen; DECIMAL(65,30) conserva su representación enviada sin atribuirles una exactitud que el SP no entrega. MONEY y NUMERIC conservan sus escalas originales. No se calcularon totales mezclando operaciones o columnas. Consulte [docs/ARQUITECTURA.md](docs/ARQUITECTURA.md), [docs/MAPEO_ORIGEN.md](docs/MAPEO_ORIGEN.md) y [docs/DESPLIEGUE.md](docs/DESPLIEGUE.md).

## 1. Requisitos

- Windows con PowerShell; Node.js 22.12+ o 24 LTS compatible con las dependencias fijadas.
- PHP de producción: **8.3.6, paquete Ubuntu `8.3.6-0ubuntu0.24.04.13`**, en Ubuntu 24.04 LTS. Composer 2 y extensiones PDO MySQL, mbstring, openssl, bcmath, curl, DOM, XML y fileinfo. SQLite/PDO SQLite para pruebas. El lock está resuelto para PHP 8.3.6; no exige PHP 8.4. La revisión `.13` es de Ubuntu y se verifica con `dpkg-query`, no con la versión upstream que muestra `php -v`.
- WampServer con MySQL 8 en desarrollo. No cambiar globalmente el PHP de otros proyectos. Para servir peticiones en Windows usar una compilación con parches vigentes, por ejemplo PHP 8.3.36 o una actualización posterior de esa rama; el paquete `.13` de Ubuntu no es un binario para Windows. Las versiones locales detectadas al inicio figuran en la tabla como inventario, no como recomendación de seguridad.
- Acceso LAN/VPN al SQL Server, usuario de lectura y ejecución de `dbo.SPWConsumoMesVF`, y CA/nombre TLS válidos. El SP debe ser validado por el DBA como de solo lectura; el cliente no cambia su definición ni crea objetos en SQL Server.

## 2. Dependencias y configuración

```powershell
Set-Location 'C:\Users\AlonsoMendoza\Documents\Proyectos\GET_Genesys'
Set-Location client
npm ci
# Ejecutar solo si .env aún no existe:
Copy-Item .env.example .env
npm run build
Set-Location ..\server
composer install
# Ejecutar solo si .env aún no existe:
Copy-Item .env.example .env
php artisan key:generate
```

Los `.env` locales ya se crearon durante la implementación. Consérvelos. No copie los ejemplos encima de configuraciones existentes. `.env`, datos de extracción y claves están excluidos de Git. Se incluyen ambos lockfiles para instalaciones reproducibles.

## 3. SQL Server: exclusivamente CASABLANCA

Edite `client/.env` localmente, sin pegar contraseñas en comandos o chats:

```dotenv
SQL_SERVER=192.168.136.3
SQL_PORT=1433
SQL_DATABASE=CASABLANCA
SQL_AUTH_MODE=sql
SQL_USERNAME=
SQL_PASSWORD=
SQL_ENCRYPT=true
SQL_TRUST_SERVER_CERTIFICATE=false
SQL_CA_FILE=
SQL_TLS_SERVER_NAME=
SQL_CONNECTION_TIMEOUT_MS=15000
SQL_REQUEST_TIMEOUT_MS=600000
```

Complete usuario/contraseña. Contraseñas con `#` o espacios requieren comillas según dotenv. Si el certificado usa un nombre DNS, configure `SQL_TLS_SERVER_NAME` con ese nombre y `SQL_CA_FILE` con la CA PEM correcta. No se desactiva TLS ni la validación de certificados por defecto. `SQL_TRUST_SERVER_CERTIFICATE=true` solo cambia la validación cuando se configura explícitamente; no es la solución recomendada para producción.

El puerto es configurable. Antes de usar uno distinto, confírmelo con el DBA. El cliente fija y verifica `DB_NAME() = CASABLANCA`, ejecuta únicamente el SP fijo y parametriza `Año` y `Mes`; no acepta SQL arbitrario ni otra base.

Si SSMS solo admite Windows integrada: el adaptador implementado usa `mssql/tedious` con autenticación SQL. `SQL_AUTH_MODE=windows` falla explícitamente. Se evaluó la vía ODBC: ODBC Driver 18 y `sqlcmd` están instalados, pero la prueba integrada no completó el login. `mssql/msnodesqlv8` sería una alternativa con ODBC, permisos del usuario de Windows y compilación nativa; **no está habilitada** porque debe verificarse también su entrega exacta de decimales. Solicite una cuenta SQL mínima o complete esa validación antes de cambiar el adaptador. No reutilizar automáticamente credenciales guardadas en SSMS.

## 4. MySQL de WampServer

Configuración local de `server/.env`:

```dotenv
APP_NAME=GET_Genesys
APP_ENV=local
APP_DEBUG=false
APP_URL=http://127.0.0.1:8000
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=get_genesys
DB_USERNAME=root
DB_PASSWORD=
```

La cuenta root sin contraseña es exclusivamente local. No exponer WampServer/MySQL a Internet.

```powershell
Get-Service -Name '*wamp*'
Get-NetTCPConnection -State Listen | Where-Object LocalPort -in 3306,3307
& 'C:\wamp64\bin\mysql\mysql8.4.7\bin\mysql.exe' --host=127.0.0.1 --port=3306 --user=root --execute="SELECT VERSION(), @@port;"
# Solo si la base del proyecto no existe:
& 'C:\wamp64\bin\mysql\mysql8.4.7\bin\mysql.exe' --host=127.0.0.1 --port=3306 --user=root --execute="CREATE DATABASE IF NOT EXISTS get_genesys CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;"
Set-Location 'C:\Users\AlonsoMendoza\Documents\Proyectos\GET_Genesys\server'
php artisan migrate
php artisan migrate:status
```

Se configura **InnoDB por tabla**, sin cambiar el motor predeterminado de otras aplicaciones. Este Wamp usa MyISAM por defecto; la API comprueba InnoDB antes de transferir. No usar `migrate:fresh`, `db:wipe` ni resetear tablas con datos. Si existen tablas ajenas con los mismos nombres, detener la instalación y revisarlas antes de migrar.

## 5. Token e inicio de Laravel

Se creó un token local limitado a `sync:write` y se guardó directamente en `client/.env` sin mostrarlo. Caduca a los 30 días; los tokens no son credenciales SQL.

Para una instalación nueva, después de migrar:

```powershell
Set-Location 'C:\Users\AlonsoMendoza\Documents\Proyectos\GET_Genesys\server'
php artisan sync:token --days=30 --save-client-env
# Alternativa que muestra el token una sola vez para copiarlo manualmente:
php artisan sync:token --days=30
php artisan serve --host=127.0.0.1 --port=8000
```

`--save-client-env` solo funciona en local y con `API_TOKEN` vacío. No sobrescribe un token existente. Para revocar un token, use `php artisan tinker`, busque el usuario técnico por `sync@get-genesys.invalid` y elimine exclusivamente el token por ID de `personal_access_tokens`; no borre otros usuarios/tokens. No habilitar registro/login público. Tokens inválidos/caducados reciben 401; tokens sin permiso o con comodín `*`, 403. Ejecuciones y estados pertenecen al usuario técnico autenticado.

En otra terminal:

```powershell
Invoke-RestMethod http://127.0.0.1:8000/api/health
```

La salud solo responde `{"data":{"status":"ok"}}`, sin datos ni detalles de infraestructura. No comprueba el estado de SQL Server.

## 6. Conexión y primera inspección

```powershell
Set-Location 'C:\Users\AlonsoMendoza\Documents\Proyectos\GET_Genesys\client'
npm run check:connection
npm run diagnose:connection
npm run sync -- --anio 2026 --mes 9 --dry-run
npm run inspect:schema -- --anio 2026 --mes 9
```

La inspección **ejecuta el SP de lectura y recorre su resultado**, usando la misma validación que dry-run. Guarda solo metadatos en `client/schema.actual.json`; no guarda filas ni envía a Laravel. El archivo existente no se sobrescribe si difiere. Para una nueva versión, configure otra `SQL_SCHEMA_FILE` y conserve la anterior.

Dry-run muestra nombres/tipos de columnas y los conteos de filas leídas, conservadas y descartadas. Nunca muestra trabajadores ni valores de filas. Se filtra por la `Fecha` mostrada, con año exacto y mes exacto. Fechas nulas/ambiguas, columnas duplicadas, múltiples resultsets o tipos no soportados detienen el proceso. No se recalculan costos ni se suman operaciones/columnas.

En SSMS, conectado **únicamente a CASABLANCA**, compare primero los resultados originales:

```sql
EXEC dbo.SPWConsumoMesVF @Año = 2026, @Mes = 9;
```

Compare el total del resultado original con `leidos` y el total filtrado por **Fecha de septiembre 2026** con `conservados`. Revise los importes por columna/operación con valores exactos; no sumar columnas duplicadas ni producción con soles. Los totales monetarios de referencia dependen de las columnas reales y deben definirse después de inspeccionarlas. El SP puede incluir años posteriores por su condición `YEAR(Fecha) >= @Año`.

## 7. Columnas reales y decimales

Después de revisar el esquema real:

```powershell
Set-Location 'C:\Users\AlonsoMendoza\Documents\Proyectos\GET_Genesys\server'
php artisan sync:schema:prepare ..\client\schema.actual.json
# Revisar el archivo de migración generado antes de ejecutarlo:
php artisan migrate
```

Para una instalación nueva de esta versión, el repositorio ya incluye la migración de las 34 columnas observadas y una copia de sus metadatos en `docs/source-schema.json`. Ejecute las migraciones y copie ese archivo a `client/schema.actual.json` y `server/storage/app/private/source-schema.json` si todavía no existen. El esquema observado en cada extracción debe seguir coincidiendo; nunca sobrescriba un esquema distinto ni ejecute de nuevo `sync:schema:prepare` sobre columnas ya instaladas.

El comando genera una migración Laravel; no cambia tablas por sí mismo. La migración del esquema observado **ya está aplicada en esta instalación**; no volver a prepararla. Agrega columnas con los nombres reales del SP y `DECIMAL(p,s)` de sus metadatos para cada decimal/numeric/money, sin mezclar valores. Las cuatro columnas FLOAT de costos observadas usan el almacenamiento validado descrito abajo. Conserva strings sin truncarlos. MySQL no diferencia `Fecha`/`fecha` ni `Mes`/`mes`: los valores derivados de la fecha filtrada quedan en `fecha`/`mes`, y los valores originales se conservan completos en `fecha_origen`/`mes_origen` y el JSON. Las fechas de origen se guardan como texto ISO para preservar 7 cifras fraccionarias y offsets. Se admiten los nombres reales con `$`. Otras colisiones con campos técnicos requieren mapeo explícito; las columnas existentes nunca se sobrescriben.

El esquema aprobado queda en `server/storage/app/private/source-schema.json`. En VPS debe ser el **mismo archivo byte por byte** que el cliente; sus SHA-256 deben coincidir. No cambie precision/scale a mano. La API rechaza columnas sin migrar y esquemas no aprobados. La evolución del SP requiere revisar un nuevo esquema y una migración específica.

`mssql` usa tedious 20.3.8 fijado. Su decodificador habitual devuelve decimales y money como `Number`, lo que puede perder cifras. `client/src/database/exact-tds.ts` intercepta la decodificación TDS en memoria antes de esa conversión y utiliza `BigInt` para producir strings exactos con escala, incluidos money de cuatro decimales. No modifica el driver instalado, SQL Server ni el SP. Se validan longitudes/buffers fragmentados y se bloquea una versión distinta del driver. `decimal.js` valida sin hacer totales en float. Este acceso a una API interna requiere revisar las pruebas al actualizar tedious. Si el SP devuelve costos como float/real, se conserva ese valor aproximado del origen; no se afirma precisión decimal que el SP no proporciona.

En el esquema real, `CostoMPSoles`, `CostoMPUS$`, `CostoMP1Soles` y `CostoMP1US$` son FLOAT de 8 bytes. El cliente mantiene esos valores como números en el JSON original y valida que su representación decimal canónica quepa en 35 dígitos enteros y 30 fraccionarios. Laravel extrae el token numérico directamente del texto JSON y expande exponentes con operaciones de strings, sin convertirlo a float ni calcular nuevos importes; MySQL recibe un string para DECIMAL(65,30). Se rechaza cualquier valor fuera de ese rango en lugar de redondearlo. Esta es una capacidad de almacenamiento, **no** la precisión del SP ni una regla de redondeo a 30 decimales. No convierte un costo aproximado de origen en un importe contablemente exacto.

## 8. Envío manual, reanudación y estado

Con Laravel iniciado, credenciales SQL completas, esquema migrado y conteos contrastados:

```powershell
Set-Location 'C:\Users\AlonsoMendoza\Documents\Proyectos\GET_Genesys\client'
npm run sync -- --anio 2026 --mes 9
# Confirmación interactiva exacta: ENVIAR 2026-09
```

La extracción se valida y prepara en `client/.runs/<UUID>/` antes de confirmar el envío. SQL se cierra antes de transferir. Lotes por defecto de 1000 filas, configurables con `BATCH_SIZE` (1–1000), y máximo de 4 MiB por payload; las filas grandes pueden producir lotes menores. Una fila que exceda el límite se rechaza. HTTP tiene timeout, reintentos acotados y espera progresiva. Nunca se sigue una redirección con el token.

```powershell
npm run sync -- --status 'UUID-DE-LA-EJECUCION'
npm run sync -- --resume 'UUID-DE-LA-EJECUCION'
```

Reanudar requiere la misma confirmación y reenvía la **misma extracción local**, sin volver a leer un SP que puede haber cambiado. Reintentar un lote idéntico o completar una ejecución completada es idempotente. El mismo número de lote con otro contenido se rechaza. Los identificadores de fila son ordinales del snapshot: se conservan dos filas válidas de contenido idéntico.

Si se abandona una transferencia, libere su período explícitamente:

```powershell
Set-Location 'C:\Users\AlonsoMendoza\Documents\Proyectos\GET_Genesys\server'
php artisan sync:abort 'UUID-DE-LA-EJECUCION'
```

Cancelar no borra staging ni lo publicado. No hay vencimiento automático ni tarea de limpieza. Los archivos `.runs` contienen datos del origen: restrinja los permisos NTFS y conserve solo lo necesario para reanudar/auditar; no los comparta ni suba a Git.

Sin `--mes`, se ejecuta `@Mes=NULL`, filtra el año y reserva/publica los doce meses atómicamente. Una extracción vacía confirmada publica un snapshot vacío para el período; revise el conteo antes de confirmarla.

## 9. API

| Método | Ruta | Acceso |
| --- | --- | --- |
| GET | `/api/health` | Mínimo público |
| POST | `/api/v1/sync/runs` | Bearer Sanctum `sync:write` |
| POST | `/api/v1/sync/runs/{uuid}/batches` | Mismo permiso y propietario |
| POST | `/api/v1/sync/runs/{uuid}/complete` | Mismo permiso y propietario |
| GET | `/api/v1/sync/runs/{uuid}/status` | Mismo permiso y propietario |

Éxito: `{"data":...}`. Error: `{"error":{"code":"...","message":"..."}}`. Un lote contiene `numero_lote`, `payload` (string JSON de filas con `numero_fila` y `datos_json`) y su SHA-256 UTF-8. El hash global es SHA-256 de concatenar `numero_lote:checksum\n` en orden. La ejecución incluye `schema_json` y su hash, cantidad esperada y cantidad de lotes. Consulte la implementación y fixtures para el contrato exacto. Las solicitudes POST requieren JSON y se limitan por tamaño y frecuencia (120/minuto). HTTP solo se permite para localhost en entorno local/testing; producción requiere HTTPS.

## 10. Consultar lo publicado en MySQL

No contar todo `costos_agricolas` como información vigente: se conservan versiones anteriores.

```sql
SELECT uuid, anio, mes, estado, total_esperado, total_recibido, lotes_recibidos
FROM sync_runs ORDER BY id DESC;

SELECT c.anio, c.mes, COUNT(*) AS filas_activas
FROM costos_agricolas c
JOIN sync_periods p ON p.active_run_id = c.sync_run_id
  AND p.anio = c.anio AND p.mes = c.mes
WHERE c.anio = 2026 AND c.mes = 9
GROUP BY c.anio, c.mes;

SELECT r.uuid, COUNT(c.id) AS filas, r.total_esperado
FROM sync_runs r LEFT JOIN costos_agricolas c ON c.sync_run_id = r.id
WHERE r.estado = 'completada'
GROUP BY r.id, r.uuid, r.total_esperado;
```

Ejecute consultas locales con `mysql.exe --host=127.0.0.1 --port=3306 --user=root --database=get_genesys`. Consulte únicamente columnas monetarias reales verificadas; no convierta DECIMAL a float en herramientas posteriores.

## 11. Pruebas

```powershell
Set-Location 'C:\Users\AlonsoMendoza\Documents\Proyectos\GET_Genesys\client'
npm run build
npm test
npm audit
Set-Location ..\server
php artisan test --compact
composer audit
```

PHPUnit fuerza SQLite `:memory:`; jamás apunta a producción ni ejecuta el SP. Los mocks prueban conexión fijada, esquema realista, parámetros y cancelación. Paquetes TDS sintéticos prueban decimal(38,8), numeric, money extremos, 100ns, offsets y fragmentación. Pruebas API cubren tokens, tipos, checksums, ordinales, reintentos, duplicados válidos, rollback, períodos anuales/mensuales y snapshots.

Prueba manual de integración **sintética** con MySQL local:

```powershell
Set-Location 'C:\Users\AlonsoMendoza\Documents\Proyectos\GET_Genesys\client'
npx tsx scripts/export-fixture.ts
Set-Location ..\server
php tests/mysql-smoke.php
```

Usa el protocolo generado por Node, peticiones a través del kernel HTTP real de Laravel, Sanctum y MySQL real. Crea tablas **temporales de sesión**, sin instalar columnas ficticias en las tablas persistentes; hace rollback y las descarta al terminar. No requiere el servidor HTTP levantado. No prueba la red HTTP ni el SQL Server real. Además de estas pruebas sintéticas, ya se completó y verificó el envío manual real de septiembre de 2026 descrito en el estado inicial del README.

## 12. Errores frecuentes

- **Credenciales SQL pendientes:** completar usuario/contraseña de CASABLANCA. No hay credenciales reales en ejemplos.
- **Timeout SQL / login integrado:** confirmar VPN/LAN, puerto, modo SQL/Windows y permisos con el DBA; no abrir 1433 a Internet.
- **Error TLS:** instalar/configurar CA PEM y nombre DNS. `ESOCKET` puede envolver `DEPTH_ZERO_SELF_SIGNED_CERT`; el cliente ahora distingue esta causa sin mostrar credenciales. `npm run diagnose:connection` muestra categorías técnicas seguras. En este entorno se autorizó temporalmente confiar en el certificado del servidor LAN manteniendo el cifrado; sustituir la excepción por la CA/certificado verificado y volver a `SQL_TRUST_SERVER_CERTIFICATE=false` cuando esté disponible.
- **`schema_not_approved` / `schema_not_migrated`:** inspeccionar, revisar, preparar y migrar; mismo archivo en ambos componentes.
- **Fecha ambigua:** detenerse y revisar el valor del SP en un entorno autorizado; no inferir si `09/10` significa septiembre u octubre.
- **MySQL 1071 / `innodb_required`:** comprobar el motor de las tablas del proyecto. La configuración del proyecto usa InnoDB. No cambiar globalmente Wamp ni borrar tablas para resolverlo.
- **401/403:** token caducado, inválido, sin `sync:write` o con `*`; emitir uno limitado y revisar HTTPS. Revisar configuración de proxy en VPS.
- **409 `period_busy`:** reanudar el UUID pendiente o cancelarlo con `sync:abort`. No eliminar el período activo.
- **413:** revisar el tamaño de lote, PHP `post_max_size` y límite de Nginx. Mantener los límites coordinados.
- **Integridad/decimal:** conservar la extracción, no publicar y revisar metadatos/driver. Nunca redondear para que el envío pase.

No se modificó el VPS. El acceso SSH no interactivo no pudo autenticarse; el administrador ejecutará el inventario. La preparación está en [deploy/README.md](deploy/README.md), con plantillas y verificaciones para `api.labour.agrocalera.app`. El paquete sin secretos se genera con `scripts/Build-Deployment.ps1` en `artifacts/`. La guía general está en [docs/DESPLIEGUE.md](docs/DESPLIEGUE.md).

La elección de PHP y sus comprobaciones de seguridad están detalladas en [docs/PHP_PRODUCCION.md](docs/PHP_PRODUCCION.md). Composer valida compatibilidad del intérprete y librerías; no puede certificar la revisión de seguridad de un paquete Ubuntu.
