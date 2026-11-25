// ==========================================
// EDITOR DE LA RED DE CAMINOS
// ==========================================
// Vista: `backend/admin/editar-red.php` · estilos: `css/admin/red-editor.css`
// Análisis: `js/admin/network-analysis.js` (E01–E08, W01–W06)
//
// Modos:
//   consultar → seleccionar y ver detalle
//   dibujar   → polilínea manual con snapping a la red (4 m)
//   editar    → vértices del segmento seleccionado con Leaflet-Geoman
//   eliminar  → marcar/desmarcar segmentos (borrado diferido al guardar)
//
// El servidor es la autoridad: aquí solo se orienta y se evita un viaje
// inútil. `save-red.php` revalida todo antes de escribir.

document.addEventListener('DOMContentLoaded', function () {
    'use strict';

    // ==========================================
    // REFERENCIAS DEL DOM
    // ==========================================
    var contenedorMapa = document.getElementById('map-red');
    if (!contenedorMapa) {
        return;
    }

    var elRevision = document.getElementById('red-revision');
    var elContador = document.getElementById('red-contador');
    var elTotalLista = document.getElementById('red-total-lista');
    var elBuscar = document.getElementById('red-buscar');
    var elLista = document.getElementById('red-lista');
    var elListaVacia = document.getElementById('red-lista-vacia');
    var elResumen = document.getElementById('red-resumen-validacion');
    var elErrores = document.getElementById('red-errores');
    var elAdvertencias = document.getElementById('red-advertencias');
    var elTotalAvisos = document.getElementById('red-total-avisos');
    var elEstado = document.getElementById('red-estado');
    var elDetalle = document.getElementById('red-detalle');
    var elMensaje = document.getElementById('red-mensaje');
    var btnGuardar = document.getElementById('btn-guardar');
    var btnDescartar = document.getElementById('btn-descartar');
    var btnTerminarDibujo = document.getElementById('btn-terminar-dibujo');
    var botonesModo = Array.prototype.slice.call(document.querySelectorAll('.red-mode'));

    // Reparto vertical de la barra lateral (Segmentos / Validación).
    var elSidebar = document.querySelector('.red-sidebar');
    var elSplitter = document.getElementById('red-splitter');
    var bloqueSegmentos = document.getElementById('bloque-segmentos');
    var bloqueValidacion = document.getElementById('bloque-validacion');

    var RED_INICIAL = window.RED_INICIAL;
    var RED_REVISION = Number(window.RED_REVISION || 0);
    var RED_CSRF = String(window.RED_CSRF || '');
    var RED_PAIRS = Array.isArray(window.RED_PAIRS) ? window.RED_PAIRS : [];
    var RED_METADATOS = window.RED_METADATOS || {};
    var RED_ESTILOS_MAPA = window.RED_ESTILOS_MAPA || {};
    var ESTILO_EDIFICIOS_ADMIN = RED_ESTILOS_MAPA.edificios_admin || {
        contorno: { color: '#000000', grosor: 1, opacidad: 1 },
        relleno: { color: '#9aa5b1', opacidad: 0.9 }
    };
    var ESTILO_SEGMENTOS = RED_ESTILOS_MAPA.segmentos || { color: '#0b57d0', grosor: 6, opacidad: 1 };
    var URL_GUARDAR = window.RED_URL_GUARDAR || 'index.php?p=save-red';
    var URL_ESTADO = window.RED_URL_ESTADO || 'index.php?p=getRed';

    if (!RED_INICIAL || !Array.isArray(RED_INICIAL.features)) {
        actualizarEstado('No se pudo cargar la red. Recarga la página.');
        return;
    }

    // ==========================================
    // ESTADO
    // ==========================================
    var estado = {
        geojson: clonar(RED_INICIAL),
        redVigente: clonar(RED_INICIAL),
        revision: RED_REVISION,
        coordsVigentes: {},
        recuadro: redRecuadro(RED_INICIAL),
        seleccionado: null,
        modo: 'consultar',
        pendientesEliminar: {},
        analisis: null,
        ultimaPropuesta: null,
        guardando: false,
        sucio: false,
        capaEditando: null,
        contadorTmp: 0,
        dibujo: null,
        altoSegmentos: null,
        arrastreReparto: null,
        mensajeTemporizador: null,
        analisisTemporizador: null,
        avisoTemporizador: null
    };

    var capas = {};
    var capasGolpe = {};
    var capaRed = L.layerGroup();
    var capaExtremos = L.layerGroup();
    var capaAvisos = L.layerGroup();

    // ==========================================
    // UTILIDADES
    // ==========================================
    function clonar(valor) {
        return JSON.parse(JSON.stringify(valor));
    }

    function idDe(feature) {
        return String(
            (feature && feature.properties && feature.properties.id)
            || (feature && feature.id)
            || ''
        );
    }

    function buscarFeature(id) {
        if (!id) {
            return null;
        }
        for (var i = 0; i < estado.geojson.features.length; i++) {
            if (idDe(estado.geojson.features[i]) === id) {
                return estado.geojson.features[i];
            }
        }
        return null;
    }

    function coordsALatLngs(coords) {
        return coords.map(function (punto) {
            return [Number(punto[1]), Number(punto[0])];
        });
    }

    function centrarCaja(coords) {
        var minLat = Infinity;
        var maxLat = -Infinity;
        var minLng = Infinity;
        var maxLng = -Infinity;

        (coords || []).forEach(function (punto) {
            var lat = Number(punto[1]);
            var lng = Number(punto[0]);
            if (lat < minLat) { minLat = lat; }
            if (lat > maxLat) { maxLat = lat; }
            if (lng < minLng) { minLng = lng; }
            if (lng > maxLng) { maxLng = lng; }
        });

        if (!isFinite(minLat)) {
            return null;
        }
        return { lat: (minLat + maxLat) / 2, lng: (minLng + maxLng) / 2 };
    }

    function escaparParaEstado(texto) {
        return String(texto);
    }

    function actualizarEstado(texto) {
        if (elEstado) {
            elEstado.textContent = escaparParaEstado(texto);
        }
    }

    function mostrarMensaje(texto, tipo) {
        if (!elMensaje) {
            return;
        }
        elMensaje.textContent = String(texto);
        elMensaje.className = 'red-mensaje show ' + (tipo || 'success');
        elMensaje.hidden = false;

        if (estado.mensajeTemporizador) {
            clearTimeout(estado.mensajeTemporizador);
        }
        estado.mensajeTemporizador = setTimeout(function () {
            elMensaje.classList.remove('show');
            elMensaje.hidden = true;
        }, tipo === 'error' ? 7000 : 4500);
    }

    function desplazarALatLng(lat, lng, zoomMinimo) {
        var zoom = Math.max(map.getZoom(), zoomMinimo || 18);
        map.flyTo([lat, lng], zoom, { duration: 0.4 });
    }

    // ==========================================
    // ESTADO DE CADA SEGMENTO
    // ==========================================
    function estadoSegmento(id) {
        if (estado.pendientesEliminar[id]) {
            return 'eliminado';
        }
        if (id.indexOf('tmp-') === 0) {
            return 'nuevo';
        }
        if (!Object.prototype.hasOwnProperty.call(estado.coordsVigentes, id)) {
            return 'nuevo';
        }
        var feature = buscarFeature(id);
        if (!feature) {
            return 'existente';
        }
        return JSON.stringify(feature.geometry.coordinates) === estado.coordsVigentes[id]
            ? 'existente'
            : 'modificado';
    }

    function etiquetaEstado(id) {
        var st = estadoSegmento(id);
        if (st === 'nuevo') { return 'Nuevo'; }
        if (st === 'modificado') { return 'Modificado'; }
        if (st === 'eliminado') { return 'Eliminar'; }
        return '';
    }

    function guardarCoordsVigentes() {
        estado.coordsVigentes = {};
        estado.redVigente.features.forEach(function (feature) {
            estado.coordsVigentes[idDe(feature)] = JSON.stringify(feature.geometry.coordinates);
        });
    }

    // ==========================================
    // PROPUESTA Y CAMBIOS
    // ==========================================
    function propuestaActual() {
        var features = estado.geojson.features.filter(function (feature) {
            return !estado.pendientesEliminar[idDe(feature)];
        });

        var propuesta = clonar(estado.geojson);
        delete propuesta.revision;
        delete propuesta.next_seq;
        propuesta.features = features;
        return propuesta;
    }

    function calcularSucio() {
        if (Object.keys(estado.pendientesEliminar).length > 0) {
            return true;
        }
        if (estado.geojson.features.length !== estado.redVigente.features.length) {
            return true;
        }
        for (var i = 0; i < estado.geojson.features.length; i++) {
            var feature = estado.geojson.features[i];
            var id = idDe(feature);
            if (!Object.prototype.hasOwnProperty.call(estado.coordsVigentes, id)) {
                return true;
            }
            if (JSON.stringify(feature.geometry.coordinates) !== estado.coordsVigentes[id]) {
                return true;
            }
        }
        return false;
    }

    function marcarSucio() {
        estado.sucio = calcularSucio();
        if (btnGuardar) {
            btnGuardar.disabled = !estado.sucio || estado.guardando;
        }
        document.title = (estado.sucio ? '● ' : '') + 'Red de caminos - ITL Laguna';
    }

    function actualizarContadores(propuesta) {
        var total = propuesta.features.length;
        if (elContador) { elContador.textContent = String(total); }
        if (elTotalLista) { elTotalLista.textContent = String(total); }
    }

    // ==========================================
    // MAPA
    // ==========================================
    var map = L.map('map-red', {
        attributionControl: false,
        center: [25.5324, -103.4360],
        zoom: 17,
        minZoom: 15,
        maxZoom: 19
    });

    L.control.attribution({ prefix: false }).addTo(map);

    L.tileLayer('tiles/satelital/{z}/{x}/{y}.jpg', {
        minZoom: 15,
        maxZoom: 19,
        tms: false,
        attribution: 'Mapa ITL Laguna'
    }).addTo(map);

    // Los edificios de referencia deben quedar por debajo de la red editable.
    // El `overlayPane` de Leaflet está en 400; este pane propio vive en 350.
    map.createPane('fondoRed');
    map.getPane('fondoRed').style.zIndex = 350;
    map.getPane('fondoRed').style.pointerEvents = 'none';

    capaRed.addTo(map);
    capaExtremos.addTo(map);
    capaAvisos.addTo(map);

    // `snappable` ayuda a que los vértices arrastrados se peguen a la red.
    if (L.PM && typeof map.pm.setGlobalOptions === 'function') {
        map.pm.setGlobalOptions({
            snappable: true,
            snapDistance: 6,
            snapSegment: true,
            allowSelfIntersection: true,
            preventMarkerRemoval: false
        });
    }

    // ==========================================
    // CAPA DE REFERENCIA (EDIFICIOS Y ACCESOS)
    // ==========================================
    function cargarReferencia() {
        var coleccion = window.datosTec;
        if (!coleccion || !Array.isArray(coleccion.features)) {
            return;
        }

        L.geoJSON(coleccion, {
            interactive: false,
            pmIgnore: true,
            smoothFactor: 0,
            pane: 'fondoRed',
            style: function () {
                return {
                    color: ESTILO_EDIFICIOS_ADMIN.contorno.color,
                    weight: ESTILO_EDIFICIOS_ADMIN.contorno.grosor,
                    opacity: ESTILO_EDIFICIOS_ADMIN.contorno.opacidad,
                    fillColor: ESTILO_EDIFICIOS_ADMIN.relleno.color,
                    fillOpacity: ESTILO_EDIFICIOS_ADMIN.relleno.opacidad
                };
            }
        }).addTo(map);

        // Etiquetas de las puertas: ayudan a orientarse y no son editables.
        coleccion.features.forEach(function (feature) {
            var id = idDe(feature);
            var meta = RED_METADATOS[id] || {};
            if (meta.tipo !== 'acceso') {
                return;
            }
            var coords = feature.geometry && feature.geometry.coordinates;
            var centro = null;
            if (feature.geometry && feature.geometry.type === 'Polygon' && coords && coords[0]) {
                centro = centrarCaja(coords[0]);
            } else if (feature.geometry && feature.geometry.type === 'Point' && coords) {
                centro = { lat: Number(coords[1]), lng: Number(coords[0]) };
            }
            if (!centro) {
                return;
            }

            L.circleMarker([centro.lat, centro.lng], {
                radius: 7,
                color: '#b45309',
                weight: 2,
                fillColor: '#fcd34d',
                fillOpacity: 0.65,
                interactive: false,
                pmIgnore: true,
                pane: 'fondoRed'
            }).addTo(map).bindTooltip(id, {
                permanent: true,
                direction: 'center',
                className: 'red-tooltip-acceso'
            });
        });
    }

    // ==========================================
    // CAPA DE LA RED Y ESTILOS
    // ==========================================
    function estiloSegmento(id) {
        var st = estadoSegmento(id);
        if (st === 'eliminado') {
            return { color: '#dc2626', weight: 6, opacity: 0.95, dashArray: '8 5' };
        }
        if (st === 'nuevo') {
            return { color: '#16a34a', weight: 7, opacity: 1 };
        }
        if (st === 'modificado') {
            return { color: '#ea580c', weight: 7, opacity: 1 };
        }
        return {
            color: ESTILO_SEGMENTOS.color,
            weight: ESTILO_SEGMENTOS.grosor,
            opacity: ESTILO_SEGMENTOS.opacidad
        };
    }

    function renderRed() {
        capaRed.clearLayers();
        capas = {};
        capasGolpe = {};

        estado.geojson.features.forEach(function (feature) {
            var id = idDe(feature);
            var latlngs = coordsALatLngs(feature.geometry.coordinates);
            var capa = L.polyline(latlngs, Object.assign({
                smoothFactor: 0
            }, estiloSegmento(id)));

            capa.on('click', function (evento) {
                alClicSegmento(id, evento);
            });

            capa.addTo(capaRed);
            capas[id] = capa;

            // Zona de clic más generosa que el trazo: la línea visible mide
            // 6–7 px y acertar con el cursor era incómodo. Esta polilínea
            // transparente de 20 px recibe el clic y comparte el manejador.
            var golpe = L.polyline(latlngs, {
                smoothFactor: 0,
                color: '#000000',
                weight: 20,
                opacity: 0,
                fill: false,
                className: 'red-golpe'
            });
            golpe.on('click', function (evento) {
                alClicSegmento(id, evento);
            });
            golpe.addTo(capaRed);
            capasGolpe[id] = golpe;
        });

        aplicarEstilos();
    }

    function aplicarEstilos() {
        Object.keys(capas).forEach(function (id) {
            aplicarEstiloSegmento(id);
        });

        if (estado.seleccionado && capas[estado.seleccionado]) {
            capas[estado.seleccionado].bringToFront();
        }
    }

    function aplicarEstiloSegmento(id) {
        if (!capas[id]) {
            return;
        }
        var estilo = estiloSegmento(id);
        if (id === estado.seleccionado) {
            estilo.color = '#f59e0b';
            estilo.weight = 9;
            estilo.opacity = 1;
        }
        capas[id].setStyle(estilo);
    }

    function renderExtremos() {
        capaExtremos.clearLayers();
        if (!estado.analisis) {
            return;
        }
        (estado.analisis.extremos || []).forEach(function (clave) {
            var punto = redPuntoDeClave(clave);
            L.circleMarker([punto.lat, punto.lng], {
                radius: 4,
                color: '#b45309',
                weight: 1.5,
                fillColor: '#f59e0b',
                fillOpacity: 1,
                interactive: false
            }).addTo(capaExtremos);
        });
    }

    // ==========================================
    // SELECCIÓN Y DETALLE
    // ==========================================
    function alClicSegmento(id, evento) {
        if (estado.modo === 'dibujar') {
            // Deja que el clic llegue al mapa: se añadirá un vértice.
            return;
        }

        if (evento && evento.originalEvent && L.DomEvent) {
            L.DomEvent.stopPropagation(evento.originalEvent);
        }

        if (estado.modo === 'eliminar') {
            seleccionar(id, false);
            alternarEliminar(id);
            return;
        }

        seleccionar(id, false);
    }

    function seleccionar(id, centrar) {
        if (estado.capaEditando && estado.capaEditando !== id) {
            desactivarEdicion();
        }

        estado.seleccionado = id || null;
        aplicarEstilos();
        renderDetalle();
        renderLista();

        if (id && capas[id]) {
            capas[id].bringToFront();
            if (centrar) {
                map.fitBounds(capas[id].getBounds(), {
                    padding: [70, 70],
                    maxZoom: 18
                });
            }
        }

        if (id && estado.modo === 'editar') {
            activarEdicion(id);
        }

        actualizarEstadoContextual();
    }

    function renderDetalle() {
        if (!elDetalle) {
            return;
        }

        var id = estado.seleccionado;
        var feature = buscarFeature(id);
        if (!id || !feature) {
            elDetalle.hidden = true;
            elDetalle.textContent = '';
            return;
        }

        elDetalle.hidden = false;
        elDetalle.textContent = '';

        var cabecera = document.createElement('div');
        cabecera.className = 'red-detalle-head';

        var titulo = document.createElement('strong');
        titulo.textContent = id;
        cabecera.appendChild(titulo);

        var cerrar = document.createElement('button');
        cerrar.type = 'button';
        cerrar.className = 'red-detalle-cerrar';
        cerrar.textContent = '×';
        cerrar.title = 'Cerrar detalle';
        cerrar.addEventListener('click', function () {
            seleccionar(null, false);
        });
        cabecera.appendChild(cerrar);

        elDetalle.appendChild(cabecera);

        var coords = feature.geometry.coordinates;
        var datos = document.createElement('dl');
        datos.className = 'red-detalle-datos';

        function fila(etiqueta, valor) {
            var dt = document.createElement('dt');
            dt.textContent = etiqueta;
            var dd = document.createElement('dd');
            dd.textContent = valor;
            datos.appendChild(dt);
            datos.appendChild(dd);
        }

        fila('Estado', estadoSegmento(id));
        fila('Vértices', String(coords.length));
        fila('Longitud', redLongitud(coords).toFixed(1) + ' m');
        if (estado.pendientesEliminar[id]) {
            fila('Borrado', 'pendiente al guardar');
        }

        elDetalle.appendChild(datos);

        var acciones = document.createElement('div');
        acciones.className = 'red-detalle-acciones';

        var btnCentrar = document.createElement('button');
        btnCentrar.type = 'button';
        btnCentrar.className = 'btn btn-secondary';
        btnCentrar.textContent = 'Centrar';
        btnCentrar.addEventListener('click', function () {
            if (capas[id]) {
                map.fitBounds(capas[id].getBounds(), { padding: [70, 70], maxZoom: 18 });
            }
        });
        acciones.appendChild(btnCentrar);

        var btnEditar = document.createElement('button');
        btnEditar.type = 'button';
        btnEditar.className = 'btn btn-primary';
        btnEditar.textContent = 'Editar vértices';
        btnEditar.addEventListener('click', function () {
            cambiarModo('editar');
        });
        acciones.appendChild(btnEditar);

        var btnEliminar = document.createElement('button');
        btnEliminar.type = 'button';
        btnEliminar.className = 'btn btn-danger';
        btnEliminar.textContent = estado.pendientesEliminar[id] ? 'Quitar marca' : 'Eliminar';
        btnEliminar.addEventListener('click', function () {
            alternarEliminar(id);
        });
        acciones.appendChild(btnEliminar);

        elDetalle.appendChild(acciones);
    }

    // ==========================================
    // LISTA DE SEGMENTOS
    // ==========================================
    function crearItemLista(id) {
        var feature = buscarFeature(id);
        if (!feature) {
            return null;
        }

        var li = document.createElement('li');
        var boton = document.createElement('button');
        boton.type = 'button';
        boton.className = 'red-item';

        var st = estadoSegmento(id);
        if (st !== 'existente') {
            boton.className += ' red-item-' + st;
        }
        if (estado.seleccionado === id) {
            boton.className += ' active';
        }
        boton.setAttribute('data-id', id);

        var cabecera = document.createElement('span');
        cabecera.className = 'red-item-cabecera';

        var etiqueta = document.createElement('span');
        etiqueta.className = 'red-item-id';
        etiqueta.textContent = id;
        cabecera.appendChild(etiqueta);

        var textoEstado = etiquetaEstado(id);
        if (textoEstado) {
            var badge = document.createElement('span');
            badge.className = 'red-item-badge red-item-badge-' + st;
            badge.textContent = textoEstado;
            cabecera.appendChild(badge);
        }

        boton.appendChild(cabecera);

        var meta = document.createElement('span');
        meta.className = 'red-item-meta';
        meta.textContent = feature.geometry.coordinates.length + ' vértices · '
            + redLongitud(feature.geometry.coordinates).toFixed(1) + ' m';
        boton.appendChild(meta);

        boton.addEventListener('click', function () {
            if (estado.modo === 'eliminar') {
                seleccionar(id, true);
                alternarEliminar(id);
            } else {
                seleccionar(id, true);
            }
        });

        li.appendChild(boton);
        return li;
    }

    function renderLista() {
        if (!elLista) {
            return;
        }

        var filtro = elBuscar ? String(elBuscar.value || '').trim().toLowerCase() : '';
        var ids = estado.geojson.features.map(idDe).filter(function (id) {
            return filtro === '' || id.toLowerCase().indexOf(filtro) !== -1;
        });

        ids.sort(function (a, b) {
            return a.localeCompare(b, 'es', { numeric: true });
        });

        elLista.textContent = '';
        var fragmento = document.createDocumentFragment();
        ids.forEach(function (id) {
            var item = crearItemLista(id);
            if (item) {
                fragmento.appendChild(item);
            }
        });
        elLista.appendChild(fragmento);

        if (elListaVacia) {
            elListaVacia.hidden = ids.length > 0;
        }
    }

    /**
     * Actualizar solo la fila de un segmento (se usa al arrastrar vértices:
     * reconstruir las 313 filas en cada evento sería muy costoso).
     */
    function actualizarItemLista(id) {
        if (!elLista) {
            return;
        }
        var boton = elLista.querySelector('[data-id="' + id.replace(/"/g, '\\"') + '"]');
        var feature = buscarFeature(id);
        if (!boton || !feature) {
            return;
        }

        boton.className = 'red-item';
        var st = estadoSegmento(id);
        if (st !== 'existente') {
            boton.className += ' red-item-' + st;
        }
        if (estado.seleccionado === id) {
            boton.className += ' active';
        }

        var meta = boton.querySelector('.red-item-meta');
        if (meta) {
            meta.textContent = feature.geometry.coordinates.length + ' vértices · '
                + redLongitud(feature.geometry.coordinates).toFixed(1) + ' m';
        }

        var badge = boton.querySelector('.red-item-badge');
        var textoEstado = etiquetaEstado(id);
        if (badge) {
            if (textoEstado) {
                badge.className = 'red-item-badge red-item-badge-' + st;
                badge.textContent = textoEstado;
            } else {
                badge.remove();
            }
        } else if (textoEstado) {
            var cabecera = boton.querySelector('.red-item-cabecera');
            if (cabecera) {
                var nuevo = document.createElement('span');
                nuevo.className = 'red-item-badge red-item-badge-' + st;
                nuevo.textContent = textoEstado;
                cabecera.appendChild(nuevo);
            }
        }
    }

    // ==========================================
    // VALIDACIÓN
    // ==========================================
    function crearAviso(aviso, tipo) {
        var boton = document.createElement('button');
        boton.type = 'button';
        boton.className = 'red-aviso';

        var codigo = document.createElement('span');
        codigo.className = 'red-aviso-codigo';
        codigo.textContent = aviso.codigo || '·';
        boton.appendChild(codigo);

        var texto = document.createElement('span');
        texto.textContent = aviso.mensaje || '';
        boton.appendChild(texto);

        boton.addEventListener('click', function () {
            resaltarAviso(aviso, tipo);
        });

        return boton;
    }

    function resaltarAviso(aviso, tipo) {
        if (aviso.punto) {
            desplazarALatLng(Number(aviso.punto.lat), Number(aviso.punto.lng), 18);
            if (estado.avisoTemporizador) {
                clearTimeout(estado.avisoTemporizador);
            }
            capaAvisos.clearLayers();
            var marca = L.circleMarker([Number(aviso.punto.lat), Number(aviso.punto.lng)], {
                radius: tipo === 'error' ? 14 : 11,
                color: tipo === 'error' ? '#dc2626' : '#f59e0b',
                weight: 3,
                fillColor: tipo === 'error' ? '#dc2626' : '#f59e0b',
                fillOpacity: 0.15,
                interactive: false
            }).addTo(capaAvisos);
            estado.avisoTemporizador = setTimeout(function () {
                capaAvisos.removeLayer(marca);
            }, 4000);
            return;
        }

        if (aviso.ids && aviso.ids.length > 0) {
            seleccionar(String(aviso.ids[0]), true);
            return;
        }

        if (aviso.codigo === 'E08' && aviso.pares && aviso.pares.length > 0) {
            var par = null;
            for (var i = 0; i < RED_PAIRS.length; i++) {
                if (RED_PAIRS[i].id === aviso.pares[0]) {
                    par = RED_PAIRS[i];
                    break;
                }
            }
            if (par) {
                map.fitBounds([
                    [par.origen.lat, par.origen.lng],
                    [par.destino.lat, par.destino.lng]
                ], { padding: [60, 60] });
            }
        }
    }

    function renderValidacion() {
        if (!elErrores || !elAdvertencias) {
            return;
        }

        var analisis = estado.analisis;
        var errores = analisis ? analisis.errores : [];
        var advertencias = analisis ? analisis.advertencias : [];

        elErrores.textContent = '';
        elAdvertencias.textContent = '';

        errores.forEach(function (error) {
            elErrores.appendChild(crearAviso(error, 'error'));
        });

        advertencias.forEach(function (advertencia) {
            elAdvertencias.appendChild(crearAviso(advertencia, 'warning'));
        });

        if (elTotalAvisos) {
            elTotalAvisos.textContent = String(errores.length + advertencias.length);
        }

        if (!elResumen) {
            return;
        }

        elResumen.className = 'red-resumen';
        var eliminados = Object.keys(estado.pendientesEliminar).length;

        if (errores.length > 0) {
            elResumen.className += ' error';
            elResumen.textContent = errores.length + ' error(es) bloquean el guardado'
                + (advertencias.length ? ' · ' + advertencias.length + ' advertencia(s)' : '');
            return;
        }

        var metricas = analisis ? analisis.metricas : null;
        var base = metricas
            ? 'Red válida · ' + metricas.nodos + ' nodos · ' + metricas.aristas
                + ' aristas · ' + metricas.componentes + ' componente(s)'
            : 'Red válida';

        if (advertencias.length > 0) {
            elResumen.className += ' warning';
            elResumen.textContent = base + ' · ' + advertencias.length + ' advertencia(s)';
        } else {
            elResumen.className += ' ok';
            elResumen.textContent = base;
        }

        if (eliminados > 0) {
            elResumen.textContent += ' · ' + eliminados + ' para eliminar';
        }
    }

    function ejecutarAnalisis() {
        if (!estado.geojson) {
            return;
        }

        var propuesta = propuestaActual();
        estado.ultimaPropuesta = propuesta;

        try {
            estado.analisis = redAnalizarRed(propuesta, {
                redVigente: estado.redVigente,
                pares: RED_PAIRS,
                recuadro: estado.recuadro
            });
        } catch (error) {
            console.error('No se pudo analizar la red:', error);
            estado.analisis = {
                ok: false,
                errores: [{
                    codigo: 'E00',
                    mensaje: 'No se pudo analizar la red en el navegador: ' + error.message
                        + '. El servidor volverá a validarla al guardar.'
                }],
                advertencias: [],
                metricas: { nodos: 0, aristas: 0, grado1: 0, componentes: 0 },
                extremos: []
            };
        }

        actualizarContadores(propuesta);
        renderValidacion();
        renderExtremos();
        aplicarEstilos();
        marcarSucio();
    }

    function programarAnalisis(retraso) {
        if (estado.analisisTemporizador) {
            clearTimeout(estado.analisisTemporizador);
        }
        estado.analisisTemporizador = setTimeout(function () {
            estado.analisisTemporizador = null;
            ejecutarAnalisis();
        }, typeof retraso === 'number' ? retraso : 350);
    }

    // ==========================================
    // MODOS
    // ==========================================
    function actualizarEstadoContextual() {
        if (estado.modo === 'consultar') {
            actualizarEstado(estado.seleccionado
                ? 'Consultar: ' + estado.seleccionado + ' seleccionado. Haz clic en otro segmento para cambiar.'
                : 'Consultar: haz clic en un segmento (lista o mapa) para ver su detalle.');
            return;
        }
        if (estado.modo === 'dibujar') {
            actualizarEstado('Dibujar: clic para agregar vértices (se engancha a la red a ≤4 m). '
                + '«Terminar dibujo», doble clic o Enter para añadirlo; Esc o el botón Dibujar para salir sin guardar.');
            return;
        }
        if (estado.modo === 'editar') {
            actualizarEstado(estado.seleccionado
                ? 'Editar: arrastra los vértices de ' + estado.seleccionado + '. Los cambios se aplican al guardar.'
                : 'Editar: selecciona un segmento en el mapa o en la lista.');
            return;
        }
        actualizarEstado('Eliminar: haz clic en un segmento para marcarlo o desmarcarlo. '
            + 'El borrado se aplica al guardar.');
    }

    function cambiarModo(modo) {
        // El botón «Dibujar» es un interruptor: un clic entra en modo dibujo y
        // otro sale sin añadir nada. Solo «Terminar dibujo», Enter o el doble
        // clic convierten el trazado en un segmento de la propuesta.
        if (modo === 'dibujar' && estado.modo === 'dibujar') {
            cancelarDibujo();
            modo = 'consultar';
        }

        if (estado.dibujo && modo !== 'dibujar') {
            cancelarDibujo(true);
        }

        if (estado.modo === 'editar' && modo !== 'editar') {
            desactivarEdicion();
        }

        estado.modo = modo || 'consultar';

        botonesModo.forEach(function (boton) {
            boton.classList.toggle('active', boton.getAttribute('data-modo') === estado.modo);
        });

        map.getContainer().style.cursor = estado.modo === 'dibujar' ? 'crosshair' : '';

        if (estado.modo === 'dibujar') {
            iniciarDibujo();
        } else if (estado.modo === 'editar' && estado.seleccionado) {
            activarEdicion(estado.seleccionado);
        }

        actualizarEstadoContextual();
    }

    botonesModo.forEach(function (boton) {
        boton.addEventListener('click', function () {
            cambiarModo(boton.getAttribute('data-modo'));
        });
    });

    // ==========================================
    // DIBUJO MANUAL
    // ==========================================
    var MIN_DISTANCIA_PX = 2;

    function iniciarDibujo() {
        cancelarDibujo(true);
        map.doubleClickZoom.disable();

        estado.dibujo = {
            coords: [],
            markers: [],
            linea: null,
            guia: null,
            snap: null
        };

        map.on('mousemove', alMoverRatonDibujo);
        actualizarBotonTerminar();
        actualizarEstadoContextual();
    }

    function actualizarBotonTerminar() {
        if (btnTerminarDibujo) {
            btnTerminarDibujo.hidden = !estado.dibujo;
        }
    }

    function limpiarCapasDibujo() {
        if (!estado.dibujo) {
            return;
        }
        estado.dibujo.markers.forEach(function (marca) { map.removeLayer(marca); });
        if (estado.dibujo.linea) { map.removeLayer(estado.dibujo.linea); }
        if (estado.dibujo.guia) { map.removeLayer(estado.dibujo.guia); }
        if (estado.dibujo.snap) { map.removeLayer(estado.dibujo.snap); }
        estado.dibujo = null;
        actualizarBotonTerminar();
    }

    function cancelarDibujo(silencioso) {
        if (!estado.dibujo) {
            return;
        }
        limpiarCapasDibujo();
        map.off('mousemove', alMoverRatonDibujo);
        map.doubleClickZoom.enable();
        map.getContainer().style.cursor = estado.modo === 'dibujar' ? 'crosshair' : '';
        if (!silencioso) {
            mostrarMensaje('Dibujo cancelado.', 'warning');
            actualizarEstadoContextual();
        }
    }

    function puntoAjustado(latlng) {
        var ajuste = redAjustarPuntoARed(
            latlng.lat,
            latlng.lng,
            estado.geojson.features.filter(function (feature) {
                return !estado.pendientesEliminar[idDe(feature)];
            }),
            4
        );
        if (ajuste) {
            return { lat: ajuste.lat, lng: ajuste.lng, ajustado: true, tipo: ajuste.tipo };
        }
        return { lat: latlng.lat, lng: latlng.lng, ajustado: false };
    }

    function alMoverRatonDibujo(evento) {
        if (!estado.dibujo) {
            return;
        }

        var punto = puntoAjustado(evento.latlng);

        if (!estado.dibujo.snap) {
            estado.dibujo.snap = L.circleMarker([punto.lat, punto.lng], {
                radius: 6,
                color: punto.ajustado ? '#b45309' : '#16a34a',
                weight: 2,
                fillColor: punto.ajustado ? '#f59e0b' : '#16a34a',
                fillOpacity: 0.35,
                interactive: false
            }).addTo(map);
        } else {
            estado.dibujo.snap.setLatLng([punto.lat, punto.lng]);
            estado.dibujo.snap.setStyle({
                color: punto.ajustado ? '#b45309' : '#16a34a',
                fillColor: punto.ajustado ? '#f59e0b' : '#16a34a'
            });
        }

        if (estado.dibujo.coords.length > 0) {
            var ultimo = estado.dibujo.coords[estado.dibujo.coords.length - 1];
            if (!estado.dibujo.guia) {
                estado.dibujo.guia = L.polyline([[ultimo[0], ultimo[1]], [punto.lat, punto.lng]], {
                    color: '#16a34a',
                    weight: 2,
                    opacity: 0.7,
                    dashArray: '5 5',
                    interactive: false
                }).addTo(map);
            } else {
                estado.dibujo.guia.setLatLngs([[ultimo[0], ultimo[1]], [punto.lat, punto.lng]]);
            }
        }
    }

    function distanciaPx(a, b) {
        return map.latLngToContainerPoint(a).distanceTo(map.latLngToContainerPoint(b));
    }

    function agregarVerticeDibujo(latlng) {
        if (!estado.dibujo) {
            return;
        }

        var punto = puntoAjustado(latlng);

        if (estado.dibujo.coords.length > 0) {
            var ultimo = estado.dibujo.coords[estado.dibujo.coords.length - 1];
            if (distanciaPx(L.latLng(punto.lat, punto.lng), L.latLng(ultimo[0], ultimo[1])) < MIN_DISTANCIA_PX) {
                return;
            }
        }

        estado.dibujo.coords.push([punto.lat, punto.lng]);

        var marca = L.circleMarker([punto.lat, punto.lng], {
            radius: 5,
            color: '#16a34a',
            weight: 2,
            fillColor: '#16a34a',
            fillOpacity: 1,
            interactive: false
        }).addTo(map);
        estado.dibujo.markers.push(marca);

        var latlngs = estado.dibujo.coords.map(function (p) { return [p[0], p[1]]; });
        if (!estado.dibujo.linea) {
            estado.dibujo.linea = L.polyline(latlngs, {
                color: '#16a34a',
                weight: 4,
                opacity: 0.9,
                smoothFactor: 0,
                interactive: false
            }).addTo(map);
        } else {
            estado.dibujo.linea.setLatLngs(latlngs);
        }

        actualizarEstado('Dibujar: ' + estado.dibujo.coords.length
            + ' vértice(s). «Terminar dibujo», doble clic o Enter para añadirlo; Esc para cancelar.');
    }

    function terminarDibujo() {
        if (!estado.dibujo) {
            return;
        }

        var coords = estado.dibujo.coords.slice();

        // Quita vértices consecutivos prácticamente idénticos.
        var limpias = [];
        coords.forEach(function (punto) {
            if (limpias.length === 0) {
                limpias.push(punto);
                return;
            }
            var previo = limpias[limpias.length - 1];
            if (distanciaMetros(previo[0], previo[1], punto[0], punto[1]) > 0.05) {
                limpias.push(punto);
            }
        });

        if (limpias.length < 2) {
            mostrarMensaje('Un segmento necesita al menos 2 vértices distintos.', 'error');
            return;
        }

        cancelarDibujo(true);

        estado.contadorTmp++;
        var id = 'tmp-' + estado.contadorTmp + '-' + Math.random().toString(16).slice(2, 8);

        estado.geojson.features.push({
            type: 'Feature',
            properties: { id: id },
            geometry: {
                type: 'LineString',
                coordinates: limpias.map(function (punto) {
                    return [punto[1], punto[0]];
                })
            }
        });

        renderRed();
        seleccionar(id, true);
        cambiarModo('consultar');
        programarAnalisis(80);
        mostrarMensaje('Segmento nuevo pendiente de guardar. Revisa la validación.', 'success');
    }

    map.on('click', function (evento) {
        if (estado.modo === 'dibujar') {
            agregarVerticeDibujo(evento.latlng);
            return;
        }
        if (estado.modo === 'consultar') {
            seleccionar(null, false);
        }
    });

    map.on('dblclick', function () {
        if (!estado.dibujo) {
            return;
        }
        setTimeout(function () {
            if (estado.dibujo) {
                terminarDibujo();
            }
        }, 120);
    });

    // ==========================================
    // ELIMINACIÓN DIFERIDA
    // ==========================================
    function alternarEliminar(id) {
        if (!id) {
            return;
        }

        if (estado.capaEditando === id) {
            desactivarEdicion();
        }

        // Los segmentos `tmp-` nunca se guardaron: se quitan de la propuesta
        // local directamente, sin pasar por la lista de borrado pendiente.
        if (id.indexOf('tmp-') === 0) {
            estado.geojson.features = estado.geojson.features.filter(function (feature) {
                return idDe(feature) !== id;
            });
            if (estado.seleccionado === id) {
                estado.seleccionado = null;
            }
            renderRed();
            renderLista();
            renderDetalle();
            programarAnalisis(80);
            mostrarMensaje('Segmento nuevo descartado: no llegó a guardarse.', 'warning');
            return;
        }

        if (estado.pendientesEliminar[id]) {
            delete estado.pendientesEliminar[id];
            mostrarMensaje(id + ' ya no se eliminará.', 'warning');
        } else {
            estado.pendientesEliminar[id] = true;
            mostrarMensaje(id + ' marcado para eliminar. Se aplicará al guardar.', 'warning');
        }

        aplicarEstilos();
        renderLista();
        renderDetalle();
        programarAnalisis(80);
    }

    // ==========================================
    // EDICIÓN DE VÉRTICES (LEAFLET-GEOMAN)
    // ==========================================
    function geomanDisponible() {
        return typeof L !== 'undefined' && typeof L.PM !== 'undefined';
    }

    function sincronizarDesdeCapa(id, programar) {
        var capa = capas[id];
        var feature = buscarFeature(id);
        if (!capa || !feature) {
            return;
        }

        var latlngs = capa.getLatLngs();
        // Las polilíneas simples devuelven [LatLng, …]; se aplana por si Geoman
        // entrega un nivel extra.
        if (latlngs.length > 0 && Array.isArray(latlngs[0])) {
            latlngs = latlngs[0];
        }

        feature.geometry.coordinates = latlngs.map(function (p) {
            return [p.lng, p.lat];
        });

        // La capa de clic debe seguir al trazo mientras se mueven los vértices.
        if (capasGolpe[id]) {
            capasGolpe[id].setLatLngs(latlngs);
        }

        renderDetalle();
        actualizarItemLista(id);
        marcarSucio();
        aplicarEstiloSegmento(id);

        if (programar) {
            programarAnalisis(120);
        }
    }

    var handlersEdicion = null;

    function activarEdicion(id) {
        if (!geomanDisponible()) {
            mostrarMensaje('Leaflet-Geoman no está disponible; no se pueden editar vértices.', 'error');
            return;
        }

        if (estado.capaEditando === id) {
            return;
        }

        desactivarEdicion();

        var capa = capas[id];
        if (!capa || typeof capa.pm === 'undefined' || typeof capa.pm.enable !== 'function') {
            mostrarMensaje('Este segmento no se puede editar. Recarga la página.', 'error');
            return;
        }

        try {
            capa.pm.enable({
                allowSelfIntersection: true,
                preventMarkerRemoval: false,
                // Sin snapping al propio trazado: Geoman podría enganchar el
                // vértice a su posición anterior y bloquear el arrastre.
                snappable: false
            });

            handlersEdicion = {
                arrastre: function () {
                    sincronizarDesdeCapa(id, false);
                },
                fin: function () {
                    sincronizarDesdeCapa(id, true);
                }
            };

            capa.on('pm:markerdrag', handlersEdicion.arrastre);
            capa.on('pm:markerdragend pm:vertexadded pm:vertexremoved pm:edit', handlersEdicion.fin);

            estado.capaEditando = id;
            actualizarEstadoContextual();
        } catch (error) {
            mostrarMensaje('No se pudo activar la edición de vértices: ' + error.message, 'error');
        }
    }

    function desactivarEdicion() {
        var id = estado.capaEditando;
        var capa = id ? capas[id] : null;
        estado.capaEditando = null;

        if (capa) {
            try {
                if (capa.pm && typeof capa.pm.disable === 'function') {
                    capa.pm.disable();
                }
            } catch (error) {
                // La capa pudo desaparecer al reconstruir la red.
            }

            if (handlersEdicion) {
                capa.off('pm:markerdrag', handlersEdicion.arrastre);
                capa.off('pm:markerdragend pm:vertexadded pm:vertexremoved pm:edit', handlersEdicion.fin);
            }
        }

        handlersEdicion = null;
    }

    // ==========================================
    // GUARDAR / DESCARTAR
    // ==========================================
    function aplicarIdMap(idMap) {
        if (!idMap) {
            return;
        }
        estado.geojson.features.forEach(function (feature) {
            var id = idDe(feature);
            if (idMap[id]) {
                feature.properties.id = idMap[id];
                feature.id = idMap[id];
            }
        });
    }

    async function guardar() {
        if (estado.guardando) {
            return;
        }

        if (estado.dibujo) {
            mostrarMensaje('Termina o cancela el dibujo antes de guardar.', 'warning');
            return;
        }

        if (estado.capaEditando) {
            desactivarEdicion();
        }

        var propuesta = propuestaActual();
        ejecutarAnalisis();

        if (!estado.analisis || !estado.analisis.ok) {
            mostrarMensaje('Corrige los errores de la validación antes de guardar.', 'error');
            return;
        }

        var advertencias = estado.analisis.advertencias || [];
        if (advertencias.length > 0) {
            var codigos = [];
            advertencias.forEach(function (aviso) {
                if (codigos.indexOf(aviso.codigo) === -1) {
                    codigos.push(aviso.codigo);
                }
            });
            var continuar = window.confirm(
                'Hay ' + advertencias.length + ' advertencia(s) (' + codigos.join(', ') + ').\n'
                + 'No bloquean el guardado. ¿Guardar igualmente?'
            );
            if (!continuar) {
                return;
            }
        }

        estado.guardando = true;
        var textoOriginal = btnGuardar.textContent;
        btnGuardar.disabled = true;
        btnGuardar.textContent = 'Guardando…';

        try {
            var datos = new FormData();
            datos.append('csrf_token', RED_CSRF);
            datos.append('base_revision', String(estado.revision));
            datos.append('geojson', JSON.stringify(propuesta));

            var respuesta = await fetch(URL_GUARDAR, {
                method: 'POST',
                body: datos,
                credentials: 'same-origin',
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            });

            var texto = await respuesta.text();
            var resultado;
            try {
                resultado = JSON.parse(texto);
            } catch (error) {
                throw new Error('Respuesta inesperada del servidor (HTTP ' + respuesta.status
                    + '). Recarga la página e inicia sesión de nuevo.');
            }

            if (respuesta.ok && resultado.success) {
                aplicarIdMap(resultado.id_map);
                estado.revision = Number(resultado.revision || estado.revision + 1);
                if (elRevision) {
                    elRevision.textContent = String(estado.revision);
                }
                mostrarMensaje(resultado.message || 'Red guardada.', 'success');

                try {
                    await recargarEstado(true);
                } catch (error) {
                    console.warn('No se pudo refrescar la red guardada:', error);
                    // Sin refresco: se adopta el estado local ya guardado
                    // (con los IDs definitivos de `id_map`) y se quitan los
                    // segmentos borrados; no se reutiliza la propuesta previa
                    // porque todavía tenía IDs `tmp-`.
                    estado.geojson.features = estado.geojson.features.filter(function (feature) {
                        return !estado.pendientesEliminar[idDe(feature)];
                    });
                    estado.pendientesEliminar = {};
                    estado.redVigente = clonar(estado.geojson);
                    estado.recuadro = redRecuadro(estado.redVigente);
                    guardarCoordsVigentes();
                    renderRed();
                    renderLista();
                    renderDetalle();
                    programarAnalisis(0);
                }
                return;
            }

            if (respuesta.status === 409) {
                if (resultado.revision && elRevision) {
                    elRevision.textContent = String(resultado.revision) + ' (remota)';
                }
                var recargar = window.confirm(
                    (resultado.message || 'La red cambió en otra sesión.')
                    + '\n\n¿Descartar los cambios locales y recargar la red vigente?'
                );
                if (recargar) {
                    await recargarEstado(true);
                    mostrarMensaje('Red recargada desde el servidor.', 'warning');
                } else {
                    mostrarMensaje('Cambios conservados en memoria, pero no se pueden guardar '
                        + 'hasta recargar la red.', 'error');
                }
                return;
            }

            if (respuesta.status === 403) {
                mostrarMensaje(resultado.message || 'Sesión expirada. Inicia sesión de nuevo.', 'error');
                setTimeout(function () {
                    window.location.href = 'index.php?p=login';
                }, 2200);
                return;
            }

            if (Array.isArray(resultado.errors) && resultado.errors.length > 0) {
                estado.analisis = {
                    ok: false,
                    errores: resultado.errors,
                    advertencias: (estado.analisis && estado.analisis.advertencias) || [],
                    metricas: (estado.analisis && estado.analisis.metricas) || null,
                    extremos: (estado.analisis && estado.analisis.extremos) || []
                };
                renderValidacion();
            }

            mostrarMensaje(resultado.message || 'No se pudo guardar la red.', 'error');
        } catch (error) {
            console.error('Error al guardar la red:', error);
            mostrarMensaje('Error al comunicarse con el servidor: ' + error.message, 'error');
        } finally {
            estado.guardando = false;
            btnGuardar.textContent = textoOriginal;
            marcarSucio();
        }
    }

    async function recargarEstado(silencioso) {
        var respuesta = await fetch(URL_ESTADO, {
            method: 'GET',
            credentials: 'same-origin',
            cache: 'no-store',
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            }
        });

        var texto = await respuesta.text();
        var datos;
        try {
            datos = JSON.parse(texto);
        } catch (error) {
            throw new Error('Respuesta inesperada del servidor (HTTP ' + respuesta.status + ').');
        }

        if (!respuesta.ok || !datos.success || !datos.geojson) {
            throw new Error(datos.message || 'No se pudo leer la red vigente.');
        }

        reemplazarRed(datos.geojson, datos.revision);

        if (!silencioso) {
            mostrarMensaje('Red recargada desde el servidor.', 'success');
        }
    }

    function reemplazarRed(geojson, revision) {
        desactivarEdicion();
        cancelarDibujo(true);

        estado.geojson = clonar(geojson);
        estado.redVigente = clonar(geojson);
        estado.revision = Number(revision || 0);
        estado.seleccionado = null;
        estado.pendientesEliminar = {};
        estado.analisis = null;
        estado.recuadro = redRecuadro(estado.redVigente);
        guardarCoordsVigentes();

        if (elRevision) {
            elRevision.textContent = String(estado.revision);
        }

        renderRed();
        renderLista();
        renderDetalle();
        ejecutarAnalisis();
        actualizarEstadoContextual();
        marcarSucio();
    }

    async function descartar() {
        if (estado.guardando) {
            return;
        }

        if (estado.sucio) {
            var confirmado = window.confirm(
                'Se descartarán todos los cambios no guardados. ¿Continuar?'
            );
            if (!confirmado) {
                return;
            }
        }

        try {
            await recargarEstado(false);
        } catch (error) {
            mostrarMensaje('No se pudo recargar la red: ' + error.message, 'error');
        }
    }

    if (btnGuardar) {
        btnGuardar.addEventListener('click', guardar);
    }
    if (btnDescartar) {
        btnDescartar.addEventListener('click', descartar);
    }

    if (elBuscar) {
        elBuscar.addEventListener('input', renderLista);
    }

    if (btnTerminarDibujo) {
        btnTerminarDibujo.addEventListener('click', function () {
            terminarDibujo();
        });
        actualizarBotonTerminar();
    }

    // ==========================================
    // REPARTO VERTICAL: SEGMENTOS / VALIDACIÓN
    // ==========================================
    var MIN_ALTO_BLOQUE = 120;

    function repartoDisponible() {
        if (!elSidebar || !bloqueSegmentos || !bloqueValidacion) {
            return 0;
        }
        var alto = elSidebar.getBoundingClientRect().height;
        var divisor = elSplitter ? elSplitter.getBoundingClientRect().height : 0;
        return alto - divisor;
    }

    function escribirReparto(altoSegmentos) {
        var disponible = repartoDisponible();
        if (disponible <= 0) {
            return;
        }
        var minimo = Math.min(MIN_ALTO_BLOQUE, disponible / 2);
        var alto = Math.max(minimo, Math.min(disponible - minimo, altoSegmentos));
        bloqueSegmentos.style.flex = '0 0 ' + alto + 'px';
        bloqueValidacion.style.flex = '0 0 ' + (disponible - alto) + 'px';
        estado.altoSegmentos = alto;
    }

    function repartoVerticalActivo() {
        return window.matchMedia('(min-width: 861px)').matches;
    }

    function limpiarReparto() {
        if (bloqueSegmentos) { bloqueSegmentos.style.flex = ''; }
        if (bloqueValidacion) { bloqueValidacion.style.flex = ''; }
    }

    function ajustarReparto() {
        if (!repartoVerticalActivo()) {
            limpiarReparto();
            return;
        }
        var disponible = repartoDisponible();
        if (disponible <= 0) {
            return;
        }
        escribirReparto(estado.altoSegmentos || disponible / 2);
    }

    if (elSplitter && bloqueSegmentos && bloqueValidacion) {
        elSplitter.addEventListener('pointerdown', function (evento) {
            if (!repartoVerticalActivo()) {
                return;
            }
            evento.preventDefault();
            estado.arrastreReparto = {
                y: evento.clientY,
                alto: bloqueSegmentos.getBoundingClientRect().height
            };
            elSplitter.classList.add('arrastrando');
            if (typeof elSplitter.setPointerCapture === 'function') {
                try {
                    elSplitter.setPointerCapture(evento.pointerId);
                } catch (error) {
                    // Sin captura el puntero sigue funcionando dentro del divisor.
                }
            }
        });

        elSplitter.addEventListener('pointermove', function (evento) {
            if (!estado.arrastreReparto) {
                return;
            }
            escribirReparto(estado.arrastreReparto.alto + (evento.clientY - estado.arrastreReparto.y));
        });

        function soltarReparto() {
            estado.arrastreReparto = null;
            elSplitter.classList.remove('arrastrando');
        }
        elSplitter.addEventListener('pointerup', soltarReparto);
        elSplitter.addEventListener('pointercancel', soltarReparto);

        elSplitter.addEventListener('keydown', function (evento) {
            if ((evento.key !== 'ArrowUp' && evento.key !== 'ArrowDown') || !repartoVerticalActivo()) {
                return;
            }
            evento.preventDefault();
            escribirReparto(bloqueSegmentos.getBoundingClientRect().height
                + (evento.key === 'ArrowDown' ? 32 : -32));
        });

        window.addEventListener('resize', ajustarReparto);
    }

    // ==========================================
    // ATAJOS Y SALIDA
    // ==========================================
    document.addEventListener('keydown', function (evento) {
        if (evento.key === 'Escape') {
            if (estado.dibujo) {
                cancelarDibujo();
                cambiarModo('consultar');
            } else if (estado.modo === 'editar') {
                cambiarModo('consultar');
            }
            return;
        }

        if (evento.key === 'Enter' && estado.dibujo) {
            evento.preventDefault();
            terminarDibujo();
        }
    });

    window.addEventListener('beforeunload', function (evento) {
        if (!estado.sucio) {
            return;
        }
        evento.preventDefault();
        evento.returnValue = '';
    });

    // ==========================================
    // INICIALIZACIÓN
    // ==========================================
    cargarReferencia();
    guardarCoordsVigentes();
    renderRed();
    renderLista();

    var cajaRed = capaRed.getLayers().length > 0
        ? L.featureGroup(capaRed.getLayers()).getBounds()
        : null;
    if (cajaRed && cajaRed.isValid()) {
        map.fitBounds(cajaRed, { padding: [40, 40] });
    }

    cambiarModo('consultar');
    ejecutarAnalisis();
    marcarSucio();
    ajustarReparto();
});
