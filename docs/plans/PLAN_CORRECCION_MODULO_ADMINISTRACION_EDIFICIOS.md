# Plan de Corrección: Módulo de Administración de Edificios

**Proyecto:** Mapa Interactivo ITL (TecNM Campus Laguna)
**Estado:** Auditoría técnica + planificación. **Sin implementar.**
**Versión:** 1.0
**Fecha:** 2026-11-13
**Ámbito:** CRUD de edificios, persistencia de geometrías, editor de polígonos (Leaflet)
**Restricción aplicada:** la única escritura de este trabajo es este documento. No se modificó frontend, backend, `GeoTec.js`, `datos.json`, ni dependencias.

---

## 0. Metodología y skills

### 0.1 Skills utilizadas

| Skill | Estado | Uso |
|---|---|---|
| `bug-hunter` | **Cargada y aplicada** | Se siguió su procedimiento: comprender el proyecto → inspeccionar sin modificar → buscar evidencia → clasificar severidad → causa raíz → informe con evidencias. Las secciones 2 y 3 son el resultado directo de ese procedimiento. |
| `browser` | Disponible, **no aplicable** | La verificación HTTP se hizo con `curl` y `php -S`; no se requería control del navegador. |
| `opencode` | Disponible, **no aplicable** | Solo describe el funcionamiento de OpenCode. |
| `report` | Disponible, **no aplicable** | Publica issues en GitHub; no se pidió publicar. |
| Skills de PHP / JavaScript / Leaflet / arquitectura | **No instaladas** | Se suplieron con lectura directa del código y verificación empírica (script PHP de auditoría, peticiones HTTP reales, prueba de `rename()` en Windows). |

### 0.2 Método de verificación empleado

1. Lectura de `AGENTS.md` y de los planes previos en `docs/plans/`.
2. Revisión del estado de Git **antes** de cualquier lectura (hay cambios locales sin commitear; no se tocaron).
3. Inspección estática de: router, panel, editor, endpoints, servicios, repositorios y JS del mapa.
4. Verificación **empírica** (sin escribir en los datos del proyecto):
   - Conteo de features y claves con un script PHP temporal en `%TEMP%`.
   - Petición HTTP real a `index.php?p=saveData` contra un servidor `php -S` en el puerto 8012 (solo lectura de respuesta; no se guardó nada porque la petición fue rechazada).
   - Prueba aislada de `rename()` sobre archivos temporales.
5. Clasificación explícita de cada hallazgo: **CONFIRMADO**, **NO REPRODUCIBLE** o **REQUIERE INVESTIGACIÓN**.

> **Nota sobre el estado del árbol de trabajo.** `GeoTec.js`, `datos.json`, `PHP/admin/saveEdificio.php`, `PHP/admin/panel.php` y otros archivos ya tienen correcciones locales **sin commitear**, incluyendo la reparación del fallo que borraba geometrías (comillas invertidas en el feature `CI`) y la reescritura de `BuildingGeometryRepository` / `BuildingService`. Este plan se apoya en ese estado; no propone revertirlo.

---

## 1. Resumen de las problemáticas

| # | Problemática reportada | Clasificación | Severidad | ¿Verificado en código? |
|---|---|---|---|---|
| 1 | CRUD inconsistente; operaciones que "parecen guardar" pero no persisten | **CONFIRMADO** (varias causas independientes) | **Crítica** | Sí, con reproducción HTTP 403 |
| 2 | Al agregar un edificio desaparecen las geometrías de los demás | **CONFIRMADO** (no se cargan, no se ocultan) | Alta | Sí |
| 3 | `Cannot read properties of undefined (reading 'enable')` al editar vértices | **CONFIRMADO** (API inexistente en Leaflet 1.9.4 sin plugin) | Alta | Sí |
| 4 | La tolerancia de cierre automático es demasiado grande | **CONFIRMADO** (10 m geográficos, no píxeles) | Media | Sí, con cálculo por zoom |
| 5 | El polígono guardado se convierte en cuadrado/rectángulo | **NO REPRODUCIBLE** en el código actual | Alta (si fuera real) | No se encontró ninguna conversión a rectángulo |

Resumen ejecutivo: **las problemáticas 2, 3 y 4 son defectos de implementación del editor**; la **problemática 1 es un conjunto de defectos de integración backend/CSRF/rutas**; la **problemática 5 no se pudo confirmar**: el flujo completo conserva todos los vértices y no existe código que construya rectángulos. Se documenta como "requiere reproducción guiada" con instrumentación concreta, no como un fallo de almacenamiento.

---

## 2. Diagnóstico técnico

### 2.1 Problemática 1 — Auditoría del CRUD

#### 2.1.1 Arquitectura real de datos

Existen **dos representaciones separadas** y **dos copias del archivo de metadatos**:

| Elemento | Ruta efectiva | Contenido | Consumidor |
|---|---|---|---|
| Metadatos (fuente de verdad usada por el panel) | `datos.json` (raíz) | 52 claves | `PHP/admin/panel.php`, `js/main.js` |
| Metadatos (copia obsoleta) | `PHP/public/datos.json` | 48 claves | `PHP/admin/saveEdificio.php` |
| Geometrías | `GeoTec.js` (raíz) | 58 features | `js/main.js`, `BuildingGeometryRepository` |
| Imágenes subidas | `PHP/public/img/<id>/` | — | `PHP/admin/saveEdificio.php` |
| Imágenes listadas por el panel | `img/<id>/` (raíz) | 4 carpetas | `PHP/admin/imagelist.php` |

La relación edificio ↔ geometría es `datos.json[id]` ↔ `feature.properties.id` de `datosTec`. No hay FK ni validación de integridad global.

#### 2.1.2 Diagnóstico por operación

| Operación | Ruta / función | Estado | Diagnóstico |
|---|---|---|---|
| **Listar** edificios | `panel.php` (render PHP) | ✅ Funciona | Lee `datos.json` raíz. |
| **Buscar** edificio/aula | `panel.php` (JS client-side) | ✅ Funciona | Filtra por `data-search`. |
| **Crear** edificio con geometría | `editar-geometria.php` → `js/editor.js` → `saveGeometria.php` → `BuildingService::crearEdificio()` | ✅ Funciona | Validado extremo a extremo: 57→58→59 features sin pérdidas; polígono irregular de 5 vértices persistido intacto. Geometría primero, metadatos después, con rollback de la geometría si fallan los metadatos. |
| **Consultar** geometría de un edificio | `BuildingService::obtenerEdificio()` → `window.EDITOR_GEOMETRIA_INICIAL` | ✅ Funciona | Preload implementado; el editor dibuja el polígono existente. |
| **Editar metadatos** (nombre/descripción/aulas) | `panel.php` → `fetch('index.php?p=saveData')` → `saveEdificio.php` | ❌ **Roto** | El formulario de edición **no incluye `csrf_token`**; `saveEdificio.php` valida CSRF y responde **HTTP 403** siempre. |
| **Editar metadatos** (si se corrigiera el CSRF) | `saveEdificio.php` | ❌ **Persiste en el archivo equivocado** | Escribe en `PHP/public/datos.json`, que el panel no lee → "Cambios guardados correctamente" **sin efecto visible**. |
| **Editar geometría existente** | `editar-geometria.php?accion=editar` → `saveGeometria.php` | ⚠️ Guarda, pero pierde `imagenes` | `BuildingService::actualizarEdificio()` reconstruye la entrada de metadatos con solo `nombre`, `descripcion`, `aulas` y `carpeta`; **descarta `imagenes`**. |
| **Eliminar** edificio | `panel.php` (form POST) → `deleteEdificio.php` | ⚠️ Elimina, pero la UX es incorrecta | El formulario es un POST normal: el navegador navega a la **respuesta JSON cruda**. No hay redirección, refresco ni mensaje. Además borra metadatos antes de geometría, sin rollback. |
| **Cancelar** edición | `btnCancelar` / `btnVolver` en `js/editor.js` | ✅ Funciona | Redirige a `index.php?p=editar` sin guardar. |
| **Guardar** y actualizar la UI | `js/editor.js` (redirige tras 1.2 s) | ✅ Funciona en el editor | En el panel, tras guardar metadatos **no se refresca la lista lateral** ni el nombre mostrado. |
| **Integridad** metadatos ↔ geometría | — | ⚠️ Inconsistente | 58 features vs 52 claves. **6 geometrías huérfanas** (`P1`–`P6`) sin entrada en `datos.json`. 0 metadatos sin geometría. 0 IDs duplicados. Todos los anillos cerrados con ≥4 puntos. |

