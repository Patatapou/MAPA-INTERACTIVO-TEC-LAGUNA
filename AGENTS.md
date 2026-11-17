# AGENTS.md — Mapa Interactivo ITL

Static Leaflet campus map (ITL Laguna). No build step, no package manager, no tests, no CI, no linter/formatter.

## Servidor local (PHP integrado)

No hay servidor en el repo. Usar `start-server.ps1` (PowerShell, Windows):

```
.\start-server.ps1
```

Lanza `php -S 127.0.0.1:8000` desde la raíz. Escucha SOLO en localhost (no exponer a Internet).

URLs:

- Mapa principal: `http://127.0.0.1:8000/index.html`
- Panel admin: `http://127.0.0.1:8000/index.php?p=login`

Detener: `Ctrl+C` en la terminal de PowerShell.

PHP 8.4.7 CLI disponible. No hay `php.ini` cargado. `mail()` requiere SMTP configurado (ver sección Correo).

## `PHP/public/` está OBSOLETO

- No agregar código, ni leer/escribir datos ahí. Todo el backend vive en la raíz y en `PHP/{admin,auth,data,repositories,services}/`.
- El router real es `index.php` (raíz); los endpoints son `saveGeometria.php`, `deleteEdificio.php`, `check-session.php`, `send-suggestion.php`, `mapa.php` (raíz).
- Persistencia: `datos.json`, `GeoTec.js` e `img/` en la raíz. `PHP/public/datos.json` y `PHP/public/img/` son copias viejas que NO se actualizan.
- No borrar el contenido de `PHP/public/` sin autorización explícita.

## Run / verify (mapa estático)

- Entry point: `index.html` (repo root). Abrir directamente en el navegador o servir estáticamente.
- Leaflet 1.9.4 carga desde unpkg CDN — internet requerido. Tiles base locales: `Teselas ITL Satelital/{z}/{x}/{y}.jpg`, zoom 15–19.
- Leaflet-Geoman está vendorizado en `js/vendor/leaflet-geoman/leaflet-geoman.min.js` y `css/vendor/leaflet-geoman/leaflet-geoman.css` (no depende de CDN).
- Orden de scripts (importante): `GeoTec.js` (define `datosTec`) → `js/beacons-simulation.js` → `js/pathfinding.js` → `js/main.js`.

## Botón de acceso administrativo / sugerencias

- Botón ⚙️ junto al control "Centrar" (arriba-derecha del mapa).
- Al hacer clic, consulta `check-session.php` (raíz) para verificar sesión de admin.
- **Usuario sin permisos:** abre modal con formulario de sugerencias (edificio, descripción, correo opcional). Incluye botón "Iniciar sesión como administrador" que redirige a `index.php?p=login`. Envía a `send-suggestion.php` (raíz), que reenvía correo a `migel2013+mapa@outlook.com`.
- **Usuario admin con sesión válida:** redirige a `index.php?p=editar` (panel).
- **Sesión expirada:** redirige a login.
- La comprobación de permisos se hace en PHP, no solo en JavaScript.

## Correo de sugerencias

- `send-suggestion.php` usa PHP `mail()` con el resultado real (no simula éxito).
- Destinatario fijo: `migel2013+mapa@outlook.com`.
- Configuración SMTP por variables de entorno: `SMTP_HOST`, `SMTP_PORT`, `SMTP_FROM`.
- Sin `php.ini` ni SMTP configurado, `mail()` fallará y se reportará el error. Configurar SMTP local antes de usar en producción.
- CSRF: token en sesión, validado con `hash_equals`.
- Anti-spam: máximo 5 envíos por sesión cada hora.
- Sanitización: se eliminan saltos de línea de campos de cabecera (protección contra inyección de encabezados).

## Archivos nuevos (Etapa 1)

- `start-server.ps1` — inicia servidor PHP local
- `check-session.php` (raíz) — verifica sesión admin + devuelve token CSRF
- `send-suggestion.php` (raíz) — procesa formulario y envía correo (también guarda en `PHP/data/suggestions.json`)
- `.gitignore` — excluye configuración local, credenciales, logs, datos runtime

## Buscador en panel administrativo

- `PHP/admin/panel.php` — sidebar de edificios con campo de búsqueda (`#buscar-edificios`)
- Filtra edificios por nombre, ID, aulas o tipo (búsqueda client-side, sin recargar página)
- `css/panel.css` — estilos del buscador en `.sidebar-search` y del distintivo `.tipo-badge`
- Cada `<a class="edificio-item">` tiene `data-search` con ID, nombre, aulas y tipo en minúsculas
- Si no hay coincidencias, se muestra mensaje "No se encontraron edificios que coincidan"

