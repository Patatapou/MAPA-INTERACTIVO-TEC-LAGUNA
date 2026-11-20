# Hallazgos relevantes — Módulo de visualización y gestión de rutas

> **Documento histórico.** Describe el estado previo a la reestructuración: las rutas `PHP/…`, `index.html` en la raíz y los endpoints sueltos ya no existen. Para la estructura y las rutas vigentes, ver `AGENTS.md` y `docs/plans/PLAN_REESTRUCTURACION_PROYECTO.md`.

> **Estado:** documento de análisis. No implementa cambios.
> **Fecha:** 2026-11-14
> **Rama analizada:** `RaMaik` (árbol limpio en el momento del análisis).
> **Alcance:** mapa público, red de caminos, cálculo de recorridos, panel administrativo, autenticación, persistencia JSON y documentación existente.
> **Documento derivado:** `docs/plans/PLAN_MODULO_GESTION_RUTAS.md`.

---

## 1. Método

1. Lectura de `AGENTS.md`, `README.md`, `uso.md` y la documentación de `docs/`.
2. Inspección del código real: `index.html`, `index.php`, `mapa.php`, `js/main.js`, `js/pathfinding.js`, `js/beacons-simulation.js`, `js/editor.js`, `PHP/`.
3. Análisis cuantitativo de `caminitos.geojson` y del grafo construido por `buildGraph()`.
4. Ejecución de `buildGraph()` y `dijkstra()` en Node sobre los archivos existentes para medir nodos, aristas, componentes y alcanzabilidad.
5. Revisión de autenticación, CSRF, rate limiting, repositorios, servicios, panel y editor de geometrías.
6. Contraste de la documentación con el código (la documentación no se tomó como evidencia de implementación).

No se modificó código, datos, dependencias ni configuración.

---

## 2. Resumen de hallazgos críticos

| # | Hallazgo | Impacto |
|---|----------|---------|
| H-01 | La red de caminos es **un solo feature `MultiLineString`** con 235 partes y 603 vértices, sin IDs de segmento. Por extremos exactos forma **31 componentes**. | No existe una entidad estable que una ruta administrativa pueda referenciar, y la conectividad no es explícita. |
| H-02 | El grafo de Dijkstra **se deriva en tiempo de ejecución** y no se persiste. | Los nodos no tienen identidad persistente; cualquier edición de geometría cambia las claves. |
| H-03 | **No existe CRUD de rutas**: solo cálculo efímero y dibujo de una polilínea. | Las dos tareas del sprint requieren desarrollo nuevo, no una corrección. |
| H-04 | `findSnappedNode()` devuelve el **primer** nodo dentro de 2,5 m, no el más cercano. | Fusiones arbitrarias de vértices en zonas densas. |
| H-05 | El cálculo de ruta **añade segmentos rectos** desde el origen/destino hasta la red, sin límite de distancia. | La distancia mostrada incluye tramos fuera de la red; 3 edificios quedan a más de 50 m del nodo más cercano. |
| H-06 | `BaseRepository::leer()` devuelve `[]` ante JSON corrupto. | Si un repositorio de rutas hereda sin sobrescribir, un archivo corrupto se sobrescribe y se pierden todas las rutas. |
| H-07 | La lectura-modificación-escritura de `BaseRepository` no es atómica entre procesos. | Dos guardados simultáneos pueden perder una actualización. |
| H-08 | El mapa público inserta datos de `datos.json` con `innerHTML`. | Riesgo de XSS almacenado si el contenido administrado no se escapa. |
| H-09 | `requerirAdmin()` solo valida un booleano de sesión; no revalida rol ni estado del usuario. | No hay permisos diferenciados ni revocación inmediata. |
| H-10 | El router no tiene `?p=rutas` ni `?p=mapa`, y `404.php` no existe. | El módulo necesita rutas nuevas y el fallback del router está incompleto. |
| H-11 | `mapa.php` es legacy y contiene errores reales (`resultado` indefinido, `seleccionarSugerencias` inexistente, `getimages` protegido). | No debe tomarse como base del módulo; el mapa público real es `index.html`. |
| H-12 | No hay pruebas, CI ni linter. | La validación del módulo debe ser manual y con scripts reproducibles. |
| H-13 | `PHP/data/` está dentro del docroot del servidor embebido y no hay router que lo bloquee. | `users.json`, `audit-log.jsonl`, `suggestions.json` y el futuro `rutas.json` podrían descargarse por HTTP. |

