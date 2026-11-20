### Mapa Interactivo del ITL

**Desarrollado por Juan Pablo Chavez Ramirez y Angel Daniel Salinas Ortiz**

Mapa enfocado en apoyar a estudiantes de nuevo ingreso y visitantes con la localizacion de las diversas aulas que existen en la institucion.

> El enlace de demostración antiguo (`MAPA INTERACTIVO ITL.html`) ya no existe: el mapa se sirve hoy desde `frontend/` con el servidor PHP local (ver abajo).

---

## Estructura

| Carpeta | Contenido | ¿Se sirve por HTTP? |
|---|---|---|
| `frontend/` | HTML, CSS, JS, imágenes, teselas y datos públicos del mapa | **Sí** (es el *document root*) |
| `backend/` | Router, vistas del panel, API, autenticación, servicios y repositorios | No |
| `database/` | JSON privado (`runtime/`), respaldos (`backups/`) y fuentes GIS (`sources/`) | No |
| `scripts/` | Migraciones CLI de la red de caminos | No |
| `tests/` | Pruebas sin dependencias (`node` y `php`) | No |
| `DUMP/` | Material legacy conservado (no se usa ni se sirve) | No |

Detalle completo en [`AGENTS.md`](AGENTS.md).

## Requisitos

- PHP 8.4 (CLI) — no hace falta Composer, `php.ini` ni extensión adicional.
- Node.js (solo para las pruebas de la red de caminos).
- Conexión a internet para Leaflet 1.9.4 (unpkg CDN). Las teselas del campus son locales.

## Cómo ejecutarlo

```powershell
.\start-server.ps1
```

Lanza `php -S 127.0.0.1:8000 -t frontend router.php` y escucha **solo** en localhost.

- Mapa: <http://127.0.0.1:8000/index.html>
- Panel: <http://127.0.0.1:8000/index.php?p=login>

Credenciales iniciales del panel: `admin` / `123456` (se cambian desde `database/runtime/users.json` con `password_hash()`).

Detener el servidor: `Ctrl+C`.

### Sin el script

```powershell
php -S 127.0.0.1:8000 -t frontend router.php
```

Sin `-t frontend`, `backend/` y `database/` quedarían expuestos en local. En producción, el *document root* del servidor web debe apuntar a `frontend/`.

## Funcionalidad

- **Mapa público**: huellas de edificios y accesos, buscador, selector de puertas, cálculo de ruta con Dijkstra sobre la red de caminos y simulación de ubicación por beacons (`USE_GPS_LOCATION = false` en `frontend/js/public/main.js`).
- **Sugerencias**: botón ⚙️ del mapa. Sin sesión abre el formulario y envía a `index.php?p=send-suggestion`; con sesión de administrador abre el panel.
- **Panel**: alta/edición/borrado de edificios (metadatos, geometría e imágenes) y administración de rutas del campus.
- **Editores**: geometrías con Leaflet-Geoman y red de caminos con validación topológica en vivo (E01–E08 / W01–W06).

## Pruebas

Sin PHPUnit ni Composer:

```powershell
php tests/rutas-repository.php
php tests/rutas-service.php
php tests/red/integridad-red.php
php tests/red/validacion-red.php
node tests/validar-cadenas.mjs --php
node tests/red/paridad-grafo.mjs
powershell -File tests/regresion-general.ps1
powershell -File tests/seguridad-rutas.ps1
powershell -File tests/sesion-expirada.ps1
powershell -File tests/red/seguridad-red.ps1
```

`tests/regresion-general.ps1` espera el servidor de `start-server.ps1` en el puerto 8000; el resto arranca su propio servidor. Lista completa y notas en `AGENTS.md`.

## Datos

- **Públicos** (`frontend/data/`): `datos.json` (metadatos), `GeoTec.js` (geometrías) y `caminitos.geojson` (red v2). Los escribe PHP y los lee el navegador.
- **Privados** (`database/runtime/`): usuarios, configuración, rutas, sugerencias y auditoría. No están en Git.
- Las imágenes de cada edificio viven en `frontend/img/<id>/`.

## Documentación

- `AGENTS.md` — arquitectura, rutas, convenciones y pruebas.
- `docs/architecture/ARQUITECTURA_PERSISTENCIA_JSON.md` — capa de persistencia JSON.
- `docs/plans/PLAN_REESTRUCTURACION_PROYECTO.md` — plan y bitácora de la reestructuración.
