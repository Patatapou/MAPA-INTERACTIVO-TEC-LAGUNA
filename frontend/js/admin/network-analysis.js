// ==========================================
// ANÁLISIS TOPOLÓGICO DE LA RED DE CAMINOS
// ==========================================
// Solo lo carga el editor de red (`backend/admin/editar-red.php`). No modifica
// `pathfinding.js`: reutiliza su `buildGraph()`, `findClosestNode()` y
// `dijkstra()` y añade el análisis que necesita la validación en vivo
// (E01–E08 / W01–W06 del plan de gestión de la red).
//
// Todo se prefija con `red` para no chocar con las funciones del mapa público.
// Requiere `js/shared/geo-utils.js` (define `distanciaMetros`) y
// `js/shared/pathfinding.js`.
//
// El cliente solo orienta al administrador: el servidor revalida todo con
// `NetworkService` antes de escribir. Las mismas reglas se aplican aquí para
// que el usuario vea el problema antes de guardar, no para sustituir al PHP.

(function (global) {
    'use strict';

    // Deben coincidir con NetworkService / NetworkGraph.
    var TOLERANCIA_SNAPPING_M = 2.5;
    var RADIO_CASI_CONECTADO_M = 8.0;
    var MAX_SEGMENTOS = 2000;
    var MAX_AVISOS_POR_TIPO = 20;
    var MARGEN_RECUADRO_M = 300.0;

    var PATRON_ID = /^seg-(?:[a-f0-9]{8}(?:-\d{2})?|\d{6})$/;
    var PATRON_TMP = /^tmp-[a-z0-9][a-z0-9-]{0,63}$/i;

    // Recuadro de reserva si la red no se puede leer (igual que el servicio).
    var RECUADRO_RESERVA = {
        minLat: 25.5280,
        maxLat: 25.5368,
        minLng: -103.4383,
        maxLng: -103.4333
    };

    // El editor llama a `redAnalizarRed()` en cada cambio, pero la red
    // persistida no cambia entre ediciones: se memoriza su alcanzabilidad.
    var memoriaVigente = typeof WeakMap === 'function' ? new WeakMap() : null;

    // ==========================================
    // UTILIDADES BÁSICAS
    // ==========================================

    /**
     * Clave de nodo con el MISMO formato que `buildGraph()`.
     * @param {number} lat
     * @param {number} lng
     * @returns {string}
     */
    function redClaveNodo(lat, lng) {
        return lat.toFixed(6) + ',' + lng.toFixed(6);
    }

    /**
     * @param {string} clave
     * @returns {{lat: number, lng: number}}
     */
    function redPuntoDeClave(clave) {
        var partes = String(clave).split(',');
        return { lat: Number(partes[0]), lng: Number(partes[1]) };
    }

    /**
     * Longitud de una polilínea en coordenadas GeoJSON `[[lng, lat], …]`.
     * @param {Array<Array<number>>} coords
     * @returns {number} metros
     */
    function redLongitud(coords) {
        if (!Array.isArray(coords) || coords.length < 2) {
            return 0;
        }
        var total = 0;
        for (var i = 0; i < coords.length - 1; i++) {
            var a = coords[i];
            var b = coords[i + 1];
            total += distanciaMetros(Number(a[1]), Number(a[0]), Number(b[1]), Number(b[0]));
        }
        return total;
    }

    /**
     * Proyección perpendicular sobre la arista u-v, en el plano local que usa
     * `pathfinding.js` (y su port PHP). Devuelve también el punto proyectado.
     *
     * @returns {{dist: number, t: number, lat: number, lng: number}}
     */
    function redProyectarEnSegmento(pLat, pLng, uLat, uLng, vLat, vLng) {
        var cos = Math.cos(uLat * Math.PI / 180);
        var dy = (vLat - uLat) * 111139;
        var dx = (vLng - uLng) * 111139 * cos;
        var py = (pLat - uLat) * 111139;
        var px = (pLng - uLng) * 111139 * cos;

        var lenSq = dx * dx + dy * dy;
        if (lenSq === 0) {
            return {
                dist: distanciaMetros(pLat, pLng, uLat, uLng),
                t: 0,
                lat: uLat,
                lng: uLng
            };
        }

        var t = (px * dx + py * dy) / lenSq;
        t = Math.max(0, Math.min(1, t));

        var projX = dx * t;
        var projY = dy * t;
        var dist = Math.sqrt(
            (px - projX) * (px - projX) + (py - projY) * (py - projY)
        );

        return {
            dist: dist,
            t: t,
            lat: uLat + (vLat - uLat) * t,
            lng: uLng + (vLng - uLng) * t
        };
    }

    /**
     * ¿El punto cae dentro del recuadro permitido?
     * @param {number} lat
     * @param {number} lng
     * @param {{minLat: number, maxLat: number, minLng: number, maxLng: number}} recuadro
     * @returns {boolean}
     */
    function redDentroDelRecuadro(lat, lng, recuadro) {
        if (!recuadro) {
            return true;
        }
        return lat >= recuadro.minLat && lat <= recuadro.maxLat
            && lng >= recuadro.minLng && lng <= recuadro.maxLng;
    }

    /**
     * Caja envolvente de la red + margen (equivale a
     * `NetworkService::recuadroValido()`).
     *
     * @param {Object|null} geojson
     * @param {number} [margenM]
     * @returns {{minLat: number, maxLat: number, minLng: number, maxLng: number}}
     */
    function redRecuadro(geojson, margenM) {
        var margen = typeof margenM === 'number' ? margenM : MARGEN_RECUADRO_M;
        var minLat = Infinity;
        var maxLat = -Infinity;
        var minLng = Infinity;
        var maxLng = -Infinity;

        var features = (geojson && Array.isArray(geojson.features)) ? geojson.features : [];
        features.forEach(function (feature) {
            var coords = feature && feature.geometry && feature.geometry.coordinates;
            if (!Array.isArray(coords)) {
                return;
            }
            coords.forEach(function (punto) {
                if (!Array.isArray(punto) || punto.length < 2) {
                    return;
                }
                var lat = Number(punto[1]);
                var lng = Number(punto[0]);
                if (!isFinite(lat) || !isFinite(lng)) {
                    return;
                }
                if (lat < minLat) { minLat = lat; }
                if (lat > maxLat) { maxLat = lat; }
                if (lng < minLng) { minLng = lng; }
                if (lng > maxLng) { maxLng = lng; }
            });
        });

        if (!isFinite(minLat) || maxLat <= minLat || maxLng <= minLng) {
            return {
                minLat: RECUADRO_RESERVA.minLat,
                maxLat: RECUADRO_RESERVA.maxLat,
                minLng: RECUADRO_RESERVA.minLng,
                maxLng: RECUADRO_RESERVA.maxLng
            };
        }

        var margenLat = margen / 111320.0;
        var cos = Math.cos(minLat * Math.PI / 180);
        var margenLng = margen / Math.max(1.0, 111320.0 * Math.abs(cos));

        return {
            minLat: minLat - margenLat,
            maxLat: maxLat + margenLat,
            minLng: minLng - margenLng,
            maxLng: maxLng + margenLng
        };
    }

    // ==========================================
    // GRAFO
    // ==========================================

    /**
     * Construir el grafo con el MISMO algoritmo del mapa público.
     * @param {Object} geojson
     * @returns {Object<string, Object<string, number>>}
     */
    function redGrafo(geojson) {
        if (typeof buildGraph !== 'function') {
            throw new Error('Falta js/shared/pathfinding.js: no se puede construir el grafo de la red.');
        }
        return buildGraph(geojson);
    }

    /**
     * Componentes conexas (DFS iterativo).
     * @param {Object<string, Object<string, number>>} grafo
     * @returns {{total: number, tamanos: number[], mayor: number, componentes: Array}}
     */
    function redComponentes(grafo) {
        var visitados = Object.create(null);
        var componentes = [];
        var claves = Object.keys(grafo);

        claves.forEach(function (inicio) {
            if (visitados[inicio]) {
                return;
            }
            var nodos = [];
            var pila = [inicio];
            visitados[inicio] = true;

            while (pila.length > 0) {
                var actual = pila.pop();
                nodos.push(actual);
                var vecinos = grafo[actual] || {};
                Object.keys(vecinos).forEach(function (vecino) {
                    if (!visitados[vecino]) {
                        visitados[vecino] = true;
                        pila.push(vecino);
                    }
                });
            }

            componentes.push({
                nodos: nodos,
                tamano: nodos.length,
                punto: redPuntoDeClave(nodos[0])
            });
        });

        var tamanos = componentes.map(function (c) { return c.tamano; });
        tamanos.sort(function (a, b) { return b - a; });

        return {
            total: componentes.length,
            tamanos: tamanos,
            mayor: tamanos.length > 0 ? tamanos[0] : 0,
            componentes: componentes
        };
    }

    /**
     * Nodos de grado 1.
     * @param {Object<string, Object<string, number>>} grafo
     * @returns {string[]}
     */
    function redExtremosLibres(grafo) {
        return Object.keys(grafo).filter(function (clave) {
            return Object.keys(grafo[clave] || {}).length === 1;
        });
    }

    /**
     * Aristas únicas con clave canónica `nodoA|nodoB`.
     * @param {Object<string, Object<string, number>>} grafo
     * @returns {Object<string, {a: string, b: string, distancia: number}>}
     */
    function redAristas(grafo) {
        var aristas = Object.create(null);
        Object.keys(grafo).forEach(function (nodo) {
            var vecinos = grafo[nodo] || {};
            Object.keys(vecinos).forEach(function (vecino) {
                var clave = nodo < vecino ? nodo + '|' + vecino : vecino + '|' + nodo;
                aristas[clave] = {
                    a: nodo,
                    b: vecino,
                    distancia: Number(vecinos[vecino])
                };
            });
        });
        return aristas;
    }

    /**
     * Métricas de resumen (mismas cifras que la prueba de huella).
     * @param {Object<string, Object<string, number>>} grafo
     * @returns {{nodos: number, aristas: number, grado1: number, componentes: number}}
     */
    function redMetricas(grafo) {
        var grado1 = 0;
        Object.keys(grafo).forEach(function (nodo) {
            if (Object.keys(grafo[nodo] || {}).length === 1) {
                grado1++;
            }
        });
        return {
            nodos: Object.keys(grafo).length,
            aristas: Object.keys(redAristas(grafo)).length,
            grado1: grado1,
            componentes: redComponentes(grafo).total
        };
    }

    /**
     * Índice nodo → número de componente, a partir de `redComponentes()`.
     * Permite comprobar alcanzabilidad sin ejecutar Dijkstra 348 veces: en un
     * grafo no dirigido, "hay camino" equivale a "misma componente conexa".
     *
     * @param {{componentes: Array}} componentes
     * @returns {Object<string, number>}
     */
    function redIndiceComponentes(componentes) {
        var indice = Object.create(null);
        (componentes.componentes || []).forEach(function (componente, i) {
            (componente.nodos || []).forEach(function (nodo) {
                indice[nodo] = i;
            });
        });
        return indice;
    }

    /**
     * Alcanzabilidad de pares con el criterio de `calcularRuta()`:
     * nodo más cercano (sin umbral) + misma componente conexa.
     *
     * @param {Object} grafo
     * @param {Array<{id: string, origen: {lat: number, lng: number}, destino: {lat: number, lng: number}}>} pares
     * @param {Object<string, number>} [indice] Resultado de `redIndiceComponentes()`
     * @returns {{alcanzables: string[], inalcanzables: string[]}}
     */
    function redParesAlcanzables(grafo, pares, indice) {
        var alcanzables = [];
        var inalcanzables = [];
        var componentes = indice ? null : redIndiceComponentes(redComponentes(grafo));
        var mapa = indice || componentes;

        // Los pares repiten puerta y edificio: se busca el nodo más cercano una
        // sola vez por punto (6 puertas + N edificios en vez de 2×348).
        var cacheCercanos = Object.create(null);

        function cercano(lat, lng) {
            var clave = lat.toFixed(6) + ',' + lng.toFixed(6);
            if (Object.prototype.hasOwnProperty.call(cacheCercanos, clave)) {
                return cacheCercanos[clave];
            }
            var nodo = findClosestNode(lat, lng, grafo);
            cacheCercanos[clave] = nodo === undefined ? null : nodo;
            return cacheCercanos[clave];
        }

        (pares || []).forEach(function (par) {
            if (!par || !par.id || !par.origen || !par.destino) {
                return;
            }
            if (typeof findClosestNode !== 'function') {
                throw new Error('Falta js/shared/pathfinding.js: no se puede comprobar la alcanzabilidad.');
            }

            var inicio = cercano(Number(par.origen.lat), Number(par.origen.lng));
            var fin = cercano(Number(par.destino.lat), Number(par.destino.lng));

            if (!inicio || !fin) {
                inalcanzables.push(par.id);
                return;
            }

            if (mapa[inicio] === undefined || mapa[fin] === undefined || mapa[inicio] !== mapa[fin]) {
                inalcanzables.push(par.id);
            } else {
                alcanzables.push(par.id);
            }
        });

        return { alcanzables: alcanzables, inalcanzables: inalcanzables };
    }

    // ==========================================
    // SNAPPING
    // ==========================================

    /**
     * Ajustar un punto libre a la red existente: primero al vértice más
     * cercano y, si no hay, a la proyección sobre la arista más cercana.
     *
     * Nota: engancharse al interior de una arista NO la divide en el archivo;
     * `buildGraph()` ya conecta ambos trazados porque proyecta todos los nodos
     * sobre cada arista a ≤2,5 m.
     *
     * @param {number} lat
     * @param {number} lng
     * @param {Array<Object>} features Features de la red (se ignoran las marcadas en `excluir`)
     * @param {number} [radioM]
     * @param {Array<string>} [excluir] IDs a no considerar (p. ej. el segmento en edición)
     * @returns {{lat: number, lng: number, tipo: string, distancia: number, ids: string[]}|null}
     */
    function redAjustarPuntoARed(lat, lng, features, radioM, excluir) {
        var radio = typeof radioM === 'number' ? radioM : 8.0;
        var vetados = Object.create(null);
        (excluir || []).forEach(function (id) { vetados[String(id)] = true; });

        var mejorVertice = null;
        var mejorArista = null;

        (features || []).forEach(function (feature) {
            var id = String((feature.properties && feature.properties.id) || '');
            if (id && vetados[id]) {
                return;
            }
            var coords = feature && feature.geometry && feature.geometry.coordinates;
            if (!Array.isArray(coords) || coords.length < 2) {
                return;
            }

            coords.forEach(function (punto) {
                var d = distanciaMetros(lat, lng, Number(punto[1]), Number(punto[0]));
                if (d <= radio && (mejorVertice === null || d < mejorVertice.distancia)) {
                    mejorVertice = {
                        lat: Number(punto[1]),
                        lng: Number(punto[0]),
                        tipo: 'vertice',
                        distancia: d,
                        ids: id ? [id] : []
                    };
                }
            });

            for (var i = 0; i < coords.length - 1; i++) {
                var a = coords[i];
                var b = coords[i + 1];
                var proy = redProyectarEnSegmento(
                    lat, lng,
                    Number(a[1]), Number(a[0]),
                    Number(b[1]), Number(b[0])
                );
                if (proy.dist <= radio && (mejorArista === null || proy.dist < mejorArista.distancia)) {
                    mejorArista = {
                        lat: proy.lat,
                        lng: proy.lng,
                        tipo: 'segmento',
                        distancia: proy.dist,
                        ids: id ? [id] : []
                    };
                }
            }
        });

        // Preferencia por el vértice: engancharse a un nodo es más estable que
        // a un punto medio (no depende de que la proyección caiga igual). Si
        // el vértice está a menos de 1 m de la mejor arista, gana el vértice.
        if (mejorVertice && mejorArista) {
            return mejorVertice.distancia <= mejorArista.distancia + 1.0
                ? mejorVertice
                : mejorArista;
        }
        return mejorVertice || mejorArista;
    }

    // ==========================================
    // VALIDACIÓN E01–E08
    // ==========================================

    /**
     * Errores estructurales (E01–E05, E07). No calcula alcanzabilidad.
     *
     * @param {Object} propuesta
     * @param {Object} [opciones] `{recuadro, idsVigentes, maxSegmentos}`
     * @returns {Array<{codigo: string, mensaje: string, indice?: number}>}
     */
    function redErroresEstructura(propuesta, opciones) {
        var opt = opciones || {};
        var maxSegmentos = typeof opt.maxSegmentos === 'number' ? opt.maxSegmentos : MAX_SEGMENTOS;
        var recuadro = opt.recuadro || redRecuadro(null);
        var idsVigentes = opt.idsVigentes || null;
        var errores = [];

        if (!propuesta || propuesta.type !== 'FeatureCollection') {
            return [{
                codigo: 'E01',
                mensaje: 'El documento debe ser una FeatureCollection de GeoJSON.'
            }];
        }

        if (!Array.isArray(propuesta.features)) {
            return [{ codigo: 'E01', mensaje: 'Falta el arreglo features.' }];
        }

        if (propuesta.features.length > maxSegmentos) {
            return [{
                codigo: 'E07',
                mensaje: 'La red supera el máximo de ' + maxSegmentos + ' segmentos.'
            }];
        }

        var vistos = Object.create(null);

        propuesta.features.forEach(function (feature, indice) {
            if (errores.length >= 50) {
                return;
            }

            var geom = feature && feature.geometry;
            if (!geom || geom.type !== 'LineString') {
                errores.push({
                    codigo: 'E02',
                    mensaje: 'El segmento debe ser un LineString.',
                    indice: indice
                });
                return;
            }

            var coords = geom.coordinates;
            if (!Array.isArray(coords) || coords.length < 2) {
                errores.push({
                    codigo: 'E03',
                    mensaje: 'El segmento necesita al menos 2 vértices.',
                    indice: indice
                });
                return;
            }

            var puntoInvalido = null;

            for (var i = 0; i < coords.length; i++) {
                var punto = coords[i];
                if (!Array.isArray(punto) || punto.length < 2) {
                    puntoInvalido = 'Coordenada mal formada.';
                    break;
                }
                var lng = Number(punto[0]);
                var lat = Number(punto[1]);
                if (!isFinite(lng) || !isFinite(lat)) {
                    puntoInvalido = 'Coordenada no numérica o no finita.';
                    break;
                }
                if (lat < -90 || lat > 90 || lng < -180 || lng > 180) {
                    puntoInvalido = 'Coordenada fuera de rango global.';
                    break;
                }
                if (!redDentroDelRecuadro(lat, lng, recuadro)) {
                    puntoInvalido = 'Coordenada fuera del recuadro del campus.';
                    break;
                }
            }

            if (puntoInvalido !== null) {
                errores.push({
                    codigo: 'E04',
                    mensaje: puntoInvalido,
                    indice: indice
                });
                return;
            }

            if (redLongitud(coords) <= 0) {
                errores.push({
                    codigo: 'E03',
                    mensaje: 'El segmento tiene longitud 0.',
                    indice: indice
                });
                return;
            }

            var id = (feature.properties && feature.properties.id) || feature.id || '';
            id = String(id);

            if (id === '' || (!PATRON_TMP.test(id) && !PATRON_ID.test(id))) {
                errores.push({
                    codigo: 'E05',
                    mensaje: 'ID de segmento inválido (usa tmp-… para segmentos nuevos).',
                    indice: indice
                });
                return;
            }

            if (vistos[id]) {
                errores.push({
                    codigo: 'E05',
                    mensaje: 'ID de segmento duplicado: ' + id + '.',
                    indice: indice
                });
                return;
            }
            vistos[id] = true;

            if (idsVigentes && PATRON_ID.test(id) && !idsVigentes[id]) {
                errores.push({
                    codigo: 'E05',
                    mensaje: 'ID definitivo desconocido: ' + id + '. Los segmentos nuevos deben usar tmp-….',
                    indice: indice
                });
            }
        });

        return errores;
    }

    // ==========================================
    // ADVERTENCIAS W01–W06
    // ==========================================

    /**
     * Estructura normalizada de cada feature para el análisis.
     * @returns {Array<{id: string, indice: number, coords: Array, longitud: number, a: Object, b: Object}>}
     */
    function redSegmentosCrudos(geojson) {
        var salida = [];
        var features = (geojson && Array.isArray(geojson.features)) ? geojson.features : [];

        features.forEach(function (feature, indice) {
            var id = String((feature.properties && feature.properties.id) || feature.id || '');
            var coords = feature && feature.geometry && feature.geometry.coordinates;
            if (id === '' || !Array.isArray(coords) || coords.length < 2) {
                return;
            }
            salida.push({
                id: id,
                indice: indice,
                coords: coords,
                longitud: redLongitud(coords),
                a: { lat: Number(coords[0][1]), lng: Number(coords[0][0]) },
                b: {
                    lat: Number(coords[coords.length - 1][1]),
                    lng: Number(coords[coords.length - 1][0])
                }
            });
        });

        return salida;
    }

    /**
     * Aristas individuales (para W02 y W03).
     * @returns {Array<{id: string, indice: number, a: Object, b: Object}>}
     */
    function redAristasCrudas(geojson) {
        var salida = [];
        var features = (geojson && Array.isArray(geojson.features)) ? geojson.features : [];

        features.forEach(function (feature, indice) {
            var id = String((feature.properties && feature.properties.id) || feature.id || '');
            var coords = feature && feature.geometry && feature.geometry.coordinates;
            if (id === '' || !Array.isArray(coords) || coords.length < 2) {
                return;
            }
            for (var i = 0; i < coords.length - 1; i++) {
                salida.push({
                    id: id,
                    indice: indice,
                    a: { lat: Number(coords[i][1]), lng: Number(coords[i][0]) },
                    b: { lat: Number(coords[i + 1][1]), lng: Number(coords[i + 1][0]) }
                });
            }
        });

        return salida;
    }

    /**
     * Intersección de dos aristas en el plano local, excluyendo extremos.
     * @returns {{lat: number, lng: number}|null}
     */
    function redInterseccionSegmentos(aLat, aLng, bLat, bLng, cLat, cLng, dLat, dLng) {
        var cos = Math.cos(aLat * Math.PI / 180);
        var ax = aLng * 111320 * cos;
        var ay = aLat * 111320;
        var bx = bLng * 111320 * cos;
        var by = bLat * 111320;
        var cx = cLng * 111320 * cos;
        var cy = cLat * 111320;
        var dx = dLng * 111320 * cos;
        var dy = dLat * 111320;

        var rx = bx - ax;
        var ry = by - ay;
        var sx = dx - cx;
        var sy = dy - cy;

        var denominador = rx * sy - ry * sx;
        if (Math.abs(denominador) < 1e-9) {
            return null;
        }

        var qx = cx - ax;
        var qy = cy - ay;
        var t = (qx * sy - qy * sx) / denominador;
        var u = (qx * ry - qy * rx) / denominador;

        if (t <= 1e-9 || t >= 1 - 1e-9 || u <= 1e-9 || u >= 1 - 1e-9) {
            return null;
        }

        return {
            lat: (ay + t * ry) / 111320,
            lng: (ax + t * rx) / (111320 * Math.max(1e-9, cos))
        };
    }

    /**
     * ¿Dos polilíneas son casi idénticas (mismos vértices a ≤0,5 m)?
     * Incluye un descarte barato en grados para no llamar a Haversine en los
     * ~49 000 pares que claramente no coinciden.
     */
    function redPolilineasSimilares(a, b) {
        if (!Array.isArray(a) || !Array.isArray(b) || a.length !== b.length || a.length < 2) {
            return false;
        }
        // 0,5 m ≈ 4,5e-6 grados de latitud; 1e-5 deja margen y evita trig.
        var MARGEN_GRADOS = 0.00001;
        for (var i = 0; i < a.length; i++) {
            if (Math.abs(Number(a[i][0]) - Number(b[i][0])) > MARGEN_GRADOS
                || Math.abs(Number(a[i][1]) - Number(b[i][1])) > MARGEN_GRADOS) {
                return false;
            }
        }
        for (var j = 0; j < a.length; j++) {
            if (distanciaMetros(
                Number(a[j][1]), Number(a[j][0]),
                Number(b[j][1]), Number(b[j][0])
            ) > 0.5) {
                return false;
            }
        }
        return true;
    }

    /**
     * Distancia del punto a la arista más cercana, ignorando las que estén a
     * menos de `ignorarMenorQue` (así el propio extremo no cuenta).
     *
     * @returns {{id: string, indice: number, distancia: number}|null}
     */
    function redDistanciaMinimaAAristas(lat, lng, aristas, ignorarMenorQue) {
        var mejor = null;
        var mejorDist = Infinity;

        (aristas || []).forEach(function (arista) {
            var proy = redProyectarEnSegmento(
                lat, lng,
                arista.a.lat, arista.a.lng,
                arista.b.lat, arista.b.lng
            );
            if (proy.dist < ignorarMenorQue) {
                return;
            }
            if (proy.dist < mejorDist) {
                mejorDist = proy.dist;
                mejor = {
                    id: arista.id,
                    indice: arista.indice,
                    distancia: proy.dist
                };
            }
        });

        return mejor;
    }

    /**
     * Índice espacial de nodos por celda (~5,5 m). Evita comparar cada vértice
     * contra los 238 nodos del grafo: solo mira las 9 celdas vecinas.
     */
    var TAM_CELDA_NODOS = 0.00005;

    function redIndiceEspacialNodos(claves) {
        var celdas = Object.create(null);
        claves.forEach(function (clave) {
            var p = redPuntoDeClave(clave);
            var cx = Math.floor(p.lng / TAM_CELDA_NODOS);
            var cy = Math.floor(p.lat / TAM_CELDA_NODOS);
            var k = cx + ':' + cy;
            if (!celdas[k]) {
                celdas[k] = [];
            }
            celdas[k].push(clave);
        });
        return celdas;
    }

    function redNodoCercanoIndexado(lat, lng, celdas) {
        var cx = Math.floor(lng / TAM_CELDA_NODOS);
        var cy = Math.floor(lat / TAM_CELDA_NODOS);

        for (var dx = -1; dx <= 1; dx++) {
            for (var dy = -1; dy <= 1; dy++) {
                var lista = celdas[(cx + dx) + ':' + (cy + dy)];
                if (!lista) {
                    continue;
                }
                for (var i = 0; i < lista.length; i++) {
                    var p = redPuntoDeClave(lista[i]);
                    if (distanciaMetros(lat, lng, p.lat, p.lng) <= TOLERANCIA_SNAPPING_M) {
                        return lista[i];
                    }
                }
            }
        }
        return null;
    }

    /**
     * @param {{total: number, mayor: number, componentes: Array}} componentes
     * @param {Array} features
     * @param {Object} grafo
     * @returns {Array}
     */
    function redAvisoComponentes(componentes, features, grafo) {
        if (componentes.total <= 1) {
            return [];
        }

        var principal = componentes.componentes[0] ? componentes.componentes[0].nodos : [];
        var enPrincipal = Object.create(null);
        principal.forEach(function (nodo) { enPrincipal[nodo] = true; });

        var claves = Object.keys(grafo);
        var celdas = redIndiceEspacialNodos(claves);
        var fuera = [];

        features.forEach(function (segmento) {
            var dentroDePrincipal = false;
            for (var i = 0; i < segmento.coords.length; i++) {
                var punto = segmento.coords[i];
                var nodo = redNodoCercanoIndexado(
                    Number(punto[1]),
                    Number(punto[0]),
                    celdas
                );
                if (nodo !== null && enPrincipal[nodo]) {
                    dentroDePrincipal = true;
                    break;
                }
            }
            if (!dentroDePrincipal) {
                fuera.push(segmento.id);
            }
        });

        return [{
            codigo: 'W01',
            mensaje: 'La red tiene ' + componentes.total + ' componentes conexas (la mayor concentra '
                + componentes.mayor + ' de ' + claves.length + ' nodos). Segmentos aislados: ' + fuera.length + '.',
            punto: componentes.componentes[1] ? componentes.componentes[1].punto : null,
            ids: fuera.slice(0, 50)
        }];
    }

    function redAvisoExtremosCasiConectados(grafo, aristas) {
        var avisos = [];
        var vistos = Object.create(null);

        redExtremosLibres(grafo).forEach(function (clave) {
            if (avisos.length >= MAX_AVISOS_POR_TIPO) {
                return;
            }
            var punto = redPuntoDeClave(clave);
            var menor = redDistanciaMinimaAAristas(punto.lat, punto.lng, aristas, TOLERANCIA_SNAPPING_M);

            if (menor === null || menor.distancia <= TOLERANCIA_SNAPPING_M
                || menor.distancia > RADIO_CASI_CONECTADO_M) {
                return;
            }

            var marca = redClaveNodo(punto.lat, punto.lng);
            if (vistos[marca]) {
                return;
            }
            vistos[marca] = true;

            avisos.push({
                codigo: 'W02',
                mensaje: 'Extremo libre casi conectado a ' + menor.id + ': '
                    + menor.distancia.toFixed(1) + ' m (se conecta por snapping hasta 2,5 m).',
                punto: punto,
                ids: [menor.id]
            });
        });

        return avisos;
    }

    /**
     * Cajas envolventes de las aristas, ordenadas por longitud mínima, para
     * descartar pares que no pueden cruzarse sin recorrer los 55 000 pares.
     */
    function redCajasOrdenadas(aristas) {
        var cajas = aristas.map(function (arista) {
            return {
                arista: arista,
                minLng: Math.min(arista.a.lng, arista.b.lng),
                maxLng: Math.max(arista.a.lng, arista.b.lng),
                minLat: Math.min(arista.a.lat, arista.b.lat),
                maxLat: Math.max(arista.a.lat, arista.b.lat)
            };
        });
        cajas.sort(function (x, y) { return x.minLng - y.minLng; });
        return cajas;
    }

    function redAvisoCrucesSinNodo(grafo, aristas) {
        var avisos = [];
        var claves = Object.keys(grafo);
        var vistos = Object.create(null);
        var cajas = redCajasOrdenadas(aristas);

        for (var i = 0; i < cajas.length; i++) {
            var cajaA = cajas[i];

            for (var j = i + 1; j < cajas.length; j++) {
                if (avisos.length >= MAX_AVISOS_POR_TIPO) {
                    return avisos;
                }

                var cajaB = cajas[j];
                if (cajaB.minLng > cajaA.maxLng) {
                    break;
                }
                if (cajaB.minLat > cajaA.maxLat || cajaB.maxLat < cajaA.minLat) {
                    continue;
                }

                var a = cajaA.arista;
                var b = cajaB.arista;
                if (a.id === b.id) {
                    continue;
                }

                var cruce = redInterseccionSegmentos(
                    a.a.lat, a.a.lng, a.b.lat, a.b.lng,
                    b.a.lat, b.a.lng, b.b.lat, b.b.lng
                );
                if (cruce === null) {
                    continue;
                }

                var marca = redClaveNodo(cruce.lat, cruce.lng);
                if (vistos[marca]) {
                    continue;
                }

                var hayNodo = claves.some(function (clave) {
                    var nodo = redPuntoDeClave(clave);
                    return distanciaMetros(cruce.lat, cruce.lng, nodo.lat, nodo.lng)
                        <= TOLERANCIA_SNAPPING_M;
                });

                if (!hayNodo) {
                    vistos[marca] = true;
                    avisos.push({
                        codigo: 'W03',
                        mensaje: 'Cruce sin nodo entre ' + a.id + ' y ' + b.id
                            + ': no hay conexión en ese punto.',
                        punto: cruce,
                        ids: [a.id, b.id],
                        indices: [a.indice, b.indice]
                    });
                }
            }
        }

        return avisos;
    }

    function redAvisoSegmentosCortos(segmentos) {
        var avisos = [];
        segmentos.forEach(function (segmento) {
            if (avisos.length >= MAX_AVISOS_POR_TIPO || segmento.longitud >= TOLERANCIA_SNAPPING_M) {
                return;
            }
            avisos.push({
                codigo: 'W04',
                mensaje: 'Segmento muy corto (' + segmento.id + '): '
                    + segmento.longitud.toFixed(2) + ' m. Sus extremos se fusionarán por snapping.',
                punto: segmento.a,
                ids: [segmento.id],
                indice: segmento.indice
            });
        });
        return avisos;
    }

    function redAvisoSolapados(segmentos) {
        var avisos = [];
        var MARGEN_GRADOS = 0.00001;

        var cajas = segmentos.map(function (segmento) {
            var minLng = Infinity;
            var maxLng = -Infinity;
            var minLat = Infinity;
            var maxLat = -Infinity;
            segmento.coords.forEach(function (punto) {
                var lng = Number(punto[0]);
                var lat = Number(punto[1]);
                if (lng < minLng) { minLng = lng; }
                if (lng > maxLng) { maxLng = lng; }
                if (lat < minLat) { minLat = lat; }
                if (lat > maxLat) { maxLat = lat; }
            });
            return {
                segmento: segmento,
                minLng: minLng,
                maxLng: maxLng,
                minLat: minLat,
                maxLat: maxLat
            };
        });

        cajas.sort(function (x, y) { return x.minLng - y.minLng; });

        for (var i = 0; i < cajas.length; i++) {
            var cajaA = cajas[i];

            for (var j = i + 1; j < cajas.length; j++) {
                if (avisos.length >= MAX_AVISOS_POR_TIPO) {
                    return avisos;
                }

                var cajaB = cajas[j];
                if (cajaB.minLng > cajaA.maxLng + MARGEN_GRADOS) {
                    break;
                }
                if (cajaB.minLat > cajaA.maxLat + MARGEN_GRADOS
                    || cajaB.maxLat < cajaA.minLat - MARGEN_GRADOS) {
                    continue;
                }

                var a = cajaA.segmento;
                var b = cajaB.segmento;
                if (a.id === b.id) {
                    continue;
                }

                var mismoSentido = redPolilineasSimilares(a.coords, b.coords);
                var inverso = mismoSentido
                    ? false
                    : redPolilineasSimilares(a.coords, b.coords.slice().reverse());

                if (mismoSentido || inverso) {
                    avisos.push({
                        codigo: 'W05',
                        mensaje: 'Segmentos solapados o casi idénticos: ' + a.id + ' y ' + b.id + '.',
                        punto: a.a,
                        ids: [a.id, b.id],
                        indices: [a.indice, b.indice]
                    });
                }
            }
        }

        return avisos;
    }

    function redAvisoVerticesDuplicados(segmentos) {        var avisos = [];

        segmentos.forEach(function (segmento) {
            if (avisos.length >= MAX_AVISOS_POR_TIPO) {
                return;
            }
            for (var i = 0; i < segmento.coords.length - 1; i++) {
                var d = distanciaMetros(
                    Number(segmento.coords[i][1]), Number(segmento.coords[i][0]),
                    Number(segmento.coords[i + 1][1]), Number(segmento.coords[i + 1][0])
                );
                if (d <= 0.01) {
                    avisos.push({
                        codigo: 'W06',
                        mensaje: 'Vértices consecutivos duplicados en ' + segmento.id + '.',
                        punto: {
                            lat: Number(segmento.coords[i][1]),
                            lng: Number(segmento.coords[i][0])
                        },
                        ids: [segmento.id],
                        indice: segmento.indice
                    });
                    break;
                }
            }
        });

        return avisos;
    }

    // ==========================================
    // ANÁLISIS COMPLETO
    // ==========================================

    /**
     * Alcanzabilidad de la red persistida, memorizada por referencia.
     *
     * @returns {{grafo: Object, alcanzables: string[]}}
     */
    function redAlcanzablesVigente(redVigente, pares) {
        if (memoriaVigente && memoriaVigente.has(redVigente)) {
            return memoriaVigente.get(redVigente);
        }

        var grafoVigente = redGrafo(redVigente);
        var resultado = {
            grafo: grafoVigente,
            alcanzables: redParesAlcanzables(grafoVigente, pares).alcanzables
        };

        if (memoriaVigente) {
            memoriaVigente.set(redVigente, resultado);
        }
        return resultado;
    }

    /**
     * Analizar una propuesta de red como lo hará el servidor.
     *
     * @param {Object} propuesta FeatureCollection completa
     * @param {Object} [opciones]
     *        - `redVigente`: red persistida (para E08 y para el recuadro)
     *        - `pares`: pares puerta→edificio de `RED_PAIRS`
     *        - `recuadro`: recuadro ya calculado (opcional)
     *        - `maxSegmentos`: límite (por defecto 2000)
     * @returns {{
     *   ok: boolean,
     *   errores: Array,
     *   advertencias: Array,
     *   grafo: Object,
     *   metricas: {nodos: number, aristas: number, grado1: number, componentes: number},
     *   componentes: Object,
     *   extremos: string[],
     *   alcanzabilidad: {alcanzables: string[], inalcanzables: string[]}|null
     * }}
     */
    function redAnalizarRed(propuesta, opciones) {
        var opt = opciones || {};
        var redVigente = opt.redVigente || null;
        var pares = Array.isArray(opt.pares) ? opt.pares : [];

        var idsVigentes = null;
        if (redVigente && Array.isArray(redVigente.features)) {
            idsVigentes = Object.create(null);
            redVigente.features.forEach(function (feature) {
                var id = String((feature.properties && feature.properties.id) || feature.id || '');
                if (id) {
                    idsVigentes[id] = true;
                }
            });
        }

        var recuadro = opt.recuadro || redRecuadro(redVigente || propuesta);

        var errores = redErroresEstructura(propuesta, {
            recuadro: recuadro,
            idsVigentes: idsVigentes,
            maxSegmentos: typeof opt.maxSegmentos === 'number' ? opt.maxSegmentos : MAX_SEGMENTOS
        });

        var grafo = {};
        var componentes = { total: 0, tamanos: [], mayor: 0, componentes: [] };
        var metricas = { nodos: 0, aristas: 0, grado1: 0, componentes: 0 };
        var extremos = [];
        var alcanzabilidad = null;
        var advertencias = [];

        if (propuesta && propuesta.type === 'FeatureCollection' && Array.isArray(propuesta.features)) {
            try {
                grafo = redGrafo(propuesta);
                componentes = redComponentes(grafo);
                metricas = redMetricas(grafo);
                extremos = redExtremosLibres(grafo);
            } catch (err) {
                errores.push({
                    codigo: 'E01',
                    mensaje: 'No se pudo construir el grafo de la red: ' + err.message
                });
            }
        }

        // E08: ningún par que hoy es alcanzable puede dejar de serlo.
        if (errores.length === 0 && redVigente && pares.length > 0 && metricas.nodos > 0) {
            try {
                var indice = redIndiceComponentes(componentes);
                var antes = redAlcanzablesVigente(redVigente, pares);
                var despues = redParesAlcanzables(grafo, pares, indice);

                var alcanzablesDespues = Object.create(null);
                despues.alcanzables.forEach(function (id) { alcanzablesDespues[id] = true; });

                var perdidos = antes.alcanzables.filter(function (id) {
                    return !alcanzablesDespues[id];
                });

                alcanzabilidad = despues;

                if (perdidos.length > 0) {
                    errores.push({
                        codigo: 'E08',
                        mensaje: 'La propuesta dejaría sin ruta accesible a ' + perdidos.length
                            + ' par(es) puerta→edificio. Corrige la red antes de guardar.',
                        pares: perdidos.slice(0, MAX_AVISOS_POR_TIPO),
                        total: perdidos.length
                    });
                }
            } catch (err) {
                // Si el análisis falla no se bloquea al usuario por un error del
                // propio analizador; el servidor volverá a comprobarlo.
                advertencias.push({
                    codigo: 'W00',
                    mensaje: 'No se pudo comprobar la alcanzabilidad en el navegador: ' + err.message,
                    punto: null,
                    ids: []
                });
            }
        }

        if (errores.length === 0) {
            var segmentos = redSegmentosCrudos(propuesta);
            var aristas = redAristasCrudas(propuesta);

            advertencias = advertencias.concat(
                redAvisoComponentes(componentes, segmentos, grafo),
                redAvisoExtremosCasiConectados(grafo, aristas),
                redAvisoCrucesSinNodo(grafo, aristas),
                redAvisoSegmentosCortos(segmentos),
                redAvisoSolapados(segmentos),
                redAvisoVerticesDuplicados(segmentos)
            );
        }

        return {
            ok: errores.length === 0,
            errores: errores,
            advertencias: advertencias,
            grafo: grafo,
            metricas: metricas,
            componentes: componentes,
            extremos: extremos,
            alcanzabilidad: alcanzabilidad
        };
    }

    // ==========================================
    // EXPORTACIÓN
    // ==========================================
    global.redClaveNodo = redClaveNodo;
    global.redPuntoDeClave = redPuntoDeClave;
    global.redLongitud = redLongitud;
    global.redProyectarEnSegmento = redProyectarEnSegmento;
    global.redDentroDelRecuadro = redDentroDelRecuadro;
    global.redRecuadro = redRecuadro;
    global.redGrafo = redGrafo;
    global.redComponentes = redComponentes;
    global.redExtremosLibres = redExtremosLibres;
    global.redAristas = redAristas;
    global.redMetricas = redMetricas;
    global.redParesAlcanzables = redParesAlcanzables;
    global.redAjustarPuntoARed = redAjustarPuntoARed;
    global.redErroresEstructura = redErroresEstructura;
    global.redInterseccionSegmentos = redInterseccionSegmentos;
    global.redAnalizarRed = redAnalizarRed;

    // Constantes expuestas para que el editor no las duplique.
    global.RED_TOLERANCIA_SNAPPING_M = TOLERANCIA_SNAPPING_M;
    global.RED_RADIO_CASI_CONECTADO_M = RADIO_CASI_CONECTADO_M;
    global.RED_MAX_SEGMENTOS = MAX_SEGMENTOS;
    global.RED_MAX_AVISOS_POR_TIPO = MAX_AVISOS_POR_TIPO;
    global.RED_MARGEN_RECUADRO_M = MARGEN_RECUADRO_M;

})(typeof window !== 'undefined' ? window : globalThis);