> El detalle y la evidencia de cada hallazgo se desarrollan en las secciones siguientes.

---

## 3. Funcionamiento actual de las rutas

### 3.1. Representación de la red

`caminitos.geojson` es una `FeatureCollection` con **1 feature**:

| Propiedad | Valor |
|---|---|
| `type` | `FeatureCollection` |
| `name` | `caminitos2` |
| `features` | 1 |
| `geometry.type` | `MultiLineString` |
| Partes (`LineString`) | **235** |
| Vértices totales | **603** |
| `properties` | `{ "id": null }` |
| CRS | `urn:ogc:def:crs:OGC:1.3:CRS84` |

Conclusiones:

- **No hay segmentos independientes con identidad.** Hay una sola geometría compuesta.
- **No hay metadatos por camino**: ni nombre, ni tipo, ni accesibilidad, ni sentido, ni estado.
- **No hay nodos explícitos.** Los nodos se infieren de los vértices y de la tolerancia de snapping.
- El archivo se consume completo en `js/main.js:120-137` y se pasa a `buildGraph()`.

### 3.2. Construcción del grafo

`js/pathfinding.js:55-143` implementa `buildGraph(geojsonPaths, snappingToleranceMeters = 2.5)`:

1. Recorre cada feature y cada `LineString`/`MultiLineString`.
2. Por cada par consecutivo de coordenadas crea un **segmento crudo**.
3. Convierte cada extremo en una clave `"lat,lng"` con `toFixed(6)`, reutilizando una clave existente si está a ≤2,5 m (`findSnappedNode`).
4. Por cada segmento crudo, proyecta todos los nodos sobre él (`projectPointOnSegment`) y parte el segmento en los puntos de proyección dentro de la tolerancia.
5. Conecta los puntos de corte adyacentes como aristas bidireccionales con peso de distancia Haversine.

Resultado medido sobre el archivo actual:

| Métrica | Valor |
|---|---|
| Nodos del grafo | **238** |
| Entradas dirigidas en el grafo | 618 |
| Aristas únicas no dirigidas | **309** |
| Distribución de grado | grado 1: 19, grado 2: 73, grado 3: 131, grado 4: 15 |
| Componentes conexas | **1** |
| Nodos aislados | 0 |
| Rutas puerta→edificio alcanzables | **348/348** |
| Peores distancias edificio→nodo | D1 76 m, Z 59 m, D6 57 m |
| Edificios con snapping >50 m | 3 |
| Edificios con snapping >100 m | 0 |

Esto confirma que **la red actual es funcional y totalmente conexa** para el universo de puertas y edificios. Es una base sólida para el módulo; no es necesario reconstruirla.

### 3.2.1. La conectividad depende del snapping, no de los extremos

Se midió la conectividad usando **solo extremos exactos** de las 235 partes, sin proyección interior ni tolerancia:

| Métrica | Valor medido |
|---|---|
| Extremos exactos únicos | 241 |
| Claves de extremo compartidas por ≥2 partes | 69 |
| Componentes solo por extremos exactos | **31** |
| Tamaños de las mayores componentes | 61, 60, 43, 10, 6, 5, 5, 5 |
| Pares de extremos a ≤0,5 m sin coincidencia exacta | 63 |
| Pares de partes conectadas por proyección interior ≤2,5 m | **149** |
| Partes que deben dividirse para hacer explícita esa conectividad | **69** |
| Grado de nodos por extremos exactos | `{1:172, 3:2, 4:66, 5:1}` |