#### 2.1.3 Operaciones que aparentan completarse y no persisten (confirmado)

Dos rutas distintas producen el mismo síntoma:

1. **Metadatos desde el panel**: la petición se rechaza con 403, pero `panel.php` solo muestra `✗ Token CSRF inválido...` dentro de un mensaje que se desvanece a los 5 s. El usuario no ve que "no se guardó nada" y el formulario conserva lo escrito.
2. **Metadatos con CSRF corregido**: `saveEdificio.php` respondería `success: true` y el panel mostraría "✓ Cambios guardados correctamente", pero escribiría en `PHP/public/datos.json`. Al recargar, el panel (que lee `datos.json` raíz) mostraría los valores antiguos. **Éxito falso confirmado por diseño de rutas.**

#### 2.1.4 Evidencias

- Reproducción HTTP real (servidor `php -S 127.0.0.1:8012`, login como `admin`, POST sin token):
  ```
  login: 302
  {"success":false,"message":"Token CSRF inválido. Recarga la página e inténtalo de nuevo."}
  HTTP 403
  ```
- `panel.php` formulario de edición: solo existe `<input type="hidden" name="id">`; el token CSRF únicamente aparece en el formulario de **eliminar** (línea 77).
- `panel.php:347-359`: el `FormData` construido en JS añade `id`, `nombre`, `descripcion`, `aulas`, imágenes y `imagenes_eliminar`; **nunca** `csrf_token`.
- `saveEdificio.php:30`: `$rutaJson = __DIR__ . '/../public/datos.json';`
- `saveEdificio.php:63`: `$carpetaImg = __DIR__ . '/../public/img/' . $id;`
- `imagelist.php:21`: `$dir = "img/" . $folder;` (raíz, no `PHP/public/img/`).
- `PHP/public/datos.json`: 48 claves; `datos.json` raíz: 52 claves.
- `BuildingService.php:163-168`: la entrada reescrita no incluye `imagenes`.
- Auditoría de datos: `features sin metadatos: ["P6","P2","P4","P5","P3","P1"]`; `metadatos sin geometria: []`.
- `js/main.js:304` trata explícitamente `P1`–`P6` como "puntos de acceso al campus", lo que sugiere que **su ausencia de metadatos podría ser intencional**. Esto se marca como **requiere investigación** en la sección 11.

---

### 2.2 Problemática 2 — Las demás geometrías no se ven al agregar un edificio

#### Comportamiento actual

La página del editor **no carga ninguna geometría que no sea la del edificio en edición**. No es que las geometrías se oculten: **nunca se cargan en esa página**.

#### Causa raíz

`PHP/admin/editar-geometria.php` incluye únicamente:

```html
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
...
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script>window.EDITOR_GEOMETRIA_INICIAL = <?= json_encode($edificioGeometria) ?>;</script>
<script src="js/editor.js"></script>
```

- No incluye `GeoTec.js`, por lo que `datosTec` no existe en esa página.
- No incluye `js/main.js` (que es quien hace `L.geoJSON(datosTec, ...)` en el mapa principal).
- No hay `fetch` de `datos.json` ni de `GeoTec.js`.
- `js/editor.js` solo crea: capa de teselas, `currentPolygon` (el que se dibuja/edita) y marcadores/líneas temporales del dibujo manual.

Con `accion=nuevo`, `$edificioGeometria = null`; con `accion=editar`, solo se inyecta **una** geometría. Por tanto el editor es una "hoja en blanco" salvo por la tesela satelital.

#### Factor secundario relevante (a considerar en el diseño de la solución)

Aunque se reutilizara `js/main.js`, las geometrías **no se verían**: su estilo es

```js
{ color: '#2F29D1', weight: 0, fillColor: '#3388FF', fillOpacity: 0 }
```

es decir, 100 % transparentes hasta que se destacan. Cualquier solución que consista en "incluir `main.js` en el editor" reproduciría el mismo síntoma. La capa de referencia debe definirse con estilo propio, independiente del estilo del mapa público.

#### Requisito de diseño

Separar en dos grupos de capas:

| Grupo | Contenido | Interacción |
|---|---|---|
| `referenceLayer` (`L.geoJSON`, `interactive: false`) | Todas las geometrías existentes, **excluyendo** la que se edita | Ninguna: no clicables, no editables, no borrables |
| `editableLayer` (`L.FeatureGroup`) | Solo `currentPolygon` (nuevo o en edición) | Dibujo/edición/borrado |

Esto satisface literalmente el requisito: las geometrías existentes sirven de referencia y **no pueden modificarse accidentalmente** mientras se dibuja una nueva.

#### Evidencias

- `editar-geometria.php:41-44, 110-115`: solo Leaflet + `editor.css` + `editor.js`.
- `js/editor.js:46-61`: solo teselas y control de atribución.
- `js/editor.js:372-391`: la única carga es `window.EDITOR_GEOMETRIA_INICIAL`.
- `js/main.js:75-86`: el render de `datosTec` vive en el mapa público, no en el editor.

---

### 2.3 Problemática 3 — Error `Cannot read properties of undefined (reading 'enable')`

#### Causa raíz (confirmada)

`js/editor.js:311` ejecuta:

```js
currentPolygon.editing.enable();
```

**`editing` no es una API de Leaflet 1.9.4.** En Leaflet core, `L.Polygon`/`L.Polyline`/`L.Path` **no** exponen `.editing`; ese objeto lo añaden plugins:

| Plugin | API correcta | ¿Cargado en el proyecto? |
|---|---|---|
| **Leaflet-Geoman** | `polygon.pm.enable({...})` (no `.editing`) | **No** |
| **Leaflet.draw** | `new L.Edit.Poly(polygon).enable()`; es ese plugin el que **asigna** `polygon.editing` | **No** |
| **leaflet-editable** | `polygon.enableEdit()` | **No** |

Además, `editar-geometria.php` documenta explícitamente que Leaflet-Geoman se retiró:

> `<!-- Solo Leaflet (sin Leaflet-Geoman, que es bloqueado por algunos navegadores) -->`
> `// Usa SOLO Leaflet 1.9.4 (no depende de Leaflet-Geoman ni de CDN externo).`

y el encabezado de `js/editor.js` repite: "modo manual, sin dependencias externas".

Por tanto, `currentPolygon` es un `L.Polygon` puro → `currentPolygon.editing === undefined` → acceder a `.enable()` lanza exactamente el error reportado. El `try/catch` de `activarModoEdicion()` lo captura y muestra el mensaje, pero **no existe ninguna implementación de edición de vértices detrás**.

#### Por qué "añadir una comprobación" no es la solución

El código ya contiene una comprobación defensiva en `desactivarModoEdicion()`:

```js
if (currentPolygon && currentPolygon.editing) { currentPolygon.editing.disable(); }
```

Eso solo evita el error al desactivar; no habilita la edición. Agregar la misma guarda en `activarModoEdicion()` convertiría el fallo en un **silencio funcional**: el botón ✏️ Editar no haría nada. El requisito real es **implementar la edición de vértices**.

#### Secuencia de inicialización (verificada, sin defectos)

1. `window.EDITOR_GEOMETRIA_INICIAL` se define en un `<script>` inline **antes** de cargar `js/editor.js`. ✔
2. `DOMContentLoaded` → se crea `map` (línea 46) → teselas → estado → IIFE `cargarGeometriaInicial()` (línea 372). ✔
3. `currentPolygon` se asigna si hay geometría inicial. ✔

El orden es correcto **porque no hay plugin**. Si se optara por reintroducir un plugin, **debería cargarse antes de `js/editor.js`** (después de Leaflet); de lo contrario el plugin no estaría disponible al inicializar y el fallo persistiría por una segunda causa.

#### Otras observaciones del modo edición

- No se sincroniza la geometría al arrastrar: aunque existiera `.editing`, **no hay listener** que llame a `actualizarGeometriaInput()` al mover un vértice. Con un plugin, el `geometry-input` quedaría con las coordenadas **previas** a la edición y se guardaría la geometría antigua. Es un defecto latente independiente del error.
- `desactivarModoEdicion()` no limpia marcadores de edición porque no existen.
- `isEditMode` se pone en `true` solo tras `enable()`; si falla, queda `false` (correcto).

---

### 2.4 Problemática 4 — Tolerancia de cierre automático

#### Funcionamiento actual