## Autenticación y control de permisos (Etapa 2)

- `PHP/auth/auth_middleware.php` — función `requerirAdmin()` para proteger rutas; `generarTokenCSRF()`, `validarTokenCSRF()`, `renovarTokenCSRF()`
- `PHP/auth/csrf.php` — generación y validación de tokens CSRF
- `PHP/auth/rate_limit.php` — rate limiting de intentos de login (máx. 5 por hora)
- `PHP/auth/` — directorio de infraestructura de seguridad

### Protección de rutas

- `index.php?p=editar` — requiere sesión de admin válida
- `index.php?p=logout` — requiere sesión de admin válida
- `index.php?p=getimages` — requiere sesión de admin válida + sanitización de `folder` contra path traversal
- `index.php?p=saveData` — requiere sesión de admin válida + validación CSRF
- `index.php?p=login` — público (puerta de entrada), con CSRF y rate limiting, autentica contra `users.json`
- `index.php?p=mapa` — público (mapa estático)

### Sesiones

- Cookie de sesión: `HttpOnly`, `SameSite=Lax`, `Secure` solo si HTTPS, vida 2 horas
- `session_regenerate_id(true)` tras login exitoso (previene fijación de sesión)
- Expiración por inactividad: 2 horas (`$_SESSION['auth_time']`)
- CSRF token requerido en formularios de login y guardado de edificios

## Capa de persistencia documental JSON (Etapa 3)

- `PHP/data/` — directorio privado de almacenamiento (usuarios, configuración, auditoría, sugerencias). No debe exponerse por HTTP en producción.
- `PHP/data/users.json` — cuentas de administrador (reemplaza credenciales hardcodeadas de `credenciales.php`)
- `PHP/data/settings.json` — configuración general del sistema (sin secretos ni contraseñas SMTP)
- `PHP/data/audit-log.jsonl` — registro de eventos de auditoría en formato JSONL
- `PHP/data/suggestions.json` — sugerencias enviadas desde el mapa público
- `PHP/repositories/BaseRepository.php` — clase base con lectura/escritura atómica (flock + reemplazo atómico)
- `PHP/repositories/UserRepository.php` — operaciones CRUD de usuarios
- `PHP/repositories/SettingsRepository.php` — operaciones de configuración
- `PHP/repositories/AuditLogRepository.php` — operaciones JSONL de auditoría
- `PHP/repositories/SuggestionRepository.php` — operaciones CRUD de sugerencias
- `PHP/services/AuthService.php` — lógica de autenticación contra users.json
- `PHP/services/SuggestionService.php` — lógica de sugerencias del mapa público
- `PHP/services/SettingsService.php` — lógica de configuración del sistema

### Autenticación

- Login autentica ÚNICAMENTE contra `PHP/data/users.json` (no usa `credenciales.php`)
- Contraseñas almacenadas con `password_hash()`, verificadas con `password_verify()`
- Primer usuario: `admin` / `123456` (hash migrado de credenciales.php)

### Modelo de usuario (users.json)

| Campo          | Tipo   | Descripción                         |
| -------------- | ------ | ------------------------------------ |
| id             | string | Identificador único                 |
| username       | string | Nombre de usuario                    |
| email          | string | Correo electrónico                  |
| password_hash  | string | Hash de contraseña                  |
| role           | string | Rol (ej: administrador)              |
| status         | string | Estado (activo, inactivo, bloqueado) |
| created_at     | string | Fecha de creación (ISO 8601)        |
| updated_at     | string | Última modificación (ISO 8601)     |
| last_login_at  | string | null                                 |
| schema_version | int    | Versión del esquema                 |

## Archivos modificados

