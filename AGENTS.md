# AGENTS.md — Mapa Interactivo ITL

Mapa Leaflet del campus del ITL Laguna + panel administrativo en PHP. Sin *build*: no hay `package.json`, ni Composer/PHPUnit, ni bundler. Los tests son scripts sueltos (`node` y `php`) que se ejecutan a mano.

## Estructura del repositorio

```
frontend/            document root: lo único que se sirve por HTTP
  index.html         mapa público
  index.php          front controller (carga ../backend/bootstrap.php y el router)
  css/public.css     estilos del mapa público
  css/admin/         panel, login, editor de geometrías, rutas y editor de red
  css/vendor/        Leaflet y Leaflet-Geoman (vendorizados)
  js/public/         main.js, config.js, beacons-simulation.js
  js/shared/         dom-seguro.js, geo-utils.js, pathfinding.js
  js/admin/          editor, rutas-admin, ruta-editor, network-analysis, network-editor
  js/vendor/         Leaflet y Leaflet-Geoman (vendorizados)
  data/              datos públicos: datos.json, GeoTec.js, caminitos.geojson
  img/<id>/          imágenes de carrusel por edificio + logo
  tiles/satelital/   teselas locales {z}/{x}/{y}.jpg (zoom 15–19)
backend/             PHP privado, fuera del document root
  bootstrap.php      ÚNICA fuente de rutas físicas (constantes)
  router.php         tabla de rutas `?p=`
  admin/             vistas del panel
  api/               endpoints JSON (nombres kebab-case)
  auth/              sesión, CSRF, rate limiting
  repositories/      persistencia JSON (flock + rename atómico)
  services/          lógica de negocio
  views/404.php
database/            datos privados y respaldos, fuera del document root
  runtime/           users.json, settings.json, rutas.json, suggestions.json, audit-log.jsonl
  backups/           respaldos (p. ej. backups/caminitos/)
  sources/           fuentes GIS originales (.geojson)
scripts/             migraciones CLI de la red de caminos
tests/               pruebas sin dependencias
DUMP/                legacy movido (PHP/public, procesar.php, mapa.php, respaldos). NO usar ni borrar
router.php           router del servidor embebido (solo local)
start-server.ps1     arranca el servidor local
start-admin.ps1      inicia el servidor local y abre el login
instalar-acceso-admin.ps1 crea el acceso directo del panel en el escritorio
```

Reglas de oro:

- **No mover ni escribir nada en `database/runtime/` a mano**: `users.json`, `rutas.json` y `audit-log.jsonl` los gestionan los repositorios (y están fuera de Git).
- Todo archivo nuevo del backend se sirve **solo** a través de `backend/router.php`; nunca en `frontend/`.
- Las rutas físicas se leen de las constantes de `backend/bootstrap.php` (`ROOT_PATH`, `BACKEND_PATH`, `FRONTEND_PATH`, `PUBLIC_DATA_PATH`, `IMG_PATH`, `DATABASE_PATH`, `RUNTIME_PATH`, `BACKUPS_PATH`). No usar `dirname(__DIR__, n)` en otros archivos.
- `DUMP/` es histórico: no se importa desde el código, no se sirve por HTTP y no se borra sin autorización explícita.

## Servidor local (PHP integrado)

```
.\start-server.ps1
```

Lanza `php -S 127.0.0.1:8000 -t frontend router.php` desde la raíz. Escucha SOLO en localhost (no exponer a Internet).

URLs:

- Mapa: `http://127.0.0.1:8000/index.html` (o `/`, que redirige)
- Panel: `http://127.0.0.1:8000/index.php?p=login`

Detener: `Ctrl+C`. PHP 8.4.7 CLI, sin `php.ini` cargado. `mail()` requiere SMTP (ver «Correo de sugerencias»).

Para abrir el login directamente y crear un acceso directo en el escritorio: `.\start-admin.ps1` y `.\instalar-acceso-admin.ps1`. El segundo se ejecuta una vez por usuario de Windows.

