<?php
require_once __DIR__ . '/logica/funciones.php';
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
    <div class="crear-cuenta">
        <div class="crear-cuenta__contenedor contenedor">
            <div class="crear-cuenta__header">
                <a href="index.php">&lt;</a>
                <h1>Empieza a usar Gamerbook</h1>
                <p>La red social hecha por gamers, para gamers. Únete a la partida.</p>
            </div>
            <form action="logica/insertar-cuenta.php" method="post" class="contenido-login__form crear-cuenta__form">
                <?php if ($mensajeError !== ''): ?>
                    <p class="mensaje-formulario mensaje-formulario--error" role="alert"><?= htmlspecialchars($mensajeError, ENT_QUOTES, 'UTF-8') ?></p>
                <?php endif; ?>
                <input type="hidden" name="token_csrf" value="<?= htmlspecialchars($tokenCsrf, ENT_QUOTES, 'UTF-8') ?>">
                <div class="contenido-login__input">
                    <label for="nombre">Nombre:</label>
                    <div class="crear-cuenta__nombre">
                        <input id="nombre" type="text" placeholder="Nombre" name="nombre" maxlength="80" autocomplete="given-name" required>
                        <input type="text" placeholder="Apellido" name="apellido" maxlength="80" autocomplete="family-name" required>
                    </div>
                </div>
                <div class="contenido-login__input">
                    <label for="gamertag">Gamertag:</label>
                    <input id="gamertag" type="text" name="gamertag" maxlength="40" required>
                </div>
                <div class="contenido-login__input">
                    <label for="fecha-nacimiento">Fecha de nacimiento:</label>
                    <input id="fecha-nacimiento" type="date" name="fecha-nacimiento" max="<?= date('Y-m-d') ?>" required>
                </div>
                <div class="contenido-login__input">
                    <label for="genero">Género:</label>
                    <select class="crear-cuenta__select" id="genero" name="genero" required>
                        <option value="" disabled selected>Selecciona</option>
                        <option value="1">Masculino</option>
                        <option value="2">Femenino</option>
                        <option value="3">Prefiero no decirlo</option>
                    </select>
                </div>
                <div class="contenido-login__input">
                    <label for="email">Correo electrónico:</label>
                    <input id="email" type="email" name="email" maxlength="254" autocomplete="email" required>
                </div>
                <div class="contenido-login__input">
                    <label for="pass">Contraseña:</label>
                    <input id="pass" type="password" name="pass" minlength="8" autocomplete="new-password" required>
                </div>
                <button class="contenido-login__boton" type="submit">Crear cuenta</button>
            </form>
        </div>
    </div>
</body>
</html>