- `index.php` (raíz) — router: middleware `requerirAdmin()` en rutas protegidas (editar, logout, getimages, saveData, saveGeometria, deleteEdificio, editar-geometria)
- `PHP/admin/login.php` — usa `AuthService` (users.json), CSRF, rate limiting, `session_regenerate_id(true)`, cookies seguras
- `PHP/admin/panel.php` — usa `requerirAdmin()` en lugar de verificación inline de sesión
- `PHP/admin/saveEdificio.php` — usa `requerirAdmin()` + CSRF + registro de auditoría al guardar metadatos
- `PHP/admin/imagelist.php` — usa `requerirAdmin()` + sanitiza `folder` contra path traversal (lee `img/<id>/` de la raíz)
- `PHP/admin/logout.php` — usa `requerirAdmin()`, limpia cookie de sesión, redirige a login
- `send-suggestion.php` (raíz) — usa `SuggestionService` para guardar en suggestions.json + envío de correo
- `index.html` — botón ⚙️ + modal de sugerencias
- `js/main.js` — lógica de sesión admin, formulario, envío AJAX
- `styles.css` (raíz, usado por `index.html`) — estilos del botón y modal

## Archivos que deben mantenerse fuera de GitHub

- `php.ini`, `.env` (configuración local)
- Credenciales y archivos `*credenciales*`
- `PHP/data/` (usuarios, configuración, auditoría, sugerencias)
- Logs `*.log`
- Imágenes subidas por el panel admin (`img/`)

## Datos clave

- `datos.json` — metadata de edificios. Claves deben coincidir con `id` en `GeoTec.js`.
- `GeoTec.js` — GeoJSON de huellas de edificio (global `datosTec`).
- `caminitos.geojson` — red de caminos normalizada del router Dijkstra y del módulo de rutas: 313 features (IDs `seg-xxxxxxxx`), 644 vértices. Respaldo del original en `caminitos.geojson.bak`.
- `img/<id>/` — imágenes de carrusel por edificio.
- `PHP/data/rutas.json` — rutas administrativas (privado, fuera de git).

## Módulo de administración de edificios (etapa actual)

- `PHP/admin/editar-geometria.php` — editor visual de geometrías con Leaflet + Leaflet-Geoman (vendorizado)
- `saveGeometria.php` (raíz) — endpoint para guardar metadatos + geometría (`datos.json` + `GeoTec.js`)
- `deleteEdificio.php` (raíz) — endpoint para eliminar edificio (ambos archivos)
- `PHP/services/BuildingService.php` — lógica de negocio: crear/actualizar/eliminar edificios, `tipo` y `actualizarMetadatos()`
- `PHP/repositories/BuildingGeometryRepository.php` — lectura/escritura atómica de GeoTec.js
- `css/editor.css` — estilos del editor (leyenda de capas, ayudas de formulario)
- `js/editor.js` — dibujo manual + edición de vértices con Geoman + capa de referencia
- `js/vendor/leaflet-geoman/leaflet-geoman.min.js` — plugin de edición vendorizado (2.20.6)
- `css/vendor/leaflet-geoman/leaflet-geoman.css` — estilos del plugin

### Rutas del panel administrativo

| Ruta                                                   | Descripción                                                     | Requiere auth |
| ------------------------------------------------------ | ---------------------------------------------------------------- | ------------- |
| `index.php?p=editar`                                 | Panel principal con lista de edificios                           | Sí           |
| `index.php?p=editar&editar=<id>`                     | Editar metadatos e imágenes (incluye `csrf_token` en el form) | Sí + CSRF    |
| `index.php?p=editar-geometria&accion=nuevo`          | Editor para crear nuevo edificio                                 | Sí           |
| `index.php?p=editar-geometria&accion=editar&id=<id>` | Editor para editar geometría existente                          | Sí           |
| `index.php?p=saveGeometria`                          | POST: guardar edificio (metadatos + geometría)                  | Sí + CSRF    |
| `index.php?p=saveData`                               | POST: guardar metadatos + imágenes de un edificio existente     | Sí + CSRF    |
| `index.php?p=deleteEdificio`                         | POST: eliminar edificio                                          | Sí + CSRF    |
| `index.php?p=rutas`                                  | Listado de rutas (`&accion=ver&id=`, `&accion=nueva`, `&accion=editar&id=`) | Sí  |
| `index.php?p=getRutas`                               | GET: listado (`?id=` para el detalle)                            | Sí           |
| `index.php?p=saveRuta`                               | POST: crear/actualizar ruta                                      | Sí + CSRF    |
| `index.php?p=deleteRuta`                             | POST: eliminar ruta                                              | Sí + CSRF    |
| `index.php?p=getRedCaminos`                          | GET: segmentos normalizados de `caminitos.geojson`               | Sí           |

### Editor de geometrías