## Rutas HTTP (`backend/router.php`)

`?p=` se resuelve a un archivo de `backend/` y decide si exige sesión **antes** de incluirlo. Sin `?p=` la respuesta es `302 → index.html`.

| Ruta | Archivo | Auth |
|---|---|---|
| `?p=login` | `backend/admin/login.php` | público (CSRF + rate limit) |
| `?p=editar[&editar=<id>]` | `backend/admin/panel.php` | sí (+ CSRF en el form) |
| `?p=logout` | `backend/admin/logout.php` | sí |
| `?p=editar-geometria&accion=nuevo\|editar&id=` | `backend/admin/editar-geometria.php` | sí |
| `?p=rutas[&accion=…]` | `backend/admin/rutas.php` | sí |
| `?p=editar-ruta…` | — (dentro de `rutas.php`/`editar-ruta.php`) | sí |
| `?p=editar-red` | `backend/admin/editar-red.php` | sí |
| `?p=getimages` | `backend/api/list-images.php` | sí + CSRF |
| `?p=saveData` | `backend/api/save-edificio.php` | sí + CSRF |
| `?p=saveGeometria` | `backend/api/save-geometria.php` | sí + CSRF |
| `?p=deleteEdificio` | `backend/api/delete-edificio.php` | sí + CSRF |
| `?p=getRutas` | `backend/api/get-rutas.php` | sí |
| `?p=saveRuta` | `backend/api/save-ruta.php` | sí + CSRF |
| `?p=deleteRuta` | `backend/api/delete-ruta.php` | sí + CSRF |
| `?p=getRedCaminos` | `backend/api/get-red-caminos.php` | sí |
| `?p=getRed` | `backend/api/get-red.php` | sí |
| `?p=save-red` | `backend/api/save-red.php` | sí + CSRF |
| `?p=get-location-mode` | `backend/api/get-location-mode.php` | sí (GET); POST sí + CSRF |
| `?p=publish-map` | `backend/api/publish-map.php` | sí + CSRF + solo localhost |

`frontend/index.php` es el único PHP accesible desde el navegador (front controller delgado).

## Mapa público (`frontend/index.html`)

- Leaflet 1.9.4 vendorizado en `frontend/js/vendor/leaflet/` y `frontend/css/vendor/leaflet/` (sin CDN ni conexión). Teselas locales: `tiles/satelital/{z}/{x}/{y}.jpg`, zoom 15–19.
- Leaflet-Geoman vendorizado en `frontend/js/vendor/leaflet-geoman/` y `frontend/css/vendor/leaflet-geoman/` (sin CDN).
- Orden de scripts (importante): `data/GeoTec.js` (define `datosTec`) → `js/shared/dom-seguro.js` → `js/shared/geo-utils.js` → `js/public/beacons-simulation.js` → `js/shared/pathfinding.js` → `js/public/config.js` → `js/public/main.js`.
- `js/public/main.js` carga `data/location-config.js`: el administrador configura el modo en la parte superior de `?p=rutas`. `simulada` activa beacons; `real` usa GPS del navegador (requiere HTTPS/localhost). El cambio se aplica al recargar el mapa público.
- Consume `fetch('data/datos.json')` y `fetch('data/caminitos.geojson')`. Si falta `datosTec` o falla un `fetch`, el mapa sigue funcionando y muestra un aviso no bloqueante.
- `js/shared/dom-seguro.js` expone `escaparHtml`, `escaparRegex` y `normalizarRutaImagen` (también como módulo CommonJS para las pruebas Node). Los popups, tooltips, panel de navegación y buscador construyen nodos de texto; no insertan datos del panel como HTML.
- `index.html` declara una CSP por `<meta>` (`script-src 'self'`, `connect-src 'self'`, `form-action 'none'`, etc.) y no usa manejadores `on*` ni atributos `style=` inline.
- Cada edificio muestra un círculo blanco con su ID y tooltip con el nombre. Las geometrías usan `smoothFactor: 0` (mapa, editor y capa de referencia); con el valor por defecto las huellas con muchos vértices se veían como cuadrados.

