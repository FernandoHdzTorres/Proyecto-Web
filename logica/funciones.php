<?php

if (session_status() !== PHP_SESSION_ACTIVE) {
    $https = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    session_set_cookie_params([
        'httponly' => true,
        'secure' => $https,
        'samesite' => 'Lax',
        'path' => '/',
    ]);
    session_start();
}

function respuestaJson(int $codigo, array $datos): never
{
    http_response_code($codigo);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function leerEntrada(): array
{
    $tipo = $_SERVER['CONTENT_TYPE'] ?? '';
    if (str_contains($tipo, 'application/json')) {
        $contenido = file_get_contents('php://input');
        $datos = json_decode($contenido ?: '', true);
        if (!is_array($datos)) {
            respuestaJson(400, ['error' => 'El cuerpo de la solicitud no es válido.']);
        }
        return $datos;
    }

    return $_POST;
}

function tokenCsrf(): string
{
    if (empty($_SESSION['token_csrf'])) {
        $_SESSION['token_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['token_csrf'];
}

function validarCsrf(array $datos = []): void
{
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($datos['token_csrf'] ?? '');
    if (!is_string($token) || empty($_SESSION['token_csrf']) || !hash_equals($_SESSION['token_csrf'], $token)) {
        respuestaJson(419, ['error' => 'La sesión del formulario expiró. Recarga la página e inténtalo de nuevo.']);
    }
}

function usuarioAutenticado(): int
{
    $usuarioId = filter_var($_SESSION['usuario_id'] ?? null, FILTER_VALIDATE_INT);
    if (!$usuarioId || $usuarioId < 1) {
        respuestaJson(401, ['error' => 'Inicia sesión para continuar.']);
    }
    return $usuarioId;
}

function subirImagen(array $archivo, string $carpeta): ?string
{
    if (($archivo['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if (($archivo['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK || ($archivo['size'] ?? 0) > 5 * 1024 * 1024) {
        respuestaJson(422, ['error' => 'La imagen supera el límite de 5 MB o no se pudo cargar.']);
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($archivo['tmp_name']);
    $extensiones = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
    ];
    if (!isset($extensiones[$mime])) {
        respuestaJson(422, ['error' => 'El archivo debe ser una imagen JPG, PNG, WEBP o GIF.']);
    }

    $rutaCarpeta = dirname(__DIR__) . '/uploads/' . $carpeta;
    if (!is_dir($rutaCarpeta) && !mkdir($rutaCarpeta, 0755, true) && !is_dir($rutaCarpeta)) {
        respuestaJson(500, ['error' => 'No se pudo preparar el almacenamiento de imágenes.']);
    }

    $nombre = bin2hex(random_bytes(16)) . '.' . $extensiones[$mime];
    if (!move_uploaded_file($archivo['tmp_name'], $rutaCarpeta . '/' . $nombre)) {
        respuestaJson(500, ['error' => 'No se pudo guardar la imagen.']);
    }

    return 'uploads/' . $carpeta . '/' . $nombre;
}

function crearNotificacion(
    PDO $conexion,
    int $destinatarioId,
    ?int $actorId,
    string $tipo,
    string $mensaje,
    ?string $columna = null,
    ?int $objetivoId = null
): void {
    $columnasValidas = [
        'publicacion_id',
        'comentario_id',
        'escuadron_id',
        'mensaje_directo_id',
        'mensaje_escuadron_id',
    ];
    if ($columna !== null && !in_array($columna, $columnasValidas, true)) {
        throw new InvalidArgumentException('Tipo de destino de notificación no permitido.');
    }

    $campos = 'destinatario_usuario_id, actor_usuario_id, tipo_notificacion, mensaje';
    $valores = ':destinatario, :actor, :tipo, :mensaje';
    if ($columna !== null) {
        $campos .= ', ' . $columna;
        $valores .= ', :objetivo';
    }

    $stmt = $conexion->prepare("INSERT INTO notificaciones ($campos) VALUES ($valores)");
    $parametros = [
        ':destinatario' => $destinatarioId,
        ':actor' => $actorId,
        ':tipo' => $tipo,
        ':mensaje' => $mensaje,
    ];
    if ($columna !== null) {
        $parametros[':objetivo'] = $objetivoId;
    }
    $stmt->execute($parametros);
}
