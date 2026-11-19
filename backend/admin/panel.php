<?php
// Los errores se ocultan por defecto para no filtrar rutas ni romper respuestas.
// Para depurar localmente: APP_DEBUG=1 php -S 127.0.0.1:8000
$debug = getenv('APP_DEBUG') === '1';
ini_set('display_errors', $debug ? '1' : '0');
ini_set('display_startup_errors', $debug ? '1' : '0');
error_reporting(E_ALL);

require_once __DIR__ . '/../bootstrap.php';
require_once BACKEND_PATH . '/auth/auth_middleware.php';
requerirAdmin();

$csrfToken = generarTokenCSRF();

$rutaJson = PUBLIC_DATA_PATH . '/datos.json';
$datos = file_exists($rutaJson)
    ? json_decode(file_get_contents($rutaJson), true)
    : [];

/* MODO EDICIÓN */
$edificioEdit = [
    'nombre' => '',
    'descripcion' => '',
    'carpeta' => [],
    'aulas' => []
];

$edificioId = '';

if (isset($_GET['editar']) && isset($datos[$_GET['editar']])) {
    $edificioId = $_GET['editar'];
    $edificioEdit = $datos[$edificioId];
    $edificioEdit['aulas'] = $edificioEdit['aulas'] ?? [];
    $edificioEdit['carpeta'] = $edificioEdit['carpeta'] ?? [];
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Panel Admin - ITL Laguna</title>
<link href="https://fonts.googleapis.com/css?family=Open+Sans:600" rel="stylesheet">
<link rel="stylesheet" href="css/admin/panel.css">
</head>
<body>

<div class="admin-container">
    <div class="admin-header">
        <h1>Panel de administración - Edificios</h1>
        <div class="admin-actions" style="display: flex; gap: 10px; align-items: center;">
            <button type="button" class="btn btn-secondary" onclick="window.location.href='index.html'">← Volver al mapa</button>
            <button type="button" class="btn btn-secondary" onclick="window.location.href='index.php?p=rutas'">🚶 Rutas</button>
            <a href="index.php?p=editar-geometria&accion=nuevo" class="btn btn-primary">➕ Nuevo edificio</a>
            <form action="index.php?p=logout" method="post" style="display: inline; margin: 0;">
                <button type="submit" class="btn btn-danger">Cerrar sesión</button>
            </form>
        </div>
    </div>

    <div class="panel-layout">
        <!-- Sidebar con lista de edificios -->
        <div class="edificios-sidebar">
            <h2>📍 Edificios</h2>
            <div class="sidebar-search">
                <label for="buscar-edificios">Buscar edificio o aula:</label>
                <input type="search" id="buscar-edificios" placeholder="Nombre, ID o aula...">
            </div>
            <ul id="lista-edificios">
            <?php foreach ($datos as $id => $e):
                $aulasTexto = implode(' ', $e['aulas'] ?? []);
                $tipoRegistro = $e['tipo'] ?? 'edificio';
                $esAcceso = $tipoRegistro === 'acceso';
                $etiquetaTipo = $esAcceso ? 'acceso' : 'edificio';
            ?>
                <li class="edificio-item-wrapper">
                    <a href="index.php?p=editar&editar=<?= $id ?>"
                       class="edificio-item <?= $edificioId === $id ? 'active' : '' ?>"
                       data-search="<?= htmlspecialchars(strtolower($id . ' ' . $e['nombre'] . ' ' . $aulasTexto . ' ' . $etiquetaTipo)) ?>">
                        <strong><?= htmlspecialchars($e['nombre']) ?></strong>
                        <?php if ($esAcceso): ?>
                            <span class="tipo-badge tipo-acceso">Acceso</span>
                        <?php else: ?>
                            <br>
                            <small><?= count($e['aulas'] ?? []) ?> aulas registradas</small>
                        <?php endif; ?>
                    </a>
                    <div class="edificio-actions">
                        <a href="index.php?p=editar-geometria&accion=editar&id=<?= $id ?>"
                           class="btn btn-primary btn-sm">Editar geometría</a>
                        <form action="index.php?p=deleteEdificio" method="post"
                              class="delete-form"
                              data-nombre="<?= htmlspecialchars($e['nombre']) ?>">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                            <input type="hidden" name="id" value="<?= htmlspecialchars($id) ?>">
                            <button type="submit" class="btn btn-danger btn-sm">Eliminar</button>
                        </form>
                    </div>
                </li>
            <?php endforeach; ?>
            </ul>
            <p id="no-resultados" style="display: none; padding: 15px 20px; color: var(--text-muted); text-align: center;">
                No se encontraron edificios que coincidan.
            </p>
        </div>

        <!-- Panel de edición -->
        <div class="form-section">
            <?php if ($edificioId): ?>
                <h2>
                    Editar: <?= htmlspecialchars($edificioEdit['nombre']) ?>
                    <?php if (($edificioEdit['tipo'] ?? 'edificio') === 'acceso'): ?>
                        <span class="tipo-badge tipo-acceso">Acceso</span>
                    <?php endif; ?>
                </h2>

                <div id="message" class="message"></div>

                <form id="edificioForm">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                    <input type="hidden" name="id" value="<?= htmlspecialchars($edificioId) ?>">

                    <div class="form-group">
                        <label>Nombre del edificio</label>
                        <input
                            type="text"
                            name="nombre"
                            id="nombre"
                            placeholder="Nombre del edificio"
                            value="<?= htmlspecialchars($edificioEdit['nombre']) ?>"
                            required
                        >
                    </div>

                    <div class="form-group">
                        <label>Descripción</label>
                        <textarea
                            name="descripcion"
                            id="descripcion"
                            placeholder="Descripción del edificio"
                            rows="4"
                        ><?= htmlspecialchars($edificioEdit['descripcion']) ?></textarea>
                    </div>

                    <!-- Carrusel de imágenes existentes -->
                    <div class="carousel-container" id="carouselContainer" style="display: none;">
                        <h3>Imágenes actuales (Carpeta: <span id="carpetaActual"></span>)</h3>
                        <div class="carousel" id="imagenesActuales">
                            <!-- Se cargará dinámicamente -->
                        </div>
                    </div>

                    <!-- Zona de carga de nuevas imágenes -->
                    <div class="upload-zone" id="uploadZone">
                        <input type="file" id="imageInput" accept="image/*" multiple>
                        <p>📁 Haz clic o arrastra imágenes aquí</p>
                        <p style="font-size: 12px; color: #666;">Formatos: JPG, PNG, GIF</p>
                    </div>

                    <!-- Previsualización de nuevas imágenes -->
                    <div class="preview-container" id="previewContainer">
                        <h3>Nuevas imágenes a cargar</h3>
                        <div id="previewList"></div>
                    </div>

                    <div class="form-group">
                        <label>Aulas</label>
                        <p style="font-size: 13px; color: #999; margin: 5px 0;">Escribe una aula por línea</p>
                        <textarea
                            name="aulas"
                            id="aulas"
                            rows="8"
                            placeholder="Ejemplo:&#10;19A&#10;19B&#10;Laboratorio 3"
                        ><?= htmlspecialchars(implode("\n", $edificioEdit['aulas'])) ?></textarea>
                    </div>

                    <button type="submit" class="btn btn-primary">
                        💾 Guardar cambios
                    </button>
                </form>
            <?php else: ?>
                <div class="no-selection">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4" />
                    </svg>
                    <h3>Selecciona un edificio</h3>
                    <p>Elige un edificio de la lista para editar su información</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
const uploadZone = document.getElementById('uploadZone');
const imageInput = document.getElementById('imageInput');
const previewContainer = document.getElementById('previewContainer');
const previewList = document.getElementById('previewList');
const form = document.getElementById('edificioForm');
const messageDiv = document.getElementById('message');
const carouselContainer = document.getElementById('carouselContainer');
const imagenesActuales = document.getElementById('imagenesActuales');
const carpetaActual = document.getElementById('carpetaActual');

let nuevasImagenes = [];
let imagenesAEliminar = [];
let edificioId = '<?= $edificioId ?>';
let imagenesExistentes = [];

// Mensaje flash que sobrevive a la recarga posterior a un guardado/borrado
const flashPendiente = sessionStorage.getItem('panelFlash');
if (flashPendiente) {
    sessionStorage.removeItem('panelFlash');
    if (messageDiv) {
        mostrarMensaje(flashPendiente, 'success');
    }
}

// Cargar imágenes existentes si hay un edificio seleccionado
if (edificioId) {
    cargarImagenesExistentes();
}

async function cargarImagenesExistentes() {
    try {
        const urlParams = new URLSearchParams(window.location.search);
        const edificioId = urlParams.get('editar');

        if (!edificioId) {
            return;
        }

        const datos = new FormData();
        datos.append('folder', edificioId);

        const response = await fetch('index.php?p=getimages', {
            method: 'POST',
            body: datos
        });

        const imagenes = await response.json();
        
        if (imagenes && imagenes.length > 0) {
            imagenesExistentes = imagenes;
            carpetaActual.textContent = edificioId;
            carouselContainer.style.display = 'block';
            mostrarImagenesExistentes();
        } else {
            carouselContainer.style.display = 'none';
        }

    } catch (error) {
        console.error('Error al cargar imágenes:', error);
    }
}

function mostrarImagenesExistentes() {
    imagenesActuales.innerHTML = '';
    
    imagenesExistentes.forEach((rutaImagen, index) => {
        if (!imagenesAEliminar.includes(rutaImagen)) {
            const div = document.createElement('div');
            div.className = 'carousel-item';
            div.setAttribute('data-imagen', rutaImagen);
            
            // ✅ SOLUCIÓN: Usar addEventListener en lugar de onclick inline
            const img = document.createElement('img');
            img.src = rutaImagen;
            img.alt = `Imagen ${index + 1}`;
            
            const btnDelete = document.createElement('button');
            btnDelete.type = 'button';
            btnDelete.className = 'delete-btn';
            btnDelete.textContent = '×';
            
            // Agregar event listener directamente (evita problemas con comillas en rutas)
            btnDelete.addEventListener('click', () => {
                eliminarImagen(rutaImagen);
            });
            
            div.appendChild(img);
            div.appendChild(btnDelete);
            imagenesActuales.appendChild(div);
        }
    });
    
    if (imagenesActuales.children.length === 0) {
        carouselContainer.style.display = 'none';
    }
}

if (uploadZone && imageInput) {
    uploadZone.addEventListener('click', () => imageInput.click());

    uploadZone.addEventListener('dragover', (e) => {
        e.preventDefault();
        uploadZone.classList.add('drag-over');
    });

    uploadZone.addEventListener('dragleave', () => {
        uploadZone.classList.remove('drag-over');
    });

    uploadZone.addEventListener('drop', (e) => {
        e.preventDefault();
        uploadZone.classList.remove('drag-over');
        const files = Array.from(e.dataTransfer.files).filter(f => f.type.startsWith('image/'));
        procesarArchivos(files);
    });

    imageInput.addEventListener('change', (e) => {
        procesarArchivos(Array.from(e.target.files));
    });
}

function procesarArchivos(files) {
    files.forEach(file => {
        nuevasImagenes.push(file);
        mostrarPreview(file);
    });
}

function mostrarPreview(file) {
    const reader = new FileReader();
    
    reader.onload = (e) => {
        const div = document.createElement('div');
        div.className = 'preview-item';
        
        const img = document.createElement('img');
        img.src = e.target.result;
        img.alt = 'Preview';
        
        const btnRemove = document.createElement('button');
        btnRemove.type = 'button';
        btnRemove.className = 'remove-preview';
        btnRemove.textContent = '×';
        
        // ✅ Event listener en lugar de onclick
        btnRemove.addEventListener('click', () => {
            removerPreview(btnRemove, file.name);
        });
        
        div.appendChild(img);
        div.appendChild(btnRemove);
        previewList.appendChild(div);
        previewContainer.classList.add('active');
    };
    
    reader.readAsDataURL(file);
}

function removerPreview(btn, fileName) {
    btn.parentElement.remove();
    nuevasImagenes = nuevasImagenes.filter(f => f.name !== fileName);

    if (nuevasImagenes.length === 0) {
        previewContainer.classList.remove('active');
    }
}

function eliminarImagen(rutaImagen) {
    if (!confirm('¿Eliminar esta imagen?')) return;

    imagenesAEliminar.push(rutaImagen);
    mostrarImagenesExistentes();
}

if (form) {
    form.addEventListener('submit', async (e) => {
        e.preventDefault();

        // El FormData del formulario ya incluye csrf_token, id, nombre,
        // descripción y aulas. Solo faltan las imágenes.
        const formData = new FormData(form);

        nuevasImagenes.forEach((file) => {
            formData.append('imagenes[]', file);
        });

        formData.append('imagenes_eliminar', JSON.stringify(imagenesAEliminar));

        const botonGuardar = form.querySelector('button[type="submit"]');
        const textoBoton = botonGuardar ? botonGuardar.textContent : '';
        if (botonGuardar) {
            botonGuardar.disabled = true;
            botonGuardar.textContent = 'Guardando...';
        }

        try {
            const response = await fetch('index.php?p=saveData', {
                method: 'POST',
                body: formData,
                credentials: 'same-origin',
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            });

            const texto = await response.text();
            let result;
            try {
                result = JSON.parse(texto);
            } catch (err) {
                throw new Error('Respuesta inesperada del servidor (HTTP ' + response.status + '). Recarga la página e inicia sesión de nuevo.');
            }

            if (result.success) {
                nuevasImagenes = [];
                imagenesAEliminar = [];
                sessionStorage.setItem('panelFlash', '✓ ' + result.message);
                // Recargar para reflejar los cambios en la lista lateral y el encabezado
                setTimeout(() => window.location.reload(), 500);
            } else {
                mostrarMensaje('✗ ' + result.message, 'error');
                if (botonGuardar) {
                    botonGuardar.disabled = false;
                    botonGuardar.textContent = textoBoton;
                }
            }

        } catch (error) {
            mostrarMensaje('✗ ' + error.message, 'error');
            if (botonGuardar) {
                botonGuardar.disabled = false;
                botonGuardar.textContent = textoBoton;
            }
        }
    });
}

// ==========================================
// ELIMINACIÓN DE EDIFICIOS (fetch + confirmación)
// ==========================================
document.querySelectorAll('.delete-form').forEach((deleteForm) => {
    deleteForm.addEventListener('submit', async (e) => {
        e.preventDefault();

        const nombre = deleteForm.dataset.nombre || 'este edificio';
        if (!confirm(`¿Eliminar "${nombre}" y su geometría? Esta acción no se puede deshacer.`)) {
            return;
        }

        const boton = deleteForm.querySelector('button[type="submit"]');
        if (boton) {
            boton.disabled = true;
            boton.textContent = 'Eliminando...';
        }

        try {
            const response = await fetch(deleteForm.action, {
                method: 'POST',
                body: new FormData(deleteForm),
                credentials: 'same-origin',
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            });

            const texto = await response.text();
            let result;
            try {
                result = JSON.parse(texto);
            } catch (err) {
                throw new Error('Respuesta inesperada del servidor (HTTP ' + response.status + '). Recarga la página e inicia sesión de nuevo.');
            }

            if (!result.success) {
                throw new Error(result.message || 'No se pudo eliminar el edificio.');
            }

            sessionStorage.setItem('panelFlash', '✓ ' + result.message);
            window.location.reload();

        } catch (error) {
            alert('✗ ' + error.message);
            if (boton) {
                boton.disabled = false;
                boton.textContent = 'Eliminar';
            }
        }
    });
});

function mostrarMensaje(texto, tipo) {
    if (!messageDiv) {
        return;
    }
    messageDiv.textContent = texto;
    messageDiv.className = `message ${tipo} show`;
    
    setTimeout(() => {
        messageDiv.classList.remove('show');
    }, 5000);
}

// ==========================================
// BUSCADOR DE EDIFICIOS EN SIDEBAR
// ==========================================
const buscadorEdificios = document.getElementById('buscar-edificios');
const listaEdificios = document.getElementById('lista-edificios');
const noResultados = document.getElementById('no-resultados');

if (buscadorEdificios && listaEdificios) {
    const items = Array.from(listaEdificios.querySelectorAll('li'));

    buscadorEdificios.addEventListener('input', function () {
        const query = this.value.toLowerCase().trim();
        let visibles = 0;

        items.forEach(item => {
            const enlace = item.querySelector('a');
            const searchData = enlace ? enlace.getAttribute('data-search') : '';
            const coincide = searchData.includes(query);

            item.style.display = coincide ? '' : 'none';
            if (coincide) visibles++;
        });

        noResultados.style.display = visibles === 0 && query !== '' ? 'block' : 'none';
    });
}
</script>

</body>
</html>