### Botón «Sugerir cambios»

- Botón junto al control «Centrar» (arriba-derecha). Es un enlace externo a un **Google Forms** configurado en `SUGGESTION_FORM_URL` (`js/public/config.js`); abre en una pestaña nueva con `target="_blank"` y `rel="noopener noreferrer"`.
- Las respuestas quedan en el Google Forms y las revisa el administrador del proyecto desde la cuenta propietaria del formulario. **No** se guardan en `database/runtime/suggestions.json` ni pasan por PHP.
- Si `SUGGESTION_FORM_URL` está vacía (o no es `https://`), el botón se oculta. El mapa público **no** tiene formulario propio ni llama a PHP.
- La CSP no bloquea el enlace: es navegación de nivel superior con `target="_blank"`, no un `fetch`, un `form-action` ni un recurso embebido.
- `backend/api/check-session.php` y `backend/api/send-suggestion.php` se conservan sin uso (decisión U-D5) y ya no están en `backend/router.php`.

### Correo de sugerencias (código conservado, sin ruta)

- El flujo activo de sugerencias es Google Forms (ver arriba); este correo es legado y no se ejecuta.
- `backend/api/send-suggestion.php` usa `mail()` con el resultado real (no simula éxito) y guarda en `database/runtime/suggestions.json`.
- Destinatario fijo: `migel2013+mapa@outlook.com`.
- SMTP por variables de entorno: `SMTP_HOST`, `SMTP_PORT`, `SMTP_FROM`. Sin SMTP, `mail()` falla y se reporta.
- CSRF con `hash_equals`; máximo 5 envíos por sesión y hora; se eliminan saltos de línea de las cabeceras.

## Datos

### Públicos (`frontend/data/`)

- `datos.json` — metadatos: `nombre`, `descripcion`, `aulas`, `carpeta`, `tipo`, `imagenes`. Clave = ID del edificio. `tipo` es `edificio` (por defecto) o `acceso`.
- `GeoTec.js` — geometrías: variable global `datosTec` (FeatureCollection); `properties.id` coincide con la clave de `datos.json`.
- `caminitos.geojson` — red de caminos (formato v2, ver «Editor de la red»).
- `location-config.js` — variable `window.MODO_UBICACION` (`simulada` o `real`), actualizada desde la parte superior de la sección de rutas del panel.

PHP escribe estos archivos desde el panel; el navegador los lee. `location-config.js` solo contiene el modo público, sin secretos. `img/<id>/` contiene las imágenes de carrusel.

### Privados (`database/`)

| Archivo | Contenido |
|---|---|
| `runtime/users.json` | cuentas de administrador |
| `runtime/settings.json` | configuración general (sin secretos) |
| `runtime/rutas.json` | rutas administrativas |
| `runtime/suggestions.json` | sugerencias del flujo PHP legado (sin uso; las activas van a Google Forms) |
| `runtime/audit-log.jsonl` | auditoría en JSONL |
| `backups/caminitos/` | respaldos de la red (retención 30) |
| `sources/*.geojson` | fuentes GIS originales |

`database/runtime/` y `database/backups/` están en `.gitignore`. `bootstrap.php` falla con mensaje claro si falta `runtime/` (fail-visible), en lugar de crear un `users.json` vacío.

### Modelo de usuario (`users.json`)

Esquema v2: `id`, `username`, `email`, `password_hash`, `role`, `status`, `must_change_password`, `password_changed_at`, `session_version`, `created_by`, `created_at`, `updated_at`, `last_login_at`, `schema_version`. Contraseñas con `password_hash()`/`password_verify()`. Usuario inicial: `admin` / `123456`, con cambio obligatorio tras instalar.

### Gestor de usuarios

