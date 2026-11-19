<?php

require_once __DIR__ . '/funciones.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Método no permitido.');
}

validarCsrf($_POST);
$_SESSION = [];

if (ini_get('session.use_cookies')) {
    $parametros = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $parametros['path'], $parametros['domain'], $parametros['secure'], $parametros['httponly']);
}

session_destroy();
header('Location: ../index.php');
exit();