| Elemento | Ubicación | Comportamiento |
|---|---|---|
| Constante | `js/editor.js:41` | `const CLOSE_DISTANCE_METERS = 10;` |
| Distancia | `js/editor.js:128-137` | Haversine (`R = 6371000`), resultado en **metros reales** |
| Predicado | `js/editor.js:140-144` | `<= CLOSE_DISTANCE_METERS` contra `manualCoords[0]` |
| Disparador | `js/editor.js:271-277` | En `map.on('click')`, si `manualCoords.length >= 3` y el clic está cerca del primer vértice → `terminarDibujoManual()` |

**No es un mecanismo del plugin ni una tolerancia en píxeles: es distancia geográfica personalizada.**

#### Por qué es inconsistente

La tolerancia es constante en metros, pero el usuario percibe píxeles. A latitud 25.5324°, metros por píxel = `156543.03392 × cos(25.5324°) / 2^z ≈ 141251.6 / 2^z`:

| Zoom | m/px | Radio de 10 m en pantalla | Diámetro |
|---|---|---|---|
| 15 | 4.31 | **2.3 px** | 4.6 px |
| 16 | 2.16 | 4.6 px | 9.3 px |
| 17 (zoom inicial del editor) | 1.08 | 9.3 px | 18.6 px |
| 18 | 0.54 | 18.6 px | 37 px |
| 19 (máx.) | 0.27 | **37 px** | 74 px |

Consecuencias:

- **Al acercar el zoom para trazar con precisión** (18–19), el "imán" del primer vértice cubre hasta 37 px de radio. Para edificios pequeños, buena parte de la huella queda dentro de ese radio → el polígono se cierra antes de terminar. **Este es el síntoma reportado.**
- **Al alejar el zoom** (15), el radio útil es de 2 px: prácticamente imposible cerrar intencionalmente acercándose al primer vértice.
- La sensación cambia según el zoom: no hay tolerancia "consistente desde la perspectiva del usuario".

#### Defectos asociados al mismo mecanismo (encontrados en la auditoría)

1. **`clickable: false` es API de Leaflet 0.7** (`js/editor.js:182`). En Leaflet 1.x se ignora; la opción correcta es `interactive: false`. Consecuencia: los `L.circleMarker` de vértice **capturan clics**, y el primer vértice está exactamente donde el usuario debe hacer clic para cerrar → el clic se pierde y el cierre intencional falla (o se comporta de forma errática).
2. **Doble clic añade vértices duplicados.** Leaflet sintetiza `dblclick` a partir de dos `click`. El handler de `click` no distingue y añade un vértice en cada uno, luego el `dblclick` (con `setTimeout` de 100 ms) cierra. Resultado: el polígono guarda **2 vértices casi idénticos** al final. No rompe la validez (`geometriaValida` solo exige ≥4 puntos y cierre), pero ensucia la geometría y puede generar aristas degeneradas.
3. **`manualTempLine` nunca se crea** (declarado, limpiado y comprobado, pero sin asignación). No hay línea elástica de previsualización; es código muerto, no un bug funcional.
4. **Mensaje con conteo incorrecto** (`js/editor.js:241`): `coords.length` incluye el punto de cierre repetido → un polígono de 5 vértices anuncia "6 vértices".

---

### 2.5 Problemática 5 — Preservación de la geometría original

#### 2.5.1 Flujo completo verificado (extremo a extremo)

| Etapa | Código | Qué ocurre con los vértices |
|---|---|---|
| 1. Dibujo | `js/editor.js:174-195` `agregarVerticeManual()` | `manualCoords.push([lat, lng])` — **N arbitrario** |
| 2. Cierre | `js/editor.js:197-209` | `[...manualCoords, manualCoords[0]]` — añade **un** punto de cierre |
| 3. Objeto Leaflet | `js/editor.js:217` | `L.polygon(coords)` con **todos** los puntos |
| 4. Serialización | `js/editor.js:84-85` | `currentPolygon.toGeoJSON()` → `[lng, lat]`, **sin simplificación** |
| 5. Input | `js/editor.js:85` | `geometryInput.value = JSON.stringify(geojson.geometry)` |
| 6. Envío | `js/editor.js:457-464` | `FormData(form)` (incluye el hidden `geometry`) |
| 7. Recepción | `saveGeometria.php:57-63` | `json_decode`; solo comprueba `is_array` |
| 8. Validación | `BuildingService::geometriaValida()` (342-376) | Tipo `Polygon`, anillo ≥4 puntos, numéricos, **cerrado**. Sin bbox, sin simplificación |
| 9. Persistencia geometría | `BuildingGeometryRepository::agregarOActualizarFeature()` (137-184) | `$existente['geometry'] = $geometry;` — asignación **literal**; red de seguridad de IDs |
| 10. Escritura | `escribirGeometrias()` (81-110) | `json_encode(..., JSON_PRETTY_PRINT)` — **no toca coordenadas** |
| 11. Lectura | `buscarFeaturePorId()` / `obtenerEdificio()` | Devuelve la geometría **verbatim** |
| 12. Preload | `editar-geometria.php:113` → `js/editor.js:372-391` | `anillo.map(c => [c[1], c[0]])` → `L.polygon` con todos los puntos |
| 13. Mapa público | `js/main.js:75-86` | `L.geoJSON(datosTec)` — renderiza tal cual |

**No hay ningún punto de pérdida de vértices.** Las conversiones `[lat,lng] ↔ [lng,lat]` son coherentes: Leaflet usa `[lat,lng]`, GeoJSON `[lng,lat]`, y ambos lados invierten exactamente una vez.

#### 2.5.2 Búsqueda explícita de conversión a rectángulo

Se buscó en `js/` y en todo el proyecto (`*.js`, `*.php`, `*.html`):

- `rectangle` / `Rectangle`: **0 coincidencias**.
- `getSouthWest` / `getNorthEast`: **0 coincidencias**.
- `latLngBounds` / `L.latLngBounds`: **0 coincidencias**.
- Usos de `getBounds()` existen solo en:
  - `js/main.js:762` → `map.fitBounds()` (encuadre de cámara, no geometría).
  - `js/editor.js:361` → prueba de contención para el modo eliminar (**test de bounding box**, ver 2.5.4).
- Usos de `L.polygon`: solo `js/editor.js:217` y `:381`, ambos con el arreglo completo de vértices.
- `L.geoJSON`: solo render, sin `coordsToLatLng` personalizado que simplifique.

**Conclusión: no existe código que genere un rectángulo a partir de dos puntos ni que reduzca la geometría a su bbox.**

#### 2.5.3 Evidencia empírica de que se conservan vértices arbitrarios

Conteo de puntos por anillo (incluye el de cierre) en el `GeoTec.js` actual:

```
D1=65, D6=33, D5=21, CI=12, 20=11, 28=10, 31=9, 23A=8, 6=8, ...
```

Un rectángulo tendría exactamente **5**. Hay edificios con 8, 9, 10, 11, 12, 21, 33 y 65 puntos almacenados y renderizados. Además, el edificio `zzz`, creado **con el editor actual** durante la prueba:

```json
{"type":"Polygon","coordinates":[[[-103.436537,25.529261],[-103.436607,25.52928],
[-103.436869,25.529135],[-103.436902,25.529036],[-103.436582,25.528958],
[-103.436537,25.529261]]]}
```

5 vértices + cierre, bbox no rectangular → **el guardado preserva la forma dibujada**.

#### 2.5.4 Defectos reales relacionados con geometría (confirmados, aunque no sean "rectangulización")

1. **Borrado por bounding box.** `js/editor.js:359-366` elimina `currentPolygon` si el clic cae dentro de `currentPolygon.getBounds()`, es decir **dentro del rectángulo envolvente**, no del polígono. Para una huella irregular (p. ej. `D1`, `CI`, `D5`), un clic en el "hueco" fuera del polígono pero dentro del bbox borra el dibujo.
2. **Pérdida de `imagenes` al guardar geometría.** `BuildingService::actualizarEdificio()` reconstruye la entrada de `datos.json` sin el campo `imagenes`. Editar la geometría de un edificio que tiene imágenes (4 casos en `datos.json`) **destruye su lista de imágenes** de forma silenciosa.
3. **ID ambiguo en el editor.** `editar-geometria.php:74` (hidden `name="id"`) y `:80` (input `name="id"`) envían **dos valores con el mismo nombre**. PHP conserva el último → el valor del campo visible. En `accion=editar`, si el usuario cambia el ID, `actualizarEdificio()` busca un edificio que no existe y falla; si escribe el ID de otro edificio, **sobrescribe metadatos y geometría de ese otro edificio** (no hay validación de colisión en actualización, a diferencia de la creación).
4. **Reformateo completo de `GeoTec.js` en cada guardado.** El escritor regenera el archivo completo con `JSON_PRETTY_PRINT`. Es funcionalmente correcto (y ya existe `.bak`), pero produce diffs enormes y ruido en Git. No afecta a la geometría.
5. **Edificios sin metadatos no se pueden editar.** `obtenerEdificio()` devuelve `null` para `P1`–`P6`; el editor abre vacío y al guardar responde "El edificio 'P1' no existe". No se pierde nada, pero es un callejón sin salida.