- `?p=usuarios` ofrece listado, alta, edición de correo, activación/desactivación, restablecimiento y eliminación de cuentas inactivas.
- `?p=cambiar-contrasena` permite cambiar la contraseña propia y es obligatorio en el primer acceso.
- Los cambios incrementan `session_version` cuando cierran sesiones ajenas: desactivación, restablecimiento y cambio de contraseña.
- Nunca queda sin administrador activo; no se permite desactivar ni eliminar la propia cuenta ni la última cuenta activa.

### Modelo edificio vs. acceso

- `tipo: "acceso"` para las puertas **P1–P6**; se gestionan como cualquier otro registro.
- En el panel se muestran con el distintivo «Acceso» en vez del conteo de aulas.
- El selector de puertas (`#doors-select`, `obtenerNombrePuerta()`) lee el nombre de `datos.json`.
- `properties.Nombre` de `GeoTec.js` es el nombre largo del tooltip; `datos.json.nombre` es el nombre corto. **Son distintos a propósito: no sincronizar en bloque.**

## Panel administrativo

### Edificios

- `#buscar-edificios` filtra por nombre, ID, aulas o tipo en el cliente (cada `.edificio-item` lleva `data-search`); sin coincidencias muestra «No se encontraron edificios que coincidan».
- Cada elemento: «Editar geometría» arriba y «Eliminar» debajo. La lista `#lista-edificios` va sin viñetas.
- «➕ Agregar edificio» abre el editor de geometrías.

### Editor de geometrías (`?p=editar-geometria`)

- Vista `backend/admin/editar-geometria.php`; lógica `frontend/js/admin/editor.js`; estilos `frontend/css/admin/editor.css`.
- Toolbar siempre visible: 🟥 Dibujar, ✏️ Editar, 🗑️ Eliminar.
- **Dibujar**: clic para añadir vértices; cierra con doble clic o con un clic a ≤10 **píxeles** del primer vértice (tolerancia en píxeles, no metros).
- **Editar**: `polygon.pm.enable()` de Geoman gestiona los vértices; `editor.js` solo sincroniza el GeoJSON al input en `pm:markerdrag`, `pm:markerdragend`, `pm:vertexadded`, `pm:vertexremoved` y `pm:edit`.
- **Eliminar**: clic sobre el polígono (no sobre su *bounding box*).
- Las geometrías existentes son capa de referencia de solo lectura (`interactive: false`); la del edificio en edición se excluye.
- Formulario: ID, Nombre, Tipo (Edificio/Acceso), Descripción, Aulas. En edición el ID es de solo lectura.
- «💾 Guardar edificio» → `backend/api/save-geometria.php` (metadatos + geometría).
- `tipo` y `actualizarMetadatos()` viven en `backend/services/BuildingService.php`; `GeoTec.js` se escribe con `backend/repositories/BuildingGeometryRepository.php`.

## Autenticación y seguridad

- `backend/auth/auth_middleware.php` — `requerirAdmin()`, `generarTokenCSRF()`, `validarTokenCSRF()`, `renovarTokenCSRF()`.
- `backend/auth/csrf.php` — tokens CSRF; `backend/auth/rate_limit.php` — máximo 5 intentos de login por hora.
- Login autentica **solo** contra `database/runtime/users.json`, vía `backend/services/AuthService.php`.
- Cookie de sesión `HttpOnly`, `SameSite=Lax`, `Secure` solo con HTTPS, vida 2 horas; expiración por inactividad (`$_SESSION['auth_time']`).
- `session_regenerate_id(true)` tras login.
- Los endpoints vuelven a comprobar sesión y CSRF por su cuenta (defensa en profundidad).

## Módulo de rutas (caminos)

Recorridos administrativos; **no** alteran el cálculo del mapa público.