- Botón "➕ Agregar edificio" en el panel principal abre el editor
- Cada elemento de la lista muestra el nombre a la izquierda y las acciones a la derecha, apiladas: "Editar geometría" arriba y "Eliminar" debajo
- Botón "Editar geometría" abre el editor de edición del edificio
- Botón "Eliminar" elimina metadatos + geometría (con confirmación y respuesta JSON)
- La lista `#lista-edificios` no muestra viñetas (`list-style: none`)
- Toolbar de dibujo siempre visible: 🟥 Dibujar, ✏️ Editar, 🗑️ Eliminar
- **Dibujar**: clic para agregar vértices. Cierra con doble clic o con un clic a ≤10 **píxeles** del primer vértice (tolerancia constante en píxeles, independiente del zoom; no son metros)
- **Editar**: activa `polygon.pm.enable()` de Leaflet-Geoman y arrastra los vértices. Es el plugin el que gestiona la edición; `js/editor.js` solo sincroniza el GeoJSON al input en `pm:markerdrag`, `pm:markerdragend`, `pm:vertexadded`, `pm:vertexremoved` y `pm:edit`
- **Eliminar**: haz clic sobre el polígono (no sobre su bounding box) para borrarlo del formulario
- Las geometrías existentes se dibujan como **capa de referencia de solo lectura** (`interactive: false`): no reciben clics, no se pueden editar ni borrar por accidente. La geometría del edificio en edición se excluye de la capa de referencia
- Formulario: ID, Nombre, Tipo (Edificio / Acceso), Descripción, Aulas
- En modo edición el ID es de solo lectura (no hay renombrado)
- Botón "💾 Guardar edificio" persiste en `datos.json` y `GeoTec.js`
- Botón "Cancelar" / "← Volver al panel" para salir sin guardar

### Arquitectura de datos (separada)

- `datos.json` (raíz) — metadatos: `nombre`, `descripcion`, `aulas`, `carpeta`, `tipo`, `imagenes`. Clave = ID del edificio.
- `GeoTec.js` (raíz) — geometrías: variable global `datosTec` (GeoJSON FeatureCollection). Cada feature tiene `properties.id` que coincide con la clave en `datos.json`.
- `caminitos.geojson` — red de caminos para el router Dijkstra.
- `img/<id>/` — imágenes de carrusel por edificio (en la raíz).

### Modelo edificio vs. acceso

- `tipo` en `datos.json` distingue registros: `"edificio"` (valor por defecto si el campo falta) y `"acceso"`.
- **P1–P6 son accesos (puertas del campus), no geometrías huérfanas.** Se conservan, se almacenan en `datos.json` con `"tipo": "acceso"` y se gestionan desde el panel como cualquier otro registro (editar metadatos, geometría, eliminar).
- En el panel se muestran con el distintivo "Acceso" en vez del conteo de aulas.
- El selector de puertas del mapa (`#doors-select`) y `obtenerNombrePuerta()` leen el nombre desde `datos.json`; renombrar un acceso en el panel se refleja en el mapa público.
- `properties.Nombre` de `GeoTec.js` es el nombre largo/descriptivo que se usa en el tooltip del mapa; `datos.json.nombre` es el nombre corto de despliegue. **Son distintos a propósito: no sincronizar en bloque.**

### Depuración de errores PHP

- Los errores están ocultos por defecto. Para verlos en local: `APP_DEBUG=1 php -S 127.0.0.1:8000` (o `$env:APP_DEBUG='1'` en PowerShell).
- Las respuestas AJAX son siempre JSON; si llega HTML, el JS muestra "Respuesta inesperada del servidor".

### Label de edificio en el mapa

- Cada edificio muestra un círculo blanco con su ID (ej: "5", "1A", "P1")
- Tooltip con el nombre del edificio al pasar el mouse
- Las geometrías se dibujan con `smoothFactor: 0` en `L.geoJSON` / `L.polygon` (mapa, editor y capa de referencia). Leaflet simplifica por defecto (`smoothFactor: 1`) y las huellas con muchos vértices se veían como cuadrados.

## Archivos nuevos (módulo de edificios)

- `PHP/admin/editar-geometria.php` — vista del editor
- `saveGeometria.php` (raíz) — endpoint guardar
- `deleteEdificio.php` (raíz) — endpoint eliminar
- `PHP/services/BuildingService.php` — servicio de lógica
- `PHP/repositories/BuildingGeometryRepository.php` — repositorio de geometrías
- `css/editor.css` — estilos
- `js/editor.js` — lógica JS
- `js/vendor/leaflet-geoman/` y `css/vendor/leaflet-geoman/` — plugin vendorizado