#### 2.5.5 Estado: NO REPRODUCIBLE

No se logró reproducir la conversión a cuadrado/rectángulo. **No se debe asumir que el almacenamiento la provoca.** Las hipótesis plausibles (todas **no confirmadas**) se detallan en la sección 11 junto con el experimento necesario para discriminar entre ellas.

---

## 3. Causas raíz consolidadas

| ID | Causa raíz | Problemática | Evidencia |
|---|---|---|---|
| RC-1 | El formulario de metadatos no envía `csrf_token` | 1 | `panel.php:347-359`; HTTP 403 reproducido |
| RC-2 | `saveEdificio.php` escribe en `PHP/public/datos.json` (copia obsoleta) en lugar de la raíz | 1 | `saveEdificio.php:30`; 48 vs 52 claves |
| RC-3 | Las subidas de imágenes van a `PHP/public/img/` y el listado lee `img/` raíz | 1 | `saveEdificio.php:63` vs `imagelist.php:21` |
| RC-4 | La edición de geometría reconstruye los metadatos sin preservar `imagenes` | 1 y 5 | `BuildingService.php:163-168` |
| RC-5 | La eliminación se hace con un POST de formulario que devuelve JSON crudo | 1 | `panel.php:76`; `deleteEdificio.php:14` |
| RC-6 | La eliminación borra metadatos antes que geometría y sin rollback | 1 | `BuildingService.php:212-222` |
| RC-7 | El editor nunca carga las geometrías existentes (no incluye `GeoTec.js` ni hace fetch) | 2 | `editar-geometria.php:110-115`; `js/editor.js` |
| RC-8 | `.editing` no existe en Leaflet 1.9.4; el plugin que lo provee no está cargado | 3 | `js/editor.js:311`; ausencia de Geoman/Leaflet.draw |
| RC-9 | La tolerancia de cierre es geográfica (10 m) y no se adapta al zoom | 4 | `js/editor.js:41,128-144` |
| RC-10 | `clickable:false` es API 0.7; en Leaflet 1.x los marcadores interceptan clics | 4 | `js/editor.js:182` |
| RC-11 | El doble clic añade dos vértices antes de cerrar | 4 | `js/editor.js:266-291` |
| RC-12 | El borrado usa el bounding box en lugar del polígono | 2 y 5 | `js/editor.js:359-366` |
| RC-13 | El editor envía `id` duplicado (hidden + visible) y no valida colisiones al actualizar | 1 y 5 | `editar-geometria.php:74,80`; `BuildingService.php:127-191` |
| RC-14 | Geometrías huérfanas `P1`–`P6` sin metadatos (posiblemente intencional) | 1 | Auditoría de datos; `js/main.js:304` |
| RC-15 | Sin listener de sincronización al mover vértices (defecto latente) | 3 | `js/editor.js` (ausente) |

---

## 4. Soluciones recomendadas

### 4.1 Problemática 1 — CRUD (dos workstreams que deben entregarse juntos)

**S1.1 — Token CSRF en el formulario de metadatos (obligatorio, mínimo cambio).**
Exponer el token como `<meta name="csrf-token" content="...">` en `panel.php` y añadirlo al `FormData` en el handler de submit. Mantener también el hidden en el formulario como respaldo sin JS. **No** desactivar la validación CSRF en el servidor.

**S1.2 — Unificar la persistencia en una sola fuente de verdad.**
Sustituir el guardado artesanal de `saveEdificio.php` por delegación a `BuildingService` (que ya escribe en la raíz y es el único escritor de `datos.json`), o bien corregir la ruta a `dirname(__DIR__, 2) . '/datos.json'` y `dirname(__DIR__, 2) . '/img/'`. Recomendado: **delegación al servicio**, para no tener dos implementaciones de escritura. Eliminar o marcar como obsoleto `PHP/public/datos.json` (el AGENTS.md ya declara `PHP/public/` deprecado).

**S1.3 — Preservar `imagenes` en las actualizaciones.**
En `actualizarEdificio()` conservar los campos no gestionados por el editor (`imagenes`) mediante *merge* en lugar de reconstrucción:

```php
$datos[$id] = array_merge($datos[$id], [
    'nombre' => ..., 'descripcion' => ..., 'aulas' => ..., 'carpeta' => ...,
]);
```

**S1.4 — Eliminación con UX correcta.**
Enviar el borrado con `fetch` (o con un formulario que redirija), mostrar confirmación, recargar la lista y mostrar el error del servidor si falla. Orden de borrado: **geometría primero, metadatos después**, con rollback de la geometría si fallan los metadatos (mismo patrón que la creación).

**S1.5 — Refresco de la UI del panel.**
Tras guardar metadatos, recargar la página (simple y consistente) o refrescar la lista lateral y el encabezado. Evitar el estado "guardado pero la lista muestra lo viejo".

**S1.6 — Higiene de errores.**
`display_errors` debe estar desactivado en el panel y en todos los endpoints que devuelven JSON; los errores van a `error_log`. Un `notice` de PHP puede corromper el JSON y romper el `response.json()` del cliente.

### 4.2 Problemática 2 — Capa de referencia de geometrías existentes

**S2.1 — Inyectar las geometrías en la vista.**

```php
// editar-geometria.php
$repo = new BuildingGeometryRepository();
$geometrias = $repo->leerGeometrias(); // ya lanza excepción si el archivo es inválido
// excluir la que se está editando para no duplicarla
```

Emitir `window.EDITOR_GEOMETRIAS_EXISTENTES = <?= json_encode($geometrias) ?>;` (o solo `features`) **antes** de `js/editor.js`.

**S2.2 — Capa separada, no interactiva.**

```js
const referenceLayer = L.geoJSON(featuresExistentes, {
    interactive: false,          // ningún clic llega a estas capas
    style: { color: '#6b7280', weight: 1, fillColor: '#6b7280', fillOpacity: 0.15, opacity: 0.8 },
    filter: f => f.properties.id !== edificioActualId
}).addTo(map);
```

Claves de la solución:

- **`interactive: false`** es lo que impide editar/borrar otros edificios; no basta con un estilo distinto.
- `filter` excluye el edificio en edición (no se dibuja dos veces).
- Ningún handler de edición se registra sobre `referenceLayer`.
- La capa de edición (`currentPolygon`) se mantiene en su propio `L.FeatureGroup` o variable.
- No reutilizar `js/main.js` (estilo transparente + lógica de rutas/popups).

**S2.3 — Controles explícitos de modo.** Añadir un estado de editor (`idle`, `drawing`, `editing`) y reflejarlo en la toolbar, de forma que `isDrawingManual`, `isEditMode` e `isDeleteMode` sean mutuamente excluyentes por construcción y no por llamadas cruzadas a `desactivar*()`.

### 4.3 Problemática 3 — Edición de vértices real

**S3.1 — Opción recomendada: implementar la edición sin plugin (coherente con la arquitectura actual).**

- Al activar ✏️ Editar: crear un `L.marker` arrastrable (`L.divIcon` circular) por cada vértice **del anillo, excluyendo el punto de cierre duplicado**.
- `marker.on('drag', ...)`: reconstruir el anillo (`[...puntos actualizados, puntos[0]]`), `currentPolygon.setLatLngs(nuevoAnillo)` y llamar a `actualizarGeometriaInput()` — **esto corrige RC-15**.
- Al desactivar: eliminar todos los marcadores de edición y restaurar el estado.
- Mantener `desactivarModoEdicion()` idempotente y sin depender de `.editing`.
- Ventajas: cero dependencias, sin CDN, sin riesgo de bloqueo por Tracking Prevention, funciona con `php -S` y offline.
- Extensiones opcionales (no bloqueantes): añadir vértice al hacer clic sobre una arista, eliminar vértice con clic derecho.

**S3.2 — Alternativa: reintroducir un plugin vendorizado (no por CDN).**

