// ==========================================
// EDITOR DE GEOMETRÍAS - Leaflet (modo manual, sin dependencias externas)
// ==========================================
// Maneja el dibujo y edición de polígonos de edificios usando solo Leaflet 1.9.4.
// No depende de Leaflet-Geoman ni de ningún CDN externo.

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

    // Toolbar de dibujo (siempre visible, sin dependencias externas)
    const drawToolbar = document.getElementById('draw-toolbar');
    const btnDrawPolygon = document.getElementById('btn-draw-polygon');
    const btnEditMode = document.getElementById('btn-edit-mode');
    const btnDeleteMode = document.getElementById('btn-delete-mode');

    // ==========================================
    // ESTADO DEL EDITOR
    // ==========================================
    let currentPolygon = null;
    let isDrawingManual = false;
    let isEditMode = false;
    let isDeleteMode = false;

    // Dibujo manual
    let manualCoords = [];
    let manualMarkers = [];
    let manualLines = [];
    let manualTempLine = null;

    // Distancia mínima en metros para cierre automático
    const CLOSE_DISTANCE_METERS = 10;

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

    const tileLayer = L.tileLayer('Teselas ITL Satelital//{z}/{x}/{y}.jpg', {
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

    function actualizarGeometriaInput() {
        if (!currentPolygon) {
            geometryInput.value = '';
            console.log("=== EDITOR DEBUG: actualizarGeometriaInput - sin polígono ===");
            return;
        }
        try {
            const geojson = currentPolygon.toGeoJSON();
            console.log("=== EDITOR DEBUG: actualizarGeometriaInput ===");
            console.log("GeoJSON type:", geojson.type);
            console.log("GeoJSON coordinates rings:", geojson.geometry.coordinates.length);
            console.log("GeoJSON first ring points:", geojson.geometry.coordinates[0].length);
            console.log("GeoJSON first 3 points:", JSON.stringify(geojson.geometry.coordinates[0].slice(0, 3)));
            console.log("GeoJSON last 3 points:", JSON.stringify(geojson.geometry.coordinates[0].slice(-3)));
            geometryInput.value = JSON.stringify(geojson.geometry);
            console.log("geometryInput.value length:", geometryInput.value.length);
        } catch (err) {
            console.error('Error serializando geometría:', err);
        }
    }

    function limpiarTodo() {
        if (currentPolygon) {
            map.removeLayer(currentPolygon);
            currentPolygon = null;
        }
        manualMarkers.forEach(m => map.removeLayer(m));
        manualLines.forEach(l => map.removeLayer(l));
        if (manualTempLine) {
            map.removeLayer(manualTempLine);
            manualTempLine = null;
        }
        manualMarkers = [];
        manualLines = [];
        manualCoords = [];
        geometryInput.value = '';

        isDrawingManual = false;
        isEditMode = false;
        isDeleteMode = false;

        btnDrawPolygon.classList.remove('active');
        btnDrawPolygon.style.background = '';
        btnDrawPolygon.style.color = '';
        btnEditMode.classList.remove('active');
        btnEditMode.style.background = '';
        btnEditMode.style.color = '';
        btnDeleteMode.classList.remove('active');
        btnDeleteMode.style.background = '';
        btnDeleteMode.style.color = '';

        map.getContainer().style.cursor = '';

        mostrarMensaje('Dibujo limpiado.', 'error');
        ocultarMensaje();
    }

    // Calcular distancia en metros entre dos puntos [lat, lng]
    function distanciaMetros(lat1, lng1, lat2, lng2) {
        const R = 6371000; // Radio de la Tierra en metros
        const dLat = (lat2 - lat1) * Math.PI / 180;
        const dLng = (lng2 - lng1) * Math.PI / 180;
        const a = Math.sin(dLat / 2) * Math.sin(dLat / 2) +
            Math.cos(lat1 * Math.PI / 180) * Math.cos(lat2 * Math.PI / 180) *
            Math.sin(dLng / 2) * Math.sin(dLng / 2);
        const c = 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
        return R * c;
    }

    // Verificar si un punto está cerca del primer punto del polígono
    function estaCercaDelPrimerPunto(lat, lng, coords) {
        if (!coords || coords.length < 2) return false;
        const primero = coords[0];
        return distanciaMetros(lat, lng, primero[0], primero[1]) <= CLOSE_DISTANCE_METERS;
    }

    // ==========================================
    // MODO DIBUJO MANUAL (único modo, sin dependencias externas)
    // ==========================================

    function iniciarDibujoManual() {
        if (currentPolygon && !isDrawingManual) {
            map.removeLayer(currentPolygon);
            currentPolygon = null;
        }

        isDrawingManual = true;
        manualCoords = [];
        manualMarkers = [];
        manualLines = [];
        manualTempLine = null;

        btnDrawPolygon.classList.add('active');
        btnDrawPolygon.style.background = '#1161ee';
        btnDrawPolygon.style.color = '#fff';

        desactivarModoEdicion();
        desactivarModoEliminacion();

        map.getContainer().style.cursor = 'crosshair';
        mostrarMensaje('Haz clic para agregar vértices. Doble clic para terminar o clic cerca del primer punto para cerrar automáticamente.', 'success');
        ocultarMensaje();
    }

    function agregarVerticeManual(lat, lng) {
        manualCoords.push([lat, lng]);

        console.log("=== EDITOR DEBUG: Agregar vértice ===");
        console.log("Vértice #" + manualCoords.length + ":", [lat, lng]);
        console.log("Total vértices en manualCoords:", manualCoords.length);
        console.log("Todos los vértices:", JSON.stringify(manualCoords));

        const marker = L.circleMarker([lat, lng], {
            radius: 5,
            color: '#1161ee',
            fillColor: '#1161ee',
            weight: 2,
            clickable: false,  // IMPORTANTE: el marcador no debe capturar clics del mapa
        }).addTo(map);
        manualMarkers.push(marker);

        if (manualCoords.length > 1) {
            const prev = manualCoords[manualCoords.length - 2];
            const line = L.polyline([prev, [lat, lng]], {
                color: '#1161ee',
                weight: 2,
                opacity: 0.6,
            }).addTo(map);
            manualLines.push(line);
        }
    }

    function terminarDibujoManual() {
        if (!isDrawingManual) return;

        console.log("=== EDITOR DEBUG: Terminar dibujo manual ===");
        console.log("manualCoords length:", manualCoords.length);
        console.log("manualCoords:", JSON.stringify(manualCoords));

        // Si hay al menos 3 puntos, cerrar el polígono
        if (manualCoords.length >= 3) {
            // Cerrar el polígono repitiendo el primer punto al final
            const closedCoords = [...manualCoords, manualCoords[0]];

            console.log("Cerrando polígono con " + closedCoords.length + " puntos (incluyendo cierre)");
            console.log("Coords cerrados:", JSON.stringify(closedCoords));

            crearPoligonoFinal(closedCoords);
        } else {
            mostrarMensaje('Se necesitan al menos 3 vértices.', 'error');
            ocultarMensaje();
        }
    }

    function crearPoligonoFinal(coords) {
        // coords viene como [lat, lng] de manualCoords
        // Leaflet espera [lat, lng] para L.polygon()
        // GeoJSON espera [lng, lat] para las coordenadas
        // currentPolygon.toGeoJSON() devuelve correctamente [lng, lat]

        console.log("=== EDITOR DEBUG: Crear polígono final ===");
        console.log("Coords recibidos (count):", coords.length);
        console.log("Coords:", JSON.stringify(coords));

        currentPolygon = L.polygon(coords, {
            color: '#1161ee',
            weight: 3,
            fillColor: '#1161ee',
            fillOpacity: 0.3,
        }).addTo(map);

        manualMarkers.forEach(m => map.removeLayer(m));
        manualLines.forEach(l => map.removeLayer(l));
        if (manualTempLine) {
            map.removeLayer(manualTempLine);
            manualTempLine = null;
        }
        manualMarkers = [];
        manualLines = [];
        manualCoords = [];

        isDrawingManual = false;
        btnDrawPolygon.classList.remove('active');
        btnDrawPolygon.style.background = '';
        btnDrawPolygon.style.color = '';
        map.getContainer().style.cursor = '';

        actualizarGeometriaInput();
        mostrarMensaje('Polígono creado con ' + coords.length + ' vértices (cerrado automáticamente).', 'success');
        ocultarMensaje();
    }

    function cancelarDibujoManual() {
        if (!isDrawingManual) return;
        manualMarkers.forEach(m => map.removeLayer(m));
        manualLines.forEach(l => map.removeLayer(l));
        if (manualTempLine) {
            map.removeLayer(manualTempLine);
            manualTempLine = null;
        }
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

        const latLng = e.latlng;

        // Si hay al menos 3 puntos y el nuevo clic está cerca del primero, cerrar automáticamente
        if (manualCoords.length >= 3) {
            if (estaCercaDelPrimerPunto(latLng.lat, latLng.lng, manualCoords)) {
                terminarDibujoManual();
                return;
            }
        }

        agregarVerticeManual(latLng.lat, latLng.lng);
    });

    // Doble clic para terminar
    map.on('dblclick', function (e) {
        if (isDrawingManual) {
            setTimeout(() => {
                if (isDrawingManual) {
                    terminarDibujoManual();
                }
            }, 100);
        }
    });

    // ==========================================
    // MODO EDICIÓN (usando Leaflet nativo)
    // ==========================================

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

        try {
            // Hacer que el polígono sea editable (arrastrar vértices)
            currentPolygon.editing.enable();
            isEditMode = true;
            btnEditMode.classList.add('active');
            btnEditMode.style.background = '#1161ee';
            btnEditMode.style.color = '#fff';
            desactivarModoEliminacion();
            mostrarMensaje('Modo edición: arrastra los vértices rosas.', 'success');
            ocultarMensaje();
        } catch (err) {
            mostrarMensaje('No se pudo activar edición de vértices: ' + err.message, 'error');
        }
    }

    function desactivarModoEdicion() {
        isEditMode = false;
        btnEditMode.classList.remove('active');
        btnEditMode.style.background = '';
        btnEditMode.style.color = '';
        try {
            if (currentPolygon && currentPolygon.editing) {
                currentPolygon.editing.disable();
            }
        } catch (err) {}
    }

    // ==========================================
    // MODO ELIMINACIÓN
    // ==========================================

    function activarModoEliminacion() {
        isDeleteMode = true;
        btnDeleteMode.classList.add('active');
        btnDeleteMode.style.background = '#dc3545';
        btnDeleteMode.style.color = '#fff';
        map.getContainer().style.cursor = 'crosshair';
        desactivarModoEdicion();
        mostrarMensaje('Haz clic en el polígono para eliminarlo.', 'warning');
        ocultarMensaje();
    }

    function desactivarModoEliminacion() {
        isDeleteMode = false;
        btnDeleteMode.classList.remove('active');
        btnDeleteMode.style.background = '';
        btnDeleteMode.style.color = '';
        map.getContainer().style.cursor = '';
    }

    map.on('click', function (e) {
        if (!isDeleteMode || !currentPolygon) return;
        const bounds = currentPolygon.getBounds();
        if (bounds.contains(e.latlng)) {
            limpiarTodo();
            desactivarModoEliminacion();
        }
    });

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
        } else {
            activarModoEliminacion();
        }
    });

    btnLimpiar.addEventListener('click', limpiarTodo);

    btnVolver.addEventListener('click', function () {
        window.location.href = 'panel.php';
    });

    btnCancelar.addEventListener('click', function () {
        window.location.href = 'panel.php';
    });

    // ==========================================
    // ENVÍO DEL FORMULARIO
    // ==========================================

    form.addEventListener('submit', async function (e) {
        e.preventDefault();

        // Depuración: mostrar qué se va a enviar
        console.log("=== EDITOR DEBUG: Enviando formulario ===");
        console.log("geometryInput.value length:", geometryInput.value ? geometryInput.value.length : 0);
        if (geometryInput.value) {
            try {
                const geom = JSON.parse(geometryInput.value);
                console.log("Geometría type:", geom.type);
                console.log("Geometría coordinates rings:", geom.coordinates ? geom.coordinates.length : 0);
                console.log("Geometría first ring points:", geom.coordinates && geom.coordinates[0] ? geom.coordinates[0].length : 0);
                console.log("Geometría first 5 points:", JSON.stringify(geom.coordinates && geom.coordinates[0] ? geom.coordinates[0].slice(0, 5) : []));
                console.log("Geometría last 3 points:", JSON.stringify(geom.coordinates && geom.coordinates[0] ? geom.coordinates[0].slice(-3) : []));
            } catch (err) {
                console.error("Error parseando geometría:", err);
            }
        }

        // Validar que hay geometría
        if (!geometryInput.value || geometryInput.value.trim() === '') {
            mostrarMensaje('Debes dibujar un polígono en el mapa antes de guardar.', 'error');
            ocultarMensaje();
            return;
        }

        // Validar que el polígono tenga al menos 3 puntos (4 coordenadas con cierre)
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
            console.log("FormData geometry field:", formData.get('geometry') ? 'presente (' + formData.get('geometry').length + ' chars)' : 'ausente');

            const response = await fetch('../public/index.php?p=saveGeometria', {
                method: 'POST',
                body: formData,
                credentials: 'same-origin',
            });

            console.log("Respuesta del servidor status:", response.status);
            const result = await response.json();
            console.log("Respuesta del servidor:", result);

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
            console.error("Error al comunicarse con el servidor:", err);
            mostrarMensaje('Error al comunicarse con el servidor: ' + err.message, 'error');
            ocultarMensaje();
            btnGuardar.disabled = false;
            btnGuardar.textContent = textoOriginal;
        }
    });
});