**Conclusión:** el grafo es conexo porque `buildGraph()` proyecta vértices sobre el interior de otros segmentos y fusiona a 2,5 m. La geometría, por sí sola, está fragmentada en 31 piezas. Cualquier módulo que valide rutas "por extremos compartidos" **necesita normalizar antes la red**, o deberá aceptar que solo una parte de los recorridos que Dijkstra resuelve sean representables como ruta administrativa.

**Normalización aplicada como prueba de concepto (no escrita en disco):**

1. Tomar los 238 nodos canónicos del grafo actual.
2. Asignar cada vértice original al nodo canónico más cercano dentro de 2,5 m.
3. Insertar un corte en cada nodo canónico que proyecte sobre cada parte dentro de 2,5 m.
4. Partir la polilínea en esos puntos, usando la coordenada canónica del nodo.
5. Descartar sub-tramos de longitud <0,05 m.

Resultado medido:

| Métrica | Antes | Después de normalizar |
|---|---|---|
| Features | 1 (`MultiLineString`) | **314** (`LineString`) |
| Partes / polilíneas | 235 | 314 |
| Vértices | 603 | **646** |
| Partes divididas | — | 69 |
| Máximo de sub-tramos por parte | — | 7 |
| Nodos | 238 (por snapping) | **238** (por extremos exactos) |
| Aristas únicas | 309 | **309** |
| Componentes | 1 (por snapping) | **1** (por extremos exactos) |
| Distribución de grado | 1:19, 2:73, 3:131, 4:15 | **1:19, 2:73, 3:131, 4:15** |

La normalización **preserva exactamente la huella del grafo** (238/309/1 y la misma distribución de grado) y hace que la conectividad sea explícita. El coste es un desplazamiento de hasta 2,5 m en los extremos afectados, el mismo grado de idealización que ya aplica Dijkstra al calcular.

### 3.3. Identidad de nodos y aristas

- La clave de nodo es la coordenada con 6 decimales (`js/pathfinding.js:87,92`), por ejemplo `25.532701,-103.436157`.
- **No hay un ID de nodo independiente de la coordenada.** Si la geometría se mueve, el nodo cambia de clave.
- Las aristas del grafo son números (distancia en metros), no objetos con metadatos (`js/pathfinding.js:137-138`).
- No existe una relación explícita entre arista y segmento de origen. Si dos segmentos se solapan, el grafo los fusiona por snapping.

### 3.4. Cálculo del recorrido

Flujo actual en `js/main.js`:

1. El usuario pulsa "Cómo llegar" en el popup de un edificio → `iniciarRuta(destId)` (`main.js:388-431`).
2. El origen se toma de la puerta seleccionada (`selectedDoorId`) o de la ubicación GPS/simulada.
3. `calcularRuta()` (`main.js:433-511`):
   - Obtiene el centro del `bounds` del edificio destino.
   - `findClosestNode()` para origen y destino (`main.js:449-450`).
   - `dijkstra()` (`main.js:458`).
   - **Antepone el origen exacto y añade el destino exacto** a la ruta (`main.js:466-467`), creando tramos rectos fuera de la red.
   - Dibuja una `L.polyline` (`main.js:473-480`), ajusta el zoom y muestra un panel con la distancia (`main.js:513-536`).
4. `limpiarRuta()` elimina la polilínea, los marcadores y limpia el selector (`main.js:538-561`).

### 3.5. Limitaciones del cálculo actual

