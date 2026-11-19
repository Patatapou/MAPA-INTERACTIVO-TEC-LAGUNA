// ==========================================
// UTILIDADES GEOGRÁFICAS COMPARTIDAS
// ==========================================
// `distanciaMetros()` la usan `pathfinding.js`, la simulación de beacons y el
// editor de red. Vive aquí para que ningún módulo dependa de que otro se haya
// cargado antes por casualidad.
//
// Orden de carga en el mapa público:
//   js/shared/geo-utils.js → js/public/beacons-simulation.js →
//   js/shared/pathfinding.js → js/public/main.js

// ---------- Distancia en metros (Fórmula de Haversine) ----------
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
