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

`docs/` y `DUMP/` no forman parte del repositorio de GitHub. `DUMP/` era material legacy y fue retirado; la documentación de trabajo se mantiene fuera del repositorio. La guía técnica disponible en el repositorio está en [`AGENTS.md`](AGENTS.md).

## Requisitos

- PHP 8.4 (CLI) — no hace falta Composer, `php.ini` ni extensión adicional.
- Node.js (solo para las pruebas de la red de caminos).
- Git instalado y autenticado localmente para usar «Actualizar mapa» (conexión a internet necesaria para publicar).
- Para ejecutar el mapa local no se necesita internet: Leaflet 1.9.4, Leaflet-Geoman y las teselas del campus están en el repositorio.

## Cómo ejecutarlo

```powershell
.\start-server.ps1
```

Lanza `php -S 127.0.0.1:8000 -t frontend router.php` y escucha **solo** en localhost.

- Mapa: <http://127.0.0.1:8000/index.html>
- Panel: <http://127.0.0.1:8000/index.php?p=login>

Las cuentas del panel se gestionan en `database/runtime/users.json`; este archivo es local y no se versiona.

Detener el servidor: `Ctrl+C`.

### Acceso directo al panel de administración

Para iniciar el servidor local y abrir directamente el login:

```powershell
.\start-admin.ps1
```

Para crear el icono **Panel Admin ITL** en el escritorio del usuario actual, ejecuta una vez:

```powershell
.\instalar-acceso-admin.ps1
```

El icono inicia PHP solo en `127.0.0.1:8000`; deja abierta la ventana de PowerShell mientras uses el panel y presiona `Ctrl+C` para detener el servidor. Si faltan los datos privados iniciales, ejecuta `php scripts/instalar.php` una sola vez. La cuenta inicial es `admin` / `123456` y requiere cambiar la contraseña al entrar.

### Actualizar el mapa publicado

En el panel, **↑ Actualizar mapa** muestra los archivos estáticos que cambiarán y solicita confirmación. Solo publica desde `main` cuando está sincronizada con `origin/main`, no hay cambios ajenos al sitio público y `node tests/publicacion.mjs` pasa. El commit/push requiere que Git esté autenticado localmente y tenga configurado su autor; no se guarda ningún token en el proyecto. El push a `main` inicia el workflow de GitHub Pages. Si el push falla después de crear el commit, la interfaz muestra el error y aclara que el commit quedó local; no lo considera publicado y no sincroniza automáticamente.

### Sin el script

```powershell
php -S 127.0.0.1:8000 -t frontend router.php
```

Sin `-t frontend`, `backend/` y `database/` quedarían expuestos en local. En producción, el *document root* del servidor web debe apuntar a `frontend/`.

## Funcionalidad

- **Mapa público**: huellas de edificios y accesos, buscador, selector de puertas, cálculo de ruta con Dijkstra sobre la red de caminos y modo de ubicación configurable (beacons o GPS) desde la sección de rutas del panel. Al quedar a 10 m o menos del centro geométrico del destino, la ruta se retira y la tarjeta muestra «¡Llegaste a tu destino!» junto con el botón para cerrar.
- **Sugerencias**: botón «Sugerir cambios» del mapa. Abre un **Google Forms** (`SUGGESTION_FORM_URL` en `frontend/js/public/config.js`) en una pestaña nueva. Las respuestas las revisa el administrador del proyecto desde la cuenta propietaria del formulario; **no** se guardan en `database/runtime/suggestions.json` ni pasan por PHP. Si la URL está vacía, el botón se oculta.
- **Panel**: alta/edición/borrado de edificios (metadatos, geometría e imágenes), administración de rutas del campus y gestión de usuarios administradores. En la sección de rutas se configura si el mapa público usa ubicación simulada por beacons o GPS real; el modo seleccionado se aplica al recargar el mapa.
- **Editores**: geometrías con Leaflet-Geoman y red de caminos con validación topológica en vivo (E01–E08 / W01–W06).

## Pruebas

Sin PHPUnit ni Composer:

```powershell
node scripts/verificar.mjs
```

Ese comando ejecuta `php -l`, la batería PHP, la batería Node, `tests/dom-seguro.mjs` y `tests/publicacion.mjs`. Las pruebas que necesitan servidor HTTP se lanzan aparte:

```powershell
powershell -File tests/regresion-general.ps1
powershell -File tests/seguridad-rutas.ps1
powershell -File tests/sesion-expirada.ps1
powershell -File tests/red/seguridad-red.ps1
```

`tests/regresion-general.ps1` espera el servidor de `start-server.ps1` en el puerto 8000; el resto arranca su propio servidor. Lista completa y notas en `AGENTS.md`.

## Datos

- **Públicos** (`frontend/data/`): `datos.json` (metadatos), `GeoTec.js` (geometrías) y `caminitos.geojson` (red v2). Los escribe PHP y los lee el navegador; sus respaldos quedan en `database/backups/`.
- **Privados** (`database/runtime/`): usuarios, configuración, rutas, sugerencias del flujo PHP legado y auditoría. No están en Git.
- Las imágenes de cada edificio viven en `frontend/img/<id>/`.

## Documentación

- `AGENTS.md` — arquitectura, rutas, convenciones y pruebas.
- `docs/` y `DUMP/` están excluidos de GitHub; no se deben referenciar como documentación disponible en el repositorio.