| Limitación | Evidencia |
|---|---|
| Dijkstra no dirigido | `graph[k1][k2]` y `graph[k2][k1]` con el mismo peso (`pathfinding.js:137-138`). |
| Pesos uniformes (solo distancia) | `distanciaMetros()` como único peso (`pathfinding.js:132`). |
| Sin accesibilidad, tipo de camino, pendiente ni restricciones | No existen campos en la geometría ni en el grafo. |
| Sin límite máximo de snapping | `findClosestNode()` recorre todo el grafo y devuelve el mínimo (`pathfinding.js:146-159`); `calcularRuta` no valida umbral. |
| Sin detección de intersecciones reales | Solo se crean nodos si un vértice cae a ≤2,5 m de la proyección; no se calculan cruces entre segmentos. |
| `findSnappedNode` no elige el más cercano | Devuelve el primero que cumple la tolerancia (`pathfinding.js:22-31`). |
| Cola de prioridad por ordenación completa | `PriorityQueue.enqueue()` ordena todo el arreglo en cada inserción (`pathfinding.js:6-15`). Suficiente para 238 nodos, no escala. |
| Grafo construido una sola vez | `pathGraph = buildGraph(data)` al cargar (`main.js:134`); no hay API de reconstrucción. |
| Dependencia implícita de `distanciaMetros` | `pathfinding.js` usa `distanciaMetros` pero no la define; proviene de `js/beacons-simulation.js`. Ejecutar `pathfinding.js` aislado lanza `ReferenceError`. |
| Distancia mostrada inconsistente | `calcularDistanciaRuta(path)` incluye los tramos rectos añadidos (`main.js:504`), mientras Dijkstra solo mide la red. |

### 3.6. Visualización actual

- La red se dibuja con `L.geoJSON` en color `#ffffff00` (transparente) y grosor 4 (`main.js:124-131`). El cambio local documentado en `AGENTS.md` ya está aplicado y el árbol está limpio.
- La ruta activa se dibuja como polilínea azul con clase `animated-route-line` (`main.js:473-480`).
- El panel de indicaciones muestra origen, destino y distancia (`main.js:513-536`).
- El marcador de destino está comentado (`main.js:495-501`); solo se usa el marcador de partida.
- No hay nombres de ruta, listado, selección ni resaltado persistente.

---

## 4. Diagnóstico de funcionalidades

Categorías: **IMPLEMENTADO**, **PARCIALMENTE IMPLEMENTADO**, **NO IMPLEMENTADO**, **REQUIERE INVESTIGACIÓN**.

### 4.1. Implementado

| Funcionalidad | Evidencia | Notas |
|---|---|---|
| Mapa Leaflet con teselas locales | `js/main.js:9-18` | Leaflet 1.9.4 desde CDN; teselas en `Teselas ITL Satelital/`. |
| Render de edificios y accesos | `js/main.js:76-117`, `GeoTec.js`, `datos.json` | 58 features y 58 metadatos. |
| Popup de edificio | `js/main.js:318-380` | Nombre, descripción, aulas, imágenes, botón "Cómo llegar". |
| Cálculo de ruta con Dijkstra | `js/pathfinding.js:162-208`, `js/main.js:433-511` | Funciona para 348/348 combinaciones puerta→edificio. |
| Selección de puerta P1–P6 | `js/main.js:607-661`, `datos.json` | Nombres sincronizados desde `datos.json`. |
| Indicaciones y distancia | `js/main.js:513-536` | Solo origen, destino y distancia total. |
| Limpiar recorrido | `js/main.js:538-561` | Elimina capa y estado. |
| Autenticación de administrador | `PHP/admin/login.php`, `PHP/services/AuthService.php` | `password_hash`/`password_verify`, `session_regenerate_id(true)`. |
| CSRF | `PHP/auth/csrf.php`, `PHP/auth/auth_middleware.php` | Token en sesión, validación con `hash_equals`. |
| Rate limiting de login | `PHP/auth/rate_limit.php` | 5 intentos por hora por sesión. |
| Persistencia JSON atómica | `PHP/repositories/BaseRepository.php` | `flock` + archivo temporal + `rename`. |
| CRUD de edificios | `PHP/services/BuildingService.php`, `saveGeometria.php`, `deleteEdificio.php` | Metadatos + geometría con rollback. |
| Editor de geometrías con Geoman | `PHP/admin/editar-geometria.php`, `js/editor.js` | Dibujo, edición de vértices, capa de referencia. |
| Buscador de edificios en panel | `PHP/admin/panel.php:64-67,494-503` | Filtrado client-side. |
| Auditoría | `PHP/repositories/AuditLogRepository.php` | JSONL, usable por el módulo. |
| Panel protegido | `index.php:13-16`, `PHP/auth/auth_middleware.php` | `requerirAdmin()` en rutas administrativas. |