- Descargar Leaflet-Geoman en `js/vendor/leaflet-geoman/` y `css/vendor/leaflet-geoman/`, versiones fijas.
- Cargar **después de Leaflet y antes de `js/editor.js`**.
- Usar la API correcta: `polygon.pm.enable({ allowSelfIntersection: false })` / `polygon.pm.disable()`.
- **No** usar `.editing` (eso es Leaflet.draw). Si se elige Leaflet.draw, sería `L.Edit.Poly`; verificar compatibilidad con 1.9.4 antes de decidir.
- Requiere verificación de licencia y de que los assets se sirvan localmente.

**Recomendación:** S3.1. Elimina la causa raíz (dependencia de una API inexistente) sin reintroducir el problema que motivó quitar Geoman.

### 4.4 Problemática 4 — Tolerancia consistente en pantalla

**S4.1 — Tolerancia en píxeles.**

```js
const CLOSE_DISTANCE_PX = 10; // ajustable 8–12

function estaCercaDelPrimerPunto(latlng, coords) {
    if (!coords || coords.length < 2) return false;
    const p = map.latLngToContainerPoint(latlng);
    const primero = map.latLngToContainerPoint(L.latLng(coords[0][0], coords[0][1]));
    return p.distanceTo(primero) <= CLOSE_DISTANCE_PX;
}
```

La tolerancia queda **constante en píxeles en todos los zooms**, que es lo que el usuario percibe. Recomendado 10 px (≈ 20 px de diámetro), con posibilidad de subir a 12 px si en pruebas resulta difícil cerrar.

**S4.2 — Marcadores de vértice no interactivos.**
Cambiar `clickable: false` por `interactive: false` en `L.circleMarker` (Leaflet 1.x). Alternativa: mantener los marcadores en un *pane* con `pointer-events: none`.

**S4.3 — Prevención de vértices duplicados por doble clic.**
En el handler de `click`, ignorar el vértice si está a menos de ~2 px del último registrado:

```js
if (manualCoords.length && map.latLngToContainerPoint(latlng)
        .distanceTo(map.latLngToContainerPoint(L.latLng(...ultimo))) < 2) return;
```

Y mantener el cierre por `dblclick` con su `setTimeout`, ya que ahora no añadirá duplicados.

**S4.4 — Feedback visual del cierre.**
Opcional pero recomendado: cuando el cursor entra en el radio de cierre, resaltar el primer vértice (cambio de color/radio) para que el cierre sea **intencional y visible**, no accidental.

**S4.5 — Corregir el mensaje de conteo.** Usar `coords.length - 1` (excluye el punto de cierre) al informar "Polígono creado con N vértices".

### 4.5 Problemática 5 — Preservación de la geometría

**S5.1 — No se requiere cambio arquitectónico.** El formato `GeoJSON Polygon` en `datosTec` admite cualquier número de vértices y todo el pipeline los conserva. La conclusión de viabilidad es **afirmativa** (sección 12).

**S5.2 — Correcciones defensivas recomendadas (bajo coste, alto valor).**

1. **Sincronizar el input al mover vértices** (RC-15). Sin esto, con la nueva edición se guardaría la geometría **anterior**; es el único punto donde hoy podría perderse una edición.
2. **Validar en servidor que el número de vértices recibido coincide con el almacenado** en actualizaciones cuando el cliente afirma no haberlos cambiado; y registrar en auditoría `vertices_antes` / `vertices_despues`. Detecta cualquier regresión futura sin bloquear.
3. **Resolver el ID duplicado del formulario** (RC-13): un solo campo `id`, con `id_original` oculto. Al actualizar, validar que el ID nuevo no colisione con otro edificio.
4. **Borrado por polígono, no por bbox** (RC-12): usar el evento `click` de la capa (`currentPolygon.on('click', ...)`) o una prueba punto-en-polígono.
5. **Prueba de ida y vuelta automatizada** (script CLI, no parte del runtime): dibujar un polígono de 7 vértices irregulares, guardarlo, releerlo y comparar arreglos de coordenadas exactos.

**S5.3 — Instrumentación para reproducir el síntoma reportado.**
Si el usuario vuelve a observar un rectángulo, capturar **antes de guardar**:

```js
btnGuardar.addEventListener('click', () => {
    console.log('VÉRTICES ENVIADOS:', JSON.parse(geometryInput.value).coordinates[0].length);
    console.log('GEOMETRÍA ENVIADA:', geometryInput.value);
}, true); // captura, antes del submit
```

y **después de guardar**, comparar con el feature almacenado. Esto discrimina entre "se dibujó un rectángulo", "se perdió en el envío" o "se perdió en la lectura/render". Sin esa captura, cualquier corrección sería especulativa.

---

## 5. Archivos involucrados

| Archivo | Problemáticas | Cambio previsto |
|---|---|---|
| `PHP/admin/panel.php` | 1 | Token CSRF (meta o hidden), `FormData`, fetch de borrado, refresco de lista, quitar `display_errors` |
| `PHP/admin/saveEdificio.php` | 1 | Delegar en `BuildingService` o corregir rutas; preservar `imagenes`; JSON de error coherente |
| `PHP/admin/imagelist.php` | 1 | Alinear carpeta de imágenes con la de subida |
| `PHP/admin/editar-geometria.php` | 2, 3, 5 | Inyectar geometrías existentes; resolver `id` duplicado; (si se elige plugin) cargar assets locales |
| `js/editor.js` | 2, 3, 4, 5 | Capa de referencia, edición por marcadores arrastrables, tolerancia en px, `interactive:false`, anti-duplicados, borrado por polígono, sincronización del input |
| `css/editor.css` | 2, 3 | Estilos de la capa de referencia y de los handles de edición (clase de marcador) |
| `PHP/services/BuildingService.php` | 1, 5 | Preservar `imagenes`; orden de borrado con rollback; validación de colisión de ID en update |
| `PHP/repositories/BuildingGeometryRepository.php` | 5 | Sin cambios funcionales; opcional: registrar auditoría de conteo de vértices |
| `PHP/public/datos.json` | 1 | Marcar obsoleto / dejar de usar (no borrar sin decisión explícita) |
| `PHP/auth/csrf.php` | 1 | Sin cambios (la función es correcta) |
| `deleteEdificio.php`, `saveGeometria.php` | 1, 5 | Sin cambios de contrato; ya devuelven JSON |
| `AGENTS.md` | — | Actualizar la sección del editor cuando se implemente (documentación) |

**No se tocarán:** `GeoTec.js`, `datos.json` (contenido), `js/main.js`, `index.html`, `caminitos.geojson`, imágenes.

---

## 6. Plan de implementación por tareas

### Grupo A — CRUD y persistencia (independiente del editor)

| ID | Tarea | Archivos | Prioridad | Depende de |
|---|---|---|---|---|
| **A1** | Añadir `csrf_token` al formulario de metadatos y al `FormData` | `panel.php` | **P0** | — |
| **A2** | Unificar la escritura de metadatos en `datos.json` raíz vía `BuildingService`; alinear `img/` | `saveEdificio.php`, `imagelist.php` | **P0** | A1 (para poder probar) |
| **A3** | Preservar `imagenes` (y campos no gestionados) al actualizar | `BuildingService.php` | **P0** | — |
| **A4** | Borrado con `fetch` + confirmación + refresco + mensaje de error | `panel.php`, `deleteEdificio.php` (contrato ya válido) | **P1** | — |
| **A5** | Orden de borrado con rollback (geometría → metadatos) | `BuildingService.php` | **P1** | A4 |
| **A6** | Refresco de la UI tras guardar metadatos | `panel.php` | **P2** | A1, A2 |
| **A7** | Desactivar `display_errors` en panel y endpoints JSON | `panel.php`, `saveEdificio.php` | **P2** | — |

A1 y A2 son **indivisibles**: con solo A1, el guardado "funciona" pero escribe en el archivo equivocado; con solo A2, sigue devolviendo 403.

### Grupo B — Editor de geometrías (independiente del CRUD)

| ID | Tarea | Archivos | Prioridad | Depende de |
|---|---|---|---|---|
| **B1** | Inyectar geometrías existentes + capa de referencia `interactive:false` con `filter` | `editar-geometria.php`, `js/editor.js`, `css/editor.css` | **P1** | — |
| **B2** | Implementar edición de vértices con marcadores arrastrables; eliminar `.editing`; sincronizar el input | `js/editor.js`, `css/editor.css` | **P0** | — |
| **B3** | Tolerancia de cierre en píxeles (`latLngToContainerPoint`) | `js/editor.js` | **P1** | — |
| **B4** | `interactive:false` en marcadores de vértice + anti-duplicados de doble clic + mensaje de conteo | `js/editor.js` | **P1** | B3 |
| **B5** | Borrado por polígono (no por bbox) | `js/editor.js` | **P2** | B1 |
| **B6** | Resolver `id` duplicado y validar colisión de ID al actualizar | `editar-geometria.php`, `BuildingService.php` | **P1** | — |
| **B7** | Instrumentación y reproducción guiada de la problemática 5 | `js/editor.js` (temporal, o consola del navegador) | **P1** | B2, B3 |

