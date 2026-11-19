// ==========================================
// EDITOR DE GEOMETRÍAS - Leaflet + Leaflet-Geoman
// ==========================================
// - Dibujo manual de polígonos (sin depender del modo dibujo del plugin).
// - Edición de vértices con Leaflet-Geoman (vendorizado en js/vendor/).
// - Capa de referencia de solo lectura con las geometrías existentes.
// - Cierre automático con tolerancia constante en píxeles.

document.addEventListener('DOMContentLoaded', function () {
    // ==========================================
    // REFERENCIAS DEL DOM
    // ==========================================
    const form = document.getElementById('edificio-form');
    const geometryInput = document.getElementById('geometry-input');
    const btnVolver = document.getElementById('btn-volver');
    const btnLimpiar = document.getElementById('btn-limpiar');
    const btnCancelar = document.getElementById('btn-cancelar');
    const btnGuardar = document.getElementById('btn-guardar');
    const formMessage = document.getElementById('form-message');
    const editorStatus = document.getElementById('editor-status');

    const btnDrawPolygon = document.getElementById('btn-draw-polygon');
    const btnEditMode = document.getElementById('btn-edit-mode');
    const btnDeleteMode = document.getElementById('btn-delete-mode');

    // ==========================================
    // ESTADO DEL EDITOR
    // ==========================================
    let currentPolygon = null;   // geometría en edición
    let referenceLayer = null;   // geometrías existentes (solo lectura)
    let isDrawingManual = false;
    let isEditMode = false;
    let isDeleteMode = false;

    let manualCoords = [];
    let manualMarkers = [];
    let manualLines = [];

    // Tolerancia de cierre en PÍXELES (constante en todos los zooms).
    // 10 px ≈ 20 px de diámetro: suficiente para cerrar a propósito sin que el
    // cierre ocurra antes de tiempo.
    const CLOSE_DISTANCE_PX = 10;

    // Distancia mínima entre vértices consecutivos (evita duplicados al
    // hacer doble clic, que Leaflet traduce en dos clics seguidos).
    const MIN_VERTEX_DISTANCE_PX = 2;

    // ==========================================
    // MAPA LEAFLET
    // ==========================================
    const map = L.map('map-editor', {
        attributionControl: false,
        center: [25.5324, -103.436],
        zoom: 17,
        minZoom: 15,
        maxZoom: 19,
    });

    L.control.attribution({ prefix: false }).addTo(map);

    L.tileLayer('tiles/satelital/{z}/{x}/{y}.jpg', {
        minZoom: 15,
        maxZoom: 19,
        tms: false,
        attribution: 'Mapa ITL Laguna',
    }).addTo(map);

    // ==========================================
    // FUNCIONES AUXILIARES
    // ==========================================

    function mostrarMensaje(texto, tipo) {
        formMessage.textContent = texto;
        formMessage.className = `form-message show ${tipo}`;
    }

    function ocultarMensaje() {
        setTimeout(() => {
            formMessage.classList.remove('show');
        }, 5000);
    }

    function actualizarEstado(texto) {
        if (editorStatus) {
            editorStatus.textContent = texto;
        }
    }

    function actualizarGeometriaInput() {
        if (!currentPolygon) {
            geometryInput.value = '';
            return;
        }
        try {
            const geojson = currentPolygon.toGeoJSON();
            geometryInput.value = JSON.stringify(geojson.geometry);
        } catch (err) {
            console.error('Error serializando geometría:', err);
        }
    }

    function distanciaPx(a, b) {
        return map.latLngToContainerPoint(a).distanceTo(map.latLngToContainerPoint(b));
    }

    // Ray casting sobre un anillo de LatLng. Sustituye la comprobación por
    // bounding box, que borraba el polígono al hacer clic en el "hueco" de una
    // geometría irregular.
    function puntoEnPoligono(latlng, anillo) {
        if (!anillo || anillo.length < 3) return false;
        let dentro = false;
        for (let i = 0, j = anillo.length - 1; i < anillo.length; j = i++) {
            const yi = anillo[i].lat, xi = anillo[i].lng;
            const yj = anillo[j].lat, xj = anillo[j].lng;
            const cruza = ((yi > latlng.lat) !== (yj > latlng.lat)) &&
                (latlng.lng < (xj - xi) * (latlng.lat - yi) / ((yj - yi) || 1e-12) + xi);
            if (cruza) {
                dentro = !dentro;
            }
        }
        return dentro;
    }

    // ==========================================
    // CAPA DE REFERENCIA (SOLO LECTURA)
    // ==========================================
    function cargarGeometriasDeReferencia() {
        const coleccion = window.EDITOR_GEOMETRIAS_EXISTENTES;
        if (!coleccion || !Array.isArray(coleccion.features)) {
            return;
        }

        const idActual = String(window.EDITOR_EDIFICIO_ID || '');

        referenceLayer = L.geoJSON(coleccion, {
            // interactive:false => ninguna geometría de referencia recibe clics,
            // por lo que NO se pueden editar ni borrar por accidente.
            interactive: false,
            // Sin simplificación: con smoothFactor por defecto las huellas con
            // muchos vértices se dibujaban como cuadrados.
            smoothFactor: 0,
            filter: function (feature) {
                return String((feature.properties && feature.properties.id) || '') !== idActual;
            },
            style: function () {
                return {
                    color: '#4b5563',
                    weight: 1.5,
                    opacity: 0.9,
                    dashArray: '4 3',
                    fillColor: '#9ca3af',
                    fillOpacity: 0.18,
                };
            },
        });

        referenceLayer.addTo(map);

        const total = Math.max(coleccion.features.length - (idActual ? 1 : 0), 0);
        if (currentPolygon) {
            actualizarEstado('Geometría cargada. ' + total + ' geometrías de referencia visibles (no editables). Usa ✏️ Editar para arrastrar los vértices.');
        } else {
            actualizarEstado('Dibujo listo. ' + total + ' geometrías existentes visibles como referencia (no editables).');
        }
    }

    // ==========================================
    // MODO DIBUJO MANUAL
    // ==========================================

    function iniciarDibujoManual() {
        if (currentPolygon && !isDrawingManual) {
            const continuar = window.confirm('Esto reemplazará la geometría actual del formulario. ¿Continuar?');
            if (!continuar) {
                return;
            }
        }

        desactivarModoEdicion();
        desactivarModoEliminacion();

        if (currentPolygon) {
            map.removeLayer(currentPolygon);
            currentPolygon = null;
            geometryInput.value = '';
        }

        isDrawingManual = true;
        manualCoords = [];
        manualMarkers = [];
        manualLines = [];

        btnDrawPolygon.classList.add('active');
        btnDrawPolygon.style.background = '#1161ee';
        btnDrawPolygon.style.color = '#fff';

        map.getContainer().style.cursor = 'crosshair';
        actualizarEstado('Dibujando: clic para agregar vértices. Doble clic o clic sobre el primer vértice para cerrar.');
        mostrarMensaje('Haz clic para agregar vértices. Doble clic para terminar o clic cerca del primer punto para cerrar automáticamente.', 'success');
        ocultarMensaje();
    }

    function agregarVerticeManual(lat, lng) {
        manualCoords.push([lat, lng]);

        const marker = L.circleMarker([lat, lng], {
            radius: 5,
            color: '#1161ee',
            fillColor: '#1161ee',
            weight: 2,
            // Leaflet 1.x: 'interactive', no 'clickable' (API 0.7, ignorada).
            // Sin esto el marcador se "come" el clic que debe cerrar el polígono.
            interactive: false,
        }).addTo(map);
        manualMarkers.push(marker);

        if (manualCoords.length > 1) {
            const prev = manualCoords[manualCoords.length - 2];
            const line = L.polyline([prev, [lat, lng]], {
                color: '#1161ee',
                weight: 2,
                opacity: 0.6,
                interactive: false,
            }).addTo(map);
            manualLines.push(line);
        }
    }

    function terminarDibujoManual() {
        if (!isDrawingManual) return;

        if (manualCoords.length >= 3) {
            const closedCoords = [...manualCoords, manualCoords[0]];
            crearPoligonoFinal(closedCoords);
        } else {
            mostrarMensaje('Se necesitan al menos 3 vértices.', 'error');
            ocultarMensaje();
        }
    }

    function crearPoligonoFinal(coords) {
        currentPolygon = L.polygon(coords, {
            color: '#1161ee',
            weight: 3,
            fillColor: '#1161ee',
            fillOpacity: 0.3,
            smoothFactor: 0,
        }).addTo(map);

        manualMarkers.forEach(m => map.removeLayer(m));
        manualLines.forEach(l => map.removeLayer(l));
        manualMarkers = [];
        manualLines = [];
        manualCoords = [];

        isDrawingManual = false;
        btnDrawPolygon.classList.remove('active');
        btnDrawPolygon.style.background = '';
        btnDrawPolygon.style.color = '';
        map.getContainer().style.cursor = '';

        actualizarGeometriaInput();

        // coords incluye el punto de cierre repetido: se resta para informar
        // el número real de vértices dibujados.
        const vertices = coords.length - 1;
        actualizarEstado('Polígono de ' + vertices + ' vértices listo. Usa ✏️ Editar para ajustar los vértices.');
        mostrarMensaje('Polígono creado con ' + vertices + ' vértices.', 'success');
        ocultarMensaje();
    }

    function cancelarDibujoManual() {
        if (!isDrawingManual) return;

        manualMarkers.forEach(m => map.removeLayer(m));
        manualLines.forEach(l => map.removeLayer(l));
        manualMarkers = [];
        manualLines = [];
        manualCoords = [];
        isDrawingManual = false;

        btnDrawPolygon.classList.remove('active');
        btnDrawPolygon.style.background = '';
        btnDrawPolygon.style.color = '';
        map.getContainer().style.cursor = '';

        mostrarMensaje('Dibujo manual cancelado.', 'error');
        ocultarMensaje();
    }

    // Clic en el mapa para agregar vértice (modo dibujo manual)
    map.on('click', function (e) {
        if (!isDrawingManual) return;

        const latlng = e.latlng;

        // Evitar vértices duplicados (el doble clic genera dos clics seguidos)
        if (manualCoords.length > 0) {
            const ultimo = manualCoords[manualCoords.length - 1];
            if (distanciaPx(latlng, L.latLng(ultimo[0], ultimo[1])) < MIN_VERTEX_DISTANCE_PX) {
                return;
            }
        }

        // Cierre intencional: cerca del primer vértice (tolerancia en píxeles)
        if (manualCoords.length >= 3) {
            const primero = manualCoords[0];
            if (distanciaPx(latlng, L.latLng(primero[0], primero[1])) <= CLOSE_DISTANCE_PX) {
                terminarDibujoManual();
                return;
            }
        }

        agregarVerticeManual(latlng.lat, latlng.lng);
    });

    // Doble clic para terminar
    map.on('dblclick', function () {
        if (isDrawingManual) {
            setTimeout(() => {
                if (isDrawingManual) {
                    terminarDibujoManual();
                }
            }, 100);
        }
    });

    // ==========================================
    // MODO EDICIÓN DE VÉRTICES (Leaflet-Geoman)
    // ==========================================

    function geomanDisponible() {
        return typeof L !== 'undefined' && typeof L.PM !== 'undefined';
    }

    function sincronizarDesdeEdicion() {
        actualizarGeometriaInput();
    }

    function activarModoEdicion() {
        if (!currentPolygon) {
            mostrarMensaje('Primero dibuja un polígono.', 'error');
            ocultarMensaje();
            return;
        }
        if (isDrawingManual) {
            mostrarMensaje('Termina el dibujo manual antes de editar.', 'error');
            ocultarMensaje();
            return;
        }
        if (isEditMode) {
            return;
        }

        if (!geomanDisponible() || !currentPolygon.pm || typeof currentPolygon.pm.enable !== 'function') {
            mostrarMensaje('El plugin de edición (Leaflet-Geoman) no se cargó. Revisa js/vendor/leaflet-geoman/leaflet-geoman.min.js.', 'error');
            ocultarMensaje();
            return;
        }

        try {
            currentPolygon.pm.enable({
                allowSelfIntersection: false,
                preventMarkerRemoval: false,
            });

            currentPolygon.on('pm:markerdrag pm:markerdragend pm:vertexadded pm:vertexremoved pm:edit', sincronizarDesdeEdicion);

            isEditMode = true;
            btnEditMode.classList.add('active');
            btnEditMode.style.background = '#1161ee';
            btnEditMode.style.color = '#fff';
            desactivarModoEliminacion();
            actualizarEstado('Editando vértices: arrastra los marcadores. Los cambios se guardan al enviar el formulario.');
            mostrarMensaje('Modo edición: arrastra los vértices para ajustar el polígono.', 'success');
            ocultarMensaje();
        } catch (err) {
            mostrarMensaje('No se pudo activar la edición de vértices: ' + err.message, 'error');
            ocultarMensaje();
        }
    }

    function desactivarModoEdicion() {
        if (currentPolygon && currentPolygon.pm && typeof currentPolygon.pm.disable === 'function') {
            try {
                currentPolygon.pm.disable();
            } catch (err) {
                // El polígono pudo haber sido eliminado del mapa
            }
            currentPolygon.off('pm:markerdrag pm:markerdragend pm:vertexadded pm:vertexremoved pm:edit', sincronizarDesdeEdicion);
        }

        isEditMode = false;
        btnEditMode.classList.remove('active');
        btnEditMode.style.background = '';
        btnEditMode.style.color = '';
    }

    // ==========================================
    // MODO ELIMINACIÓN
    // ==========================================

    function alHacerClicPoligono() {
        if (!isDeleteMode || !currentPolygon) return;
        limpiarTodo();
    }

    function activarModoEliminacion() {
        if (!currentPolygon) {
            mostrarMensaje('No hay ningún polígono en el mapa para eliminar.', 'error');
            ocultarMensaje();
            return;
        }

        desactivarModoEdicion();

        isDeleteMode = true;
        btnDeleteMode.classList.add('active');
        btnDeleteMode.style.background = '#dc3545';
        btnDeleteMode.style.color = '#fff';

        // Se usa el clic sobre la capa (no el bounding box): así un clic fuera
        // del polígono pero dentro de su rectángulo envolvente no lo elimina.
        currentPolygon.on('click', alHacerClicPoligono);

        actualizarEstado('Modo eliminar: haz clic sobre el polígono para borrarlo del formulario.');
        mostrarMensaje('Haz clic sobre el polígono para eliminarlo.', 'warning');
        ocultarMensaje();
    }

    function desactivarModoEliminacion() {
        if (currentPolygon) {
            currentPolygon.off('click', alHacerClicPoligono);
        }

        isDeleteMode = false;
        btnDeleteMode.classList.remove('active');
        btnDeleteMode.style.background = '';
        btnDeleteMode.style.color = '';
    }

    function limpiarTodo() {
        desactivarModoEdicion();
        desactivarModoEliminacion();

        if (currentPolygon) {
            map.removeLayer(currentPolygon);
            currentPolygon = null;
        }

        manualMarkers.forEach(m => map.removeLayer(m));
        manualLines.forEach(l => map.removeLayer(l));
        manualMarkers = [];
        manualLines = [];
        manualCoords = [];
        geometryInput.value = '';

        isDrawingManual = false;
        btnDrawPolygon.classList.remove('active');
        btnDrawPolygon.style.background = '';
        btnDrawPolygon.style.color = '';

        map.getContainer().style.cursor = '';

        actualizarEstado('Dibujo limpiado.');
        mostrarMensaje('Dibujo limpiado.', 'error');
        ocultarMensaje();
    }

    // ==========================================
    // CARGA DE GEOMETRÍA EXISTENTE (modo edición)
    // ==========================================

    (function cargarGeometriaInicial() {
        const geom = window.EDITOR_GEOMETRIA_INICIAL;
        if (!geom || !geom.coordinates || !geom.coordinates[0]) return;

        const anillo = geom.coordinates[0];
        if (anillo.length < 4) return;

        // GeoJSON [lng, lat] -> Leaflet [lat, lng]
        const latlngs = anillo.map(c => [c[1], c[0]]);
        currentPolygon = L.polygon(latlngs, {
            color: '#1161ee',
            weight: 3,
            fillColor: '#1161ee',
            fillOpacity: 0.3,
            smoothFactor: 0,
        }).addTo(map);

        map.fitBounds(currentPolygon.getBounds(), { padding: [40, 40] });
        actualizarGeometriaInput();
        actualizarEstado('Geometría cargada. Usa ✏️ Editar para arrastrar los vértices.');
    })();

    // ==========================================
    // BOTONES DE LA TOOLBAR
    // ==========================================

    btnDrawPolygon.addEventListener('click', function () {
        if (isDrawingManual) {
            cancelarDibujoManual();
        } else {
            iniciarDibujoManual();
        }
    });

    btnEditMode.addEventListener('click', activarModoEdicion);

    btnDeleteMode.addEventListener('click', function () {
        if (isDeleteMode) {
            desactivarModoEliminacion();
            actualizarEstado('Modo eliminar desactivado.');
        } else {
            activarModoEliminacion();
        }
    });

    btnLimpiar.addEventListener('click', limpiarTodo);

    btnVolver.addEventListener('click', function () {
        window.location.href = 'index.php?p=editar';
    });

    btnCancelar.addEventListener('click', function () {
        window.location.href = 'index.php?p=editar';
    });

    // ==========================================
    // ENVÍO DEL FORMULARIO
    // ==========================================

    form.addEventListener('submit', async function (e) {
        e.preventDefault();

        if (!geometryInput.value || geometryInput.value.trim() === '') {
            mostrarMensaje('Debes dibujar un polígono en el mapa antes de guardar.', 'error');
            ocultarMensaje();
            return;
        }

        try {
            const geom = JSON.parse(geometryInput.value);
            if (!geom.coordinates || geom.coordinates.length === 0 || geom.coordinates[0].length < 4) {
                mostrarMensaje('El polígono debe tener al menos 3 vértices.', 'error');
                ocultarMensaje();
                return;
            }
        } catch (err) {
            mostrarMensaje('Error al procesar la geometría.', 'error');
            ocultarMensaje();
            return;
        }

        btnGuardar.disabled = true;
        const textoOriginal = btnGuardar.textContent;
        btnGuardar.textContent = 'Guardando...';

        try {
            const formData = new FormData(form);

            const response = await fetch('index.php?p=saveGeometria', {
                method: 'POST',
                body: formData,
                credentials: 'same-origin',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
            });

            const texto = await response.text();
            let result;
            try {
                result = JSON.parse(texto);
            } catch (err) {
                throw new Error('Respuesta inesperada del servidor (HTTP ' + response.status + '). Recarga la página e inicia sesión de nuevo.');
            }

            if (result.success) {
                mostrarMensaje(result.message, 'success');
                setTimeout(() => {
                    window.location.href = 'index.php?p=editar';
                }, 1200);
            } else {
                mostrarMensaje(result.message, 'error');
                ocultarMensaje();
                btnGuardar.disabled = false;
                btnGuardar.textContent = textoOriginal;
            }
        } catch (err) {
            console.error('Error al comunicarse con el servidor:', err);
            mostrarMensaje('Error al comunicarse con el servidor: ' + err.message, 'error');
            ocultarMensaje();
            btnGuardar.disabled = false;
            btnGuardar.textContent = textoOriginal;
        }
    });

    // ==========================================
    // INICIALIZACIÓN
    // ==========================================
    cargarGeometriasDeReferencia();
});