- `backend/admin/rutas.php` — listado con buscador, filtros (tipo, solo accesibles), mapa, detalle y modal de borrado.
- `backend/admin/editar-ruta.php` — editor de la cadena de segmentos.
- `backend/api/{get-rutas,save-ruta,delete-ruta,get-red-caminos}.php` + `backend/api/_api-rutas.php` (helpers).
- `backend/services/RouteService.php` — validaciones, nombre único, conflicto 409, auditoría.
- `backend/services/PathNetworkService.php` — índice de la red, `validarCadena()`, `hidratar()`, Haversine.
- `backend/services/NetworkTopologyService.php` — nodos, grado, componentes.
- `backend/repositories/RouteRepository.php` (persistencia) y `PathNetworkRepository.php` (red).
- Cliente: `frontend/css/admin/rutas.css`, `frontend/js/admin/rutas-admin.js`, `frontend/js/admin/ruta-editor.js`.

`database/runtime/rutas.json`:

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

- `tipo`: `principal | alterna | acceso | emergencia | institucional`; `estado`: `activa | inactiva | borrador`.
- `segmentos`: solo `{id, sentido}` (`normal | inverso`); geometría y distancia las calcula **siempre** el servidor.
- `origen`/`destino`: `{tipo, ref}` con `tipo` en `acceso | edificio | nodo`, validado contra `datos.json`.

Reglas: nombre único (sin distinguir mayúsculas), 3–80 caracteres, sin caracteres de control; cadena contigua con tolerancia de 2,5 m (`PathNetworkService::TOLERANCIA_CONTINUIDAD_M`), máximo 500 segmentos, sin IDs duplicados ni inexistentes; al actualizar se exige `updated_at` vigente (si no, **409**); toda alta/edición/borrado se audita (`ruta_creada`, `ruta_actualizada`, `ruta_eliminada`).

### Interacción del editor de rutas

- Clic en la red: elige el segmento más cercano al cursor en 12 px (independiente del zoom) y cambia el cursor a puntero (`#map-ruta.mapa-sobre-segmento`).
- Si el segmento no es contiguo, se busca uno contiguo a ≤14 px antes de rechazar; el mensaje indica la separación real al extremo más cercano.
- Estilos: base azul marino `#12306b` (peso 5, contorno `#f8fafc` peso 9), contiguos `#0b57d0` (6/10), no contiguos `#7d8ba3` (4, opacidad 0,35), cadena en ámbar `#f59e0b` (peso 8, no interactiva).
- `cargarRed()` se resuelve **antes** de `cargarRutaExistente()` (secuencial).
- La tarjeta completa abre el detalle; cada tarjeta conserva «Editar» y «Eliminar».
- Navegación con botones: «🚶 Rutas» en el panel y «← Panel» en el módulo.

## Editor de la red de caminos

Editor gráfico de `frontend/data/caminitos.geojson`. No altera `dijkstra`, `buildGraph`, `findClosestNode` ni `js/public/main.js`.

### Formato v2

- `schema_version: 2`, `revision` (entero monotónico) y `next_seq`.
- IDs históricos `seg-<8hex>` (y `seg-<8hex>-NN`) se conservan porque los usa `rutas.json`; los nuevos son `seg-<6 dígitos>` desde `next_seq`; los no guardados son `tmp-…` y el servidor los renumera.
- Migración única: `php scripts/migrar_red_v2.php --apply` (idempotente). No confundir con `scripts/migrar_red_caminos.php` (normaliza topología).
- Cada guardado deja respaldo en `database/backups/caminitos/` (retención 30). Escritura con `flock`, temporal y `rename` atómico; lectura **fail-closed**. El archivo de bloqueo vive en `database/runtime/locks/` (nunca dentro de `frontend/`): `NetworkRepository` acepta un directorio de bloqueos propio en pruebas.

### Vista y modos

