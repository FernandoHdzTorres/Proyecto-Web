<?php

$host     = getenv('MYSQLHOST')     ?: ($_ENV['MYSQLHOST']     ?? ($_SERVER['MYSQLHOST']     ?? 'localhost'));
$dbname   = getenv('MYSQLDATABASE') ?: ($_ENV['MYSQLDATABASE'] ?? ($_SERVER['MYSQLDATABASE'] ?? 'gamerbook'));
$username = getenv('MYSQLUSER')     ?: ($_ENV['MYSQLUSER']     ?? ($_SERVER['MYSQLUSER']     ?? 'root'));
$password = getenv('MYSQLPASSWORD') ?: ($_ENV['MYSQLPASSWORD'] ?? ($_SERVER['MYSQLPASSWORD'] ?? 'root'));
$port     = getenv('MYSQLPORT')     ?: ($_ENV['MYSQLPORT']     ?? ($_SERVER['MYSQLPORT']     ?? '8889'));
$charset = 'utf8mb4';
$dsn = "mysql:host=$host;port=$port;dbname=$dbname;charset=$charset";

try {
    $conexion = new PDO($dsn, $username, $password);
    $conexion->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    error_log('Gamerbook database connection failed: ' . $e->getMessage());
}
?>