### 4.2. Parcialmente implementado

| Funcionalidad | Qué existe | Qué falta | Evidencia |
|---|---|---|---|
| Visualización de rutas | Polilínea efímera de un recorrido calculado | Entidad "ruta", listado, selección, resaltado persistente, relación con edificios | `main.js:433-536` |
| Gestión de rutas | Nada administrativo | CRUD, formularios, permisos específicos, auditoría de rutas | — |
| Red de caminos | Geometría completa y grafo funcional | Segmentos con ID, metadatos, edición, validación topológica | `caminitos.geojson`, `pathfinding.js` |
| Selección de origen/destino | Puertas y edificios | Nodos arbitrarios, coordenadas, rutas entre edificios | `main.js:388-431` |
| Persistencia documental | Repositorios genéricos y de edificios | Repositorio de rutas, validación de esquema, fail-closed | `BaseRepository.php` |
| Auditoría | Repositorio y eventos de edificios | Eventos de rutas y de red | `AuditLogRepository.php` |
| Búsqueda | Edificios y aulas | Búsqueda de rutas | `main.js:709-781`, `panel.php` |
| Autenticación por roles | Rol `administrador` | Roles Master/Operador, autorización granular | `PHP/data/users.json`, `auth_middleware.php:33` |
| Documentación | Planes y arquitectura | Estado real, plan de rutas, criterios de aceptación | `docs/` |

### 4.3. No implementado

| Funcionalidad | Estado |
|---|---|
| Entidad persistente de ruta | No existe archivo, repositorio ni servicio. |
| Alta de ruta desde el panel | No existe vista ni endpoint. |
| Edición de ruta | No existe. |
| Eliminación de ruta | No existe. |
| Listado, búsqueda y filtros de rutas | No existe. |
| Detalle de ruta (segmentos, nodos, edificios) | No existe. |
| Validación de cadenas de segmentos | No existe. |
| Referencias ruta↔segmento | No existe. |
| CRUD de segmentos de red | No existe. |
| Detección de intersecciones / división de segmentos | No existe. |
| Validación de conectividad en servidor | No existe. |
| Exportación pública de rutas | No existe. |
| Pruebas automatizadas | No existe infraestructura de tests. |

### 4.4. Requiere investigación

| Tema | Motivo |
|---|---|
| Uso real de `mapa.php` | Parece legacy, pero sigue accesible sin `?p=`; hay que confirmar si algún usuario lo usa. |
| Roles Master/Operador | No existen en `users.json`; no hay evidencia de requisitos. |
| Publicación de rutas en el mapa público | No hay requisito explícito ni mecanismo de exposición de `PHP/data/`. |
| Edición de la red desde el panel | El sprint pide rutas, no red; confirmar alcance. |
| Política de eliminación (física vs lógica) | No hay precedente para rutas. |
| Conectividad por extremos vs proyección interior | **Ya medido (3.2.1):** 31 componentes por extremos, 149 pares interiores, 69 partes a dividir. Queda por decidir si se aprueba la normalización (D1). |

---

## 5. Hallazgos de arquitectura y datos

### H-01. Red sin identidad de segmento

**Evidencia:** `caminitos.geojson` tiene 1 feature `MultiLineString`, 235 partes, `properties.id = null`.