## Archivos modificados (adicionales)

- `index.php` (raíz) — rutas `?p=editar-geometria`, `?p=saveGeometria`, `?p=deleteEdificio`
- `PHP/admin/panel.php` — botón "Agregar edificio", "Editar geometría", "Eliminar", "Volver al mapa", CSRF en formularios, borrado vía `fetch`
- `js/main.js` — nombres de accesos leídos desde `datos.json`

## Archivos que deben mantenerse fuera de GitHub

- `php.ini`, `.env` (configuración local)
- Credenciales y archivos `*credenciales*`
- `PHP/data/` (usuarios, configuración, auditoría, sugerencias)
- Logs `*.log`
- Imágenes subidas por el panel admin (`img/`)

## Módulo de gestión de rutas (caminos)

Recorridos administrativos (no alteran el cálculo del mapa público). Sin base de datos: persisten en `PHP/data/rutas.json` y trabajan sobre la red normalizada de `caminitos.geojson`.

### Archivos del módulo

- `PHP/admin/rutas.php` — listado con buscador, filtros (tipo, solo accesibles), mapa, panel de detalle y modal de borrado
- `PHP/admin/editar-ruta.php` — editor: mapa de la red + formulario + toolbar de cadena
- `PHP/admin/getRutas.php`, `saveRuta.php`, `deleteRuta.php`, `getRedCaminos.php` — endpoints JSON
- `PHP/admin/_api-rutas.php` — utilidades compartidas de la API (respuestas JSON, CSRF, parseo)
- `PHP/services/RouteService.php` — reglas de negocio (validaciones, nombre único, conflicto 409, auditoría)
- `PHP/services/PathNetworkService.php` — índice de la red, `validarCadena()`, `hidratar()`, Haversine
- `PHP/services/NetworkTopologyService.php` — topología (nodos, grado, componentes) para diagnóstico
- `PHP/repositories/RouteRepository.php` — persistencia de `rutas.json` (fail-closed, lock, `.bak`, migración de esquema)
- `PHP/repositories/PathNetworkRepository.php` — lectura/escritura atómica de `caminitos.geojson`
- `css/rutas.css`, `js/rutas-admin.js`, `js/ruta-editor.js` — vista y lógica de cliente
- `scripts/migrar_red_caminos.php` — normalización de la red (CLI)
- `tests/` — pruebas sin dependencias (ver abajo)

### Modelo de datos

`PHP/data/rutas.json`:

```json
{
  "schema_version": 1,
  "updated_at": "2026-11-16T00:00:00+00:00",
  "rutas": {
    "ruta-1a2b3c4d": {
      "id": "ruta-1a2b3c4d",
      "nombre": "Ruta principal norte",
      "descripcion": "",
      "tipo": "principal",
      "estado": "activa",
      "accesible": true,
      "origen": { "tipo": "edificio", "ref": "5" },
      "destino": { "tipo": "acceso", "ref": "P1" },
      "segmentos": [{ "id": "seg-d52f1975", "sentido": "normal" }],
      "creado_en": "...",
      "actualizado_en": "...",
      "creado_por": "admin_001",
      "actualizado_por": "admin_001"
    }
  }
}
```

- `tipo`: `principal | alterna | acceso | emergencia | institucional`
- `estado`: `activa | inactiva | borrador`
- `segmentos`: solo `{id, sentido}`; `sentido` es `normal | inverso`. La geometría y la distancia **siempre** las calcula el servidor.
- `origen`/`destino`: `{tipo, ref}` con `tipo` en `acceso | edificio | nodo` y `ref` validado contra `datos.json`.

### Reglas de negocio

- Nombre único (comparación sin distinguir mayúsculas), 3–80 caracteres, sin caracteres de control.
- La cadena de segmentos debe ser contigua: extremo compartido con tolerancia de 2,5 m (`PathNetworkService::TOLERANCIA_CONTINUIDAD_M`, la misma del snapping del grafo público); máximo 500 segmentos; sin IDs duplicados ni inexistentes.
- Al actualizar se exige `updated_at` vigente; si no coincide se devuelve **409** y no se sobrescribe nada.
- Toda alta, edición y borrado se registra en `PHP/data/audit-log.jsonl` (`ruta_creada`, `ruta_actualizada`, `ruta_eliminada`).

### Editor de rutas: interacción y estilos

