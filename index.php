<?php
require_once __DIR__ . '/logica/funciones.php';

if (!empty($_SESSION['usuario_id'])) {
    header('Location: muro.html');
    exit();
}
$tokenCsrf = tokenCsrf();
$mensajeError = $_SESSION['mensaje_error'] ?? '';
unset($_SESSION['mensaje_error']);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@100;200;300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="preload" href="css/style.css" as="style">
    <link rel="stylesheet" href="css/style.css">
    <title>Gamerbook</title>
</head>
<body>
    <div class="login">
        <div class="fondo-login">
            <div class="fondo-login__contenido contenedor">
                <h1>GAMERBOOK</h1>
                <h3>Bienvenido a tu comunidad gamer</h3>
                <p>Para mantenerte conectado, por favor inicia sesión</p>
            </div>
        </div>
        <div class="contenido-login">
            <form class="contenido-login__form" action="logica/iniciar-sesion.php" method="post">
                <h3>Inicia sesión</h3>
                <p>Ingresa tus datos para continuar</p>
                <?php if ($mensajeError !== ''): ?>
                    <p class="mensaje-formulario mensaje-formulario--error" role="alert"><?= htmlspecialchars($mensajeError, ENT_QUOTES, 'UTF-8') ?></p>
                <?php endif; ?>
                <input type="hidden" name="token_csrf" value="<?= htmlspecialchars($tokenCsrf, ENT_QUOTES, 'UTF-8') ?>">
                <div class="contenido-login__input">
                    <label for="email">Correo electrónico:</label>
                    <input id="email" name="email" type="email" autocomplete="email" required>
                </div>
                <div class="contenido-login__input">
                    <label for="contrasenia">Contraseña:</label>
                    <input id="contrasenia" name="contrasenia" type="password" autocomplete="current-password" required>
                </div>
                <button class="contenido-login__boton" type="submit">Iniciar sesión</button>
                <div class="contenido-login__enlace">
                    <a href="recuperar-contraseña.php">¿Olvidaste tu contraseña?</a>
                </div>
                <hr>
                <div class="contenido-login__crear-cuenta">
                    <p>¿Aún no tienes cuenta? <a href="crear-cuenta.php">Crear cuenta</a></p>
                </div>
                <a class="contenido-login__vista-previa" href="muro.html?preview=1">Ver vista previa</a>
            </form>
        </div>
    </div>
</body>
</html>
