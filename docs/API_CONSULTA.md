# API de consulta para sistemas externos

> Actualización 26/10/2026: toda nueva extracción usa exclusivamente **dbo.SPWConsumoMes (38 columnas)**. El esquema VF de 34 columnas queda registrado para consulta histórica. Antes de operar, seguir [la transición de fuente](CAMBIO_FUENTE.md). No reutilizar el checkpoint vacío anterior ni reconstruir históricos sin aprobación.

Base de producción: `https://api.labour.agrocalera.app`. Las rutas están implementadas; su disponibilidad pública depende del despliegue y DNS/HTTPS del VPS. En desarrollo se admite `http://127.0.0.1:8000`.

Cada sistema usa una cuenta de consulta propia, creada por un administrador. El usuario y contraseña se envían en el body JSON del login; las siguientes solicitudes usan el token devuelto en `Authorization: Bearer ...`. Estas cuentas consultan los períodos publicados de esta instalación. No hay partición de datos por empresa o usuario: cada cuenta habilitada tiene acceso de lectura a todos esos períodos. El identificador no es un usuario de SQL Server ni de MySQL.

## 1. Crear y administrar la cuenta

Desde `server/`, tras aplicar las migraciones:

```bash
php8.3 artisan api:usuario mi_sistema --nombre="Sistema de gestión"
```

En Windows local puede usarse `php artisan ...`. La consola solicita dos veces la contraseña de forma oculta. Debe tener entre 16 y 72 bytes; con caracteres ASCII corresponde a 16–72 caracteres. No se recibe como argumento del proceso ni se imprime. Se almacena un hash, nunca la contraseña en texto.

Ejecutar de nuevo el comando para la misma cuenta cambia su contraseña, la habilita y revoca sus tokens de consulta anteriores. Para deshabilitarla y revocar todos sus tokens de lectura:

```bash
php8.3 artisan api:usuario mi_sistema --desactivar
```

No se crean usuarios o contraseñas predeterminados. Las cuentas técnicas de sincronización existentes no adquieren acceso por contraseña con esta migración.

## 2. Login

```http
POST /api/v1/auth/login HTTP/1.1
Host: api.labour.agrocalera.app
Accept: application/json
Content-Type: application/json

{"usuario":"mi_sistema","password":"CONTRASEÑA_CONFIGURADA"}
```

No enviar credenciales como query string, autenticación Basic ni en los logs. `usuario` admite 3–64 caracteres: letras ASCII, números, punto, guion y guion bajo; empieza con letra o número. Se compara sin distinguir mayúsculas. La contraseña se compara tal como se recibe, incluidos sus espacios.

Respuesta 200 (token ilustrativo):

```json
{
  "data": {
    "access_token": "TOKEN_DEVUELTO_POR_EL_SERVIDOR",
    "token_type": "Bearer",
    "expires_in": 3600,
    "expires_at": "2026-10-24T06:00:00+00:00"
  }
}
```

El permiso es exclusivamente `costs:read`. La vigencia predeterminada es 60 minutos, configurable en el servidor con `API_QUERY_TOKEN_TTL_MINUTES` entre 1 y 1440. Al vencer, realizar un nuevo login. Se conservan como máximo 10 tokens de consulta por cuenta: un nuevo login revoca los más antiguos que excedan ese límite, sin invalidar automáticamente los demás.

El login admite 5 intentos por minuto para una combinación cuenta/IP y 30 por IP. Un 429 incluye `Retry-After`; respetar ese intervalo. Usuario inexistente, contraseña errónea y cuenta deshabilitada producen el mismo 401. La API no registra contraseñas ni tokens.

## 3. Consultar los períodos publicados

```http
GET /api/v1/costos/periodos?anio=2026 HTTP/1.1
Host: api.labour.agrocalera.app
Accept: application/json
Authorization: Bearer TOKEN_DEVUELTO_POR_EL_SERVIDOR
```

`anio` es obligatorio (1900–9999). Devuelve hasta 12 meses con `anio`, `mes`, `publicacion_uuid`, `publicado_en` y `filas`. El conteo corresponde al mes, incluso cuando la publicación proviene de una sincronización anual. Un mes publicado vacío aparece con `filas: 0`; un mes nunca publicado no aparece. Las fechas de publicación están expresadas en UTC.

## 4. Consultar los registros

```http
GET /api/v1/costos?anio=2026&mes=9&limite=100 HTTP/1.1
Host: api.labour.agrocalera.app
Accept: application/json
Authorization: Bearer TOKEN_DEVUELTO_POR_EL_SERVIDOR
```

