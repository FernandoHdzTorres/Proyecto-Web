<?php
require_once __DIR__ . '/logica/funciones.php';
$token = trim((string)($_GET['token'] ?? ''));
$mensajeError = $_SESSION['mensaje_error'] ?? '';
unset($_SESSION['mensaje_error']);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="css/style.css">
    <title>Gamerbook</title>
</head>
<body>
    <div class="recuperar">
        <div class="recuperar__contenido">
            <h1>Crea una nueva contraseña</h1>
            <?php if ($mensajeError !== ''): ?>
                <p class="mensaje-formulario mensaje-formulario--error" role="alert"><?= htmlspecialchars($mensajeError, ENT_QUOTES, 'UTF-8') ?></p>
            <?php endif; ?>
            <form action="logica/restablecer-contrasena.php" method="post">
                <input type="hidden" name="token_csrf" value="<?= htmlspecialchars(tokenCsrf(), ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="token" value="<?= htmlspecialchars($token, ENT_QUOTES, 'UTF-8') ?>">
                <div class="contenido-login__input">
                    <label for="pass">Nueva contraseña</label>
                    <input id="pass" name="pass" type="password" minlength="8" autocomplete="new-password" required>
                </div>
                <div class="contenido-login__input">
                    <label for="pass-confirmacion">Confirma la contraseña</label>
                    <input id="pass-confirmacion" name="pass_confirmacion" type="password" minlength="8" autocomplete="new-password" required>
                </div>
                <button class="contenido-login__boton" type="submit">Guardar contraseña</button>
            </form>
        </div>
    </div>
</body>
</html>