- Solo se abre desde el módulo de rutas con «🗺️ Red de caminos»; vuelve con «← Volver a rutas».
- La barra lateral divide **Segmentos** y **Validación** al 50 % con divisor arrastrable (`#red-splitter`): ratón, táctil o `↑`/`↓`. El reparto se guarda en `estado.altoSegmentos`; por debajo de 861 px el divisor se oculta.
- **Consultar**: clic para ver ID, estado, vértices y longitud.
- **Dibujar**: polilínea con snapping propio a ≤8 m (preferencia de vértice si está a ≤1 m). Botón interruptor; se confirma con «✔️ Terminar dibujo», Enter o doble clic; Esc cancela.
- **Editar**: Geoman gestiona los vértices del segmento seleccionado; la capa de referencia es de solo lectura.
- **Eliminar**: marca/desmarca segmentos; un `tmp-` se descarta directo.
- Trazo visible de 6–7 px (9 px al seleccionar) más una polilínea transparente de 20 px (`red-golpe`) para acertar con el cursor.
- Validación en vivo (`frontend/js/admin/network-analysis.js`) replica E01–E08/W01–W06 y bloquea el guardado si la propuesta deja sin ruta algún par puerta→edificio (E08); el servidor lo revalida con `NetworkService`/`NetworkGraph`.

### Arquitectura

- `backend/admin/editar-red.php` — vista + datos embebidos (`RED_INICIAL`, `RED_REVISION`, `RED_CSRF`, `RED_PAIRS`, `RED_METADATOS`).
- `backend/api/get-red.php`, `backend/api/save-red.php` (400 E01–E05/E07/E08, 403, 405, 409, 500; cuerpo FormData o JSON; `base_revision` obligatorio; límite 2 MB).
- `backend/services/NetworkService.php`, `backend/services/NetworkGraph.php`.
- `backend/services/MapPublisher.php` publica el contenido estático de `frontend/` con previsualización, validación y confirmación; exige `main` sincronizada con `origin/main`.
- `backend/repositories/NetworkRepository.php` (v2, 409, respaldos; extiende `PathNetworkRepository`; el editor nunca usa `PathNetworkRepository::guardar()`).
- `scripts/migrar_red_v2.php`.
- `frontend/js/admin/network-analysis.js`, `frontend/js/admin/network-editor.js`, `frontend/css/admin/red-editor.css`.

## Migración de la red (topología, una sola vez)

```powershell
php scripts/migrar_red_caminos.php --dry-run
php scripts/migrar_red_caminos.php
```

Normaliza extremos compartidos, asigna IDs estables, conserva `properties` e IDs previos y deja `caminitos.geojson.bak.<marca>`. Es idempotente. No toca `datos.json` ni `GeoTec.js`.

## Pruebas (sin PHPUnit ni Composer)

Comando único (multiplataforma): `node scripts/verificar.mjs`. Ejecuta `php -l` sobre todo el PHP, la batería PHP y Node, y `tests/publicacion.mjs`; devuelve 0 solo si todo pasa. Con `--rapido`, la paridad de grafo usa 25 redes aleatorias en vez de 200. En un clon limpio crea vacías `database/runtime/` y `database/backups/` (están en `.gitignore`); no escribe datos reales.

| Comando | Qué valida |
|---|---|
| `node scripts/verificar.mjs` | Comando único: sintaxis, batería PHP/Node y sitio publicable |
| `node tests/publicacion.mjs` | Sitio publicable: sin PHP salvo `index.php`, sin privados, recursos relativos existentes, imágenes `img/<id>/<archivo>`, Google Forms, CSP y ausencia de inline |
| `node tests/dom-seguro.mjs` | Helper de DOM seguro: escapado HTML, metacaracteres RegExp y validación de rutas de imagen |
| `node tests/grafo-huella.mjs --geometria` | Invariantes del grafo calculadas del archivo (hoy `nodos=242 aristas=314 grado1=20 componentes=1 rutas=348/348`, geometría `313/646`); con `--geometria`, valida cada segmento |
| `node tests/validar-cadenas.mjs --php` | 9 casos de cadenas + paridad JS/PHP con `PathNetworkService` |
| `node tests/diagnostico-red.mjs` | Diagnóstico de la red (informe, sin umbral) |
| `node tests/red/paridad-grafo.mjs` | Paridad JS/PHP del grafo y la alcanzabilidad (201 redes) |
| `node tests/red/analisis-cliente.mjs` | Análisis cliente: huella calculada del archivo, E01–E08, W01/W04/W05/W06, snapping y 6 × edificios pares |
| `php tests/rutas-repository.php` | Persistencia: archivo ausente/corrupto, `.bak`, conflicto, concurrencia, migración |
| `php tests/rutas-service.php` | Reglas de negocio, hidratación y auditoría |
| `php tests/map-publisher.php` | Vista previa, allowlist, confirmación, validación y push a un remoto Git temporal |
| `php tests/red/integridad-red.php` | Red ausente/corrupta/v1, respaldo, 409, IDs `tmp-`, retención de 30, bloqueo en directorio privado |
| `php tests/red/validacion-red.php` | Un caso por E01–E08 y W01–W06 + IDs y auditoría |
| `powershell -File tests/seguridad-rutas.ps1` | S-01…S-09 por HTTP (servidor propio en 8019) |
| `powershell -File tests/sesion-expirada.ps1` | S-10: sesión caducada/vigente (puerto 8020) |
| `powershell -File tests/red/seguridad-red.ps1` | 403/405/409/400, CSRF, límite 2 MB y `database/` inaccesible (puerto 8021) |
| `powershell -File tests/regresion-general.ps1` | Regresión del mapa público, datos intactos y CSRF de edificios (puerto 8000) |