| Parámetro | Regla |
| --- | --- |
| `anio` | Obligatorio, 1900–9999 |
| `mes` | Obligatorio, 1–12; para un año consultar cada mes |
| `limite` | Opcional, 1–500; predeterminado 100 |
| `operacion` | Opcional, valor de la columna original `Operacion`, hasta 14 caracteres |
| `tabla_origen` | Opcional, valor de `TablaOrigen`, hasta 20 caracteres |
| `fecha_desde`, `fecha_hasta` | Opcionales, `YYYY-MM-DD`, inclusivas y dentro del año/mes indicado |
| `cursor` | Valor opaco devuelto por la página anterior; codificarlo como parámetro URL |

Los filtros de operación y tabla usan igualdad en MySQL, con la intercalación de la base. Los filtros de fecha utilizan la `Fecha` real conservada por el proceso de sincronización. No se calculan sumas ni se combinan operaciones o columnas monetarias.

Respuesta ilustrativa (se muestran solo algunas columnas dentro de `datos`):

```json
{
  "data": [
    {
      "numero_fila": 1,
      "fecha": "2026-09-01",
      "datos": {
        "Fecha": "2026-09-01T12:30:00.1234567",
        "Kardex": "999999999999999999",
        "Cantidad": "123456789012345.6789",
        "HAS": "0.0001",
        "CostoMPSoles": "0.30000000000000004",
        "CostoMPUS$": "0.0000000000000000000000001"
      }
    }
  ],
  "meta": {
    "anio": 2026,
    "mes": 9,
    "publicacion_uuid": "UUID_DE_LA_PUBLICACION",
    "publicado_en": "2026-10-24 03:32:36",
    "paginacion": {
      "limite": 100,
      "cantidad": 1,
      "hay_mas": false,
      "siguiente_cursor": null
    }
  }
}
```

En la respuesta real, `datos` conserva las 38 columnas originales en la fuente actual (34 en históricos VF), nombres, Unicode, espacios, fechas y NULL. NUMERIC/MONEY siguen siendo strings con la escala original. Los ocho costos FLOAT de la fuente actual se exponen también como strings decimales, extraídos del token numérico del JSON almacenado y expandiendo exponentes sin convertirlos a float. Esto conserva su representación aproximada de origen; no convierte FLOAT en un importe exacto. El resto de los tipos mantiene su representación del protocolo de extracción. No convertir estos strings a `Number`, `float` o `double` si el sistema necesita conservar todas sus cifras.

## 5. Paginación consistente

La primera página selecciona la publicación activa del mes. Leer `meta.paginacion.siguiente_cursor` y repetir la solicitud con los mismos filtros y límite, agregando ese cursor. Terminar cuando sea `null`. Se conservan filas idénticas que tienen distintos ordinales.

Cada cursor está cifrado y autenticado, vinculado al usuario, filtros, límite y publicación inicial. Vence 60 minutos después de la primera página, sin extenderse al avanzar. Si llega una nueva sincronización durante la consulta, el cursor continúa sobre la publicación ya elegida. Una consulta nueva sin cursor selecciona la nueva publicación activa. No se puede elegir libremente una publicación histórica enviando su UUID.

Si el cursor vence o se alteran sus filtros, se devuelve 422 `invalid_cursor`: reiniciar la consulta completa y no mezclar páginas de diferentes publicaciones. Una identidad técnica de fila es `(publicacion_uuid, numero_fila)`; no equivale a una clave de negocio permanente. No se usa paginación por offset ni número de página.

Solo se consultan datos publicados de MySQL. El endpoint no ejecuta el SP ni expone staging, payloads de transferencia o ejecuciones pendientes. Un mes sin publicación devuelve 404 `period_not_published`. Una publicación vacía devuelve 200 con `data: []` y su UUID; un filtro sin coincidencias también devuelve una lista vacía.

## 6. Cerrar el acceso del token actual

```http
POST /api/v1/auth/logout HTTP/1.1
Host: api.labour.agrocalera.app
Accept: application/json
Content-Type: application/json
Authorization: Bearer TOKEN_DEVUELTO_POR_EL_SERVIDOR

{}
```

Devuelve `{"data":{"revocado":true}}`. Revoca únicamente el token usado; los demás tokens de esa cuenta continúan hasta vencer, cerrarse o ser revocados por administración.

Las rutas de consulta y logout permiten 120 solicitudes por minuto por cuenta, compartidas entre sus tokens. Exigen Bearer y cuenta habilitada. Un token de sincronización no autoriza consultas y un token de lectura no autoriza envíos. No se admiten comodines ni tokens con ambos permisos.

Respuestas: 401 para autenticación ausente/inválida/caducada, 403 para permisos o HTTPS insuficientes, 404 para período no publicado, 415 si un POST no lleva JSON, 422 para parámetros/cursor inválidos y 429 por límite. Los errores usan `{"error":{"code":"...","message":"..."}}`, sin datos internos. Las respuestas llevan `Cache-Control: no-store, private`.

Documentación base de la implementación: [tokens y caducidad de Sanctum](https://laravel.com/docs/12.x/sanctum), [límites de solicitudes de Laravel](https://laravel.com/docs/12.x/routing#rate-limiting).
