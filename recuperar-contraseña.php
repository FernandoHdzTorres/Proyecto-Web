<?php
require_once __DIR__ . '/logica/funciones.php';
$tokenCsrf = tokenCsrf();
$mensaje = $_SESSION['mensaje_recuperacion'] ?? '';
unset($_SESSION['mensaje_recuperacion']);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@100;200;300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/style.css">
    <title>Gamerbook</title>
</head>
<body>
    <div class="recuperar">
        <div class="recuperar__contenido">
            <a href="index.php" aria-label="Volver">&lt;</a>
            <h1>Recupera tu cuenta</h1>
            <?php if ($mensaje !== ''): ?>
                <p class="mensaje-formulario mensaje-formulario--error" role="alert"><?= htmlspecialchars($mensaje, ENT_QUOTES, 'UTF-8') ?></p>
            <?php endif; ?>
            <form action="logica/solicitar-restablecimiento.php" method="post">
                <input type="hidden" name="token_csrf" value="<?= htmlspecialchars($tokenCsrf, ENT_QUOTES, 'UTF-8') ?>">
                <div class="contenido-login__input">
                    <label class="recuperar__label" for="email">Ingresa tu correo electrónico</label>
                    <input id="email" name="email" type="email" autocomplete="email" required>
                </div>
                <button class="contenido-login__boton" type="submit">Continuar</button>
            </form>
        </div>
    </div>
</body>
</html>