- **Clic en la red**: el editor escucha el clic sobre el mapa y elige el segmento **más cercano al cursor** dentro de un radio de 12 px (independiente del zoom); no depende del orden de las capas ni del grosor de la línea. El cursor cambia a puntero (`#map-ruta.mapa-sobre-segmento`) cuando hay un segmento a tiro.
- Si el segmento elegido no es contiguo, se busca un segmento contiguo a ≤14 px del clic antes de rechazar la operación.
- Al rechazar, el mensaje indica la separación real al extremo más cercano de la cadena (útil para detectar huecos de la red).
- **Estilos de la red**: base azul marino `#12306b` (peso 5) con contorno claro `#f8fafc` (peso 9); contiguos en azul `#0b57d0` (peso 6) con contorno (peso 10); no contiguos en gris azulado `#7d8ba3` (peso 4, opacidad 0,35) sin contorno; cadena en ámbar `#f59e0b` (peso 8, no interactiva). La leyenda usa el mismo azul marino.
- **Precarga**: `cargarRed()` se resuelve **antes** de `cargarRutaExistente()` (secuencial). En paralelo, la cadena de una ruta existente se comparaba contra un índice vacío y se descartaban segmentos válidos con el aviso de "segmentos que ya no existen".
- En el listado, la tarjeta completa abre el detalle al hacer clic (sin botón "Ver"); cada tarjeta conserva "Editar" y "Eliminar".
- Navegación del panel con **botones** (no enlaces): "🚶 Rutas" en el panel de edificios y "← Panel" en el módulo de rutas.

### Migración de la red (una sola vez)

```powershell
php scripts/migrar_red_caminos.php --dry-run   # simulación, no escribe
php scripts/migrar_red_caminos.php             # normaliza caminitos.geojson
```

- Normaliza topología (extremos compartidos), asigna IDs estables `seg-xxxxxxxx`, conserva `properties` e IDs previos y deja respaldo `caminitos.geojson.bak.<marca>`.
- Es **idempotente**: volver a ejecutarlo no cambia el archivo (línea base 313 features / 644 vértices).
- La migración no toca `datos.json`, `GeoTec.js` ni `PHP/public/`.

### Pruebas (sin PHPUnit ni Composer)

| Comando | Qué valida |
|---|---|
| `node tests/grafo-huella.mjs --geometria` | Huella del grafo: `nodos=238 aristas=309 grado1=19 componentes=1 rutas=348/348` y geometría `313/644` |
| `node tests/validar-cadenas.mjs --php` | 9 casos de cadenas + paridad JS/PHP con `PathNetworkService` |
| `node tests/diagnostico-red.mjs` | Diagnóstico de la red: extremos sin pareja (19), "atascados" con interior cercano (0) y realmente aislados (19) a 2,5 m |
| `php tests/rutas-repository.php` | Persistencia: archivo ausente/corrupto, `.bak`, conflicto, concurrencia, migración |
| `php tests/rutas-service.php` | Reglas de negocio, hidratación y auditoría |
| `powershell -File tests/seguridad-rutas.ps1` | S-01…S-09 por HTTP (arranca su propio servidor en el puerto 8019) |
| `powershell -File tests/sesion-expirada.ps1` | S-10: sesión caducada/vigente con archivos de sesión fabricados (puerto 8020) |
| `powershell -File tests/regresion-general.ps1` | Regresión: mapa público, `datos.json`/`GeoTec.js` intactos, CSRF de edificios |

`tests/_pruebas.php`, `tests/_validar-cadenas.php` y `tests/_escritor-concurrente.php` son soporte interno (mini-harness y helpers).

### Router de desarrollo

- `router.php` bloquea por HTTP `PHP/data/`, `PHP/auth/`, `PHP/services/`, `PHP/repositories/`, `scripts/`, `tests/`, `docs/` y extensiones peligrosas (`.bak`, `.jsonl`, `.log`, `.ini`, `.env`, `.tmp`).
- `start-server.ps1` ya arranca `php -S 127.0.0.1:8000 router.php`; sin él, `PHP/data/` quedaría accesible en local.

## Comportamiento toggle

- `js/main.js` línea 4: `USE_GPS_LOCATION = false` → simulación por beacons. `true` → GPS real (requiere HTTPS/localhost).

## Git

- Cambio local no committeado: `js/main.js` path color `#ffffff` → `#ffffff00` (rutas transparentes).