Los `.ps1` arrancan su propio servidor con `-t frontend router.php` (excepto `regresion-general`, que usa el servidor de `start-server.ps1` en el puerto 8000). No forman parte de `verificar.mjs` porque necesitan servidor HTTP.

`tests/_pruebas.php`, `tests/_validar-cadenas.php`, `tests/_escritor-concurrente.php` y `tests/red/_grafo-php.php` son soporte interno.

**Expectativas de red (resuelto en la Fase 0):** `grafo-huella` y `analisis-cliente` ya no fijan 312/644/241/312; calculan las cifras de `caminitos.geojson` y comprueban invariantes (conexión, alcanzabilidad y coherencia), así que no vuelven a caducar cuando la red crece.

**Dependencia de datos reales:** `verificar.mjs` no lee `database/runtime/` ni `database/backups/`; solo necesita que las carpetas existan (las crea vacías si faltan). Las pruebas PHP usan directorios temporales. Los `.ps1` de HTTP sí usan el `users.json` real y el servidor local, por eso quedan fuera del comando único.

**Integración continua:** `.github/workflows/ci.yml` ejecuta `node scripts/verificar.mjs` en cada `push` y `pull_request` con PHP 8.4 y Node LTS.

## Router de desarrollo y protección

- `router.php` (raíz) se usa con `php -S … -t frontend router.php`. Como `backend/`, `database/`, `DUMP/`, `scripts/`, `tests/` y `docs/` quedan fuera del document root, no se sirven; el router además bloquea esas rutas por URI y las extensiones sensibles (`.bak`, `.jsonl`, `.log`, `.ini`, `.env`, `.tmp`, `.lock`, `.md`), cualquier nombre con `.bak` y archivos/directorios ocultos.
- Verificado en local: `/backend/*`, `/database/*`, `/DUMP/*`, `/PHP/*`, `/scripts/*`, `/tests/*`, `/docs/*` → 403.

## Depuración de errores PHP

- Errores ocultos por defecto. En local: `$env:APP_DEBUG='1'; .\start-server.ps1` (o `APP_DEBUG=1 php -S …`).
- Las respuestas AJAX son siempre JSON; si llega HTML, el JS muestra «Respuesta inesperada del servidor».

## Archivos que deben mantenerse fuera de GitHub

- `php.ini`, `.env`, `*.local.php`, `*credenciales*`, `*secret*`
- `database/runtime/` y `database/backups/`
- Logs `*.log`, `server.err`, `*.lock`, `*.tmp.*`
- `uso.md` (documentación local)

## Git

- Rama de trabajo de la reestructuración: `refactor/estructura`; plan y bitácora en `docs/plans/PLAN_REESTRUCTURACION_PROYECTO.md`.
