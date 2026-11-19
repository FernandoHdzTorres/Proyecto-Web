<?php

require_once __DIR__ . '/funciones.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../index.php');
    exit();
}

$datos = $_POST;
validarCsrf($datos);
$email = $datos['email'] ?? '';
$pass = $datos['contrasenia'] ?? '';

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $_SESSION['mensaje_error'] = 'Correo o contraseña incorrectos.';
    header('Location: ../index.php');
    exit();
}

try {
    require_once __DIR__ . '/conexion.php';
    $stmt = $conexion->prepare(
        "SELECT usuario_id, contrasena_hash, es_administrador FROM usuarios
         WHERE correo_electronico = :correo AND estado_cuenta = 'activo' LIMIT 1"
    );
    $stmt->execute([':correo' => $email]);
    $usuario = $stmt->fetch();

    if (!$usuario || !password_verify($pass, $usuario['contrasena_hash'])) {
        $_SESSION['mensaje_error'] = 'Correo o contraseña incorrectos.';
        header('Location: ../index.php');
        exit();
    }

    if (password_needs_rehash($usuario['contrasena_hash'], PASSWORD_DEFAULT)) {
        $stmt = $conexion->prepare('UPDATE usuarios SET contrasena_hash = :hash WHERE usuario_id = :usuario_id');
        $stmt->execute([
            ':hash' => password_hash($pass, PASSWORD_DEFAULT),
            ':usuario_id' => $usuario['usuario_id'],
        ]);
    }

    session_regenerate_id(true);
    $_SESSION['usuario_id'] = (int)$usuario['usuario_id'];
    $_SESSION['es_administrador'] = (bool)$usuario['es_administrador'];
    $_SESSION['token_csrf'] = bin2hex(random_bytes(32));
    header('Location: ../muro.html');
    exit();
} catch (Throwable $e) {
    error_log('Gamerbook login failed: ' . $e->getMessage());
    $_SESSION['mensaje_error'] = 'No se pudo iniciar sesión. Revisa la conexión con la base de datos.';
    header('Location: ../index.php');
    exit();
}
