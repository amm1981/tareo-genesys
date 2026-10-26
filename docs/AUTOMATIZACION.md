# Automatización incremental de GET_Genesys

Implementación del 25 de octubre de 2026 (America/Lima), sobre el cambio de GitHub `3152d8b`, incorporado primero mediante fast-forward. Se mantuvo la carpeta de desarrollo existente por indicación del usuario. `C:\Apps\GET_Genesys` es la ruta propuesta para la instalación operativa de Windows; los scripts aceptan otra ruta explícita.

Esta entrega prepara código, pruebas y definición de tarea. No ejecuta BACKFILL/DAILY reales, no cambia el VPS, no aplica migraciones en producción, no modifica contraseñas y no registra tareas. Las pruebas de automatización usan datos sintéticos, directorios temporales y HTTP de loopback. Las definiciones de Task Scheduler se validan en memoria.

## Arquitectura reutilizada

`Source` conserva la conexión a CASABLANCA, verifica `DB_NAME()` y ejecuta únicamente `dbo.SPWConsumoMesVF` con parámetros tipados. El motor automático exige además `192.168.136.3:1433`. Usa `extract()` y sus mismos serializadores, filtro sobre la `Fecha` real, esquema aprobado de 34 columnas y decodificador TDS exacto. No cambia el SP ni consulta otras bases.

El nuevo runner prepara los mismos lotes y manifiesto que el cliente manual. Comprueba integridad local antes del primer envío y reanuda desde el progreso confirmado por Laravel. Se conserva la transacción InnoDB de publicación de `SyncService`: completar cambia el snapshot activo; un error deja vigente el anterior. No se recalculan costos, no se mezclan operaciones y no se convierten NUMERIC/MONEY a float. Los cuatro costos FLOAT mantienen el tratamiento aproximado del origen ya documentado.

Las rutas de terceros `/api/v1/auth/*` y `/api/v1/costos*` conservan su contrato. Se agregan únicamente rutas de inspección con permiso exclusivo `sync:write`:

| Ruta GET | Finalidad |
| --- | --- |
| `/api/v1/sync/publication?anio=2026&mes=9` | Referencias activa y pendiente, verificadas y limitadas al propietario técnico |
| `/api/v1/sync/runs/{uuid}/verification` | Estado, cantidades, esquema y ledger del UUID propio |

`verificado` exige estado completado, fecha final, cantidades recibidas/publicadas, cantidad y orden de lotes, suma de sus registros, checksum global del ledger y esquema aprobado coincidente. La integridad de payloads y staging se comprueba al publicar, mediante el servicio existente. La inspección operativa no recalcula cada JSON histórico byte por byte. Si una publicación activa falla esta comprobación, el runner se detiene para revisión.

## BACKFILL

El calendario enumera enero de 2023 hasta el mes actual de Lima, inclusive, en orden. Antes de extraer cada mes consulta la publicación del VPS. Omite solamente los meses publicados y verificados con el mismo esquema aprobado. Una publicación anual anterior se reconoce en cada mes al que apunta.

Cada mes se extrae completo. Los registros de años o meses posteriores que devuelva `@Año` se descartan mediante la `Fecha` real. Se conservan conteos de origen, conservados y descartados en `plan.json`; las confirmaciones remotas conservan las cantidades de publicación.

El modo automático no pide confirmaciones. `--max-periods N` limita la cantidad de ejecuciones que termina una invocación; los meses omitidos no consumen ese cupo. Un UUID pendiente recuperado sí consume un cupo. La carga masiva se inicia únicamente cuando el operador ejecute el comando real tras aprobar el despliegue.

## DAILY

La ventana móvil cubre **30 × 24 horas hasta el momento de ejecución**. Se convierten ambos extremos a `America/Lima` con `Intl.DateTimeFormat` para seleccionar todos los meses que intersectan ese intervalo. No depende de la zona del Windows anfitrión. El plan muestra las fechas de Lima y los instantes UTC exactos del inicio y final.

Se extraen todos los meses completos que intersectan la ventana. Puede ser uno, dos o tres meses: el 1 de marzo incluye parte de enero y febrero, tanto en año bisiesto como no bisiesto. El 30 de junio incluye parte del 31 de mayo. El 25 de octubre de 2026 a las 02:00 de Lima la ventana comienza el 25 de septiembre a las 02:00 y se extraen septiembre y octubre completos.