**Consecuencia:** una ruta administrativa no puede referenciar "el segmento X". Cualquier referencia por índice de parte es frágil: reordenar o editar el archivo cambia los índices.

**Consecuencia adicional (medida en 3.2.1):** la red tampoco es conexa por extremos. Convertir cada parte en un feature sin más produce **31 componentes**; **149 pares de partes** dependen de proyección interior ≤2,5 m y **69 partes** deben dividirse. La migración de formato es, en realidad, una **normalización topológica**: da como resultado **314 features**, 646 vértices, 238 nodos, 309 aristas y 1 componente, con la misma distribución de grado.

**Opciones:**

1. Normalizar la topología y migrar a **314 features `LineString`** con `properties.id` estable (recomendada).
2. Migrar solo el formato a 235 features sin normalizar: deja 31 componentes y hace inviable la validación por extremos.
3. Mantener el `MultiLineString` y usar un archivo auxiliar índice→ID (frágil).
4. Guardar rutas como secuencias de coordenadas de nodos (duplica datos y no detecta cambios de red).

### H-02. Grafo derivado, no persistido

**Evidencia:** `buildGraph()` se ejecuta al cargar (`main.js:134`); no hay archivo de nodos/aristas.

**Consecuencia:** los nodos no tienen ID estable; la identidad es la coordenada. Es aceptable para Dijkstra, pero obliga a que las rutas referencien segmentos, no nodos.

### H-03. Ausencia total de CRUD de rutas

**Evidencia:** no hay `rutas.json`, `RouteRepository`, `RouteService`, `?p=rutas` ni formulario. `activeRouteLine` es una variable de sesión de página (`main.js:42`).

**Conclusión:** la tarea es de desarrollo nuevo sobre infraestructura existente, no de corrección.

### H-04. Snapping arbitrario

**Evidencia:** `findSnappedNode()` recorre `existingNodes` en orden y retorna el primero dentro de 2,5 m (`pathfinding.js:22-31`). No compara distancias.

**Consecuencia:** en zonas con varios vértices próximos, un extremo puede unirse a un nodo que no es el más cercano. En la red actual no rompe la conectividad (1 componente), pero es un riesgo al editar.

**Mitigación propuesta:** en el editor administrativo, exigir coincidencia exacta de extremos (o snapping al más cercano con tolerancia ≤1 m) y no depender del orden de inserción.

### H-05. Tramos rectos fuera de la red

**Evidencia:** `path.unshift(origCoors)` y `path.push(destCoors)` (`main.js:466-467`).

**Consecuencia:** el recorrido dibujado puede cruzar edificios o zonas no peatonales; la distancia incluye esos tramos. Los peores casos medidos: D1 76 m, Z 59 m, D6 57 m.

**Mitigación propuesta:** en el módulo administrativo, mostrar la distancia de red y la distancia total por separado; marcar el tramo de conexión como "acceso" y advertir si supera un umbral (p. ej. 40 m).

### H-06. `BaseRepository` falla abierto

**Evidencia:** `leer()` devuelve `[]` si `json_decode` falla (`BaseRepository.php:35-38`). `BuildingGeometryRepository` evita el problema lanzando excepción (`BuildingGeometryRepository.php:52-66`).

**Consecuencia para rutas:** si `rutas.json` se corrompe y `RouteRepository` no sobrescribe `leer()`, el siguiente guardado escribiría solo la ruta nueva y borraría todas las demás.

**Requisito:** el repositorio de rutas debe fallar cerrado (excepción) y nunca devolver una colección vacía ante un archivo existente pero inválido.

### H-07. Condición de carrera en lectura-modificación-escritura

**Evidencia:** `leer()` y `escribir()` son operaciones separadas; `flock` solo protege la escritura del archivo temporal (`BaseRepository.php:49-82`).

**Consecuencia:** dos administradores que guardan a la vez pueden perder una actualización.

