<?php

require_once __DIR__ . '/funciones.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../crear-cuenta.php');
    exit();
}

$datos = $_POST;
validarCsrf($datos);

$nombre = $datos['nombre'] ?? '';
$apellido = $datos['apellido'] ?? '';
$gamerTag = $datos['gamertag'] ?? '';
$fechaNac = $datos['fecha-nacimiento'] ?? '';
$genero = filter_var($datos['genero'] ?? null, FILTER_VALIDATE_INT);
$email = $datos['email'] ?? '';
$pass = $datos['pass'] ?? '';

$fechaValida = DateTime::createFromFormat('Y-m-d', $fechaNac);
if (
    !$fechaValida || $fechaValida->format('Y-m-d') !== $fechaNac ||
    !in_array($genero, [1, 2, 3], true) ||
    !filter_var($email, FILTER_VALIDATE_EMAIL)
) {
    $_SESSION['mensaje_error'] = 'Revisa tu fecha de nacimiento, género y correo electrónico.';
    header('Location: ../crear-cuenta.php');
    exit();
}

try {
    require_once __DIR__ . '/conexion.php';
    $conexion->beginTransaction();

    $stmt = $conexion->prepare(
        'INSERT INTO usuarios (correo_electronico, contrasena_hash) VALUES (:correo, :contrasena)'
    );
    $stmt->execute([
        ':correo' => $email,
        ':contrasena' => password_hash($pass, PASSWORD_DEFAULT),
    ]);
    $usuarioId = (int)$conexion->lastInsertId();

    $stmt = $conexion->prepare(
        'INSERT INTO perfiles (usuario_id, nombre, apellido, fecha_nacimiento, genero_id, gamertag)
         VALUES (:usuario_id, :nombre, :apellido, :fecha_nacimiento, :genero_id, :gamertag)'
    );
    $stmt->execute([
        ':usuario_id' => $usuarioId,
        ':nombre' => $nombre,
        ':apellido' => $apellido,
        ':fecha_nacimiento' => $fechaNac,
        ':genero_id' => $genero,
        ':gamertag' => $gamerTag,
    ]);
    $conexion->commit();

    session_regenerate_id(true);
    $_SESSION['usuario_id'] = $usuarioId;
    $_SESSION['es_administrador'] = false;
    $_SESSION['token_csrf'] = bin2hex(random_bytes(32));
    header('Location: ../muro.html');
    exit();
} catch (PDOException $e) {
    if (isset($conexion) && $conexion->inTransaction()) {
        $conexion->rollBack();
    }

    if ($e->getCode() === '23000') {
        $_SESSION['mensaje_error'] = 'Ese correo o gamertag ya está registrado.';
    } else {
        error_log('Gamerbook registration failed: ' . $e->getMessage());
        $_SESSION['mensaje_error'] = 'No se pudo crear tu cuenta. Inténtalo más tarde.';
    }
    header('Location: ../crear-cuenta.php');
    exit();
} catch (Throwable $e) {
    if (isset($conexion) && $conexion->inTransaction()) {
        $conexion->rollBack();
    }
    error_log('Gamerbook registration failed: ' . $e->getMessage());
    $_SESSION['mensaje_error'] = 'No se pudo crear tu cuenta. Revisa la conexión con la base de datos.';
    header('Location: ../crear-cuenta.php');
    exit();
}
