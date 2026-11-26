// ==========================================
// CONFIGURACIÓN DE DESARROLLO
// ==========================================
var USE_GPS_LOCATION = false; // El modo se carga desde la configuración del panel.

// ==========================================
// VARIABLES GLOBALES
// ==========================================
//cargar  mapa y ubicación inicial
var map = L.map('map', { attributionControl: false }).setView([25.5324, -103.436], 17);
L.control.attribution({ prefix: false }).addTo(map);

// Capa satelital local (teselas)
var tilesource_layer = L.tileLayer('tiles/satelital/{z}/{x}/{y}.jpg', {
    minZoom: 15,
    maxZoom: 19,
    tms: false,
    attribution: 'Imágenes extraídas de Google Maps. Mapa diseñado por Juan Pablo Chávez Ramírez e información de los edificios recolectada por Ángel Daniel Salinas Ortiz. maik. Sistema de caminos y rutas.'
}).addTo(map);

// Datos y capas del campus
var infoEdificios = {};
var capasEdificios = {};
var edificioDestacado = null;
var pathGraph = null;

// Ubicación y navegación
var currentPosition = null;      // Para GPS real [lat, lng]
var userMarker = null;           // Para GPS real
var accuracyCircle = null;       // Para GPS real
var watchId = null;              // ID de geolocalización real
var positionBuffer = [];
const MAX_BUFFER = 5;

// Variables de Simulación
var ubicacionSim = null;
var simulador = null;

// Variables de Ruta
var selectedDoorId = null;
var selectedDestinationId = null;
var activeRouteLine = null;
var publicRouteLine = null;
var selectedPublicRoute = null;
var publicRoutes = [];
var startPointMarker = null;
var endPointMarker = null;
var rutaFinalizada = false;
const RADIO_LLEGADA_METROS = 10;

var CONFIGURACION_PUBLICA_DEFECTO = {
    rutas: { distancia_inicio_ruta_m: 7 },
    ubicacion: { precision_gps_maxima_m: 60 },
    estilos: {
        ruta_publica: { color: '#1565c0', grosor: 6, opacidad: 0.85 },
        caminos: { color: '#12306b', grosor: 4, opacidad: 0.85 },
        caminos_publicos: { color: '#12306b', grosor: 4, opacidad: 0 },
        edificios_publicos: {
            contorno: { color: '#2F29D1', grosor: 0, opacidad: 1 },
            relleno: { color: '#3388FF', opacidad: 0 }
        }
    }
};

// Beacons en el campus (se cargan al inicializar si estamos en simulación o como referencia)
const beaconsData = [
    new Beacon('Beacon1', 'Beacon Edificio 1', 25.535220, -103.43479),
    new Beacon('Beacon2', 'Beacon Biblioteca', 25.529297, -103.43632),
    new Beacon('Beacon3', 'Beacon Cafeteria', 25.534350, -103.435491),
    new Beacon('Beacon4', 'Beacon Computo', 25.532600, -103.436093),
    new Beacon('Beacon5', 'Beacon Alberca', 25.531040, -103.435900),
    new Beacon('Beacon6', 'Beacon Gimnasio ', 25.530420, -103.435650),
    new Beacon('Beacon7', 'Beacon Sistemas', 25.533490, -103.436030),
    new Beacon('Beacon8', 'Beacon Vinculacion', 25.535080, -103.434177),
    new Beacon('Beacon9', 'Beacon Edificio 23', 25.533124, -103.435130),
    new Beacon('Beacon10', 'Beacon Edificio 35', 25.532068, -103.435080),
    new Beacon('Beacon11', 'Beacon Edificio 5', 25.535017, -103.436223)
];

// ==========================================
// UTILIDADES SEGURAS (delegadas en DomSeguro)
// ==========================================
// `js/shared/dom-seguro.js` se carga antes que este archivo. Los envoltorios
// mantienen el mapa en marcha aunque el helper no estuviera disponible, pero
// nunca insertan datos sin escapar.
var Seguro = (typeof DomSeguro !== 'undefined') ? DomSeguro : null;

