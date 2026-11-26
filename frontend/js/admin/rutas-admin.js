/* ==========================================
   GESTIÓN DE RUTAS — LISTADO Y DETALLE
   ==========================================
   Vista de solo lectura: consulta `getRutas` y `getRedCaminos`, dibuja el
   mapa y permite eliminar una ruta con confirmación (POST + CSRF).

   Reglas de seguridad del módulo:
    - Todo texto administrado se inserta con `textContent`, nunca con
      `innerHTML`. Los datos vienen de `rutas.json` y de `datos.json`.
    - La geometría se toma del servidor; el cliente no la recalcula. */

(function () {
    'use strict';

    var cfg = window.RUTAS_ADMIN || {};
    var urls = cfg.urls || {};
    var datos = cfg.datos || {};
    var config = cfg.config || {};
    var estilosMapa = cfg.settings && cfg.settings.estilos ? cfg.settings.estilos : {};

    var estado = {
        rutas: [],
        red: null,
        seleccionada: null,
        detalle: null,
        cargando: true,
        error: null,
        filtro: { texto: '', tipo: '', estado: '', accesible: false },
        capasRuta: new Map(),
        capasRed: new Map(),
        detalleCache: new Map()
    };

    // ---------- Utilidades DOM ----------

    function el(tag, clase, texto) {
        var nodo = document.createElement(tag);
        if (clase) { nodo.className = clase; }
        if (texto !== undefined && texto !== null) { nodo.textContent = String(texto); }
        return nodo;
    }

    function limpiar(nodo) {
        while (nodo && nodo.firstChild) { nodo.removeChild(nodo.firstChild); }
    }

    function normalizar(texto) {
        var cadena = String(texto || '').toLowerCase();
        if (typeof cadena.normalize === 'function') {
            cadena = cadena.normalize('NFD').replace(/[\u0300-\u036f]/g, '');
        }
        return cadena;
    }

    function metros(valor) {
        var numero = Number(valor) || 0;
        if (numero >= 1000) { return (numero / 1000).toFixed(2) + ' km'; }
        return Math.round(numero) + ' m';
    }

    function mayusculaInicial(texto) {
        var cadena = String(texto || '');
        return cadena.charAt(0).toUpperCase() + cadena.slice(1);
    }

    function etiquetaTipo(tipo) {
        var mapa = {
            principal: 'Principal',
            alterna: 'Alterna',
            acceso: 'Acceso',
            emergencia: 'Emergencia',
            institucional: 'Institucional'
        };
        return mapa[tipo] || mayusculaInicial(tipo);
    }

    function etiquetaEstado(estadoRuta) {
        var mapa = { activa: 'Activa', inactiva: 'Inactiva', borrador: 'Borrador' };
        return mapa[estadoRuta] || mayusculaInicial(estadoRuta);
    }

    function nombreVinculo(referencia) {
        if (!referencia || !referencia.ref) { return '—'; }
        if (referencia.tipo === 'nodo') { return 'Nodo de la red'; }
        var registro = datos[referencia.ref];
        return registro && registro.nombre ? registro.nombre : referencia.ref;
    }

    // ---------- Referencias al DOM ----------

    var dom = {
        contador: document.getElementById('rutas-contador'),
        cargando: document.getElementById('rutas-cargando'),
        vacio: document.getElementById('rutas-vacio'),
        error: document.getElementById('rutas-error'),
        sinResultados: document.getElementById('rutas-sin-resultados'),
        lista: document.getElementById('rutas-lista'),
        buscar: document.getElementById('buscar-rutas'),
        filtroTipo: document.getElementById('filtro-tipo'),
        filtroEstado: document.getElementById('filtro-estado'),
        filtroAccesible: document.getElementById('filtro-accesible'),
        panel: document.getElementById('detalle-panel'),
        panelCerrar: document.getElementById('detalle-cerrar'),
        detalleNombre: document.getElementById('detalle-nombre'),
        detalleTrayecto: document.getElementById('detalle-trayecto'),
        detalleDatos: document.getElementById('detalle-datos'),
        detalleDescripcion: document.getElementById('detalle-descripcion'),
        detalleAvisos: document.getElementById('detalle-avisos'),
        detalleSegmentos: document.getElementById('detalle-segmentos'),
        detalleEditar: document.getElementById('detalle-editar'),
        detalleEliminar: document.getElementById('detalle-eliminar'),
        modal: document.getElementById('modal-eliminar'),
        modalPregunta: document.getElementById('modal-eliminar-pregunta'),
        modalError: document.getElementById('modal-eliminar-error'),
        modalCancelar: document.getElementById('modal-cancelar'),
        modalConfirmar: document.getElementById('modal-confirmar'),
        abrirAjustes: document.getElementById('abrir-ajustes'),
        modalAjustes: document.getElementById('modal-ajustes'),
        camposAjustes: document.getElementById('ajustes-campos'),
        estadoAjustes: document.getElementById('ajustes-estado'),
        guardarAjustes: document.getElementById('ajustes-guardar'),
        cancelarAjustes: document.getElementById('ajustes-cancelar'),
        restablecerAjustes: document.getElementById('ajustes-restablecer')
    };

    // ---------- Mapa ----------

    var mapa = L.map('map-rutas', {
        attributionControl: false,
        zoomControl: true
    }).setView([25.5324, -103.436], 17);

    L.tileLayer('tiles/satelital/{z}/{x}/{y}.jpg', {
        minZoom: 15,
        maxZoom: 19
    }).addTo(mapa);

    // Edificios: referencia visual, nunca clicables.
    var capaEdificios = L.geoJSON(
        window.datosTec && window.datosTec.features ? window.datosTec : { type: 'FeatureCollection', features: [] },
        {
            interactive: false,
            smoothFactor: 0,
            style: function () {
                return {
                    color: estilosMapa.edificios_admin ? estilosMapa.edificios_admin.contorno.color : '#000000',
                    weight: estilosMapa.edificios_admin ? estilosMapa.edificios_admin.contorno.grosor : 1,
                    opacity: estilosMapa.edificios_admin ? estilosMapa.edificios_admin.contorno.opacidad : 1,
                    fillColor: estilosMapa.edificios_admin ? estilosMapa.edificios_admin.relleno.color : '#9aa5b1',
                    fillOpacity: estilosMapa.edificios_admin ? estilosMapa.edificios_admin.relleno.opacidad : 0.9
                };
            }
        }
    ).addTo(mapa);

    var capaRed = L.layerGroup().addTo(mapa);
    var capaRutas = L.layerGroup().addTo(mapa);
    var capaMarcadores = L.layerGroup().addTo(mapa);

    function ajustarVistaInicial() {
        var capas = [];
        capaEdificios.eachLayer(function (capa) { capas.push(capa); });
        var grupo = L.featureGroup(capas);
        if (grupo.getLayers().length > 0) {
            mapa.fitBounds(grupo.getBounds(), { padding: [20, 20], maxZoom: 18 });
        }
    }

    // ---------- Peticiones ----------

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
                var datosRespuesta = null;
                try {
                    datosRespuesta = JSON.parse(texto);
                } catch (error) {
                    throw new Error('Respuesta inesperada del servidor (HTTP ' + respuesta.status + ').');
                }
                if (!respuesta.ok || datosRespuesta.success === false) {
                    throw new Error(datosRespuesta.message || ('Error HTTP ' + respuesta.status));
                }
                return datosRespuesta;
            });
        });
    }

    var etiquetasAjustes = {
        modo_ubicacion: 'Modo de ubicación',
        tolerancia_continuidad_m: 'Tolerancia de continuidad (m)',
        distancia_inicio_ruta_m: 'Distancia para iniciar sobre ruta (m)',
        precision_gps_maxima_m: 'Precisión GPS máxima aceptada (m)',
        max_segmentos_por_ruta: 'Máximo de segmentos por ruta',
        max_salto_enlace_m: 'Salto máximo para enlazar (m)',
        ruta_publica: 'Ruta pública',
        ruta_admin: 'Ruta administrativa',
        caminos: 'Red de caminos',
        caminos_publicos: 'Red de caminos públicos',
        segmentos: 'Segmentos resaltados',
        edificios_publicos: 'Edificios públicos',
        edificios_admin: 'Edificios administrativos',
        color: 'Color',
        grosor: 'Grosor',
        opacidad: 'Opacidad',
        contorno: 'Contorno',
        relleno: 'Relleno'
    };

    function copiar(objeto) {
        return JSON.parse(JSON.stringify(objeto));
    }

    function anadirCampoAjuste(contenedor, ruta, valor) {
        var fila = el('label', 'ajustes-fila');
        var nombre = ruta.split('.').pop();
        fila.appendChild(el('span', null, etiquetasAjustes[nombre] || nombre));
        var entrada;
        if (ruta === 'modo_ubicacion') {
            entrada = document.createElement('select');
            [['simulada', 'Simulada (beacons)'], ['real', 'Real (GPS)']].forEach(function (opcion) {
                var opt = el('option', null, opcion[1]);
                opt.value = opcion[0];
                entrada.appendChild(opt);
            });
        } else {
            entrada = document.createElement('input');
            entrada.type = nombre === 'color' ? 'color' : 'number';
            if (nombre === 'color') {
                entrada.value = /^#[0-9a-f]{6}$/i.test(valor) ? valor : '#000000';
            } else {
                if (nombre === 'max_segmentos_por_ruta') {
                    entrada.step = '1';
                    entrada.min = '1';
                    entrada.max = '5000';
                } else if (nombre === 'precision_gps_maxima_m') {
                    entrada.step = '0.1';
                    entrada.min = '0.1';
                    entrada.max = '500';
                } else {
                    entrada.step = nombre === 'opacidad' ? '0.05' : '0.1';
                    entrada.min = nombre === 'max_salto_enlace_m' || nombre === 'grosor' || nombre === 'opacidad' ? '0' : '0.1';
                    entrada.max = nombre === 'grosor' ? '20' : (nombre === 'opacidad' ? '1'
                        : (nombre === 'max_salto_enlace_m' ? '1000'
                            : (nombre === 'distancia_inicio_ruta_m' ? '100' : '25')));
                }
                entrada.value = String(valor);
            }
        }
        entrada.dataset.settingPath = ruta;
        fila.appendChild(entrada);
        contenedor.appendChild(fila);
        if (ruta === 'modo_ubicacion') { entrada.value = valor; }
    }

    function anadirGrupoAjuste(contenedor, clave, valor, ruta) {
        var grupo = el('section', 'ajustes-grupo');
        grupo.appendChild(el('h3', null, etiquetasAjustes[clave] || clave));
        Object.keys(valor).forEach(function (propiedad) {
            var actual = valor[propiedad];
            var camino = ruta + '.' + propiedad;
            if (actual && typeof actual === 'object' && !Array.isArray(actual)) {
                anadirGrupoAjuste(grupo, propiedad, actual, camino);
            } else {
                anadirCampoAjuste(grupo, camino, actual);
            }
        });
        contenedor.appendChild(grupo);
    }

    function mostrarCamposAjustes(settings) {
        limpiar(dom.camposAjustes);
        anadirCampoAjuste(dom.camposAjustes, 'modo_ubicacion', settings.modo_ubicacion);
        anadirGrupoAjuste(dom.camposAjustes, 'Rutas', settings.rutas, 'rutas');
        anadirGrupoAjuste(dom.camposAjustes, 'Ubicación GPS', settings.ubicacion, 'ubicacion');
        anadirGrupoAjuste(dom.camposAjustes, 'Estilos', settings.estilos, 'estilos');
    }

    function leerCamposAjustes() {
        var resultado = { modo_ubicacion: '', rutas: {}, ubicacion: {}, estilos: {} };
        var entradas = dom.camposAjustes.querySelectorAll('[data-setting-path]');
        for (var i = 0; i < entradas.length; i++) {
            var entrada = entradas[i];
            if (!entrada.checkValidity()) {
                entrada.reportValidity();
                return null;
            }
            var valor = entrada.type === 'number' ? Number(entrada.value) : entrada.value;
            var partes = entrada.dataset.settingPath.split('.');
            var destino = resultado;
            for (var parte = 0; parte < partes.length - 1; parte++) {
                if (!destino[partes[parte]]) { destino[partes[parte]] = {}; }
                destino = destino[partes[parte]];
            }
            destino[partes[partes.length - 1]] = valor;
        }
        return resultado;
    }

    function cerrarAjustes() {
        dom.modalAjustes.classList.remove('visible');
        dom.modalAjustes.setAttribute('hidden', '');
    }

    function abrirAjustes() {
        mostrarCamposAjustes(cfg.settings);
        dom.estadoAjustes.textContent = '';
        dom.estadoAjustes.className = 'ajustes-estado';
        dom.modalAjustes.removeAttribute('hidden');
        dom.modalAjustes.classList.add('visible');
        dom.guardarAjustes.focus();
    }

    function guardarAjustes() {
        var configuracion = leerCamposAjustes();
        if (!configuracion) { return; }
        dom.guardarAjustes.disabled = true;
        dom.estadoAjustes.className = 'ajustes-estado';
        dom.estadoAjustes.textContent = 'Guardando…';
        var cuerpo = new FormData();
        cuerpo.append('csrf_token', cfg.csrfToken || '');
        cuerpo.append('settings_json', JSON.stringify(configuracion));

        fetch(urls.settings, {
            method: 'POST',
            body: cuerpo,
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
        }).then(function (respuesta) {
            if (respuesta.status === 403) {
                window.location.href = 'index.php?p=login';
                throw new Error('Sesión expirada o token CSRF inválido.');
            }
            return respuesta.text().then(function (texto) {
                var resultado;
                try { resultado = JSON.parse(texto); }
                catch (error) { throw new Error('Respuesta inesperada del servidor (HTTP ' + respuesta.status + ').'); }
                if (!respuesta.ok || resultado.success !== true) {
                    throw new Error(resultado.message || ('Error HTTP ' + respuesta.status));
                }
                return resultado;
            });
        }).then(function (resultado) {
            cfg.settings = resultado.settings;
            cfg.config = resultado.settings.rutas;
            actualizarEstilosMapa();
            cerrarAjustes();
        }).catch(function (error) {
            dom.estadoAjustes.className = 'ajustes-estado error';
            dom.estadoAjustes.textContent = 'No se pudo guardar: ' + error.message;
        }).then(function () {
            dom.guardarAjustes.disabled = false;
        });
    }

    function actualizarEstilosMapa() {
        if (!cfg.settings || !cfg.settings.estilos) { return; }
        var estilos = cfg.settings.estilos;
        estilosMapa = estilos;
        var edificios = estilos.edificios_admin;
        capaEdificios.setStyle({
            color: edificios.contorno.color,
            weight: edificios.contorno.grosor,
            opacity: edificios.contorno.opacidad,
            fillColor: edificios.relleno.color,
            fillOpacity: edificios.relleno.opacidad
        });
        dibujarRed();
        dibujarRutas();
    }

    function guardarAjustesPredeterminados() {
        mostrarCamposAjustes(cfg.settingsDefaults);
        dom.estadoAjustes.textContent = 'Se guardarán los valores predeterminados al pulsar Guardar.';
        dom.estadoAjustes.className = 'ajustes-estado';
    }

    function cargarTodo() {
        dom.cargando.textContent = 'Cargando rutas…';
        dom.cargando.classList.remove('oculto');
        dom.error.classList.add('oculto');

        Promise.all([
            pedirJson(urls.getRutas),
            pedirJson(urls.getRedCaminos).catch(function () { return null; })
        ]).then(function (resultados) {
            estado.rutas = Array.isArray(resultados[0].rutas) ? resultados[0].rutas : [];
            estado.red = resultados[1];
            estado.cargando = false;
            dibujarRed();
            renderizarLista();
            dibujarRutas();
            ajustarVistaInicial();

            if (cfg.idInicial) {
                seleccionar(cfg.idInicial, true);
            }
        }).catch(function (error) {
            estado.cargando = false;
            estado.error = error.message;
            dom.cargando.classList.add('oculto');
            dom.error.textContent = '✗ ' + error.message;
            dom.error.classList.remove('oculto');
            dom.contador.textContent = 'Error de carga';
        });
    }

    // ---------- Red de caminos ----------

    function dibujarRed() {
        capaRed.clearLayers();
        estado.capasRed.clear();
        if (!estado.red || !Array.isArray(estado.red.segmentos)) { return; }

        estado.red.segmentos.forEach(function (segmento) {
            if (!Array.isArray(segmento.coordenadas) || segmento.coordenadas.length < 2) { return; }
            var puntos = segmento.coordenadas.map(function (punto) {
                return [Number(punto[1]), Number(punto[0])];
            });
            var estiloRed = estilosMapa.caminos || { color: '#12306b', grosor: 4, opacidad: 0.85 };
            var capa = L.polyline(puntos, {
                color: estiloRed.color,
                weight: estiloRed.grosor,
                opacity: estiloRed.opacidad,
                interactive: false,
                smoothFactor: 0
            });
            capa.addTo(capaRed);
            estado.capasRed.set(segmento.id, capa);
        });
    }

    // ---------- Rutas ----------

    function coordenadasRuta(ruta) {
        var geometria = ruta && ruta.geometria;
        if (!geometria || !Array.isArray(geometria.coordinates)) { return []; }
        return geometria.coordinates.map(function (punto) {
            return [Number(punto[1]), Number(punto[0])];
        });
    }

    function dibujarRutas() {
        capaRutas.clearLayers();
        estado.capasRuta.clear();

        estado.rutas.forEach(function (ruta) {
            var puntos = coordenadasRuta(ruta);
            if (puntos.length < 2) { return; }
            var estiloRuta = estilosMapa.ruta_admin || { color: '#1161ee', grosor: 6, opacidad: 1 };
            var capa = L.polyline(puntos, {
                color: estiloRuta.color,
                weight: estiloRuta.grosor,
                opacity: estiloRuta.opacidad * 0.25,
                smoothFactor: 0
            });
            capa.on('click', function () { seleccionar(ruta.id); });
            capa.addTo(capaRutas);
            estado.capasRuta.set(ruta.id, capa);
        });

        if (estado.seleccionada && estado.capasRuta.has(estado.seleccionada)) {
            aplicarSeleccion();
        }
    }

    function aplicarSeleccion() {
        var estiloRuta = estilosMapa.ruta_admin || { color: '#1161ee', grosor: 6, opacidad: 1 };
        estado.capasRuta.forEach(function (capa, id) {
            var elemento = capa.getElement();
            if (id === estado.seleccionada) {
                capa.setStyle({
                    color: estiloRuta.color,
                    weight: estiloRuta.grosor,
                    opacity: estiloRuta.opacidad,
                    dashArray: '12 8'
                });
                capa.bringToFront();
                if (elemento) { elemento.classList.add('ruta-animada'); }
            } else {
                capa.setStyle({
                    color: estiloRuta.color,
                    weight: estiloRuta.grosor,
                    opacity: estiloRuta.opacidad * 0.25,
                    dashArray: null
                });
                if (elemento) { elemento.classList.remove('ruta-animada'); }
            }
        });
    }

    function dibujarMarcadores(ruta) {
        capaMarcadores.clearLayers();
        var puntos = coordenadasRuta(ruta);
        if (puntos.length < 2) { return; }

        var inicio = L.circleMarker(puntos[0], {
            radius: 8, color: '#ffffff', weight: 2, fillColor: '#1b7f3b', fillOpacity: 1, interactive: false
        }).addTo(capaMarcadores);
        inicio.bindTooltip('Inicio', { direction: 'top', offset: [0, -6] });

        var fin = L.circleMarker(puntos[puntos.length - 1], {
            radius: 8, color: '#ffffff', weight: 2, fillColor: '#b3261e', fillOpacity: 1, interactive: false
        }).addTo(capaMarcadores);
        fin.bindTooltip('Fin', { direction: 'top', offset: [0, -6] });
    }

    function seleccionar(id, ajustar) {
        if (!id) { return; }
        estado.seleccionada = id;
        aplicarSeleccion();
        renderizarLista();

        var capa = estado.capasRuta.get(id);
        if (capa) {
            dibujarMarcadores({ geometria: { coordinates: capa.getLatLngs().map(function (p) { return [p.lng, p.lat]; }) } });
            if (ajustar) {
                mapa.fitBounds(capa.getBounds(), { padding: [40, 40], maxZoom: 19 });
            } else {
                capa.bringToFront();
            }
        }

        abrirDetalle(id);
    }

    function cerrarDetalle() {
        dom.panel.classList.remove('visible');
        estado.seleccionada = null;
        estado.detalle = null;
        capaMarcadores.clearLayers();
        aplicarSeleccion();
        renderizarLista();
    }

    // ---------- Detalle ----------

    function abrirDetalle(id) {
        var resumen = estado.rutas.filter(function (r) { return r.id === id; })[0];
        if (!resumen) { return; }

        dom.panel.classList.add('visible');
        dom.detalleEditar.setAttribute('href', urls.editor + encodeURIComponent(id));

        if (estado.detalleCache.has(id)) {
            pintarDetalle(estado.detalleCache.get(id));
            return;
        }

        pintarDetalle(resumen);

        pedirJson(urls.getRutas + '&id=' + encodeURIComponent(id)).then(function (respuesta) {
            var detalle = respuesta.ruta;
            if (!detalle) { return; }
            estado.detalleCache.set(id, detalle);
            if (estado.seleccionada === id) { pintarDetalle(detalle); }
        }).catch(function (error) {
            dom.detalleAvisos.hidden = false;
            limpiar(dom.detalleAvisos);
            dom.detalleAvisos.appendChild(el('li', null, 'No se pudo cargar el detalle: ' + error.message));
        });
    }

    function pintarDetalle(ruta) {
        estado.detalle = ruta;

        dom.detalleNombre.textContent = ruta.nombre || '(sin nombre)';
        dom.detalleTrayecto.textContent = nombreVinculo(ruta.origen) + ' → ' + nombreVinculo(ruta.destino);

        limpiar(dom.detalleDatos);
        var filas = [
            ['Tipo', etiquetaTipo(ruta.tipo)],
            ['Estado', etiquetaEstado(ruta.estado)],
            ['Accesible', ruta.accesible ? 'Sí' : 'No'],
            ['Distancia', metros(ruta.distancia_m)],
            ['Segmentos', String(ruta.n_segmentos || 0)],
            ['Actualizada', ruta.actualizado_en || '—']
        ];
        filas.forEach(function (fila) {
            dom.detalleDatos.appendChild(el('dt', null, fila[0]));
            dom.detalleDatos.appendChild(el('dd', null, fila[1]));
        });

        if (ruta.descripcion) {
            dom.detalleDescripcion.hidden = false;
            dom.detalleDescripcion.textContent = ruta.descripcion;
        } else {
            dom.detalleDescripcion.hidden = true;
            dom.detalleDescripcion.textContent = '';
        }

        limpiar(dom.detalleAvisos);
        var avisos = Array.isArray(ruta.advertencias) ? ruta.advertencias.slice() : [];
        if (ruta.inconsistente) {
            avisos.unshift('La ruta referencia segmentos que ya no existen en la red.');
        }
        if (avisos.length > 0) {
            dom.detalleAvisos.hidden = false;
            avisos.forEach(function (aviso) { dom.detalleAvisos.appendChild(el('li', null, aviso)); });
        } else {
            dom.detalleAvisos.hidden = true;
        }

        limpiar(dom.detalleSegmentos);
        var segmentos = Array.isArray(ruta.segmentos_detalle) ? ruta.segmentos_detalle : [];
        if (segmentos.length === 0) {
            dom.detalleSegmentos.appendChild(el('li', null, 'Sin detalle de segmentos.'));
        } else {
            segmentos.forEach(function (segmento, indice) {
                var item = el('li');
                item.appendChild(el('span', 'detalle-seg-orden', String(indice + 1)));
                item.appendChild(el('span', 'detalle-seg-nombre', segmento.nombre || segmento.id));
                item.appendChild(el('span', 'detalle-seg-sentido', segmento.sentido === 'inverso' ? 'inv.' : 'norm.'));
                item.appendChild(el('span', 'detalle-seg-sentido', metros(segmento.longitud_m)));

                item.addEventListener('mouseenter', function () { resaltarSegmento(segmento.id, true); });
                item.addEventListener('mouseleave', function () { resaltarSegmento(segmento.id, false); });

                dom.detalleSegmentos.appendChild(item);
            });
        }
    }

    function resaltarSegmento(id, activo) {
        var capa = estado.capasRed.get(id);
        if (!capa) { return; }
        if (activo) {
            capa.setStyle({ color: '#f59e0b', weight: 6, opacity: 1 });
            capa.bringToFront();
        } else {
            capa.setStyle({ color: '#9aa5b1', weight: 2, opacity: 0.55 });
            // La ruta seleccionada debe quedar por encima después de restaurar.
            var seleccionada = estado.capasRuta.get(estado.seleccionada);
            if (seleccionada) { seleccionada.bringToFront(); }
        }
    }

    // ---------- Listado ----------

    function rutasFiltradas() {
        var filtro = estado.filtro;
        var texto = normalizar(filtro.texto);

        return estado.rutas.filter(function (ruta) {
            if (filtro.tipo && ruta.tipo !== filtro.tipo) { return false; }
            if (filtro.estado && ruta.estado !== filtro.estado) { return false; }
            if (filtro.accesible && ruta.accesible !== true) { return false; }
            if (!texto) { return true; }

            var campos = [
                ruta.nombre,
                ruta.descripcion,
                ruta.tipo,
                ruta.estado,
                nombreVinculo(ruta.origen),
                nombreVinculo(ruta.destino)
            ].join(' ');

            return normalizar(campos).indexOf(texto) !== -1;
        });
    }

    function renderizarLista() {
        var filtradas = rutasFiltradas();

        dom.cargando.classList.add('oculto');
        dom.error.classList.add('oculto');
        dom.vacio.classList.toggle('oculto', estado.rutas.length !== 0);
        dom.sinResultados.classList.toggle('oculto', !(estado.rutas.length > 0 && filtradas.length === 0));

        limpiar(dom.lista);

        dom.contador.textContent = estado.rutas.length === 0
            ? 'Sin rutas registradas'
            : (filtradas.length === estado.rutas.length
                ? estado.rutas.length + (estado.rutas.length === 1 ? ' ruta' : ' rutas')
                : filtradas.length + ' de ' + estado.rutas.length + ' rutas');

        filtradas.forEach(function (ruta) {
            dom.lista.appendChild(crearTarjeta(ruta));
        });
    }

    function crearTarjeta(ruta) {
        var item = el('li', 'ruta-card' + (estado.seleccionada === ruta.id ? ' active' : ''));
        item.setAttribute('data-id', ruta.id);
        item.tabIndex = 0;
        item.setAttribute('role', 'button');

        var titulo = el('div', 'ruta-card-titulo');
        titulo.appendChild(el('span', 'ruta-card-nombre', ruta.nombre || '(sin nombre)'));
        titulo.appendChild(el('span', 'badge badge-tipo-' + ruta.tipo, etiquetaTipo(ruta.tipo)));
        item.appendChild(titulo);

        item.appendChild(el('div', 'ruta-card-trayecto',
            nombreVinculo(ruta.origen) + ' → ' + nombreVinculo(ruta.destino)));

        var meta = el('div', 'ruta-card-meta');
        meta.appendChild(el('span', 'badge badge-estado-' + ruta.estado, etiquetaEstado(ruta.estado)));
        meta.appendChild(el('span', null, metros(ruta.distancia_m)));
        meta.appendChild(el('span', null, (ruta.n_segmentos || 0) + ' seg.'));
        meta.appendChild(el('span', 'badge ' + (ruta.accesible ? 'badge-accesible' : 'badge-no-accesible'),
            ruta.accesible ? 'Accesible' : 'No accesible'));
        if (ruta.inconsistente) {
            meta.appendChild(el('span', 'badge badge-alerta', 'Revisar'));
        }
        item.appendChild(meta);

        var acciones = el('div', 'ruta-card-acciones');

        var editar = el('a', 'btn btn-primary', 'Editar');
        editar.setAttribute('href', urls.editor + encodeURIComponent(ruta.id));
        editar.addEventListener('click', function (evento) { evento.stopPropagation(); });
        acciones.appendChild(editar);

        var eliminar = el('button', 'btn btn-danger', 'Eliminar');
        eliminar.type = 'button';
        eliminar.addEventListener('click', function (evento) {
            evento.stopPropagation();
            abrirModalEliminar(ruta);
        });
        acciones.appendChild(eliminar);

        item.appendChild(acciones);

        item.addEventListener('click', function () { seleccionar(ruta.id, true); });
        item.addEventListener('keydown', function (evento) {
            if (evento.key === 'Enter' || evento.key === ' ') {
                evento.preventDefault();
                seleccionar(ruta.id, true);
            }
        });

        return item;
    }

    // ---------- Eliminación ----------

    var rutaPendiente = null;

    function abrirModalEliminar(ruta) {
        rutaPendiente = ruta;
        dom.modalPregunta.textContent = '¿Eliminar «' + (ruta.nombre || '(sin nombre)') + '»?';
        dom.modalError.classList.remove('visible');
        dom.modalError.textContent = '';
        dom.modalConfirmar.disabled = false;
        dom.modalConfirmar.textContent = 'Eliminar ruta';
        dom.modal.classList.add('visible');
        dom.modalConfirmar.focus();
    }

    function cerrarModalEliminar() {
        rutaPendiente = null;
        dom.modal.classList.remove('visible');
    }

    function confirmarEliminar() {
        if (!rutaPendiente) { return; }

        var ruta = rutaPendiente;
        dom.modalConfirmar.disabled = true;
        dom.modalConfirmar.textContent = 'Eliminando…';
        dom.modalError.classList.remove('visible');

        var cuerpo = new FormData();
        cuerpo.append('csrf_token', cfg.csrfToken || '');
        cuerpo.append('id', ruta.id);

        fetch(urls.deleteRuta, {
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
                var datosRespuesta = null;
                try {
                    datosRespuesta = JSON.parse(texto);
                } catch (error) {
                    throw new Error('Respuesta inesperada del servidor (HTTP ' + respuesta.status + ').');
                }
                if (!respuesta.ok || datosRespuesta.success === false) {
                    throw new Error(datosRespuesta.message || ('Error HTTP ' + respuesta.status));
                }
                return datosRespuesta;
            });
        }).then(function () {
            estado.rutas = estado.rutas.filter(function (r) { return r.id !== ruta.id; });
            estado.detalleCache.delete(ruta.id);

            var capa = estado.capasRuta.get(ruta.id);
            if (capa) { capaRutas.removeLayer(capa); }
            estado.capasRuta.delete(ruta.id);

            if (estado.seleccionada === ruta.id) { cerrarDetalle(); }
            cerrarModalEliminar();
            renderizarLista();
        }).catch(function (error) {
            dom.modalError.textContent = '✗ ' + error.message;
            dom.modalError.classList.add('visible');
            dom.modalConfirmar.disabled = false;
            dom.modalConfirmar.textContent = 'Eliminar ruta';
        });
    }

    // ---------- Eventos ----------

    dom.buscar.addEventListener('input', function () {
        estado.filtro.texto = dom.buscar.value;
        renderizarLista();
    });

    dom.filtroTipo.addEventListener('change', function () {
        estado.filtro.tipo = dom.filtroTipo.value;
        renderizarLista();
    });

    dom.filtroEstado.addEventListener('change', function () {
        estado.filtro.estado = dom.filtroEstado.value;
        renderizarLista();
    });

    dom.filtroAccesible.addEventListener('change', function () {
        estado.filtro.accesible = dom.filtroAccesible.checked;
        renderizarLista();
    });

    dom.panelCerrar.addEventListener('click', cerrarDetalle);

    dom.detalleEliminar.addEventListener('click', function () {
        if (!estado.detalle) { return; }
        abrirModalEliminar(estado.detalle);
    });

    dom.modalCancelar.addEventListener('click', cerrarModalEliminar);
    dom.modalConfirmar.addEventListener('click', confirmarEliminar);
    dom.abrirAjustes.addEventListener('click', abrirAjustes);
    dom.cancelarAjustes.addEventListener('click', cerrarAjustes);
    dom.guardarAjustes.addEventListener('click', guardarAjustes);
    dom.restablecerAjustes.addEventListener('click', guardarAjustesPredeterminados);

    dom.modal.addEventListener('click', function (evento) {
        if (evento.target === dom.modal) { cerrarModalEliminar(); }
    });
    dom.modalAjustes.addEventListener('click', function (evento) {
        if (evento.target === dom.modalAjustes) { cerrarAjustes(); }
    });

    document.addEventListener('keydown', function (evento) {
        if (evento.key === 'Escape') {
            if (dom.modalAjustes.classList.contains('visible')) {
                cerrarAjustes();
            } else if (dom.modal.classList.contains('visible')) {
                cerrarModalEliminar();
            } else if (dom.panel.classList.contains('visible')) {
                cerrarDetalle();
            }
        }
    });

    // ---------- Inicio ----------

    cargarTodo();
})();
