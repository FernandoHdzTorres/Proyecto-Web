<?php

require_once __DIR__ . '/funciones.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../recuperar-contraseña.php');
    exit();
}

validarCsrf($_POST);
$email = $_POST['email'] ?? '';
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $_SESSION['mensaje_recuperacion'] = 'Ingresa un correo electrónico válido.';
    header('Location: ../recuperar-contraseña.php');
    exit();
}

try {
    require_once __DIR__ . '/conexion.php';
    $stmt = $conexion->prepare(
        "SELECT usuario_id FROM usuarios
         WHERE correo_electronico = :correo AND estado_cuenta = 'activo' LIMIT 1"
    );
    $stmt->execute([':correo' => $email]);
    $usuarioId = $stmt->fetchColumn();

    if ($usuarioId) {
        $token = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $token, true);
        $stmt = $conexion->prepare(
            'INSERT INTO tokens_restablecimiento_contrasena (usuario_id, token_hash, expira_en)
             VALUES (:usuario_id, :token_hash, DATE_ADD(CURRENT_TIMESTAMP, INTERVAL 1 HOUR))'
        );
        $stmt->execute([':usuario_id' => $usuarioId, ':token_hash' => $tokenHash]);

        $protocolo = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $directorio = rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/\\');
        $url = $protocolo . '://' . $host . $directorio . '/restablecer-contrasena.php?token=' . urlencode($token);
        $remitente = getenv('GAMERBOOK_MAIL_FROM') ?: 'no-reply@gamerbook.local';
        $cabeceras = "From: Gamerbook <$remitente>\r\nContent-Type: text/plain; charset=UTF-8";
        $enviado = mail($email, 'Restablece tu contraseña de Gamerbook', "Usa este enlace dentro de una hora:\n$url", $cabeceras);
        if (!$enviado) {
            $conexion->prepare('DELETE FROM tokens_restablecimiento_contrasena WHERE token_hash = :token_hash')
                ->execute([':token_hash' => $tokenHash]);
            error_log('Gamerbook password reset email could not be sent; configure PHP mail transport.');
            $_SESSION['mensaje_recuperacion'] = 'El servidor no pudo enviar el correo de recuperación. Configura el servicio de correo y vuelve a intentarlo.';
            header('Location: ../recuperar-contraseña.php');
            exit();
        }
    }

    $_SESSION['mensaje_recuperacion'] = 'Si el correo está registrado, recibirás un enlace para restablecer tu contraseña.';
    header('Location: ../recuperar-contraseña.php');
    exit();
} catch (Throwable $e) {
    error_log('Gamerbook password reset request failed: ' . $e->getMessage());
    $_SESSION['mensaje_recuperacion'] = 'No se pudo procesar la solicitud. Inténtalo más tarde.';
    header('Location: ../recuperar-contraseña.php');
    exit();
}