function escaparHtml(valor) {
    if (Seguro && typeof Seguro.escaparHtml === 'function') {
        return Seguro.escaparHtml(valor);
    }
    return String(valor === null || valor === undefined ? '' : valor)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

function escaparRegex(texto) {
    if (Seguro && typeof Seguro.escaparRegex === 'function') {
        return Seguro.escaparRegex(texto);
    }
    return String(texto === null || texto === undefined ? '' : texto)
        .replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

function normalizarImagen(valor, idEsperado) {
    if (Seguro && typeof Seguro.normalizarRutaImagen === 'function') {
        return Seguro.normalizarRutaImagen(valor, idEsperado);
    }
    return null;
}

// Aviso no bloqueante para fallos de carga de datos.
function mostrarAviso(mensaje) {
    var aviso = document.getElementById('aviso-mapa');
    if (!aviso) {
        aviso = document.createElement('div');
        aviso.id = 'aviso-mapa';
        aviso.className = 'aviso-mapa';
        aviso.setAttribute('role', 'status');
        document.body.appendChild(aviso);
    }
    aviso.textContent = mensaje;
}

function obtenerConfiguracionPublica() {
    var config = window.CONFIG_MAPA || {};
    var estilos = Object.assign({}, CONFIGURACION_PUBLICA_DEFECTO.estilos, config.estilos || {});
    if (!config.estilos || !config.estilos.caminos_publicos) {
        estilos.caminos_publicos = config.estilos && config.estilos.caminos
            ? config.estilos.caminos
            : CONFIGURACION_PUBLICA_DEFECTO.estilos.caminos_publicos;
    }
    return {
        rutas: Object.assign({}, CONFIGURACION_PUBLICA_DEFECTO.rutas, config.rutas || {}),
        ubicacion: Object.assign({}, CONFIGURACION_PUBLICA_DEFECTO.ubicacion, config.ubicacion || {}),
        estilos: estilos
    };
}

function aplicarEstiloEdificiosPublicos() {
    var estilo = obtenerConfiguracionPublica().estilos.edificios_publicos;
    if (!capasEdificios || !estilo) return;
    Object.keys(capasEdificios).forEach(function (id) {
        var capa = capasEdificios[id];
        if (capa && typeof capa.setStyle === 'function') {
            capa.setStyle({
                color: estilo.contorno.color,
                weight: estilo.contorno.grosor,
                opacity: estilo.contorno.opacidad,
                fillColor: estilo.relleno.color,
                fillOpacity: estilo.relleno.opacidad
            });
        }
    });
}

// ==========================================
// CARGA DE DATOS & GEOMETRÍA
// ==========================================

// 1. Cargar metadatos de los edificios
fetch('data/datos.json')
    .then(function (respuesta) {
        if (!respuesta.ok) {
            throw new Error('HTTP ' + respuesta.status);
        }
        return respuesta.json();
    })
    .then(function (data) {
        if (!data || typeof data !== 'object' || Array.isArray(data)) {
            throw new Error('Formato inesperado en datos.json');
        }
        infoEdificios = data;
        sincronizarNombresAccesos();
        console.log("Cargados metadatos de edificios con éxito.");
    })
    .catch(function (error) {
        console.error("Error cargando datos.json:", error);
        mostrarAviso('No se pudieron cargar los datos de los edificios. El mapa sigue disponible.');
    });

// 2. Renderizar edificios desde datosTec (definido en GeoTec.js)
function renderizarEdificios() {
    if (typeof datosTec === 'undefined' || !datosTec || !Array.isArray(datosTec.features)) {
        console.error("GeoTec.js no definió datosTec; no se dibujan las huellas.");
        mostrarAviso('No se pudieron cargar las geometrías de los edificios.');
        return;
    }

    L.geoJSON(datosTec, {
        // Sin simplificación: Leaflet recorta vértices al dibujar (smoothFactor=1
        // por defecto) y las huellas irregulares se veían como rectángulos.
        smoothFactor: 0,
        style: function (feature) {
            var estilos = obtenerConfiguracionPublica().estilos.edificios_publicos;
            return {
                color: estilos.contorno.color,
                weight: estilos.contorno.grosor,
                opacity: estilos.contorno.opacidad,
                fillColor: estilos.relleno.color,
                fillOpacity: estilos.relleno.opacidad
            };
        },
        onEachFeature: function (feature, layer) {
            var propiedades = feature.properties || {};

            if (propiedades.id) {
                capasEdificios[propiedades.id] = layer;
            }

            if (propiedades.Nombre) {
                layer.bindTooltip(escaparHtml(propiedades.Nombre), {
                    permanent: false,
                    direction: 'top',
                    className: 'mi-tooltip-estilo'
                });
            }

            if (propiedades.id) {
                var centro = layer.getBounds().getCenter();
                var esAcceso = /^P[1-6]$/.test(String(propiedades.id));
                var iconoID = L.divIcon({
                    className: esAcceso ? 'label-acceso' : 'label-edificio',
                    html: escaparHtml(propiedades.id),
                    iconSize: [24, 24],
                    iconAnchor: [12, 12]
                });

                L.marker(centro, { icon: iconoID, interactive: false }).addTo(map);
            }

            layer.on('click', function () {
                if (propiedades.id) {
                    destacarEdificio(propiedades.id);
                }
                mostrarPopupEdificio(propiedades.id, layer);
            });
        }
    }).addTo(map);
}

renderizarEdificios();

// 3. Cargar caminos y construir el grafo para Dijkstra
fetch('data/caminitos.geojson')
    .then(function (respuesta) {
        if (!respuesta.ok) {
            throw new Error('HTTP ' + respuesta.status);
        }
        return respuesta.json();
    })
    .then(function (data) {
        var estiloCaminos = obtenerConfiguracionPublica().estilos.caminos_publicos;
        L.geoJSON(data, {
            style: {
                color: estiloCaminos.color,
                weight: estiloCaminos.grosor,
                opacity: estiloCaminos.opacidad,
                lineCap: 'round'
            }
        }).addTo(map);

        // Construir grafo en pathfinding.js
        pathGraph = buildGraph(data);
        console.log("Grafo para Dijkstra construido con éxito.");
        if (selectedPublicRoute) {
            actualizarRutaPublica(selectedPublicRoute, obtenerPosicionUsuario(), false);
        }
    })
    .catch(function (error) {
        console.error("Error cargando caminitos.geojson:", error);
        mostrarAviso('No se pudo cargar la red de caminos. El mapa sigue disponible.');
    });

// ==========================================
// INICIALIZACIÓN DE LOCALIZACIÓN
// ==========================================

function inicializarLocalizacion() {
    if (USE_GPS_LOCATION) {
        console.log("Iniciando en modo GPS Real");
        startGeolocation();
    } else {
        console.log("Iniciando en modo Simulación por Beacons");
        // Dibujar beacons en el mapa
        beaconsData.forEach(function (b) { b.agregarAlMapa(map); });

        // Crear simulador y ubicación simulada arrastrable en el centro del campus
        simulador = new SimuladorBeacons(beaconsData);
        ubicacionSim = new UbicacionSimulada(map, 25.5324, -103.436);

        // Escuchar el movimiento del marcador simulado
        ubicacionSim.onChange = function (pos) {
            let posEstimada = simulador.trilaterar(pos);
            if (posEstimada) {
                if (window.estimacionMarker) {
                    window.estimacionMarker.setLatLng([posEstimada.lat, posEstimada.lng]);
                } else {
                    window.estimacionMarker = L.circleMarker([posEstimada.lat, posEstimada.lng], {
                        color: 'darkred',
                        fillColor: 'red',
                        radius: 6,
                        weight: 2,
                        fillOpacity: 0.8
                    }).addTo(map).bindTooltip('Estimado por beacons');
                }
            }

            actualizarEstadoLlegada(pos);

            // Si hay una ruta activa calculada desde la ubicación del usuario, actualizarla en tiempo real
            if (selectedPublicRoute && !rutaFinalizada) {
                actualizarRutaPublica(selectedPublicRoute, pos, false);
            } else if (selectedDestinationId && !selectedDoorId && !rutaFinalizada) {
                calcularRuta(pos, selectedDestinationId, "Mi ubicación (Simulada)");
            }
        };

        // Ejecutar inicialización de la posición simulada
        ubicacionSim.actualizar();
    }
}

function cargarModoUbicacion() {
    return new Promise(function (resolve, reject) {
        var script = document.createElement('script');
        script.src = 'data/location-config.js?v=' + Date.now();
        script.onload = function () {
            var modo = window.MODO_UBICACION;
            if (script.parentNode) script.parentNode.removeChild(script);
            if (modo !== 'real' && modo !== 'simulada') {
                reject(new Error('El modo de ubicación no es válido.'));
                return;
            }
            aplicarEstiloEdificiosPublicos();
            resolve(modo);
        };
        script.onerror = function () {
            if (script.parentNode) script.parentNode.removeChild(script);
            reject(new Error('No se pudo cargar data/location-config.js.'));
        };
        document.head.appendChild(script);
    });
}

cargarModoUbicacion()
    .then(function (modo) {
        USE_GPS_LOCATION = modo === 'real';
        inicializarLocalizacion();
    })
    .catch(function (error) {
        console.error('No se pudo cargar el modo de ubicación:', error);
        USE_GPS_LOCATION = false;
        mostrarAviso('No se pudo cargar la configuración de ubicación. Se usará el modo simulado.');
        inicializarLocalizacion();
    });

// ==========================================
// GEOLOCALIZACIÓN REAL (Nativa)
// ==========================================

function startGeolocation() {
    if (!navigator.geolocation) {
        alert('Geolocalización no soportada por este navegador.');
        return;
    }

    watchId = navigator.geolocation.watchPosition(
        function (position) {
            var lat = position.coords.latitude;
            var lng = position.coords.longitude;
            var accuracy = position.coords.accuracy;
            var precisionMaxima = Number(obtenerConfiguracionPublica().ubicacion.precision_gps_maxima_m);

            if (!Number.isFinite(precisionMaxima) || accuracy > precisionMaxima) {
                console.warn(`Precisión baja (${accuracy}m), ignorando...`);
                return;
            }

            positionBuffer.push({ lat: lat, lng: lng, accuracy: accuracy });
            if (positionBuffer.length > MAX_BUFFER) positionBuffer.shift();

            var sumLat = 0, sumLng = 0;
            for (var i = 0; i < positionBuffer.length; i++) {
                sumLat += positionBuffer[i].lat;
                sumLng += positionBuffer[i].lng;
            }
            var avgLat = sumLat / positionBuffer.length;
            var avgLng = sumLng / positionBuffer.length;
            var bestAccuracy = Math.min(...positionBuffer.map(p => p.accuracy));

            currentPosition = [avgLat, avgLng];

            actualizarEstadoLlegada({ lat: avgLat, lng: avgLng });

            if (userMarker) {
                userMarker.setLatLng([avgLat, avgLng]);
            } else {
                userMarker = L.circleMarker([avgLat, avgLng], {
                    color: 'red',
                    fillColor: 'red',
                    fillOpacity: 1,
                    radius: 8,
                    weight: 2
                }).addTo(map);
            }

            if (accuracyCircle) {
                accuracyCircle.setLatLng([avgLat, avgLng]);
                accuracyCircle.setRadius(bestAccuracy);
            } else {
                accuracyCircle = L.circle([avgLat, avgLng], {
                    color: 'red',
                    fillColor: 'red',
                    fillOpacity: 0.1,
                    radius: bestAccuracy,
                    weight: 1,
                    dashArray: '5, 5'
                }).addTo(map);
            }

            // Si hay ruta activa y no hay puerta seleccionada, recalculamos desde la nueva posición real
            if (selectedPublicRoute && !rutaFinalizada) {
                actualizarRutaPublica(selectedPublicRoute, { lat: avgLat, lng: avgLng }, false);
            } else if (selectedDestinationId && !selectedDoorId && !rutaFinalizada) {
                calcularRuta({ lat: avgLat, lng: avgLng }, selectedDestinationId, "Mi ubicación (GPS)");
            }
        },
        function (error) {
            console.error('Error de geolocalización:', error);
            var errorMsg = 'No se pudo obtener la ubicación.';
            switch (error.code) {
                case error.PERMISSION_DENIED:
                    errorMsg += ' Permiso denegado.';
                    break;
                case error.POSITION_UNAVAILABLE:
                    errorMsg += ' Ubicación no disponible.';
                    break;
                case error.TIMEOUT:
                    errorMsg += ' Tiempo de espera agotado.';
                    break;
            }
            console.warn(errorMsg);
        },
        {
            enableHighAccuracy: true,
            timeout: 5000,
            maximumAge: 0
        }
    );
}

function stopGeolocation() {
    if (watchId !== null) {
        navigator.geolocation.clearWatch(watchId);
        watchId = null;
    }
    if (userMarker) {
        map.removeLayer(userMarker);
        userMarker = null;
    }
    if (accuracyCircle) {
        map.removeLayer(accuracyCircle);
        accuracyCircle = null;
    }
    currentPosition = null;
    positionBuffer = [];
}

function centerOnLocation() {
    if (USE_GPS_LOCATION) {
        if (currentPosition) {
            map.setView(currentPosition, 19);
        } else {
            alert('Ubicación real no disponible aún. Esperando lectura precisa del GPS...');
        }
    } else {
        if (ubicacionSim) {
            map.setView([ubicacionSim.lat, ubicacionSim.lng], 19);
        }
    }
}

function obtenerPosicionUsuario() {
    if (USE_GPS_LOCATION) {
        return currentPosition
            ? { lat: currentPosition[0], lng: currentPosition[1] }
            : null;
    }
    return ubicacionSim ? ubicacionSim.getPosicion() : null;
}

function actualizarEstadoLlegada(posicion) {
    if ((!activeRouteLine && !publicRouteLine) || rutaFinalizada || !posicion) return;
    var centroDestino;
    if (selectedPublicRoute) {
        centroDestino = destinoRutaPublica(selectedPublicRoute);
    } else {
        if (!selectedDestinationId) return;
        var destino = capasEdificios[selectedDestinationId];
        if (!destino) return;
        centroDestino = destino.getBounds().getCenter();
    }
    if (!centroDestino) return;
    var distanciaAlDestino = distanciaMetros(
        posicion.lat,
        posicion.lng,
        centroDestino.lat,
        centroDestino.lng
    );

    if (distanciaAlDestino <= RADIO_LLEGADA_METROS) {
        rutaFinalizada = true;
        finalizarRecorrido();
    }
}

function finalizarRecorrido() {
    if (activeRouteLine) {
        map.removeLayer(activeRouteLine);
        activeRouteLine = null;
    }
    if (startPointMarker) {
        map.removeLayer(startPointMarker);
        startPointMarker = null;
    }
    if (endPointMarker) {
        map.removeLayer(endPointMarker);
        endPointMarker = null;
    }
    limpiarSeleccionRutaPublica();

    var panel = document.getElementById('navigation-info-panel');
    if (!panel) return;

    panel.innerHTML = '';
    var mensajeLlegada = document.createElement('div');
    mensajeLlegada.className = 'nav-info-header';
    mensajeLlegada.setAttribute('role', 'status');
    mensajeLlegada.textContent = '¡Llegaste a tu destino!';

    var botonCerrar = document.createElement('button');
    botonCerrar.type = 'button';
    botonCerrar.id = 'clear-route-btn';
    botonCerrar.setAttribute('data-accion', 'limpiar-ruta');
    botonCerrar.textContent = 'Cerrar';
    panel.appendChild(mensajeLlegada);
    panel.appendChild(botonCerrar);
}

// ==========================================
// INTERFAZ POPUP DE DETALLE DE EDIFICIOS
// ==========================================

function crearBotonAccion(texto, accion, id) {
    var boton = document.createElement('button');
    boton.type = 'button';
    boton.className = 'como-llegar-btn';
    boton.textContent = texto;
    boton.setAttribute('data-accion', accion);
    if (id !== undefined && id !== null) {
        boton.setAttribute('data-id', String(id));
    }
    return boton;
}

function crearPopupPuerta(id) {
    var contenedor = document.createElement('div');
    contenedor.className = 'popup-tec';

    var titulo = document.createElement('h3');
    titulo.textContent = obtenerNombrePuerta(id);
    contenedor.appendChild(titulo);

    var descripcion = document.createElement('p');
    descripcion.textContent = 'Punto de acceso al campus.';
    contenedor.appendChild(descripcion);

    var acciones = document.createElement('div');
    acciones.className = 'popup-actions popup-actions--columna';
    acciones.appendChild(crearBotonAccion('Seleccionar punto de acceso', 'seleccionar-puerta', id));
    acciones.appendChild(crearBotonAccion('Cómo llegar', 'iniciar-ruta', id));
    contenedor.appendChild(acciones);

    return contenedor;
}

function crearPopupEdificio(id, datos) {
    var contenedor = document.createElement('div');
    contenedor.className = 'popup-tec';

    var nombre = (datos && typeof datos.nombre === 'string' && datos.nombre !== '')
        ? datos.nombre
        : ('Edificio ' + id);

    var titulo = document.createElement('h3');
    titulo.textContent = nombre;
    contenedor.appendChild(titulo);

    // Solo se renderizan imágenes que cumplan `img/<id>/<archivo>` y que
    // pertenezcan al propio edificio. Cualquier otra ruta se descarta.
    var imagenes = (datos && Array.isArray(datos.imagenes)) ? datos.imagenes : [];
    var imagenesValidas = [];
    imagenes.forEach(function (valor) {
        var ruta = normalizarImagen(valor, id);
        if (ruta) imagenesValidas.push(ruta);
    });

    if (imagenesValidas.length > 0) {
        var carrusel = document.createElement('div');
        carrusel.className = 'carousel-container';
        imagenesValidas.forEach(function (ruta) {
            var imagen = document.createElement('img');
            imagen.src = ruta;
            imagen.alt = nombre;
            imagen.loading = 'lazy';
            carrusel.appendChild(imagen);
        });
        contenedor.appendChild(carrusel);
    }

    if (datos && typeof datos.descripcion === 'string' && datos.descripcion !== '') {
        var descripcion = document.createElement('p');
        descripcion.textContent = datos.descripcion;
        contenedor.appendChild(descripcion);
    }

    var etiquetaAulas = document.createElement('strong');
    etiquetaAulas.textContent = 'Aulas u otros:';
    contenedor.appendChild(etiquetaAulas);

    var aulas = (datos && Array.isArray(datos.aulas)) ? datos.aulas : [];
    if (aulas.length > 0) {
        var contenedorAulas = document.createElement('div');
        contenedorAulas.className = 'aulas-container';
        aulas.forEach(function (aula) {
            var insignia = document.createElement('span');
            insignia.className = 'badge-aula';
            insignia.textContent = String(aula);
            contenedorAulas.appendChild(insignia);
        });
        contenedor.appendChild(contenedorAulas);
    }

    var acciones = document.createElement('div');
    acciones.className = 'popup-actions';
    acciones.appendChild(crearBotonAccion('Cómo llegar', 'iniciar-ruta', id));
    contenedor.appendChild(acciones);

    return contenedor;
}

function crearPopupSinDatos(id) {
    var contenedor = document.createElement('div');
    contenedor.className = 'popup-tec';

    var titulo = document.createElement('h3');
    titulo.textContent = 'Edificio ' + id;
    contenedor.appendChild(titulo);

    var descripcion = document.createElement('p');
    descripcion.textContent = 'Información detallada no disponible.';
    contenedor.appendChild(descripcion);

    var acciones = document.createElement('div');
    acciones.className = 'popup-actions';
    acciones.appendChild(crearBotonAccion('Cómo llegar', 'iniciar-ruta', id));
    contenedor.appendChild(acciones);

    return contenedor;
}

// mostrar la informacion del edificio seleccionado
function mostrarPopupEdificio(id, layer) {
    if (["P1", "P2", "P3", "P4", "P5", "P6"].includes(id)) {
        layer.bindPopup(crearPopupPuerta(id)).openPopup();
        return;
    }

    var datos = infoEdificios[id];
    if (datos) {
        layer.bindPopup(crearPopupEdificio(id, datos)).openPopup();
    } else {
        layer.bindPopup(crearPopupSinDatos(id)).openPopup();
    }
}

// Wrapper para que sea llamado externamente por la sugerencia de búsqueda
function mostrarPopup(edificioId, layer) {
    mostrarPopupEdificio(edificioId, layer);
}

// Delegación de eventos: los popups y el panel se construyen con atributos
// `data-accion`/`data-id` en lugar de manejadores `onclick` inline (CSP).
document.addEventListener('click', function (evento) {
    var objetivo = evento.target;
    if (!objetivo || typeof objetivo.closest !== 'function') return;

    var boton = objetivo.closest('[data-accion]');
    if (!boton) return;

    var accion = boton.getAttribute('data-accion');
    var id = boton.getAttribute('data-id');

    if (accion === 'seleccionar-puerta' && id) {
        window.seleccionarPuerta(id);
    } else if (accion === 'iniciar-ruta' && id) {
        window.iniciarRuta(id);
    } else if (accion === 'limpiar-ruta') {
        window.limpiarRuta();
    } else if (accion === 'seleccionar-ruta-publica' && id) {
        seleccionarRutaPublica(id);
    }
});

// ==========================================
// SISTEMA DE NAVEGACIÓN DIJKSTRA
// ==========================================

// Función puente llamada desde el Popup "Cómo llegar"
window.iniciarRuta = function (destId) {
    limpiarSeleccionRutaPublica();
    selectedDestinationId = destId;

    let origCoors = null;
    let origName = "";

    // 1. Verificar si hay una puerta de acceso seleccionada como origen
    if (selectedDoorId) {
        origCoors = obtenerCentroPuerta(selectedDoorId);
        let selectEl = document.getElementById('doors-select');
        if (selectEl && selectEl.selectedIndex >= 0) {
            origName = selectEl.options[selectEl.selectedIndex].text;
        } else {
            origName = obtenerNombrePuerta(selectedDoorId);
        }
    } else {
        // 2. Si no hay puerta, usar la ubicación del usuario
        if (USE_GPS_LOCATION) {
            if (currentPosition) {
                origCoors = { lat: currentPosition[0], lng: currentPosition[1] };
                origName = "Mi ubicación (GPS)";
            }
        } else {
            if (ubicacionSim) {
                origCoors = ubicacionSim.getPosicion();
                origName = "Mi ubicación (Simulada)";
            }
        }
    }

    // 3. CAPTURA DE ERROR: Si la ubicación no es accesible, lanzar alerta y sugerir puerta
    if (!origCoors) {
        alert("No pudimos obtener tu ubicación actual.\n\nSelecciona un punto de acceso desde el popup de una puerta.");

        // Enfocar / parpadear visualmente el dropdown de puertas para guiar al usuario
        let selectEl = document.getElementById('doors-select');
        if (selectEl) {
            selectEl.style.outline = "3px solid #ff5722";
            setTimeout(() => {
                selectEl.style.outline = "none";
            }, 3000);
        }
        return;
    }

    // 4. Calcular y dibujar la ruta
    rutaFinalizada = false;
    calcularRuta(origCoors, destId, origName);
};


// trazar y calcular la ruta de inicio a fin
function calcularRuta(origCoors, destId, origName) {
    if (!pathGraph) {
        alert("Los caminos aún se están cargando. Espera un momento.");
        return;
    }

    let destCapa = capasEdificios[destId];
    if (!destCapa) {
        alert("No se encontró la ubicación geométrica del destino.");
        return;
    }

    let destCoors = destCapa.getBounds().getCenter();
    let destName = infoEdificios[destId] ? infoEdificios[destId].nombre : `Edificio ${destId}`;

    // Encontrar nodos más cercanos del grafo de caminos
    let startNodeKey = findClosestNode(origCoors.lat, origCoors.lng, pathGraph);
    let endNodeKey = findClosestNode(destCoors.lat, destCoors.lng, pathGraph);

    if (!startNodeKey || !endNodeKey) {
        alert("No se pudo enlazar la ubicación de inicio o destino a los caminos peatonalizados.");
        return;
    }

    // Calcular ruta más corta con Dijkstra
    let path = dijkstra(pathGraph, startNodeKey, endNodeKey);

    if (!path || path.length === 0) {
        alert("No fue posible trazar un camino conectado para este destino.");
        return;
    }

    // Agregar coordenadas iniciales y finales para cerrar la brecha con la red
    path.unshift({ lat: origCoors.lat, lng: origCoors.lng });
    path.push({ lat: destCoors.lat, lng: destCoors.lng });

    // Limpiar capa de ruta anterior
    if (activeRouteLine) map.removeLayer(activeRouteLine);

    // Dibujar nueva polilínea con animación
    activeRouteLine = L.polyline(path, {
        color: '#1565c0',
        weight: 6,
        opacity: 0.85,
        lineCap: 'round',
        lineJoin: 'round',
        className: 'animated-route-line'
    }).addTo(map);

    // Ajustar zoom para abarcar todo el recorrido
    map.fitBounds(activeRouteLine.getBounds(), { padding: [60, 60] });

    // Actualizar marcadores de inicio y fin de ruta
    if (startPointMarker) {
        map.removeLayer(startPointMarker);
        startPointMarker = null;
    }
    if (endPointMarker) {
        map.removeLayer(endPointMarker);
        endPointMarker = null;
    }

    // Calcular distancia total de la ruta
    let distanciaTotal = Math.round(calcularDistanciaRuta(path));

    // Mostrar panel flotante con la información de navegación
    mostrarPanelNavegacion(origName, destName, distanciaTotal);
    actualizarEstadoLlegada(obtenerPosicionUsuario());

    // Cerrar cualquier popup abierto
    map.closePopup();
}

function cargarCatalogoRutasPublicas() {
    fetch('data/rutas-publicas.json', { cache: 'no-store' })
        .then(function (respuesta) {
            if (!respuesta.ok) throw new Error('HTTP ' + respuesta.status);
            return respuesta.json();
        })
        .then(function (catalogo) {
            if (!catalogo || catalogo.schema_version !== 1 || !Array.isArray(catalogo.rutas)) {
                throw new Error('Formato del catálogo no válido.');
            }
            publicRoutes = catalogo.rutas.filter(function (ruta) {
                return ruta && typeof ruta.id === 'string' && typeof ruta.nombre === 'string';
            });
            renderizarSelectorRutasPublicas();
        })
        .catch(function (error) {
            console.warn('No se pudo cargar el catálogo de rutas públicas:', error);
            var estadoRutas = document.getElementById('public-routes-status');
            if (estadoRutas) estadoRutas.textContent = 'Las rutas registradas no están disponibles en esta publicación.';
        });
}

function renderizarSelectorRutasPublicas() {
    var lista = document.getElementById('public-routes-items');
    var estadoRutas = document.getElementById('public-routes-status');
    if (!lista || !estadoRutas) return;
    while (lista.firstChild) lista.removeChild(lista.firstChild);
    if (publicRoutes.length === 0) {
        estadoRutas.textContent = 'No hay rutas activas disponibles.';
        return;
    }
    estadoRutas.textContent = '';
    publicRoutes.forEach(function (ruta) {
        var item = document.createElement('li');
        var boton = document.createElement('button');
        boton.type = 'button';
        boton.className = 'public-route-item';
        boton.setAttribute('data-accion', 'seleccionar-ruta-publica');
        boton.setAttribute('data-id', ruta.id);
        boton.setAttribute('aria-pressed', 'false');
        var nombre = document.createElement('strong');
        nombre.textContent = ruta.nombre;
        var resumen = document.createElement('span');
        var distancia = Number(ruta.distancia_m);
        resumen.textContent = (ruta.destino && ruta.destino.nombre ? 'Destino: ' + ruta.destino.nombre : 'Ruta del campus')
            + (Number.isFinite(distancia) ? ' · ' + (distancia >= 1000 ? (distancia / 1000).toFixed(2) + ' km' : Math.round(distancia) + ' m') : '');
        boton.appendChild(nombre);
        boton.appendChild(resumen);
        item.appendChild(boton);
        lista.appendChild(item);
    });
}

function limpiarCapaRutaPublica() {
    if (publicRouteLine) {
        map.removeLayer(publicRouteLine);
        publicRouteLine = null;
    }
}

function coordenadasPublicas(ruta) {
    var coordenadas = ruta && ruta.geometria && ruta.geometria.coordinates;
    if (!Array.isArray(coordenadas) || coordenadas.length < 2) return [];
    var puntos = [];
    for (var i = 0; i < coordenadas.length; i++) {
        var punto = coordenadas[i];
        if (!Array.isArray(punto) || punto.length < 2 || !Number.isFinite(Number(punto[0])) || !Number.isFinite(Number(punto[1]))) {
            return [];
        }
        puntos.push([Number(punto[1]), Number(punto[0])]);
    }
    return puntos;
}

function destinoRutaPublica(ruta) {
    var destino = ruta && ruta.destino;
    if (!destino) return null;
    if (destino.tipo === 'nodo') {
        var valores = String(destino.ref || '').split(',').map(Number);
        return valores.length === 2 && valores.every(Number.isFinite) ? { lat: valores[0], lng: valores[1] } : null;
    }
    var capa = capasEdificios[destino.ref];
    if (!capa || typeof capa.getBounds !== 'function') return null;
    var centro = capa.getBounds().getCenter();
    return { lat: centro.lat, lng: centro.lng };
}

function recortarRutaDesde(posicion, coordenadas) {
    if (!posicion || coordenadas.length < 2) return coordenadas;
    var mejor = { distancia: Infinity, indice: -1, punto: null };
    for (var i = 0; i < coordenadas.length - 1; i++) {
        var inicio = coordenadas[i];
        var fin = coordenadas[i + 1];
        var proyeccion = projectPointOnSegment(posicion.lat, posicion.lng, inicio[0], inicio[1], fin[0], fin[1]);
        if (proyeccion.dist < mejor.distancia) {
            mejor = {
                distancia: proyeccion.dist,
                indice: i,
                punto: [
                    inicio[0] + (fin[0] - inicio[0]) * proyeccion.t,
                    inicio[1] + (fin[1] - inicio[1]) * proyeccion.t
                ]
            };
        }
    }
    var configuracion = obtenerConfiguracionPublica();
    if (mejor.indice < 0 || mejor.distancia > Number(configuracion.rutas.distancia_inicio_ruta_m || 7)) {
        return coordenadas;
    }
    var recortada = [[posicion.lat, posicion.lng], mejor.punto];
    for (var j = mejor.indice + 1; j < coordenadas.length; j++) {
        recortada.push(coordenadas[j]);
    }
    return recortada;
}

function calcularRutaPublicaDinamica(ruta, posicion) {
    if (!pathGraph || !posicion) return null;
    var destino = destinoRutaPublica(ruta);
    if (!destino) return null;
    var inicioKey = findClosestNode(posicion.lat, posicion.lng, pathGraph);
    var finKey = findClosestNode(destino.lat, destino.lng, pathGraph);
    if (!inicioKey || !finKey) return null;
    var nodos = dijkstra(pathGraph, inicioKey, finKey);
    if (!Array.isArray(nodos) || nodos.length === 0) return null;
    nodos.unshift({ lat: posicion.lat, lng: posicion.lng });
    nodos.push(destino);
    return nodos.map(function (punto) { return [punto.lat, punto.lng]; });
}

function dibujarRutaPublica(ruta, coordenadas, ajustarVista) {
    limpiarCapaRutaPublica();
    if (!Array.isArray(coordenadas) || coordenadas.length < 2) return false;
    var estilo = obtenerConfiguracionPublica().estilos.ruta_publica;
    publicRouteLine = L.polyline(coordenadas, {
        color: estilo.color,
        weight: estilo.grosor,
        opacity: estilo.opacidad,
        lineCap: 'round',
        lineJoin: 'round',
        className: 'animated-route-line'
    }).addTo(map);
    if (ajustarVista) map.fitBounds(publicRouteLine.getBounds(), { padding: [60, 60] });
    var metrosRuta = calcularDistanciaRuta(coordenadas.map(function (punto) {
        return { lat: punto[0], lng: punto[1] };
    }));
    var destino = ruta.destino && ruta.destino.nombre ? ruta.destino.nombre : 'Destino';
    mostrarPanelNavegacion('Ruta: ' + ruta.nombre, destino, Math.round(metrosRuta));
    map.closePopup();
    actualizarEstadoLlegada(obtenerPosicionUsuario());
    return true;
}

function actualizarRutaPublica(ruta, posicion, ajustarVista) {
    var coordenadas = [];
    if (ruta.dinamica && !ruta.geometria) {
        coordenadas = calcularRutaPublicaDinamica(ruta, posicion) || [];
    } else {
        coordenadas = coordenadasPublicas(ruta);
        coordenadas = recortarRutaDesde(posicion, coordenadas);
    }
    if (!dibujarRutaPublica(ruta, coordenadas, ajustarVista)) {
        var estadoRutas = document.getElementById('public-routes-status');
        if (estadoRutas) estadoRutas.textContent = ruta.dinamica
            ? 'No se pudo calcular la ruta desde tu ubicación. Comprueba que la ubicación y la red estén disponibles.'
            : 'No se pudo dibujar la geometría de esta ruta.';
        return false;
    }
    return true;
}

function seleccionarRutaPublica(id) {
    var ruta = publicRoutes.find(function (item) { return item.id === id; });
    if (!ruta) return;
    if (activeRouteLine) {
        map.removeLayer(activeRouteLine);
        activeRouteLine = null;
    }
    if (startPointMarker) {
        map.removeLayer(startPointMarker);
        startPointMarker = null;
    }
    if (endPointMarker) {
        map.removeLayer(endPointMarker);
        endPointMarker = null;
    }
    selectedDestinationId = null;
    selectedDoorId = null;
    var selectorPuertas = document.getElementById('doors-select');
    if (selectorPuertas) selectorPuertas.value = '';
    selectedPublicRoute = ruta;
    document.querySelectorAll('.public-route-item').forEach(function (boton) {
        boton.setAttribute('aria-pressed', boton.getAttribute('data-id') === id ? 'true' : 'false');
    });
    var posicion = obtenerPosicionUsuario();
    var limpiarRuta = document.getElementById('public-routes-clear');
    if (limpiarRuta) limpiarRuta.hidden = false;
    actualizarRutaPublica(ruta, posicion, true);
    abrirSelectorRutas(false);
}

function abrirSelectorRutas(abierto) {
    var lista = document.getElementById('public-routes-list');
    var boton = document.getElementById('public-routes-toggle');
    if (!lista || !boton) return;
    lista.hidden = !abierto;
    boton.setAttribute('aria-expanded', abierto ? 'true' : 'false');
}

function limpiarSeleccionRutaPublica() {
    selectedPublicRoute = null;
    limpiarCapaRutaPublica();
    var borrar = document.getElementById('public-routes-clear');
    if (borrar) borrar.hidden = true;
    document.querySelectorAll('.public-route-item').forEach(function (boton) {
        boton.setAttribute('aria-pressed', 'false');
    });
}

function activarPanelMovible(panel, cabecera, resizeHandle) {
    function limitarPanel(ancho, alto, izquierda, arriba) {
        return {
            left: Math.max(12, Math.min(izquierda, window.innerWidth - ancho - 12)),
            top: Math.max(12, Math.min(arriba, window.innerHeight - alto - 12))
        };
    }

    cabecera.addEventListener('pointerdown', function (evento) {
        if (evento.target.closest('button, a, input, select')) return;
        var rect = panel.getBoundingClientRect();
        var inicioX = evento.clientX;
        var inicioY = evento.clientY;
        panel.style.left = rect.left + 'px';
        panel.style.top = rect.top + 'px';
        panel.style.right = 'auto';
        panel.style.bottom = 'auto';
        panel.style.transform = 'none';
        cabecera.setPointerCapture(evento.pointerId);
        evento.stopPropagation();
        evento.preventDefault();
        function mover(movimiento) {
            var limites = limitarPanel(rect.width, rect.height, rect.left + movimiento.clientX - inicioX, rect.top + movimiento.clientY - inicioY);
            panel.style.left = limites.left + 'px';
            panel.style.top = limites.top + 'px';
        }
        function terminar() {
            cabecera.removeEventListener('pointermove', mover);
            cabecera.removeEventListener('pointerup', terminar);
            cabecera.removeEventListener('pointercancel', terminar);
        }
        cabecera.addEventListener('pointermove', mover);
        cabecera.addEventListener('pointerup', terminar);
        cabecera.addEventListener('pointercancel', terminar);
    });

    resizeHandle.addEventListener('pointerdown', function (evento) {
        var rect = panel.getBoundingClientRect();
        var inicioX = evento.clientX;
        var inicioY = evento.clientY;
        var anchoInicial = rect.width;
        var altoInicial = rect.height;
        panel.style.left = rect.left + 'px';
        panel.style.top = rect.top + 'px';
        panel.style.right = 'auto';
        panel.style.bottom = 'auto';
        panel.style.transform = 'none';
        resizeHandle.setPointerCapture(evento.pointerId);
        evento.stopPropagation();
        evento.preventDefault();
        function redimensionar(movimiento) {
            panel.style.width = Math.max(220, Math.min(anchoInicial + movimiento.clientX - inicioX, window.innerWidth - rect.left - 12)) + 'px';
            panel.style.height = Math.max(120, Math.min(altoInicial + movimiento.clientY - inicioY, window.innerHeight - rect.top - 12)) + 'px';
        }
        function terminar() {
            resizeHandle.removeEventListener('pointermove', redimensionar);
            resizeHandle.removeEventListener('pointerup', terminar);
            resizeHandle.removeEventListener('pointercancel', terminar);
        }
        resizeHandle.addEventListener('pointermove', redimensionar);
        resizeHandle.addEventListener('pointerup', terminar);
        resizeHandle.addEventListener('pointercancel', terminar);
    });
}

function crearFilaNavegacion(etiqueta, valor, claseValor) {
    var fila = document.createElement('div');
    fila.className = 'nav-info-item';

    var titulo = document.createElement('strong');
    titulo.textContent = etiqueta;

    var detalle = document.createElement('span');
    if (claseValor) detalle.className = claseValor;
    detalle.textContent = valor;

    fila.appendChild(titulo);
    fila.appendChild(detalle);
    return fila;
}

function mostrarPanelNavegacion(origen, destino, distancia) {
    let panel = document.getElementById('navigation-info-panel');
    if (!panel) {
        panel = document.createElement('div');
        panel.id = 'navigation-info-panel';
        panel.className = 'navigation-info-panel';
        document.body.appendChild(panel);
    }

    // Se construye con nodos de texto: los nombres vienen de los datos y no
    // deben interpretarse como HTML.
    panel.innerHTML = '';

    var cabecera = document.createElement('div');
    cabecera.className = 'nav-info-header';
    cabecera.textContent = 'Indicaciones de Ruta';

    var detalles = document.createElement('div');
    detalles.className = 'nav-info-details';
    detalles.appendChild(crearFilaNavegacion('Desde:', String(origen)));
    detalles.appendChild(crearFilaNavegacion('Hasta:', String(destino)));
    detalles.appendChild(crearFilaNavegacion('Distancia:', distancia + ' metros', 'nav-dist'));
    var filaEstado = crearFilaNavegacion(
        'Estado:',
        rutaFinalizada ? '¡Llegaste! Recorrido finalizado.' : 'En camino',
        'nav-route-status' + (rutaFinalizada ? ' route-completed' : '')
    );
    filaEstado.querySelector('span').id = 'navigation-route-status';
    filaEstado.querySelector('span').setAttribute('role', 'status');
    filaEstado.querySelector('span').setAttribute('aria-live', 'polite');
    detalles.appendChild(filaEstado);

    var boton = document.createElement('button');
    boton.type = 'button';
    boton.id = 'clear-route-btn';
    boton.setAttribute('data-accion', 'limpiar-ruta');
    boton.textContent = 'Limpiar Recorrido';

    panel.appendChild(cabecera);
    panel.appendChild(detalles);
    panel.appendChild(boton);
    var resizeHandle = document.createElement('div');
    resizeHandle.className = 'nav-panel-resize';
    resizeHandle.setAttribute('role', 'separator');
    resizeHandle.setAttribute('aria-label', 'Cambiar tamaño del panel');
    panel.appendChild(resizeHandle);
    activarPanelMovible(panel, cabecera, resizeHandle);

    // Añadir estilo activo
    setTimeout(() => {
        panel.classList.add('active');
    }, 50);
}

window.limpiarRuta = function () {
    if (activeRouteLine) {
        map.removeLayer(activeRouteLine);
        activeRouteLine = null;
    }
    if (startPointMarker) {
        map.removeLayer(startPointMarker);
        startPointMarker = null;
    }
    if (endPointMarker) {
        map.removeLayer(endPointMarker);
        endPointMarker = null;
    }
    limpiarSeleccionRutaPublica();

    // Limpiar selecciones de puertas
    var selectEl = document.getElementById('doors-select');
    if (selectEl) selectEl.value = "";
    selectedDoorId = null;
    selectedDestinationId = null;
    rutaFinalizada = false;

    let panel = document.getElementById('navigation-info-panel');
    if (panel) {
        panel.classList.remove('active');
    }
};

// ==========================================
// SELECTOR & CENTRADO DE PUERTAS
// ==========================================

function obtenerNombrePuerta(idPuerta) {
    // Los accesos (P1–P6) se gestionan desde el panel como cualquier otro
    // registro; si existe metadato, su nombre es la fuente de verdad.
    if (infoEdificios && infoEdificios[idPuerta] && infoEdificios[idPuerta].nombre) {
        return infoEdificios[idPuerta].nombre;
    }

    let selectEl = document.getElementById('doors-select');
    if (selectEl) {
        for (let i = 0; i < selectEl.options.length; i++) {
            if (selectEl.options[i].value === idPuerta) {
                return selectEl.options[i].text;
            }
        }
    }
    const fallbacks = {
        "P1": "Puerta 1 (Norte - Gestión)",
        "P2": "Puerta 2 (Norte - Admin)",
        "P3": "Puerta 3 (Este - Alberca)",
        "P4": "Puerta 4 (Sur - Gimnasio)",
        "P5": "Puerta 5 (Sur - Béisbol)",
        "P6": "Puerta 6 (Oeste - Aulas)"
    };
    return fallbacks[idPuerta] || `Puerta ${idPuerta}`;
}

// Mantiene el selector de puertas alineado con los metadatos del panel, de
// modo que renombrar un acceso en el panel se refleje también en el mapa.
function sincronizarNombresAccesos() {
    const selectEl = document.getElementById('doors-select');
    if (!selectEl || !infoEdificios) return;

    Array.prototype.forEach.call(selectEl.options, function (opcion) {
        const meta = infoEdificios[opcion.value];
        if (meta && meta.nombre) {
            opcion.textContent = meta.nombre;
        }
    });
}

// puntos de origen (accesos)
window.seleccionarPuerta = function (doorId) {
    limpiarSeleccionRutaPublica();
    let selectEl = document.getElementById('doors-select');
    if (selectEl) {
        selectEl.value = doorId;
        selectEl.dispatchEvent(new Event('change'));
    }
    map.closePopup();
};

function obtenerCentroPuerta(idPuerta) {
    let capa = capasEdificios[idPuerta];
    if (capa) {
        return capa.getBounds().getCenter();
    }

    // Coordenadas fallback precisas de las puertas en caso de no haberse mapeado la geometría aún
    const fallbacks = {
        "P1": { lat: 25.534749, lng: -103.434492 },
        "P2": { lat: 25.533321, lng: -103.434524 },
        "P3": { lat: 25.531002, lng: -103.435153 },
        "P4": { lat: 25.530236, lng: -103.435404 },
        "P5": { lat: 25.5294985, lng: -103.435666 },
        "P6": { lat: 25.535214, lng: -103.436398 }
    };
    return fallbacks[idPuerta];
}

// Escuchar cambios en el dropdown de puertas
document.getElementById('doors-select').addEventListener('change', function (e) {
    let doorId = e.target.value;
    selectedDoorId = doorId;

    let centro = obtenerCentroPuerta(doorId);
    if (centro) {
        // Centrar mapa
        map.setView([centro.lat, centro.lng], 19);

        // Poner marcador de partida en la puerta
        if (startPointMarker) map.removeLayer(startPointMarker);

        let doorName = e.target.options[e.target.selectedIndex].text;
        startPointMarker = L.marker([centro.lat, centro.lng], {
            icon: L.divIcon({
                className: 'route-point-start',
                html: '<div class="route-point-start-dot"></div>',
                iconSize: [16, 16]
            })
        }).addTo(map).bindTooltip(escaparHtml('Partida: ' + doorName)).openTooltip();

        // Si hay un destino seleccionado actualmente, recalculamos la ruta desde la puerta elegida
        if (selectedDestinationId) {
            rutaFinalizada = false;
            calcularRuta(centro, selectedDestinationId, doorName);
        }
    }
});

// ==========================================
// BUSCADOR & CONTROLES DE INTERFAZ
// ==========================================

var searchInput = document.getElementById('searchbar-input');
var suggestionsContainer = document.getElementById('searchbar-suggestions');
var searchBtn = document.getElementById('search-btn');

searchInput.addEventListener('input', function () {
    var query = this.value.toLowerCase().trim();

    if (query.length === 0) {
        suggestionsContainer.classList.remove('active');
        return;
    }

    var resultados = buscarEdificiosYAulas(query);
    mostrarSugerencias(resultados, query);
});

searchBtn.addEventListener('click', function () {
    var query = searchInput.value.toLowerCase().trim();

    if (query.length > 0) {
        var resultados = buscarEdificiosYAulas(query);
        if (resultados.length > 0) {
            seleccionarSugerencia(resultados[0].edificioId, resultados[0].aula);
        }
    }
});

searchInput.addEventListener('keypress', function (e) {
    if (e.key === 'Enter') {
        e.preventDefault();
        searchBtn.click();
    }
});

var centerBtn = document.getElementById('center-location-btn');
centerBtn.addEventListener('click', function () {
    centerOnLocation();
});

document.getElementById('public-routes-toggle').addEventListener('click', function () {
    abrirSelectorRutas(this.getAttribute('aria-expanded') !== 'true');
});
document.getElementById('public-routes-close').addEventListener('click', function () {
    abrirSelectorRutas(false);
});
document.getElementById('public-routes-clear').addEventListener('click', function () {
    window.limpiarRuta();
});

// buscar coincidencias en los nombres de edificios y aulas, ignorando mayúsculas/minúsculas
function buscarEdificiosYAulas(query) {
    var resultados = [];
    var texto = String(query === null || query === undefined ? '' : query).toLowerCase();

    if (texto === '' || !infoEdificios || typeof infoEdificios !== 'object') {
        return resultados;
    }

    Object.keys(infoEdificios).forEach(function (edificioId) {
        var edificio = infoEdificios[edificioId];
        if (!edificio || typeof edificio !== 'object') return;

        var nombre = typeof edificio.nombre === 'string' ? edificio.nombre : '';
        var nombreVisible = nombre !== '' ? nombre : ('Edificio ' + edificioId);
        var aulas = Array.isArray(edificio.aulas) ? edificio.aulas : [];

        if (nombre.toLowerCase().includes(texto) || edificioId.toLowerCase().includes(texto)) {
            resultados.push({
                tipo: 'edificio',
                edificioId: edificioId,
                nombre: nombreVisible,
                aula: null
            });
        }

        aulas.forEach(function (aula) {
            if (typeof aula === 'string' && aula.toLowerCase().includes(texto)) {
                resultados.push({
                    tipo: 'aula',
                    edificioId: edificioId,
                    nombre: nombreVisible,
                    aula: aula
                });
            }
        });
    });

    return resultados;
}

function mostrarSugerencias(resultados, query) {
    suggestionsContainer.innerHTML = '';

    if (resultados.length === 0) {
        suggestionsContainer.classList.remove('active');
        return;
    }

    // Limitar las sugerencias para no sobrecargar
    var resultadosLimitados = resultados.slice(0, 10);

    resultadosLimitados.forEach(function (resultado) {
        var item = document.createElement('div');
        item.className = 'suggestion-item';

        var nombre = document.createElement('div');
        nombre.className = 'suggestion-edificio';
        nombre.appendChild(resaltarTexto(resultado.nombre, query));
        item.appendChild(nombre);

        if (resultado.tipo === 'aula') {
            var aula = document.createElement('div');
            aula.className = 'suggestion-aula';
            aula.appendChild(document.createTextNode('✒️ '));
            aula.appendChild(resaltarTexto(resultado.aula, query));
            item.appendChild(aula);
        }

        item.addEventListener('click', function () {
            seleccionarSugerencia(resultado.edificioId, resultado.aula);
        });

        suggestionsContainer.appendChild(item);
    });

    suggestionsContainer.classList.add('active');
}

// Devuelve un fragmento con el texto resaltado. No construye HTML a partir de
// los datos: los tramos son nodos de texto y el resaltado es un <span> creado
// con `textContent`.
function resaltarTexto(texto, query) {
    var fragmento = document.createDocumentFragment();
    var textoPlano = String(texto === null || texto === undefined ? '' : texto);
    var patron = String(query === null || query === undefined ? '' : query);

    if (patron === '') {
        fragmento.appendChild(document.createTextNode(textoPlano));
        return fragmento;
    }

    var regex;
    try {
        regex = new RegExp(escaparRegex(patron), 'gi');
    } catch (error) {
        fragmento.appendChild(document.createTextNode(textoPlano));
        return fragmento;
    }

    var ultimo = 0;
    var coincidencia;
    while ((coincidencia = regex.exec(textoPlano)) !== null) {
        if (coincidencia[0] === '') {
            regex.lastIndex += 1;
            continue;
        }

        if (coincidencia.index > ultimo) {
            fragmento.appendChild(document.createTextNode(textoPlano.slice(ultimo, coincidencia.index)));
        }

        var resaltado = document.createElement('span');
        resaltado.className = 'highlight';
        resaltado.textContent = coincidencia[0];
        fragmento.appendChild(resaltado);

        ultimo = coincidencia.index + coincidencia[0].length;
    }

    if (ultimo < textoPlano.length) {
        fragmento.appendChild(document.createTextNode(textoPlano.slice(ultimo)));
    }

    return fragmento;
}

//identificar el edificio seleccionado por el usuario
function seleccionarSugerencia(edificioId, aula) {
    suggestionsContainer.classList.remove('active');

    var metadatos = infoEdificios ? infoEdificios[edificioId] : null;
    var nombre = (metadatos && metadatos.nombre) ? metadatos.nombre : ('Edificio ' + edificioId);
    searchInput.value = aula || nombre;

    destacarEdificio(edificioId);

    if (capasEdificios[edificioId]) {
        var bounds = capasEdificios[edificioId].getBounds();
        map.fitBounds(bounds, {
            padding: [50, 50],
            maxZoom: 18
        });

        setTimeout(function () {
            mostrarPopup(edificioId, capasEdificios[edificioId]);
        }, 500);
    }
}

function destacarEdificio(edificioId) {
    if (edificioDestacado) {
        edificioDestacado.setStyle({
            color: '#2F29D1',
            weight: 0,
            fillColor: '#3388FF',
            fillOpacity: 0
        });
    }

    if (capasEdificios[edificioId]) {
        var layer = capasEdificios[edificioId];
        layer.setStyle({
            fillColor: '#2196F3',
            fillOpacity: 0.6,
            color: '#1976D2',
            weight: 3
        });
        edificioDestacado = layer;

        setTimeout(function () {
            if (edificioDestacado === layer) {
                layer.setStyle({
                    color: '#2F29D1',
                    weight: 0,
                    fillColor: '#3388FF',
                    fillOpacity: 0
                });
                edificioDestacado = null;
            }
        }, 3000);
    }
}

// ==========================================
// SUGERENCIAS (FORMULARIO EXTERNO)
// ==========================================

// El enlace se define en `config.js`. Si la URL está vacía (o no es https),
// el botón se oculta: el sitio público no tiene formulario propio ni llama a
// PHP. La URL de Google Forms se abre como navegación normal en otra pestaña.
function initSuggestionLink() {
    const enlace = document.getElementById('admin-access-btn');
    if (!enlace) return;

    const url = typeof SUGGESTION_FORM_URL === 'string' ? SUGGESTION_FORM_URL.trim() : '';

    if (url === '' || !/^https:\/\//i.test(url)) {
        enlace.hidden = true;
        return;
    }

    enlace.href = url;
    enlace.hidden = false;
}

initSuggestionLink();
cargarCatalogoRutasPublicas();
