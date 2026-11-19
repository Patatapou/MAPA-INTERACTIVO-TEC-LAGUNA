<?php
// Procesa el formulario de sugerencias de usuarios comunes.
// Valida CSRF, limita envíos por sesión, desinfecta entradas,
// protege contra inyección de encabezados, almacena en suggestions.json
// y envía correo. Devuelve JSON con el resultado real (no simula éxito).
header('Content-Type: application/json');
session_set_cookie_params(0, '/');
session_start();

require_once __DIR__ . '/../bootstrap.php';
require_once BACKEND_PATH . '/auth/csrf.php';
require_once BACKEND_PATH . '/services/SuggestionService.php';

$response = ['success' => false, 'message' => ''];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Método no permitido']);
    exit;
}

// ---------- Validación CSRF ----------
$token = $_POST['csrf_token'] ?? '';
if (!validarTokenCSRF($token)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Token de seguridad inválido. Recarga la página e inténtalo de nuevo.']);
    exit;
}

// ---------- Limitación de envíos (anti-spam) por sesión ----------
$now = time();
$window = 3600; // 1 hora
$maxAttempts = 5;

$attempts = $_SESSION['suggestion_attempts'] ?? [];
$attempts = array_filter($attempts, function ($t) use ($now, $window) {
    return (int)$t > $now - $window;
});

if (count($attempts) >= $maxAttempts) {
    http_response_code(429);
    echo json_encode(['success' => false, 'message' => 'Has superado el límite de sugerencias. Intenta nuevamente más tarde.']);
    exit;
}

$attempts[] = $now;
$_SESSION['suggestion_attempts'] = $attempts;

// ---------- Obtener y desinfectar entradas ----------
$descripcion = trim($_POST['descripcion'] ?? '');
$edificio = trim($_POST['edificio'] ?? '');
$correo = trim($_POST['correo'] ?? '');

// ---------- Validación ----------
if (empty($descripcion)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'La descripción de la sugerencia es requerida.']);
    exit;
}

if (!empty($correo) && !filter_var($correo, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'El correo electrónico no es válido.']);
    exit;
}

// ---------- Protección contra inyección de encabezados de correo ----------
$safeEdificio = str_replace(["\r", "\n", "%0a", "%0d", "\0"], '', $edificio);
$safeCorreo = str_replace(["\r", "\n", "%0a", "%0d", "\0"], '', $correo);
$safeDescripcion = str_replace(["\r", "\n"], ' ', $descripcion);

// ---------- Configurar correo ----------
$to = 'migel2013+mapa@outlook.com';
$subject = 'Sugerencia de mapa interactivo - ITL Laguna';

$smtpHost = getenv('SMTP_HOST');
$smtpPort = getenv('SMTP_PORT');
if ($smtpHost !== false && $smtpHost !== '') {
    ini_set('SMTP', $smtpHost);
}
if ($smtpPort !== false && $smtpPort !== '') {
    ini_set('smtp_port', (int)$smtpPort);
}
$smtpFrom = getenv('SMTP_FROM');
$fromEmail = ($smtpFrom !== false && $smtpFrom !== '') ? $smtpFrom : 'no-reply@itllaguna.local';
ini_set('sendmail_from', $fromEmail);

// ---------- Construir mensaje ----------
$headers = "From: $fromEmail\r\n";
$headers .= "Reply-To: " . ($safeCorreo ? $safeCorreo : $fromEmail) . "\r\n";
$headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
$headers .= "X-Mailer: PHP/" . phpversion() . "\r\n";

$message = "Nueva sugerencia de mapa interactivo ITL\n\n";
$message .= "Edificio/elemento: " . ($safeEdificio ? $safeEdificio : 'No especificado') . "\n";
$message .= "Correo de contacto: " . ($safeCorreo ? $safeCorreo : 'No proporcionado') . "\n";
$message .= "IP: " . ($_SERVER['REMOTE_ADDR'] ?? 'desconocida') . "\n";
$message .= "\n---\n";
$message .= $safeDescripcion . "\n";

// ---------- Almacenar sugerencia en suggestions.json ----------
$suggestionService = new SuggestionService();
$suggestionId = $suggestionService->crearSugerencia([
    'type' => 'building_info',
    'building_id' => $safeEdificio ?: null,
    'description' => $safeDescripcion,
    'contact_email' => $safeCorreo ?: null,
    'status' => 'pendiente',
]);

// ---------- Enviar correo (resultado real, no simulado) ----------
$sent = mail($to, $subject, $message, $headers);

if ($sent === true) {
    echo json_encode([
        'success' => true,
        'message' => 'Sugerencia enviada correctamente. Gracias por tu aporte.',
        'id' => $suggestionId,
    ]);
} else {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'No se pudo enviar el correo. Configura un servidor SMTP local (SMTP_HOST, SMTP_PORT, SMTP_FROM) o revisa php.ini.',
        'id' => $suggestionId,
    ]);
}
