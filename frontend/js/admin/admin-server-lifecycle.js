(function () {
    var script = document.currentScript;
    var csrfToken = script && script.dataset ? script.dataset.csrfToken : '';
    var endpoint = 'index.php?p=admin-server-lifecycle';

    if (!csrfToken) {
        console.error('No se encontró el token CSRF para el ciclo de vida del servidor local.');
        return;
    }

    function enviarAccion(action, keepalive) {
        var body = new URLSearchParams({
            action: action,
            csrf_token: csrfToken
        });

        if (keepalive && navigator.sendBeacon && navigator.sendBeacon(endpoint, body)) {
            return;
        }

        fetch(endpoint, {
            method: 'POST',
            body: body,
            credentials: 'same-origin',
            keepalive: Boolean(keepalive)
        }).catch(function (error) {
            console.error('No se pudo actualizar el ciclo de vida del servidor local:', error);
        });
    }

    enviarAccion('heartbeat', false);
    var heartbeat = window.setInterval(function () {
        enviarAccion('heartbeat', false);
    }, 5000);

    window.addEventListener('pagehide', function () {
        window.clearInterval(heartbeat);
        enviarAccion('disconnect', true);
    }, { once: true });
})();
