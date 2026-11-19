<?php

require_once __DIR__ . '/funciones.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../index.php');
    exit();
}

validarCsrf($_POST);
$token = $_POST['token'] ?? '';
$pass = $_POST['pass'] ?? '';
$confirmacion = $_POST['pass_confirmacion'] ?? '';
if (!preg_match('/^[a-f0-9]{64}$/', $token) || $pass !== $confirmacion) {
    $_SESSION['mensaje_error'] = 'El enlace o la contraseña no son válidos.';
    header('Location: ../restablecer-contrasena.php?token=' . urlencode($token));
    exit();
}

try {
    require_once __DIR__ . '/conexion.php';
    $conexion->beginTransaction();
    $stmt = $conexion->prepare(
        'SELECT token_restablecimiento_id, usuario_id
         FROM tokens_restablecimiento_contrasena
         WHERE token_hash = :token_hash AND usado_en IS NULL AND expira_en > CURRENT_TIMESTAMP
         LIMIT 1 FOR UPDATE'
    );
    $stmt->execute([':token_hash' => hash('sha256', $token, true)]);
    $registro = $stmt->fetch();
    if (!$registro) {
        $conexion->rollBack();
        $_SESSION['mensaje_error'] = 'El enlace expiró o ya fue utilizado. Solicita uno nuevo.';
        header('Location: ../recuperar-contraseña.php');
        exit();
    }

    $stmt = $conexion->prepare('UPDATE usuarios SET contrasena_hash = :hash WHERE usuario_id = :usuario_id');
    $stmt->execute([
        ':hash' => password_hash($pass, PASSWORD_DEFAULT),
        ':usuario_id' => $registro['usuario_id'],
    ]);
    $stmt = $conexion->prepare(
        'UPDATE tokens_restablecimiento_contrasena SET usado_en = CURRENT_TIMESTAMP
         WHERE token_restablecimiento_id = :id'
    );
    $stmt->execute([':id' => $registro['token_restablecimiento_id']]);
    $conexion->commit();

    $_SESSION['mensaje_error'] = 'Contraseña actualizada. Ya puedes iniciar sesión.';
    header('Location: ../index.php');
    exit();
} catch (Throwable $e) {
    if (isset($conexion) && $conexion->inTransaction()) {
        $conexion->rollBack();
    }
    error_log('Gamerbook password reset failed: ' . $e->getMessage());
    $_SESSION['mensaje_error'] = 'No se pudo actualizar la contraseña. Inténtalo más tarde.';
    header('Location: ../recuperar-contraseña.php');
    exit();
}