**Mitigación propuesta:** bloqueo por archivo durante todo el ciclo (leer-modificar-escribir) o un helper `actualizar(callable)` en el repositorio; registrar `updated_at` y detectar conflicto optimista.

### H-08. XSS almacenado en el mapa público

**Evidencia:** `main.js:349-353` inserta `datos.nombre`, `datos.descripcion` y `datos.aulas` en `innerHTML`; `main.js:522-530` inserta origen/destino; `main.js:756-759` inserta resultados de búsqueda.

**Consecuencia:** un valor malicioso en `datos.json` (por ejemplo, si se compromete una cuenta de administrador) se ejecuta en el navegador de cualquier visitante.

**Relación con el módulo:** el módulo de rutas debe escapar nombres/descripciones al renderizar y no introducir nuevos `innerHTML` con datos administrados. La corrección del mapa público queda como tarea relacionada, fuera del alcance de este plan.

### H-09. Autorización gruesa

**Evidencia:** `requerirAdmin()` comprueba `$_SESSION['admin'] === true` (`auth_middleware.php:33`); no consulta `users.json` ni el rol/estado. `users.json` solo tiene un usuario con `role: "administrador"` y `status: "activo"`.

**Consecuencia:** no se pueden implementar permisos diferenciados Master/Administrador/Operador sin ampliar el modelo. Desactivar un usuario no invalida su sesión activa.

**Decisión pendiente:** mantener un solo rol para el módulo (recomendado para el MVP) o extender roles antes de implementar.

### H-10. Router incompleto

**Evidencia:** `index.php:10-46` no contempla `?p=mapa` ni `?p=rutas`; el `else` apunta a `404.php`, que no existe (`index.php:45,51-53`).

**Consecuencia:** hay que añadir rutas explícitas para el módulo y, opcionalmente, crear `404.php`.

### H-11. `mapa.php` legacy con errores

**Evidencia:**
- `mapa.php:162` usa `resultado[0]` (variable no definida; el arreglo se llama `resultados`).
- `mapa.php:162` llama a `seleccionarSugerencias()`, que no está definida.
- `mapa.php:96` llama a `index.php?p=getimages`, ruta protegida por `requerirAdmin()` (`index.php:24-27`).

**Conclusión:** no usar `mapa.php` como base. El mapa público real es `index.html`.

### H-12. Sin pruebas ni CI

**Evidencia:** no hay `package.json`, `composer.json`, `phpunit.xml`, `.github/workflows` ni scripts de test.

**Consecuencia:** la validación debe ser manual + scripts reproducibles de Node/PHP. El plan debe incluir una "huella del grafo" para detectar regresiones.

### H-13. `PHP/data/` expuesto por HTTP en el servidor local

**Evidencia:** `start-server.ps1:20` ejecuta `php -S "127.0.0.1:8000"` sin router. El servidor embebido de PHP sirve directamente cualquier archivo existente bajo el directorio de trabajo. `PHP/data/users.json`, `PHP/data/audit-log.jsonl`, `PHP/data/suggestions.json` y el futuro `PHP/data/rutas.json` están bajo ese directorio.

**Consecuencia:** un visitante local (o cualquier proceso con acceso a `127.0.0.1:8000`) puede solicitar `http://127.0.0.1:8000/PHP/data/users.json` y descargar hashes de contraseñas, correos y auditoría. `AGENTS.md` advierte que no debe exponerse en producción, pero el servidor de desarrollo no lo impide.

**Mitigación propuesta (no implementada):** usar un router de desarrollo que deniegue `/PHP/data/`, mover los datos fuera del docroot, o aplicar reglas de servidor en producción. El módulo de rutas **no debe** añadir más datos sensibles bajo `PHP/data/` sin resolver antes este punto.

---

## 6. Hallazgos de persistencia y operación