B2 y B3 pueden desarrollarse en paralelo (archivos distintos dentro de `editor.js`, pero conviene la misma rama). B1 no depende de B2 ni al contrario.

### Orden de implementación sugerido

1. **A1 + A2 + A3** → desbloquea por completo la edición de metadatos y evita pérdida de imágenes.
2. **B2 + B3 + B4** → editor usable: se puede editar de verdad y dibujar con precisión.
3. **B1** → geometrías de referencia al crear.
4. **A4 + A5** → borrado correcto y seguro.
5. **B6, B7, B5** → robustez y verificación.
6. **A6, A7** → pulido.

---

## 7. Prioridades y dependencias

| Prioridad | Significado | Tareas |
|---|---|---|
| **P0** | Bloqueante: funcionalidad principal rota o pérdida de datos | A1, A2, A3, B2 |
| **P1** | Alto: requisito explícito del usuario o riesgo de corrupción | A4, A5, B1, B3, B4, B6, B7 |
| **P2** | Medio: robustez, UX, mantenibilidad | A6, A7, B5 |
| **P3** | Bajo: mejoras opcionales | Feedback visual del cierre, añadir/eliminar vértices por clic |

**Dependencias críticas**

```
A1 ──► A2 ──► A6
 └────► A4 ──► A5
A3 (independiente, debe ir con A2)
B2 ──► B7
B3 ──► B4 ──► B7
B1 ──► B5
```

**Riesgo de orden:** si se implementa A1 sin A2, se introduce el "éxito falso" (mensaje verde, datos no persistidos), que es **peor** que el 403 actual porque es silencioso. Deben desplegarse juntas.

---

## 8. Criterios de aceptación

### Grupo A

| ID | Criterio |
|---|---|
| A1/A2 | Editar nombre, descripción y aulas desde el panel y recargar: los cambios persisten en `datos.json` raíz. El panel no muestra error 403. |
| A2 | `PHP/public/datos.json` deja de recibir escrituras (verificable por fecha de modificación). |
| A2 | Subir una imagen y verla inmediatamente en el carrusel del panel (misma ruta raíz). |
| A3 | Editar la geometría de un edificio con `imagenes` (p. ej. `34`): la lista `imagenes` sigue intacta en `datos.json`. |
| A4 | Eliminar un edificio: se ve confirmación, se vuelve al panel con mensaje de éxito, el edificio desaparece de la lista y del mapa. Nunca se muestra JSON crudo. |
| A5 | Si falla el borrado de geometría, los metadatos **no** se eliminan (o se restauran). |
| A6 | Tras guardar, la lista lateral y el encabezado muestran los valores nuevos sin recargar manualmente. |
| A7 | Ningún endpoint JSON emite warnings/notices en el cuerpo de la respuesta. |

### Grupo B

| ID | Criterio |
|---|---|
| B1 | Al abrir "Agregar edificio" se ven **todas** las geometrías existentes como referencia. |
| B1 | Hacer clic, arrastrar o intentar editar una geometría de referencia **no** la modifica ni la selecciona. |
| B1 | Al editar un edificio, su geometría no se dibuja dos veces (referencia + editable). |
| B2 | ✏️ Editar muestra handles en cada vértice; arrastrarlos deforma el polígono en pantalla **sin ningún error en consola**. |
| B2 | Tras arrastrar y guardar, `GeoTec.js` contiene las coordenadas nuevas (no las anteriores). |
| B3 | A zoom 15, 17 y 19, el cierre automático requiere acercarse al primer vértice con la **misma** tolerancia visual. |
| B3 | Es posible cerrar intencionalmente un polígono en los tres niveles de zoom. |
| B4 | Un doble clic para terminar no añade vértices duplicados (conteo = vértices dibujados + 1). |
| B4 | Hacer clic exactamente sobre el primer vértice cierra el polígono. |
| B5 | En un polígono irregular, un clic fuera del polígono pero dentro de su bbox **no** lo elimina. |
| B6 | Cambiar el ID a uno existente al editar muestra un error de validación y no sobrescribe el otro edificio. |
| B7 | Un polígono irregular de 7 lados dibujado en el editor se guarda con 8 coordenadas (7 + cierre) y se recupera idéntico. |

---

## 9. Estrategia de pruebas

### 9.1 Pruebas manuales guiadas (navegador)

1. **CRUD metadatos**
   - Login → panel → seleccionar edificio → cambiar nombre/descripción/aulas → Guardar → recargar → verificar.
   - Verificar en `datos.json` (raíz) y confirmar que `PHP/public/datos.json` **no** cambió.
2. **Preservación de imágenes**
   - Editar geometría del edificio `34` (tiene `imagenes`) → guardar → comprobar que `imagenes` sigue presente.
3. **Borrado**
   - Crear un edificio de prueba → eliminar → verificar panel, mapa y ambos archivos. Confirmar que no hay geometría huérfana.
4. **Capa de referencia**
   - Abrir "Agregar edificio" → contar geometrías visibles (debe coincidir con features menos la editada).
   - Intentar hacer clic/arrastrar sobre una referencia → no debe cambiar nada.
5. **Edición de vértices**
   - Editar `zzz` (5 vértices) → arrastrar un vértice → guardar → releer → comparar coordenadas.
6. **Cierre por proximidad**
   - Dibujar un polígono de 6 lados a zoom 15, 17 y 19; comprobar que no se cierra antes de tempo y que se puede cerrar acercándose al primer vértice.
7. **Doble clic**
   - Terminar con doble clic → verificar el número de coordenadas almacenadas.

### 9.2 Pruebas automatizadas ligeras (scripts CLI, sin framework)

El proyecto no tiene gestor de paquetes ni suite de tests; se proponen scripts PHP de un solo uso en `%TEMP%`, no versionados:

| Script | Comprueba |
|---|---|
| `audit_data.php` (ya usado en esta auditoría) | Integridad: features vs claves, huérfanos, anillos cerrados, duplicados, mínimo de 4 puntos |
| `roundtrip.php` | Crea una geometría de 7 vértices vía `BuildingService`, la relee, compara arreglos exactos, restaura el estado |
| `csrf_check.php` | POST sin token → 403; POST con token → 200 (contra `php -S`) |
| `path_check.php` | Comprueba que el guardado modifica `datos.json` raíz y no `PHP/public/datos.json` |

### 9.3 Pruebas de regresión

- El mapa público (`index.html`) sigue renderizando **58** features y los popups funcionan.
- Los edificios `P1`–`P6` siguen mostrando el popup de "punto de acceso".
- Las rutas (`pathfinding.js`) y los beacons no se ven afectados (no se tocan).
- `datos.json` conserva las 52 claves y `GeoTec.js` las 58 features tras las pruebas (restaurar datos de prueba).

### 9.4 Prueba específica de la problemática 5 (discriminante)

Con la instrumentación de S5.3, para un mismo dibujo:

| Observación | Conclusión |
|---|---|
| `geometry-input` tiene N vértices, el POST tiene N, `GeoTec.js` tiene N, el mapa dibuja un rectángulo | El fallo está en **render** (`js/main.js` / Leaflet), no en el almacenamiento |
| `geometry-input` tiene N, `GeoTec.js` tiene N, el editor recarga con N, pero el usuario percibe rectángulo | Percepción/estilo visual (probablemente el `weight:0/fillOpacity:0` del mapa público) |
| `geometry-input` tiene 5 cuando se dibujaron 7 | El fallo está en el **dibujo** (cierre prematuro por RC-9/RC-10), no en el guardado |
| `geometry-input` tiene 7, `GeoTec.js` tiene 5 | Fallo real de persistencia → prioridad máxima y revisión de `agregarOActualizarFeature` |

Hasta no obtener esta tabla, la problemática 5 permanece **NO REPRODUCIDA**.

---

## 10. Riesgos y consideraciones