Una nueva fecha local permite una nueva versión por mes. Un reinicio dentro de la misma fecha recupera primero el UUID pendiente y evita repetir los meses cuyo ciclo diario ya terminó y cuya publicación sigue verificada. La continuidad requiere conservar `.automation` y `.runs` en un disco persistente; al trasladar la instalación, trasladar ambos junto al esquema. El estado no puede reutilizarse con otro destino, origen o esquema.

## Recuperación y exclusión

Los checkpoints en `client/.automation/state.json` registran UUID, modo, ciclo, período, estado, cantidades esperadas, lotes y hashes. Se escriben a un temporal, se sincronizan a disco y se reemplazan mediante rename. Los lotes se sincronizan antes de renombrarse; `plan.json` es el marcador final de una extracción completa.

Estados: `extracting`, `prepared`, `uploading`, `empty_blocked`, `verified`, `abandoned`.

- Si la extracción no terminó, se conserva su carpeta parcial para revisión y se inicia una extracción nueva con otro UUID. No es posible continuar un stream SQL interrumpido sobre resultados que pueden haber cambiado.
- Si existe un plan final, se valida y se continúa ese UUID, sin volver a ejecutar el SP.
- Si falta la respuesta de un lote, se consulta el progreso de Laravel y se envían solo los lotes que faltan.
- Si `complete` ya confirmó en el VPS pero se perdió la respuesta o el checkpoint, se reconoce el UUID completado y verificado; no se crea otra publicación.
- Una reserva remota desconocida detiene el proceso. No se cancela ni se adopta una transferencia manual ajena automáticamente.
- Un estado corrupto se conserva y causa error. No se reemplaza por un estado vacío.

Manual y automático comparten un bloqueo del SO mediante un listener exclusivo de loopback `127.0.0.1:47831` (configurable con `SYNC_LOCK_PORT`). No sirve HTTP ni datos; impide dos procesos de este cliente en el mismo equipo y el SO lo libera tras terminar o reiniciar. Mantener el mismo puerto para todas las copias actualizadas del cliente. Task Scheduler agrega `IgnoreNew`; los bloqueos de períodos del VPS protegen la publicación frente a clientes de otros equipos. No constituye un bloqueo global de todos los meses entre distintos Windows.

## Resultados vacíos

Un resultado vacío automático se conserva como `empty_blocked` y no inicia una ejecución en Laravel, incluso en BACKFILL. Así no reemplaza datos anteriores por una extracción vacía inesperada.

Si el administrador verifica que un vacío es correcto, puede autorizar **ese UUID concreto** en `client/.automation/empty-approvals.json`:

```json
{"uuids":["UUID_REVISADO_DE_LA_EXTRACCION_VACIA"]}
```

El siguiente inicio continúa esa extracción. No hay una opción global que permita todos los vacíos. Se conserva el archivo de aprobación para recuperar el mismo UUID después de un fallo. Para rechazar definitivamente un vacío, revisar estado local/remoto y documentar la decisión; no borrar checkpoints a ciegas. El modo manual conserva su confirmación interactiva existente.

## Credencial y renovación

El automático utiliza `AUTO_TOKEN_FILE`, independiente del token y URL manuales de Wamp. El archivo contiene únicamente el token Sanctum del VPS. No se introduce en argumentos, XML, repositorio o logs. La API lo relee para cada solicitud; no hay login por contraseña que entregue `sync:write`.

Una futura preparación autorizada de la credencial local se hace con:

```powershell
.\scripts\windows\Set-SyncCredential.ps1 -ProjectRoot 'C:\Apps\GET_Genesys' -TaskAccount 'EQUIPO\svc_getgenesys'
```

Solicita el token oculto. Crea `client/.secrets/vps-sync-token` con DACL privada para la cuenta ejecutora, operador, SYSTEM y administradores; usa reemplazo atómico al rotar. No cambia contraseñas ni lee secretos de otra aplicación. Preparar también ACL privadas para `.env`, esquema, `.runs` y `.automation`: el servicio necesita leer configuración y escribir estado/spool, y no debe permitir lectura general de los datos extraídos. Excluir estos directorios de respaldos públicos, sincronizadores personales y Git.

Renovación operativa propuesta:

1. Antes de vencer, el administrador emite otro token mediante `php8.3 artisan sync:token --days=30` en una terminal privada del VPS, sin redirigir la salida a logs. Reutiliza la misma cuenta técnica existente: conserva la propiedad de UUID y reservas.
2. Entrega el token mediante un canal privado al operador e introduce el valor en el script anterior. Verifica un endpoint de inspección mediante una herramienta que pida el token oculto; no usar el secreto en la línea de comandos.
3. Una vez verificado el nuevo acceso, revoca únicamente el ID público del antiguo: `php8.3 artisan sync:revoke-token ID_ANTIGUO`. El comando rechaza tokens `costs:read` y cuentas distintas de la cuenta técnica.
4. Si vence antes de rotarse, el cliente registra `SYNC_TOKEN_RENEWAL_REQUIRED` y conserva el pendiente. Después de rotar, el siguiente inicio reanuda con el mismo propietario. No se reintenta un 401 indefinidamente ni se envía con permisos de lectura.

