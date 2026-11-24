(function () {
    'use strict';
    var cfg = window.USUARIOS_ADMIN || {};
    var usuarios = [];

    var dom = {
        lista: document.getElementById('usuarios-lista'),
        buscar: document.getElementById('usuarios-buscar'),
        filtro: document.getElementById('usuarios-filtro'),
        mensaje: document.getElementById('usuarios-mensaje'),
        nuevo: document.getElementById('usuarios-nuevo'),
        dialogo: document.getElementById('usuarios-dialogo'),
        form: document.getElementById('usuarios-form'),
        titulo: document.getElementById('usuarios-dialogo-titulo'),
        username: document.getElementById('usuarios-username'),
        email: document.getElementById('usuarios-email'),
        formError: document.getElementById('usuarios-form-error'),
        submit: document.getElementById('usuarios-form-submit'),
        cancelar: document.getElementById('usuarios-form-cancelar'),
        temporal: document.getElementById('usuarios-temporal'),
        passwordTemporal: document.getElementById('usuarios-password-temporal'),
        copiar: document.getElementById('usuarios-copiar'),
        temporalCerrar: document.getElementById('usuarios-temporal-cerrar')
    };

    function texto(tag, clase, valor) {
        var nodo = document.createElement(tag);
        if (clase) nodo.className = clase;
        if (valor !== undefined) nodo.textContent = String(valor);
        return nodo;
    }
    function limpiar(nodo) { while (nodo.firstChild) nodo.removeChild(nodo.firstChild); }

    function mostrarTemporal(password) {
        dom.passwordTemporal.textContent = password;
        dom.temporal.showModal();
    }

    function cerrarTemporal() {
        dom.passwordTemporal.textContent = '';
        if (dom.temporal.open) dom.temporal.close();
    }

    async function peticion(url, body) {
        var datos = new URLSearchParams();
        datos.set('csrf_token', cfg.csrf);
        Object.keys(body || {}).forEach(function (clave) { datos.set(clave, String(body[clave])); });
        var respuesta = await fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8', 'X-Requested-With': 'XMLHttpRequest' },
            body: datos.toString(),
            cache: 'no-store'
        });
        var json = await respuesta.json();
        if (!respuesta.ok || !json.success) throw new Error(json.message || 'No se pudo completar la operación.');
        return json;
    }

    function boton(clase, etiqueta, accion) {
        var nodo = texto('button', 'btn ' + clase, etiqueta);
        nodo.type = 'button';
        nodo.addEventListener('click', accion);
        return nodo;
    }

    function render() {
        limpiar(dom.lista);
        var consulta = dom.buscar.value.toLowerCase();
        var filtro = dom.filtro.value;
        var visibles = usuarios.filter(function (usuario) {
            if (filtro && usuario.status !== filtro) return false;
            var contenido = (usuario.username + ' ' + (usuario.email || '')).toLowerCase();
            return contenido.indexOf(consulta) !== -1;
        });
        visibles.forEach(function (usuario) {
            var tr = document.createElement('tr');
            tr.appendChild(texto('td', '', usuario.username));
            tr.appendChild(texto('td', '', usuario.email || '—'));
            tr.appendChild(texto('td', '', usuario.status));
            tr.appendChild(texto('td', '', usuario.last_login_at || 'Nunca'));
            tr.appendChild(texto('td', '', usuario.created_at || '—'));
            var acciones = texto('td', 'usuarios-actions');
            var propio = usuario.id === cfg.usuarioActualId;

            if (!propio) {
                acciones.appendChild(boton('btn-secondary btn-sm', 'Editar correo', function () { abrirEdicion(usuario); }));
                var nuevoEstado = usuario.status === 'activo' ? 'inactivo' : 'activo';
                acciones.appendChild(boton(usuario.status === 'activo' ? 'btn-warning btn-sm' : 'btn-primary btn-sm',
                    usuario.status === 'activo' ? 'Desactivar' : 'Activar',
                    function () { cambiarEstado(usuario, nuevoEstado); }));
                acciones.appendChild(boton('btn-secondary btn-sm', 'Restablecer', function () { restablecer(usuario); }));
                if (usuario.status === 'inactivo') {
                    acciones.appendChild(boton('btn-danger btn-sm', 'Eliminar', function () { eliminar(usuario); }));
                }
            } else {
                acciones.appendChild(texto('span', 'usuarios-propio', 'Cuenta propia'));
            }
            tr.appendChild(acciones);
            dom.lista.appendChild(tr);
        });
        if (!visibles.length) {
            var vacia = document.createElement('tr');
            var celda = texto('td', '', 'No hay usuarios que coincidan.');
            celda.colSpan = 6;
            vacia.appendChild(celda);
            dom.lista.appendChild(vacia);
        }
    }

    async function cargar() {
        dom.mensaje.textContent = 'Cargando usuarios…';
        try {
            var respuesta = await fetch(cfg.urls.get, { headers: { 'X-Requested-With': 'XMLHttpRequest' }, cache: 'no-store' });
            var json = await respuesta.json();
            if (!respuesta.ok || !json.success) throw new Error(json.message || 'No se pudieron cargar los usuarios.');
            usuarios = Object.keys(json.usuarios || {}).map(function (id) { return json.usuarios[id]; });
            dom.mensaje.textContent = '';
            render();
        } catch (error) {
            dom.mensaje.textContent = error.message;
        }
    }

    function abrirNuevo() {
        dom.titulo.textContent = 'Nuevo usuario';
        dom.username.value = '';
        dom.username.readOnly = false;
        dom.username.required = true;
        dom.email.value = '';
        dom.form.elements.id.value = '';
        dom.formError.textContent = '';
        dom.submit.textContent = 'Crear';
        dom.dialogo.showModal();
    }

    function abrirEdicion(usuario) {
        dom.titulo.textContent = 'Editar correo';
        dom.username.value = usuario.username;
        dom.username.readOnly = true;
        dom.username.required = false;
        dom.email.value = usuario.email || '';
        dom.form.elements.id.value = usuario.id;
        dom.formError.textContent = '';
        dom.submit.textContent = 'Guardar';
        dom.dialogo.showModal();
    }

    dom.form.addEventListener('submit', async function (event) {
        event.preventDefault();
        dom.formError.textContent = '';
        var id = dom.form.elements.id.value;
        try {
            var respuesta = await peticion(cfg.urls.save, {
                id: id,
                username: dom.username.value,
                email: dom.email.value
            });
            dom.dialogo.close();
            if (respuesta.password_temporal) mostrarTemporal(respuesta.password_temporal);
            await cargar();
        } catch (error) {
            dom.formError.textContent = error.message;
        }
    });

    async function cambiarEstado(usuario, estado) {
        if (!window.confirm(estado === 'activo' ? '¿Activar esta cuenta?' : '¿Desactivar esta cuenta?')) return;
        try {
            await peticion(cfg.urls.estado, { id: usuario.id, status: estado });
            await cargar();
        } catch (error) { dom.mensaje.textContent = error.message; }
    }
    async function restablecer(usuario) {
        if (!window.confirm('¿Restablecer la contraseña de ' + usuario.username + '?')) return;
        try {
            var respuesta = await peticion(cfg.urls.reset, { id: usuario.id });
            mostrarTemporal(respuesta.password_temporal);
            await cargar();
        } catch (error) { dom.mensaje.textContent = error.message; }
    }
    async function eliminar(usuario) {
        if (!window.confirm('¿Eliminar definitivamente a ' + usuario.username + '? Solo deben eliminarse cuentas inactivas.')) return;
        try {
            await peticion(cfg.urls.eliminar, { id: usuario.id });
            await cargar();
        } catch (error) { dom.mensaje.textContent = error.message; }
    }

    dom.nuevo.addEventListener('click', abrirNuevo);
    dom.cancelar.addEventListener('click', function () { dom.dialogo.close(); });
    dom.buscar.addEventListener('input', render);
    dom.filtro.addEventListener('change', render);
    dom.copiar.addEventListener('click', async function () {
        var password = dom.passwordTemporal.textContent;
        if (navigator.clipboard) await navigator.clipboard.writeText(password);
    });
    dom.temporalCerrar.addEventListener('click', cerrarTemporal);
    cargar();
}());