| Riesgo | Probabilidad | Impacto | Mitigación |
|---|---|---|---|
| Implementar A1 sin A2 → "éxito falso" silencioso | Alta si se separan | Alto | Desplegar A1+A2 juntas; prueba `path_check.php` |
| Dos escritores de `datos.json` (`saveEdificio` y `BuildingService`) generan condiciones de carrera | Media | Alto | Un solo escritor (delegación); `LOCK_EX` ya presente |
| Reintroducir Geoman por CDN y que Tracking Prevention lo bloquee de nuevo | Media | Alto | Vendorizar localmente; o no usar plugin (recomendado) |
| La capa de referencia se vuelve editable por accidente | Media | Alto | `interactive:false` + no registrar handlers + prueba manual explícita |
| Pérdida de `imagenes` al actualizar geometría | **Alta (ya ocurre)** | Alto | A3 antes de permitir ediciones de geometría masivas |
| `GeoTec.js` reformateado en cada guardado genera diffs enormes | Alta | Bajo | Ya existe `.bak`; documentar; opcional: escritor que preserve formato |
| Edificios huérfanos `P1`–`P6` se "arreglan" por error | Media | Medio | Confirmar con producto antes de tocarlos (sección 11) |
| Regresión en el mapa público al cambiar estilos | Baja | Medio | No tocar `js/main.js`; prueba visual de 58 features |
| Pruebas que dejan datos basura (`zzz`) | Alta | Bajo | Restaurar `datos.json`/`GeoTec.js` desde `.bak` o Git tras cada prueba |
| `display_errors` activo en producción filtra rutas y warnings | Alta | Medio | A7 |
| El usuario cambia el ID y sobrescribe otro edificio | Media | **Alto** | B6 (validación de colisión) |

### Consideraciones adicionales

- **`PHP/public/` está deprecado** según `AGENTS.md`: las copias raíz son las usadas por el router. Cualquier corrección debe reforzar esa dirección, no crear una tercera fuente.
- **`rename()` sí sobrescribe en este entorno** (PHP 8.4.7 / Windows), verificado empíricamente. El comentario de `BuildingService::escribirDatosJson()` que afirma lo contrario es una precaución heredada, no un hecho actual; no es necesario cambiarla, pero tampoco debe citarse como limitación real.
- **Sin HTTPS** el GPS real no funciona (`USE_GPS_LOCATION`), pero eso es ajeno a este plan.
- Los cambios actuales del árbol de trabajo **no están commiteados**. Antes de implementar conviene commitear el estado actual como línea base para poder revertir.

---

## 11. Funcionalidades que requieren investigación adicional

| # | Tema | Por qué no se resolvió | Qué falta |
|---|---|---|---|
| I1 | **Problemática 5 (rectángulo)** | No reproducible con el código actual; no existe conversión a rectángulo | Reproducción guiada por el usuario + instrumentación de S5.3. Posibles explicaciones **no confirmadas**: (a) la geometría se dibujó realmente con 4 vértices; (b) el cierre prematuro (RC-9/RC-10) truncó el dibujo; (c) confusión con el bbox del modo eliminar; (d) percepción por el estilo invisible del mapa público (`weight:0`, `fillOpacity:0`); (e) residuo de las versiones anteriores del editor (antes del preload, la geometría existente no se cargaba) |
| I2 | **Geometrías huérfanas `P1`–`P6`** | `js/main.js:304` las trata como "puntos de acceso", lo que sugiere intencionalidad | Decidir: ¿son puertas sin metadatos (correcto) o edificios incompletos? Si son puertas, excluirlas explícitamente del CRUD y documentarlo |
| I3 | **Renombrado de ID** | No hay requisito definido | Decidir si el ID es inmutable o si el renombrado debe migrar metadatos + geometría + carpeta de imágenes atómicamente |
| I4 | **Imágenes** | Ruta de subida y de listado discrepan; además el formulario de metadatos no forma parte del editor de geometría | Definir el flujo único de imágenes y dónde debe vivir (`img/` raíz) |
| I5 | **Compatibilidad de plugins** | No se instaló nada (restricción) | Si se opta por S3.2, verificar Leaflet-Geoman y/o Leaflet.draw contra 1.9.4 en este entorno, con assets locales |
| I6 | **Concurrencia de escritura** | No se probó con dos sesiones simultáneas | Probar dos administradores guardando a la vez; evaluar si `LOCK_EX` es suficiente o hace falta bloqueo de lectura-modificación-escritura |
| I7 | **Auditoría de vértices** | No existe | Definir si se registra el conteo de vértices antes/después como salvaguarda |
| I8 | **Rendimiento con 58+ features** | No medido | Si se añade la capa de referencia, medir el coste de `L.geoJSON` con `D1` (65 puntos) y `D6` (33) |

---

## 12. Conclusión sobre la viabilidad de preservar polígonos irregulares

### 12.1 Veredicto: **VIABLE — sin cambios arquitectónicos**

La arquitectura actual **ya soporta polígonos de cualquier número de vértices**:

1. **Formato de almacenamiento:** `GeoJSON Polygon` en la variable `datosTec` de `GeoTec.js`. GeoJSON no impone un máximo de vértices; solo exige anillo cerrado y ≥4 posiciones (3 vértices + cierre).
2. **Transporte:** el polígono viaja como JSON en un campo de formulario y se decodifica sin transformación.
3. **Validación:** `geometriaValida()` comprueba tipo, cierre, numericidad y mínimo de puntos. **No** aplica bbox, ni simplificación, ni `convexHull`, ni reducción.
4. **Persistencia:** asignación literal del arreglo de coordenadas; `json_encode` no altera valores.
5. **Lectura y render:** `obtenerEdificio()` devuelve la geometría intacta; el editor y `L.geoJSON` la dibujan tal cual.
6. **Evidencia empírica:** hay geometrías almacenadas y renderizadas de 5, 6, 7, 8, 9, 10, 11, 12, 21, 33 y 65 puntos. `zzz` (5 vértices, irregular) se creó con el editor actual y se conservó exacto.

### 12.2 Qué provoca realmente el síntoma (si existe)

No hay pérdida de vértices en el guardado. Las causas plausibles del "cuadrado" son de **entrada** (el dibujo terminó antes de tiempo por la tolerancia de 10 m y el doble clic) o de **percepción** (estilos invisibles en el mapa público, o el bbox del modo eliminar). **Ninguna está confirmada**; se requiere la reproducción instrumentada de la sección 9.4.

Esto implica una consecuencia importante para el plan: **no debe emprenderse una reescritura del almacenamiento ni una migración de formato.** Sería esfuerzo sin beneficio y con riesgo de regresión.

### 12.3 Qué sí debe corregirse para garantizar la preservación

Aunque el almacenamiento sea correcto, hoy existen **dos formas reales de perder trabajo**:

1. **`currentPolygon.editing` inexistente** (RC-8): el modo edición no funciona, por lo que no se puede corregir una geometría mal dibujada.
2. **Falta de sincronización al mover vértices** (RC-15): cuando se implemente la edición, si no se actualiza el input, se guardará la geometría **anterior**. Es el punto exacto donde una corrección de visualización no bastaría.

Por eso B2 (con sincronización del input) y B3/B4 (dibujo predecible) son **correcciones prioritarias**, no cosméticas.

---

## Anexo A — Evidencias recogidas

### A.1 Estado de datos (auditoría de solo lectura)

```
features en GeoTec.js: 58
claves datos.json: 52
claves PHP/public/datos.json: 48
features sin metadatos: ["P6","P2","P4","P5","P3","P1"]
metadatos sin geometría: []
IDs duplicados en GeoTec.js: []
anillos abiertos: []
anillos con <4 puntos: []
entradas con campo imagenes: 4 (todas con lista no vacía)
carpetas en img/: 4
```

Conteo de puntos por anillo (incluye cierre):
```
2=5, 1A=5, 1B=5, 7=7, 3=5, 4=6, 5=7, 6=8, 9=7, 13=5, 12=6, 10=7, 8=7,
11=5, P6=5, 18=6, 25=5, 31=9, 26=5, 20=11, 27=5, 24=5, 19=5, 30=5, 39=5,
29=5, 36=5, 34=7, 32=5, D6=33, 28=10, 37=7, 33=5, 35=5, 21=5, 23=6, 17=6,
16=5, 14=7, 15=5, 22=5, 23A=8, 23B=6, P2=5, 40=5, D4=7, 41=5, P4=5, P5=5,
38=7, P3=5, D5=21, CI=12, D2=5, D3=5, D1=65, P1=5, zzz=6
```

### A.2 Prueba de CSRF (HTTP real)

```
POST http://127.0.0.1:8012/index.php?p=saveData
  id=2&nombre=Vinculacion&descripcion=x&aulas=A   (sin csrf_token)

→ {"success":false,"message":"Token CSRF inválido..."}
→ HTTP 403
```

