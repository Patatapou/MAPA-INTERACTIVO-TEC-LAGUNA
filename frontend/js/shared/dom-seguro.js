// ==========================================
// UTILIDADES DE DOM SEGURO (NAVEGADOR Y NODE)
// ==========================================
// Helpers compartidos por el mapa público para no insertar datos del panel
// como HTML. El archivo funciona como script clásico en el navegador
// (`globalThis.DomSeguro`) y como módulo CommonJS en las pruebas Node.
(function (raiz) {
    'use strict';

    const MAPA_ESCAPE = {
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#39;'
    };

    /**
     * Escapa texto para insertarlo en HTML (atributos o contenido).
     * @param {*} valor
     * @returns {string}
     */
    function escaparHtml(valor) {
        return String(valor === null || valor === undefined ? '' : valor)
            .replace(/[&<>"']/g, (caracter) => MAPA_ESCAPE[caracter]);
    }

    /**
     * Escapa metacaracteres para construir un RegExp literal.
     * @param {*} texto
     * @returns {string}
     */
    function escaparRegex(texto) {
        return String(texto === null || texto === undefined ? '' : texto)
            .replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    }

    /**
     * Normaliza y valida una ruta de imagen de carrusel.
     *
     * Acepta `img/<id>/<archivo>` (con o sin `/` inicial) y devuelve la ruta
     * relativa; devuelve null si no cumple el patrón, si intenta salir de la
     * carpeta o si el `<id>` no coincide con el edificio esperado.
     *
     * @param {*} valor
     * @param {string|number|null|undefined} idEsperado
     * @returns {string|null}
     */
    function normalizarRutaImagen(valor, idEsperado) {
        if (typeof valor !== 'string') return null;

        const ruta = valor.trim().replace(/^\/+/, '');
        if (ruta === '' || ruta.includes('\\') || ruta.includes('..')) return null;

        const coincidencia = /^img\/([A-Za-z0-9_-]+)\/([A-Za-z0-9][A-Za-z0-9._-]*)$/.exec(ruta);
        if (!coincidencia) return null;

        if (idEsperado !== undefined && idEsperado !== null
            && coincidencia[1] !== String(idEsperado)) {
            return null;
        }

        return ruta;
    }

    const api = { escaparHtml, escaparRegex, normalizarRutaImagen };

    if (typeof module !== 'undefined' && module.exports) {
        module.exports = api;
    }
    raiz.DomSeguro = api;
})(typeof globalThis !== 'undefined' ? globalThis : this);