No se ejecutó esta renovación en la entrega. Programar la revisión operativa de caducidad según el mecanismo existente de la organización. No se introduce una credencial permanente de renovación en el cliente.

## Disco y retención

Antes de ejecutar y antes de escribir cada lote se consulta `statfs`. Defaults: al menos **1024 MiB libres** después de escribir y un máximo de **10240 MiB de spool** acumulado. Se detiene con `INSUFFICIENT_FREE_SPACE` o `SPOOL_LIMIT_REACHED` y conserva el pendiente. El tamaño incluye carpetas parciales e históricas; no se sigue un enlace en el árbol del spool.

`AUTO_LOCAL_RETENTION_DAYS=14` propone retener spools verificados al menos 14 días. El proceso diario solo informa candidatos, sin borrar. Revisar el plan de limpieza con:

```powershell
Set-Location client
npm run sync:retention
```

La eliminación local requiere la autorización explícita de ejecutar `npm run sync:retention -- --apply`. Antes de eliminar se vuelven a verificar manifiesto local, estado, hashes y recibo del VPS. Solo se borran los lotes y plan reconocidos de UUID verificados de esta automatización; no se tocan pendientes, extracciones manuales, archivos adicionales, enlaces, respaldos o datos del servidor. Si encuentra `backup.bak` u otro archivo desconocido, se detiene. No se ha ejecutado limpieza real en esta entrega.

Las carpetas parciales/abandonadas y temporales de checkpoint requieren revisión administrativa; permanecen contabilizadas en la cuota. Conservar checkpoints y recibos de auditoría. Los logs mensuales de metadata también requieren una retención administrativa acordada.

**Política propuesta para MySQL:** mantener siempre publicación activa y reservas, conservar versiones anteriores al menos 90 días y hasta disponer de respaldo probado y autorización de purga. La ventana de retención también debe exceder la hora de validez de cursores de consulta. Esta es una propuesta operativa, no un borrado implementado. No hay DELETE automático de staging/versiones, ni eliminación automática de respaldos. Dimensionar disco para el BACKFILL y las versiones diarias antes de autorizar la carga.

## Task Scheduler preparado

Generar solamente XML, desde el proyecto que estará instalado en la ruta elegida:

```powershell
.\scripts\windows\New-SyncTask.ps1 -ProjectRoot 'C:\Apps\GET_Genesys' -At '02:00' -RunAs 'EQUIPO\svc_getgenesys'
```

El script conserva XML existentes; usar otro `-OutputPath` para revisar otra configuración. El XML queda en `artifacts/GET_Genesys-Daily.xml`. No registra ni ejecuta una tarea por defecto. Contiene rutas absolutas a Windows PowerShell y Node, `WorkingDirectory`, zona `-05:00` para Lima, `LogonType=Password`, permisos de ejecución mínimos, `IgnoreNew`, inicio pendiente al estar disponible y tres reintentos de fallos cada 15 minutos. No depende de npm, tsx, perfiles o sesión gráfica al ejecutarse: inicia `node client/dist/auto.js daily`.

La cuenta dedicada requiere inicio de sesión por lotes, acceso de red LAN/VPN a CASABLANCA y HTTPS al dominio, lectura del código/credenciales y escritura de estado/spool. Task Scheduler almacena su credencial mediante Windows; el proceso Node no la recibe como argumento. No se utiliza S4U por sus restricciones de acceso a red. La acción fija `RemoteSigned` solo para ese proceso PowerShell y respeta las directivas de grupo; revisar firmas/Zone.Identifier según la política del equipo. No se modifica una política global.

Después de revisión y autorización, el administrador puede importar **el XML revisado** mediante Task Scheduler, indicando la contraseña en la interfaz segura, o generar una nueva definición con `-Install` y un `-OutputPath` nuevo. El switch solicita `Get-Credential`, rechaza una tarea existente y registra sin iniciar inmediatamente. No se utilizó `-Install` en esta entrega.