### A.3 Prueba de `rename()` en Windows / PHP 8.4.7

```
php -r "var_dump(rename('.../a.txt', '.../b.txt'));"
→ bool(true)
contenido de b.txt: viejo   (sobrescritura correcta)
```

### A.4 Búsqueda de conversión a rectángulo

```
grep -i "rectangle|Rectangle" *.js *.php *.html   → 0 coincidencias
grep "getSouthWest|getNorthEast|latLngBounds"     → 0 coincidencias
getBounds()  → js/main.js:762 (fitBounds), js/editor.js:361 (test de borrado)
L.polygon()  → js/editor.js:217, :381 (con todos los vértices)
```

---

## Anexo B — Trazabilidad problema → causa → tarea

| Problemática | Causas raíz | Tareas de corrección |
|---|---|---|
| 1. CRUD | RC-1, RC-2, RC-3, RC-4, RC-5, RC-6, RC-13, RC-14 | A1, A2, A3, A4, A5, A6, A7, B6 |
| 2. Geometrías no visibles al crear | RC-7, RC-12 | B1, B5 |
| 3. Error `enable()` | RC-8, RC-15 | B2 |
| 4. Cierre prematuro | RC-9, RC-10, RC-11 | B3, B4 |
| 5. Rectángulo | Sin causa confirmada (posible RC-9/RC-10/estilos) | B7 (verificación), B2/B3/B4 |

---

## 13. Estado de implementación (ejecutado)

> Sección añadida tras aprobar y ejecutar el plan. Describe qué quedó implementado,
> con qué criterio y qué queda pendiente.

### 13.1 Regla de oro: `PHP/public/` está obsoleto

- Ningún endpoint nuevo lee ni escribe en `PHP/public/`.
- Persistencia real: `datos.json`, `GeoTec.js` e `img/<id>/` en la raíz del proyecto.
- Endpoints en la raíz: `saveGeometria.php`, `deleteEdificio.php`, `check-session.php`,
  `send-suggestion.php`, `mapa.php`; router `index.php`.
- `PHP/public/datos.json` (48 claves) y `PHP/public/img/` quedan como copias históricas:
  **no se actualizan y no se borraron**.
- Verificación: tras un `saveData` real, `PHP/public/datos.json` conservó su `mtime`.

### 13.2 Modelo de datos: edificios y accesos (P1–P6)

Las seis geometrías P1–P6 **no eran huérfanas**: son los accesos (puertas) del campus.

- Nuevo campo `tipo` en `datos.json`:
  - `"edificio"` — valor por defecto (también cuando el campo falta).
  - `"acceso"` — puertas P1–P6.
- Se añadieron las 6 entradas a `datos.json` con `tipo: "acceso"` e `imagenes: []`.
  Resultado: **58 claves en `datos.json` y 58 features en `GeoTec.js`**, sin huérfanas.
- `BuildingService::normalizarTipo()` sanea el valor: cualquier cosa distinta de `acceso`
  cae en `edificio`.
- El panel muestra el distintivo `Acceso` (`.tipo-badge.tipo-acceso`) en vez del conteo
  de aulas.
- El editor de geometrías incluye un `select` de tipo, para poder reclasificar registros.
- El mapa público toma el nombre del acceso desde `datos.json`
  (`obtenerNombrePuerta()` + `sincronizarNombresAccesos()`), de modo que renombrar una
  puerta en el panel se refleja en `#doors-select`.

### 13.3 CRUD y persistencia (Grupo A)

| Cambio | Archivo |
|---|---|
| `tipo` al crear/actualizar; merge que preserva `imagenes`/`tipo` | `PHP/services/BuildingService.php` |
| `actualizarMetadatos()` para el panel (merge por lista blanca) | `PHP/services/BuildingService.php` |
| Borrado: geometría primero y rollback si falla `datos.json` | `PHP/services/BuildingService.php` |
| Reescritura completa: rutas raíz, CSRF, imágenes a `img/<id>/`, borrado confinado, `imagenes` resincronizado desde disco, errores JSON | `PHP/admin/saveEdificio.php` |
| Listado de imágenes desde `img/<folder>/` de la raíz | `PHP/admin/imagelist.php` |
| CSRF en el formulario de metadatos, borrado vía `fetch` + confirmación, flash vía `sessionStorage`, badge de tipo, `APP_DEBUG` | `PHP/admin/panel.php` |
| `tipo` validado y pasado al servicio; ID validado con `[A-Za-z0-9_-]+` | `saveGeometria.php` |

Decisión: el ID es de solo lectura en modo edición (no se soporta renombrar), y se
eliminó el `input` duplicado de `id`.

### 13.4 Editor de geometrías (Grupo B)

- **Capa de referencia** (problema 2): `L.geoJSON(..., { interactive: false, filter })`.
  Las geometrías existentes se ven, pero no reciben clics. El edificio en edición se
  excluye del filtro para no dibujarlo dos veces. Leyenda visible en el mapa.
- **Edición de vértices** (problema 3): se usa el plugin adecuado, **Leaflet-Geoman
  2.20.6 vendorizado** en `js/vendor/leaflet-geoman/` y `css/vendor/leaflet-geoman/`
  (el CDN estaba bloqueado por la *Tracking Prevention* del navegador). La API correcta
  es `polygon.pm.enable()` / `.disable()`, no `.editing`.
  - `js/editor.js` solo sincroniza el GeoJSON al input en
    `pm:markerdrag`, `pm:markerdragend`, `pm:vertexadded`, `pm:vertexremoved`, `pm:edit`.
  - Si el plugin no carga, se muestra un mensaje claro en vez de lanzar un `TypeError`.
- **Tolerancia de cierre** (problema 4): constante en **píxeles**
  (`CLOSE_DISTANCE_PX = 10`), calculada con `latLngToContainerPoint()`, más
  `MIN_VERTEX_DISTANCE_PX = 2` para descartar vértices duplicados del doble clic.
- **Borrado**: clic sobre el polígono con *ray casting* (no sobre el bounding box), para
  no borrar al hacer clic en el "hueco" de una geometría irregular.
- **Preservación** (problema 5): no se toca el orden ni el número de vértices; el
  GeoJSON se serializa con `toGeoJSON()`.
- El dibujo manual se mantiene (no se usa el modo dibujo de Geoman).

### 13.5 Verificaciones ejecutadas

Entorno: `php -S 127.0.0.1:8013`, login `admin` / `123456`.

- `php -l` OK en los 8 PHP tocados; `node --check` OK en `js/editor.js` y `js/main.js`.
- `datos.json` válido, 58 claves; `GeoTec.js` 58 features; sin IDs duplicados.
- `saveData` con CSRF → `{"success":true}` y escritura en la raíz (`PHP/public` intacto).
- Alta de edificio de prueba → 59/59; geometría de 7 coords preservada exactamente;
  borrado → 58/58, sin duplicados y con P1–P6 intactos.
- Panel de P1: CSRF presente, badge `Acceso`, nombre completo de la puerta.
- Editor (`accion=nuevo`): `L.PM` cargado, 58 rutas de referencia, **0** con
  `leaflet-interactive`, leyenda presente.
- Editor (`accion=editar&id=zzz`): 1 polígono editable + 57 de referencia, sin errores
  de consola; arrastre sintético de un vértice actualizó el `geometry-input` y conservó
  los 6 puntos.
- Dibujo manual: 4 clics separados no cierran; un 5.º clic a ~5 px del primero cierra
  con 4 vértices (5 coords). Mensajes de estado correctos.
- Mapa público: 58 labels, 58 claves de metadatos, `obtenerNombrePuerta('P1')` y la
  opción del selector devuelven "Puerta 1 (Norte - Gestión)". Los únicos errores de
  consola son 404 de teselas preexistentes (`Teselas ITL Satelital//...`).

### 13.6 Pendiente / no reproducible

- **Problemática 5 ("el polígono se vuelve cuadrado")**: sigue **NO REPRODUCIBLE** tras
  la corrección. No se encontró ninguna conversión a rectángulo en el código (ver
  Anexo A.4). Se mantiene la instrumentación descrita en 9.4 para el caso de que el
  usuario pueda reproducirlo con una secuencia concreta.
- Documentación actualizada: `AGENTS.md` (rutas raíz, fin de `PHP/public`, Geoman
  vendorizado, modelo `tipo`, `APP_DEBUG`) y esta sección.

---

**Fin del documento.** Plan ejecutado; los cambios están en local y sin commit.