| Tema | Hallazgo | Evidencia |
|---|---|---|
| Estado inicial | `PHP/data/` está ignorado por Git y no existe en un clon limpio; `users.json` no se versiona. | `.gitignore`, `AGENTS.md` |
| Escritura atómica | `BaseRepository` usa `flock` + temporal + `rename`, pero sin `fsync` ni bloqueo de lectura. | `BaseRepository.php:49-82` |
| Validación | Los repositorios no validan esquema; los servicios validan geometría de edificios. | `BuildingService.php:452-481` |
| Rollback | `BuildingService` intenta revertir `GeoTec.js` si falla `datos.json`. | `BuildingService.php:77-110,213-250` |
| Auditoría | JSONL con `flock` en modo append; no participa de transacciones. | `AuditLogRepository.php:20-44` |
| Recuperación | `BuildingGeometryRepository` crea `.bak` antes de escribir. | `BuildingGeometryRepository.php:96-99` |
| Límites PHP | `upload_max_filesize=2M`, `post_max_size=8M`, `session.gc_maxlifetime=1440`. | `php -i` |
| Sesión | Cookie de 2 h, pero GC del servidor a 24 min; la sesión puede caducar antes. | `auth_middleware.php:21-30` |
| Exposición HTTP | `PHP/data/` está bajo el docroot y no hay router que lo bloquee. | `start-server.ps1:20`, H-13 |

**Conclusión:** el módulo de rutas debe reutilizar `BaseRepository`, pero con un repositorio propio que:
1. falle cerrado ante corrupción,
2. bloquee el ciclo completo de actualización,
3. haga respaldo antes de sobrescribir,
4. valide el documento antes y después de escribir,
5. mantenga `schema_version` para migraciones futuras.

---

## 7. Hallazgos de documentación

- Varios planes indican "Estado: Planificación (sin implementar)" aunque el código correspondiente ya existe (autenticación, persistencia, módulo de edificios). La documentación no es evidencia fiable del estado.
- `AGENTS.md` afirma que hay un cambio local no committeado en `js/main.js` (`#ffffff` → `#ffffff00`); el árbol está limpio y el cambio ya está en el código (`main.js:126`).
- `docs/architecture/ARQUITECTURA_PERSISTENCIA_JSON.md` describe una estructura con `PHP/public/` que `AGENTS.md` marca como obsoleta.
- No existe documentación del sistema de rutas ni de `caminitos.geojson`.

**Recomendación:** al aprobar e implementar el módulo, actualizar `AGENTS.md` y los planes con estado real y enlaces a este análisis.

---

## 8. Conclusión

La base técnica es favorable:

- La red actual es **conexa y funcional** para Dijkstra (238 nodos, 309 aristas, 1 componente por snapping, 348/348 rutas alcanzables).
- Existen **patrones reutilizables** de autenticación, CSRF, persistencia, servicios, panel, editor Leaflet y auditoría.
- Dijkstra funciona y no necesita reemplazarse para el MVP.

Pero hay un matiz decisivo: **esa conectividad depende del snapping de 2,5 m**, no de extremos compartidos. Por extremos exactos la red son 31 componentes. Para que un editor pueda construir rutas válidas sin ambigüedad, la red debe **normalizarse topológicamente** (314 features, 646 vértices, 1 componente), operación que preserva la huella del grafo.

Lo que falta es **la capa de gestión**: una entidad de ruta persistente, un formato de red con segmentos identificables, interfaces administrativas y validaciones de integridad.

El plan propone separar explícitamente:

1. **Red de caminos** (`caminitos.geojson`): geometría canónica y grafo para Dijkstra.
2. **Rutas administrativas** (`PHP/data/rutas.json`): entidades nombradas que referencian una secuencia ordenada y orientada de segmentos de la red.

Esta separación evita duplicar geometría, permite que varias rutas compartan segmentos y garantiza que eliminar una ruta no destruya la red ni afecte a otras rutas.