El wrapper registra fecha UTC, modo, resultado y códigos sanitizados en `.automation/task-YYYYMM.jsonl`. El runner registra eventos por UUID/período/cantidades en `.automation/events-YYYY-MM.jsonl`; no copia mensajes arbitrarios del driver ni cuerpo de solicitudes. El siguiente inicio recupera el pendiente antes de calcular trabajo nuevo. BACKFILL se inicia aparte; no se agenda la carga masiva con la tarea diaria.

## Pruebas reproducibles, sin carga real

```powershell
Set-Location client
npm run build
npm test
npm run sync:backfill -- --plan
npm run sync:daily -- --plan
Set-Location ..\server
php artisan test --compact
Set-Location ..
.\scripts\windows\Test-AutomationScripts.ps1
```

`--plan` calcula calendarios, no abre SQL, no llama al VPS ni modifica checkpoints. Las pruebas cubren año/mes, límites UTC/Lima, bisiesto y febrero de 28 días, meses completos, descarte de años posteriores, exactitud decimal, HTTP local real, reintentos, lote ya recibido, finalización con respuesta perdida, estado aún en extracting, vacío protegido/aprobado, disco, bloqueo local, rotación del archivo de token y retención con protección de respaldos. Las pruebas manuales existentes siguen incluidas.

El test PowerShell crea fixtures sintéticos bajo artifacts/, valida el XML con Task Scheduler en memoria y prueba un Node ficticio con salida 7 mediante Windows PowerShell sin interacción. No registra tareas, no toca la credencial real, no consulta SQL y no llama al VPS. Los fixtures quedan para revisión en artifacts/.

Resultados de esta entrega: build correcto, **46 Vitest**, **51 PHPUnit (312 aserciones)** y pruebas PowerShell correctas. La prueba adicional optativa `php tests/mysql-smoke.php`, que usa solo tablas temporales y esquema sintético privado, no pudo completarse el 25/10 porque MySQL local en `127.0.0.1:3306` rechazó la conexión. No se arrancaron servicios. Repetirla con MySQL local disponible antes del despliegue; informa fallo con código 1 sin volcar SQL ni valores. La integración de esta entrega que sí pasó incluye el runner con HTTP real de loopback, filesystem/checkpoints y simulación del servidor; PHPUnit utiliza SQLite en memoria.

## Plan de despliegue por etapas

1. Revisar y desplegar primero el backend con las dos rutas de inspección y el comando de revocación. Esta automatización no agrega migraciones. Conservar la corrección 401 de GitHub y validar la API de terceros con sus pruebas actuales.
2. En staging, verificar esquema aprobado, InnoDB, límites de HTTP/MySQL, respaldo y espacio. Probar credenciales `sync:write` y rechazo de `costs:read` en inspección. No usar una cuenta técnica diferente para una reserva pendiente existente.
3. Preparar la instalación de Windows: código del mismo commit, Node compatible, `npm ci`, build, `.env` privado, esquema idéntico, directorios persistentes y ACL. No trasladar `.env` por Git ni regenerar contraseñas. Mantener la carpeta actual de desarrollo; crear la copia operativa solo cuando se autorice.
4. Ejecutar únicamente pruebas y `--plan`. Revisar los 46 meses previstos a octubre de 2026 y la ventana diaria de Lima. Comprobar el XML, cuenta, GPO, LAN/VPN sin sesión interactiva y permisos.
5. Tras autorización específica de carga, ejecutar inicialmente `npm run sync:backfill -- --max-periods 1`. Comparar conteos y valores por columna contra el origen y verificar que terceros vean solo la publicación activa. Repetir el comando para comprobar omisión, y ensayar caída/reanudación en staging.
6. Autorizar la continuación del BACKFILL completo. El comando futuro es `npm run sync:backfill`; es real y sin confirmaciones, por lo que **no forma parte de las comprobaciones de esta entrega**.
7. Autorizar DAILY con una ejecución controlada. Luego importar la tarea revisada a la hora acordada. Verificar resultados y caducidad del token. Un rollback detiene la tarea y vuelve al código anterior conservando `.automation`, `.runs`, snapshots y respaldos; no invierte migraciones ni borra publicaciones.

Referencias primarias: [LogonType y acceso a red](https://learn.microsoft.com/en-us/windows/win32/taskschd/taskschedulerschema-logontype-simpletype), [StartBoundary](https://learn.microsoft.com/en-us/windows/win32/taskschd/taskschedulerschema-startboundary-triggerbasetype-element), [políticas de ejecución y alcance de proceso](https://learn.microsoft.com/en-us/powershell/module/microsoft.powershell.core/about/about_execution_policies?view=powershell-5.1).
