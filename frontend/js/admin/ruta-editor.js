/* ==========================================
   EDITOR DE RUTAS — CADENA DE SEGMENTOS
   ==========================================
   Construcción de una ruta administrativa encadenando segmentos existentes
   de la red. El cliente solo produce `[{id, sentido}]`; el servidor
   reconstruye la geometría y valida la cadena antes de guardar.

   Estados: cargando → listo/construyendo → guardando → guardado/error. */

(function () {
    'use strict';

    var cfg = window.RUTA_EDITOR || {};
    var urls = cfg.urls || {};
    var datos = cfg.datos || {};
    var config = cfg.config || {};
    var tolerancia = Number(config.tolerancia_continuidad_m) || 2.5;

    // Estilos de la red: azul marino oscuro con contorno claro para que los
    // caminos se distingan sobre la imagen satelital.
    var ESTILOS_RED = {
        base: { color: '#12306b', weight: 5, opacity: 0.9 },
        contiguo: { color: '#0b57d0', weight: 6, opacity: 1 },
        noContiguo: { color: '#7d8ba3', weight: 4, opacity: 0.35 },
        cadena: { color: '#0b57d0', weight: 6, opacity: 1 }
    };
    var COLOR_CONTORNO = '#f8fafc';
    // Radio de acierto del clic, en píxeles (independiente del zoom).
    var RADIO_CLIC_PX = 12;
    var RADIO_AYUDA_CLIC_PX = 14;

    var estado = {
        segmentos: new Map(),
        cadena: [],
        centros: new Map(),
        capasRed: new Map(),
        contornosRed: new Map(),
        origenManual: false,
        destinoManual: false,
        sucio: false,
        guardando: false,
        listo: false
    };

    // ---------- Utilidades ----------

    function el(tag, clase, texto) {
        var nodo = document.createElement(tag);
        if (clase) { nodo.className = clase; }
        if (texto !== undefined && texto !== null) { nodo.textContent = String(texto); }
        return nodo;
    }

    function limpiar(nodo) {
        while (nodo && nodo.firstChild) { nodo.removeChild(nodo.firstChild); }
    }

    function claveNodo(lat, lng) {
        return Number(lat).toFixed(6) + ',' + Number(lng).toFixed(6);
    }

    function claveDePunto(punto) {
        return claveNodo(punto[1], punto[0]);
    }

    function distanciaMetros(lat1, lng1, lat2, lng2) {
        var radio = 6371000;
        var dLat = (lat2 - lat1) * Math.PI / 180;
        var dLng = (lng2 - lng1) * Math.PI / 180;
        var a = Math.sin(dLat / 2) * Math.sin(dLat / 2) +
            Math.cos(lat1 * Math.PI / 180) * Math.cos(lat2 * Math.PI / 180) *
            Math.sin(dLng / 2) * Math.sin(dLng / 2);
        return 2 * radio * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
    }

    function distanciaEntreClaves(claveA, claveB) {
        var a = String(claveA).split(',').map(Number);
        var b = String(claveB).split(',').map(Number);
        return distanciaMetros(a[0], a[1], b[0], b[1]);
    }

    function mismaPosicion(claveA, claveB) {
        if (!claveA || !claveB) { return false; }
        return distanciaEntreClaves(claveA, claveB) <= tolerancia;
    }

    function metros(valor) {
        var numero = Number(valor) || 0;
        if (numero >= 1000) { return (numero / 1000).toFixed(2) + ' km'; }
        return Math.round(numero) + ' m';
    }

    // ---------- DOM ----------

    var dom = {
        resumenSegmentos: document.getElementById('cadena-segmentos'),
        resumenDistancia: document.getElementById('cadena-distancia'),
        resumenNodos: document.getElementById('cadena-nodos'),
        cadenaLista: document.getElementById('cadena-lista'),
        cadenaVacia: document.getElementById('cadena-vacia'),
        btnDeshacer: document.getElementById('btn-deshacer'),
        btnInvertir: document.getElementById('btn-invertir'),
        btnLimpiar: document.getElementById('btn-limpiar'),
        btnZoom: document.getElementById('btn-zoom'),
        btnGuardar: document.getElementById('btn-guardar'),
        btnCancelar: document.getElementById('btn-cancelar'),
        btnVolver: document.getElementById('btn-volver'),
        form: document.getElementById('ruta-form'),
        nombre: document.getElementById('ruta-nombre'),
        descripcion: document.getElementById('ruta-descripcion'),
        tipo: document.getElementById('ruta-tipo'),
        estadoRuta: document.getElementById('ruta-estado'),
        accesible: document.getElementById('ruta-accesible'),
        idRuta: document.getElementById('ruta-id'),
        updatedAt: document.getElementById('ruta-updated-at'),
        segmentosInput: document.getElementById('ruta-segmentos'),
        origenTipo: document.getElementById('origen-tipo'),
        origenRef: document.getElementById('origen-ref'),
        origenNodo: document.getElementById('origen-nodo'),
        destinoTipo: document.getElementById('destino-tipo'),
        destinoRef: document.getElementById('destino-ref'),
        destinoNodo: document.getElementById('destino-nodo'),
        errorNombre: document.getElementById('error-nombre'),
        errorOrigen: document.getElementById('error-origen'),
        errorDestino: document.getElementById('error-destino'),
        mensaje: document.getElementById('editor-mensaje')
    };

    // ---------- Mapa ----------

    var mapa = L.map('map-ruta', {
        attributionControl: false,
        zoomControl: true
    }).setView([25.5324, -103.436], 17);

    L.tileLayer('tiles/satelital/{z}/{x}/{y}.jpg', {
        minZoom: 15,
        maxZoom: 19
    }).addTo(mapa);

    var capaEdificios = L.geoJSON(
        window.datosTec && window.datosTec.features ? window.datosTec : { type: 'FeatureCollection', features: [] },
        {
            interactive: false,
            smoothFactor: 0,
            style: function () {
                return {
                    color: '#000000',
                    weight: 1,
                    opacity: 1,
                    fillColor: '#9aa5b1',
                    fillOpacity: 0.9
                }; 
            }
        }
    ).addTo(mapa);

    var capaRed = L.layerGroup().addTo(mapa);
    var capaCadena = L.layerGroup().addTo(mapa);
    var capaEtiquetas = L.layerGroup().addTo(mapa);
    var capaMarcadores = L.layerGroup().addTo(mapa);

    // Centroides de edificios y accesos (para autocompletar origen/destino).
    (function calcularCentros() {
        var coleccion = window.datosTec && window.datosTec.features ? window.datosTec : null;
        if (!coleccion) { return; }
        coleccion.features.forEach(function (feature) {
            var id = feature && feature.properties ? feature.properties.id : null;
            if (!id || !datos[id]) { return; }
            try {
                var centro = L.geoJSON(feature).getBounds().getCenter();
                estado.centros.set(String(id), {
                    lat: centro.lat,
                    lng: centro.lng,
                    tipo: datos[id].tipo === 'acceso' ? 'acceso' : 'edificio'
                });
            } catch (error) {
                // Geometría inválida: se omite del autocompletado.
            }
        });
    })();

    // ---------- Mensajes y errores ----------

    function mostrarMensaje(texto, tipo, detalles) {
        limpiar(dom.mensaje);
        dom.mensaje.className = 'editor-mensaje visible ' + (tipo || 'error');
        dom.mensaje.appendChild(el('div', null, texto));
        if (Array.isArray(detalles) && detalles.length > 0) {
            var lista = el('ul');
            detalles.forEach(function (detalle) { lista.appendChild(el('li', null, detalle)); });
            dom.mensaje.appendChild(lista);
        }
    }

    function ocultarMensaje() {
        dom.mensaje.className = 'editor-mensaje';
        limpiar(dom.mensaje);
    }

    function marcarError(nodo, texto) {
        if (!nodo) { return; }
        nodo.textContent = texto || '';
        nodo.classList.toggle('visible', Boolean(texto));
    }

    // ---------- Carga ----------

    function pedirJson(url) {
        return fetch(url, {
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
        }).then(function (respuesta) {
            if (respuesta.status === 403) {
                window.location.href = 'index.php?p=login';
                throw new Error('Sesión expirada.');
            }
            return respuesta.text().then(function (texto) {
                var contenido = null;
                try {
                    contenido = JSON.parse(texto);
                } catch (error) {
                    throw new Error('Respuesta inesperada del servidor (HTTP ' + respuesta.status + ').');
                }
                if (!respuesta.ok || contenido.success === false) {
                    throw new Error(contenido.message || ('Error HTTP ' + respuesta.status));
                }
                return contenido;
            });
        });
    }

    function cargarRed() {
        return pedirJson(urls.getRedCaminos).then(function (respuesta) {
            var segmentos = Array.isArray(respuesta.segmentos) ? respuesta.segmentos : [];
            segmentos.forEach(function (segmento) {
                if (!Array.isArray(segmento.coordenadas) || segmento.coordenadas.length < 2) { return; }
                var coords = segmento.coordenadas.map(function (punto) {
                    return [Number(punto[1]), Number(punto[0])];
                });
                var registro = {
                    id: segmento.id,
                    nombre: segmento.nombre || segmento.id,
                    coords: coords,
                    inicio: claveDePunto(segmento.coordenadas[0]),
                    fin: claveDePunto(segmento.coordenadas[segmento.coordenadas.length - 1]),
                    longitud: Number(segmento.longitud_m) || 0
                };
                estado.segmentos.set(segmento.id, registro);

                // Contorno claro bajo la línea: da contraste sobre la imagen.
                var contorno = L.polyline(coords, {
                    color: COLOR_CONTORNO,
                    weight: ESTILOS_RED.base.weight + 4,
                    opacity: 0.7,
                    smoothFactor: 0,
                    interactive: false
                });
                contorno.addTo(capaRed);
                estado.contornosRed.set(registro.id, contorno);

                var capa = L.polyline(coords, {
                    color: ESTILOS_RED.base.color,
                    weight: ESTILOS_RED.base.weight,
                    opacity: ESTILOS_RED.base.opacity,
                    smoothFactor: 0,
                    interactive: false
                });
                capa.addTo(capaRed);
                estado.capasRed.set(registro.id, capa);
            });

            if (segmentos.length === 0) {
                mostrarMensaje('La red de caminos está vacía: no se puede construir una ruta.', 'error');
            }
        });
    }

    function cargarRutaExistente() {
        if (cfg.modo !== 'editar' || !cfg.id) { return Promise.resolve(); }
        return pedirJson(urls.getRutas + '&id=' + encodeURIComponent(cfg.id)).then(function (respuesta) {
            var ruta = respuesta.ruta;
            if (!ruta) { throw new Error('La ruta no existe.'); }

            dom.nombre.value = ruta.nombre || '';
            dom.descripcion.value = ruta.descripcion || '';
            dom.tipo.value = ruta.tipo || 'principal';
            dom.estadoRuta.value = ruta.estado || 'borrador';
            dom.accesible.checked = ruta.accesible !== false;
            dom.updatedAt.value = ruta.actualizado_en || ruta.updated_at || '';

            configurarTipoReferencia('origen', ruta.origen ? ruta.origen.tipo : 'edificio');
            configurarTipoReferencia('destino', ruta.destino ? ruta.destino.tipo : 'edificio');
            aplicarReferencia('origen', ruta.origen);
            aplicarReferencia('destino', ruta.destino);
            estado.origenManual = true;
            estado.destinoManual = true;

            var referencias = Array.isArray(ruta.segmentos) ? ruta.segmentos : [];
            var descartados = [];
            estado.cadena = referencias.filter(function (referencia) {
                if (estado.segmentos.has(referencia.id)) { return true; }
                descartados.push(referencia.id);
                return false;
            }).map(function (referencia) {
                return {
                    id: referencia.id,
                    sentido: referencia.sentido === 'inverso' ? 'inverso' : 'normal'
                };
            });

            if (descartados.length > 0) {
                mostrarMensaje(
                    'La ruta referencia segmentos que ya no existen en la red y se excluyeron de la cadena. ' +
                    'Al guardar, esos segmentos se eliminarán de la ruta.',
                    'error',
                    descartados
                );
            }

            dom.segmentosInput.value = JSON.stringify(estado.cadena);
            actualizarVistaCadena();
        });
    }

    // ---------- Referencias de origen y destino ----------

    function opcionesParaTipo(tipo) {
        var salida = [];
        Object.keys(datos).forEach(function (id) {
            var registro = datos[id];
            var tipoRegistro = registro.tipo === 'acceso' ? 'acceso' : 'edificio';
            if (tipoRegistro === tipo) {
                salida.push({ id: id, nombre: registro.nombre || id });
            }
        });
        salida.sort(function (a, b) { return a.nombre.localeCompare(b.nombre, 'es'); });
        return salida;
    }

    function configurarTipoReferencia(cual, tipo) {
        var tipoEl = cual === 'origen' ? dom.origenTipo : dom.destinoTipo;
        var refEl = cual === 'origen' ? dom.origenRef : dom.destinoRef;
        var nodoEl = cual === 'origen' ? dom.origenNodo : dom.destinoNodo;

        tipoEl.value = tipo;

        var esNodo = tipo === 'nodo';
        refEl.hidden = esNodo;
        nodoEl.hidden = !esNodo;

        limpiar(refEl);
        if (esNodo) {
            return;
        }

        var opciones = opcionesParaTipo(tipo);
        var vacio = el('option', null, opciones.length ? 'Selecciona…' : 'Sin registros de este tipo');
        vacio.value = '';
        refEl.appendChild(vacio);

        opciones.forEach(function (opcion) {
            var nodo = el('option', null, opcion.nombre);
            nodo.value = opcion.id;
            refEl.appendChild(nodo);
        });
    }

    function aplicarReferencia(cual, referencia) {
        if (!referencia) { return; }
        var tipoEl = cual === 'origen' ? dom.origenTipo : dom.destinoTipo;
        var refEl = cual === 'origen' ? dom.origenRef : dom.destinoRef;
        var nodoEl = cual === 'origen' ? dom.origenNodo : dom.destinoNodo;

        tipoEl.value = referencia.tipo || 'edificio';
        if (referencia.tipo === 'nodo') {
            nodoEl.value = referencia.ref || '';
        } else {
            refEl.value = referencia.ref || '';
        }
    }

    function leerReferencia(cual) {
        var tipoEl = cual === 'origen' ? dom.origenTipo : dom.destinoTipo;
        var refEl = cual === 'origen' ? dom.origenRef : dom.destinoRef;
        var nodoEl = cual === 'origen' ? dom.origenNodo : dom.destinoNodo;
        var tipo = tipoEl.value;
        return {
            tipo: tipo,
            ref: tipo === 'nodo' ? nodoEl.value.trim() : refEl.value
        };
    }

    function extremosCadena() {
        if (estado.cadena.length === 0) { return { inicio: null, fin: null }; }
        var primero = estado.segmentos.get(estado.cadena[0].id);
        var ultimo = estado.segmentos.get(estado.cadena[estado.cadena.length - 1].id);
        if (!primero || !ultimo) { return { inicio: null, fin: null }; }
        return {
            inicio: estado.cadena[0].sentido === 'normal' ? primero.inicio : primero.fin,
            fin: estado.cadena[estado.cadena.length - 1].sentido === 'normal' ? ultimo.fin : ultimo.inicio
        };
    }

    function masCercano(lat, lng) {
        var mejor = null;
        var mejorDist = Infinity;
        estado.centros.forEach(function (centro, id) {
            var dist = distanciaMetros(lat, lng, centro.lat, centro.lng);
            if (dist < mejorDist) {
                mejorDist = dist;
                mejor = { id: id, tipo: centro.tipo };
            }
        });
        return mejor;
    }

    function autocompletarExtremos() {
        var extremos = extremosCadena();
        if (!extremos.inicio || !extremos.fin) { return; }

        if (!estado.origenManual) {
            var a = extremos.inicio.split(',').map(Number);
            var origen = masCercano(a[0], a[1]);
            if (origen) {
                configurarTipoReferencia('origen', origen.tipo);
                dom.origenRef.value = origen.id;
            }
        }

        if (!estado.destinoManual) {
            var b = extremos.fin.split(',').map(Number);
            var destino = masCercano(b[0], b[1]);
            if (destino) {
                configurarTipoReferencia('destino', destino.tipo);
                dom.destinoRef.value = destino.id;
            }
        }
    }

    // ---------- Cadena ----------

    function coordsOrientadas(referencia) {
        var segmento = estado.segmentos.get(referencia.id);
        if (!segmento) { return []; }
        return referencia.sentido === 'inverso' ? segmento.coords.slice().reverse() : segmento.coords.slice();
    }

    function coordsCadena() {
        var salida = [];
        estado.cadena.forEach(function (referencia) {
            var coords = coordsOrientadas(referencia);
            var desde = salida.length > 0 ? 1 : 0;
            for (var i = desde; i < coords.length; i++) {
                salida.push(coords[i]);
            }
        });
        return salida;
    }

    function distanciaCadena() {
        return estado.cadena.reduce(function (total, referencia) {
            var segmento = estado.segmentos.get(referencia.id);
            return total + (segmento ? segmento.longitud : 0);
        }, 0);
    }

    function alHacerClicSegmento(id, latlng) {
        if (!estado.listo || estado.guardando) { return; }

        if (estado.cadena.some(function (referencia) { return referencia.id === id; })) {
            quitarSegmento(id);
            return;
        }

        var segmento = estado.segmentos.get(id);
        if (!segmento) { return; }

        if (estado.cadena.length === 0) {
            estado.cadena.push({ id: id, sentido: 'normal' });
            marcarSucio();
            actualizarVistaCadena();
            return;
        }

        var extremos = extremosCadena();

        if (mismaPosicion(segmento.inicio, extremos.fin)) {
            estado.cadena.push({ id: id, sentido: 'normal' });
        } else if (mismaPosicion(segmento.fin, extremos.fin)) {
            estado.cadena.push({ id: id, sentido: 'inverso' });
        } else if (mismaPosicion(segmento.fin, extremos.inicio)) {
            estado.cadena.unshift({ id: id, sentido: 'normal' });
        } else if (mismaPosicion(segmento.inicio, extremos.inicio)) {
            estado.cadena.unshift({ id: id, sentido: 'inverso' });
        } else {
            // El clic pudo caer unos píxeles fuera del segmento contiguo:
            // probar con el contiguo más cercano antes de rechazar.
            var alternativa = segmentoContiguoCercano(latlng);
            if (alternativa && alternativa !== id) {
                alHacerClicSegmento(alternativa, latlng);
                return;
            }

            if (tocaAlgunSegmentoCadena(segmento)) {
                mostrarMensaje(
                    'Ese segmento se une al interior de la cadena. Solo se puede continuar por el inicio o el final de la cadena.',
                    'error'
                );
                return;
            }

            var hueco = distanciaSegmentoACadena(segmento);
            mostrarMensaje(
                'Ese segmento no es contiguo a la cadena. Solo se pueden agregar segmentos que compartan un extremo (tolerancia ' +
                String(tolerancia).replace('.', ',') + ' m).' +
                (isFinite(hueco) ? ' El extremo más cercano está a ' + hueco.toFixed(1).replace('.', ',') + ' m.' : '') +
                ' Los segmentos contiguos aparecen resaltados en azul.',
                'error'
            );
            return;
        }

        marcarSucio();
        ocultarMensaje();
        actualizarVistaCadena();
    }

    function quitarSegmento(id) {
        if (estado.cadena.length === 0) { return; }
        var primero = estado.cadena[0].id;
        var ultimo = estado.cadena[estado.cadena.length - 1].id;

        if (id === ultimo) {
            estado.cadena.pop();
        } else if (id === primero) {
            estado.cadena.shift();
        } else {
            mostrarMensaje('Solo se puede quitar el primer o el último segmento de la cadena.', 'error');
            return;
        }

        marcarSucio();
        ocultarMensaje();
        actualizarVistaCadena();
    }

    function invertirCadena() {
        if (estado.cadena.length === 0) { return; }
        estado.cadena.reverse().forEach(function (referencia) {
            referencia.sentido = referencia.sentido === 'normal' ? 'inverso' : 'normal';
        });
        marcarSucio();
        actualizarVistaCadena();
    }

    function limpiarCadena() {
        if (estado.cadena.length === 0) { return; }
        if (!window.confirm('¿Vaciar la cadena de segmentos?')) { return; }
        estado.cadena = [];
        marcarSucio();
        actualizarVistaCadena();
    }

    function ajustarVistaCadena() {
        var coords = coordsCadena();
        if (coords.length < 2) { return; }
        mapa.fitBounds(L.latLngBounds(coords), { padding: [40, 40], maxZoom: 19 });
    }

    function esContiguoExtremo(segmento) {
        var extremos = extremosCadena();
        if (!extremos.inicio || !extremos.fin) { return true; }
        return mismaPosicion(segmento.inicio, extremos.fin) ||
            mismaPosicion(segmento.fin, extremos.fin) ||
            mismaPosicion(segmento.fin, extremos.inicio) ||
            mismaPosicion(segmento.inicio, extremos.inicio);
    }

    /**
     * Distancia en metros de un punto `{lat, lng}` a una polilínea dada como
     * `[[lat, lng], ...]`. Proyección equirectangular local: suficiente para
     * distancias de pocos metros.
     */
    function distanciaPuntoTramo(punto, coords) {
        var latRef = punto.lat * Math.PI / 180;
        var kx = 111320 * Math.cos(latRef);
        var ky = 110540;
        var px = punto.lng * kx;
        var py = punto.lat * ky;
        var mejor = Infinity;

        for (var i = 1; i < coords.length; i += 1) {
            var ax = coords[i - 1][1] * kx;
            var ay = coords[i - 1][0] * ky;
            var bx = coords[i][1] * kx;
            var by = coords[i][0] * ky;
            var dx = bx - ax;
            var dy = by - ay;
            var largo2 = dx * dx + dy * dy;
            var t = largo2 === 0 ? 0 : ((px - ax) * dx + (py - ay) * dy) / largo2;
            t = Math.max(0, Math.min(1, t));
            var distancia = Math.sqrt(
                Math.pow(px - (ax + t * dx), 2) + Math.pow(py - (ay + t * dy), 2)
            );
            if (distancia < mejor) { mejor = distancia; }
        }

        return mejor;
    }

    /**
     * Metros por píxel a la latitud actual del mapa. Permite fijar el área de
     * acierto del clic en píxeles y que funcione igual en todos los zooms.
     */
    function metrosPorPixel() {
        var centro = mapa.getCenter();
        var punto = mapa.latLngToContainerPoint(centro);
        var alLado = mapa.containerPointToLatLng(L.point(punto.x + 1, punto.y));
        var distancia = centro.distanceTo(alLado);
        return distancia > 0 ? distancia : 1;
    }

    /** Segmento más cercano al punto, dentro del radio indicado en metros. */
    function segmentoCercano(latlng, radioMetros) {
        var mejor = null;

        estado.segmentos.forEach(function (segmento, id) {
            var distancia = distanciaPuntoTramo(latlng, segmento.coords);
            if (distancia <= radioMetros && (mejor === null || distancia < mejor.distancia)) {
                mejor = { id: id, distancia: distancia };
            }
        });

        return mejor;
    }

    /**
     * Segmento contiguo más cercano al punto del clic. Evita rechazar un clic
     * que cae a pocos píxeles del cruce con el extremo de la cadena.
     */
    function segmentoContiguoCercano(latlng) {
        if (!latlng) { return null; }
        var radio = RADIO_AYUDA_CLIC_PX * metrosPorPixel();
        var enCadena = new Set(estado.cadena.map(function (referencia) { return referencia.id; }));
        var mejor = null;

        estado.segmentos.forEach(function (segmento, id) {
            if (enCadena.has(id) || !esContiguoExtremo(segmento)) { return; }
            var distancia = distanciaPuntoTramo(latlng, segmento.coords);
            if (distancia <= radio && (mejor === null || distancia < mejor.distancia)) {
                mejor = { id: id, distancia: distancia };
            }
        });

        return mejor ? mejor.id : null;
    }

    /**
     * ¿El segmento comparte algún extremo con cualquier segmento de la cadena
     * (no solo con los extremos libres)? Sirve para explicar el rechazo.
     */
    function tocaAlgunSegmentoCadena(segmento) {
        return estado.cadena.some(function (referencia) {
            var otro = estado.segmentos.get(referencia.id);
            if (!otro) { return false; }
            return mismaPosicion(segmento.inicio, otro.inicio) ||
                mismaPosicion(segmento.inicio, otro.fin) ||
                mismaPosicion(segmento.fin, otro.inicio) ||
                mismaPosicion(segmento.fin, otro.fin);
        });
    }

    /**
     * Separación mínima entre los extremos de un segmento y los de la cadena,
     * para explicar por qué no se puede encadenar.
     */
    function distanciaSegmentoACadena(segmento) {
        var extremos = extremosCadena();
        var referencias = [extremos.inicio, extremos.fin].filter(Boolean);
        if (referencias.length === 0) { return Infinity; }

        var mejor = Infinity;
        [segmento.inicio, segmento.fin].forEach(function (clave) {
            referencias.forEach(function (referencia) {
                var distancia = distanciaEntreClaves(clave, referencia);
                if (distancia < mejor) { mejor = distancia; }
            });
        });

        return mejor;
    }

    function actualizarEstilosRed() {
        var enCadena = new Set(estado.cadena.map(function (referencia) { return referencia.id; }));
        var vacia = estado.cadena.length === 0;

        estado.capasRed.forEach(function (capa, id) {
            var segmento = estado.segmentos.get(id);
            if (!segmento) { return; }

            var estilo;
            if (enCadena.has(id)) {
                estilo = ESTILOS_RED.cadena;
            } else if (vacia || esContiguoExtremo(segmento)) {
                estilo = vacia ? ESTILOS_RED.base : ESTILOS_RED.contiguo;
            } else {
                estilo = ESTILOS_RED.noContiguo;
            }

            capa.setStyle(estilo);

            var contorno = estado.contornosRed.get(id);
            if (contorno) {
                var oculto = estilo === ESTILOS_RED.noContiguo;
                contorno.setStyle({
                    weight: oculto ? 0 : estilo.weight + 4,
                    opacity: oculto ? 0 : 0.7
                });
            }

            if (enCadena.has(id)) { capa.bringToFront(); }
        });
    }

    function dibujarCadena() {
        capaCadena.clearLayers();
        capaEtiquetas.clearLayers();
        capaMarcadores.clearLayers();

        var coords = coordsCadena();
        if (coords.length >= 2) {
            L.polyline(coords, {
                color: '#f59e0b',
                weight: 8,
                opacity: 0.92,
                smoothFactor: 0,
                interactive: false
            }).addTo(capaCadena);

            estado.cadena.forEach(function (referencia, indice) {
                var tramo = coordsOrientadas(referencia);
                if (tramo.length === 0) { return; }
                var punto = tramo[Math.floor(tramo.length / 2)];
                var marcador = L.marker(punto, {
                    interactive: false,
                    icon: L.divIcon({
                        className: '',
                        html: '<div class="etiqueta-orden">' + (indice + 1) + '</div>',
                        iconSize: [22, 22],
                        iconAnchor: [11, 11]
                    })
                });
                marcador.addTo(capaEtiquetas);
            });

            L.circleMarker(coords[0], {
                radius: 9, color: '#ffffff', weight: 2, fillColor: '#1b7f3b', fillOpacity: 1, interactive: false
            }).bindTooltip('Inicio', { direction: 'top', offset: [0, -8] }).addTo(capaMarcadores);

            L.circleMarker(coords[coords.length - 1], {
                radius: 9, color: '#ffffff', weight: 2, fillColor: '#b3261e', fillOpacity: 1, interactive: false
            }).bindTooltip('Fin', { direction: 'top', offset: [0, -8] }).addTo(capaMarcadores);
        }
    }

    function actualizarVistaCadena() {
        dom.segmentosInput.value = JSON.stringify(estado.cadena);
        dom.resumenSegmentos.textContent = String(estado.cadena.length);
        dom.resumenDistancia.textContent = metros(distanciaCadena());
        dom.resumenNodos.textContent = String(coordsCadena().length);

        dom.cadenaVacia.hidden = estado.cadena.length > 0;

        limpiar(dom.cadenaLista);
        estado.cadena.forEach(function (referencia, indice) {
            var segmento = estado.segmentos.get(referencia.id);
            var item = el('li');
            item.appendChild(el('span', 'detalle-seg-orden', String(indice + 1)));
            item.appendChild(el('span', 'detalle-seg-nombre', segmento ? segmento.nombre : referencia.id));
            item.appendChild(el('span', 'detalle-seg-sentido', referencia.sentido === 'inverso' ? 'inv.' : 'norm.'));

            var quitar = el('button', null, '×');
            quitar.type = 'button';
            quitar.title = 'Quitar de la cadena';
            quitar.addEventListener('click', function () { quitarSegmento(referencia.id); });
            item.appendChild(quitar);

            dom.cadenaLista.appendChild(item);
        });

        var hayCadena = estado.cadena.length > 0;
        dom.btnDeshacer.disabled = !hayCadena;
        dom.btnInvertir.disabled = !hayCadena;
        dom.btnLimpiar.disabled = !hayCadena;
        dom.btnZoom.disabled = !hayCadena;
        dom.btnGuardar.disabled = !hayCadena || estado.guardando;

        actualizarEstilosRed();
        dibujarCadena();
        autocompletarExtremos();
    }

    // ---------- Cambios sin guardar ----------

    function marcarSucio() {
        estado.sucio = true;
    }

    function hayCambios() {
        return estado.sucio;
    }

    function confirmarSalida() {
        if (!hayCambios()) { return true; }
        return window.confirm('Hay cambios sin guardar. ¿Salir de todos modos?');
    }

    // ---------- Guardar ----------

    function validarFormulario() {
        var valido = true;
        var nombre = dom.nombre.value.trim();

        marcarError(dom.errorNombre, '');
        marcarError(dom.errorOrigen, '');
        marcarError(dom.errorDestino, '');

        if (nombre.length < 3 || nombre.length > 80) {
            marcarError(dom.errorNombre, 'El nombre debe tener entre 3 y 80 caracteres.');
            valido = false;
        }

        if (estado.cadena.length === 0) {
            mostrarMensaje('La ruta necesita al menos un segmento.', 'error');
            valido = false;
        }

        var origen = leerReferencia('origen');
        var destino = leerReferencia('destino');

        if (!origen.ref) {
            marcarError(dom.errorOrigen, 'Selecciona el origen de la ruta.');
            valido = false;
        }
        if (!destino.ref) {
            marcarError(dom.errorDestino, 'Selecciona el destino de la ruta.');
            valido = false;
        }

        return valido ? { nombre: nombre, origen: origen, destino: destino } : null;
    }

    function guardar(evento) {
        if (evento) { evento.preventDefault(); }
        if (estado.guardando) { return; }
        ocultarMensaje();

        var datosFormulario = validarFormulario();
        if (!datosFormulario) { return; }

        estado.guardando = true;
        dom.btnGuardar.disabled = true;
        dom.btnGuardar.textContent = 'Guardando…';

        var cuerpo = new FormData();
        cuerpo.append('csrf_token', cfg.csrfToken || '');
        if (cfg.modo === 'editar' && cfg.id) {
            cuerpo.append('id', cfg.id);
            cuerpo.append('updated_at', dom.updatedAt.value);
        }
        cuerpo.append('nombre', datosFormulario.nombre);
        cuerpo.append('descripcion', dom.descripcion.value.trim());
        cuerpo.append('tipo', dom.tipo.value);
        cuerpo.append('estado', dom.estadoRuta.value);
        cuerpo.append('accesible', dom.accesible.checked ? '1' : '0');
        cuerpo.append('origen', JSON.stringify(datosFormulario.origen));
        cuerpo.append('destino', JSON.stringify(datosFormulario.destino));
        cuerpo.append('segmentos', JSON.stringify(estado.cadena));

        fetch(urls.saveRuta, {
            method: 'POST',
            body: cuerpo,
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
        }).then(function (respuesta) {
            if (respuesta.status === 403) {
                window.location.href = 'index.php?p=login';
                throw new Error('Sesión expirada.');
            }
            return respuesta.text().then(function (texto) {
                var contenido = null;
                try {
                    contenido = JSON.parse(texto);
                } catch (error) {
                    throw new Error('Respuesta inesperada del servidor (HTTP ' + respuesta.status + '). Recarga la página.');
                }
                return { estado: respuesta.status, contenido: contenido };
            });
        }).then(function (resultado) {
            if (resultado.estado === 409) {
                throw Object.assign(new Error(resultado.contenido.message || 'La ruta fue modificada por otra sesión.'), {
                    detalles: resultado.contenido.errores || []
                });
            }
            if (resultado.estado >= 400 || resultado.contenido.success === false) {
                throw Object.assign(new Error(resultado.contenido.message || 'No se pudo guardar la ruta.'), {
                    detalles: resultado.contenido.errores || []
                });
            }

            var ruta = resultado.contenido.ruta;
            estado.sucio = false;
            mostrarMensaje('Ruta guardada correctamente.', 'ok');
            window.location.href = urls.listado + '&accion=ver&id=' + encodeURIComponent(ruta.id);
        }).catch(function (error) {
            estado.guardando = false;
            dom.btnGuardar.disabled = estado.cadena.length === 0;
            dom.btnGuardar.textContent = '💾 Guardar ruta';
            mostrarMensaje('✗ ' + error.message, 'error', error.detalles || []);
        });
    }

    // ---------- Eventos ----------

    dom.btnDeshacer.addEventListener('click', function () {
        if (estado.cadena.length === 0) { return; }
        estado.cadena.pop();
        marcarSucio();
        actualizarVistaCadena();
    });

    dom.btnInvertir.addEventListener('click', invertirCadena);
    dom.btnLimpiar.addEventListener('click', limpiarCadena);
    dom.btnZoom.addEventListener('click', ajustarVistaCadena);
    dom.form.addEventListener('submit', guardar);

    // El clic se resuelve sobre el mapa, no sobre cada polilínea: gana el
    // segmento más cercano al cursor, sin depender del orden de las capas.
    mapa.on('click', function (evento) {
        if (!estado.listo || estado.guardando) { return; }
        var cercano = segmentoCercano(evento.latlng, RADIO_CLIC_PX * metrosPorPixel());
        if (!cercano) { return; }
        alHacerClicSegmento(cercano.id, evento.latlng);
    });

    mapa.on('mousemove', function (evento) {
        if (!estado.listo || estado.guardando) { return; }
        var cercano = segmentoCercano(evento.latlng, RADIO_CLIC_PX * metrosPorPixel());
        mapa.getContainer().classList.toggle('mapa-sobre-segmento', cercano !== null);
    });

    mapa.on('mouseout', function () {
        mapa.getContainer().classList.remove('mapa-sobre-segmento');
    });

    [dom.nombre, dom.descripcion, dom.tipo, dom.estadoRuta, dom.accesible].forEach(function (campo) {
        if (!campo) { return; }
        campo.addEventListener('input', marcarSucio);
        campo.addEventListener('change', marcarSucio);
    });

    dom.origenTipo.addEventListener('change', function () {
        configurarTipoReferencia('origen', dom.origenTipo.value);
        estado.origenManual = true;
        marcarSucio();
    });

    dom.destinoTipo.addEventListener('change', function () {
        configurarTipoReferencia('destino', dom.destinoTipo.value);
        estado.destinoManual = true;
        marcarSucio();
    });

    dom.origenRef.addEventListener('change', function () {
        estado.origenManual = true;
        marcarSucio();
    });

    dom.destinoRef.addEventListener('change', function () {
        estado.destinoManual = true;
        marcarSucio();
    });

    dom.btnCancelar.addEventListener('click', function () {
        if (confirmarSalida()) { window.location.href = urls.listado; }
    });

    dom.btnVolver.addEventListener('click', function (evento) {
        if (!confirmarSalida()) { evento.preventDefault(); }
    });

    window.addEventListener('beforeunload', function (evento) {
        if (!hayCambios()) { return undefined; }
        evento.preventDefault();
        evento.returnValue = '';
        return '';
    });

    // ---------- Inicio ----------

    configurarTipoReferencia('origen', 'edificio');
    configurarTipoReferencia('destino', 'edificio');
    actualizarVistaCadena();

    // La red debe estar cargada antes de precargar la ruta: si se hace en
    // paralelo, la cadena se compara contra un índice vacío y los segmentos
    // válidos se descartan (bug de "segmentos que ya no existen").
    cargarRed().then(cargarRutaExistente).then(function () {
        estado.listo = true;
        estado.sucio = false;
        actualizarVistaCadena();

        var capaEdificiosG = L.featureGroup();
        capaEdificios.eachLayer(function (capa) { capaEdificiosG.addLayer(capa); });
        if (capaEdificiosG.getLayers().length > 0) {
            mapa.fitBounds(capaEdificiosG.getBounds(), { padding: [20, 20], maxZoom: 18 });
        }

        if (estado.cadena.length > 0) {
            ajustarVistaCadena();
        }
    }).catch(function (error) {
        mostrarMensaje('✗ ' + error.message, 'error');
    });
})();
