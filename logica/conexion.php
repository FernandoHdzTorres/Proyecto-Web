<?php

$host     = getenv('MYSQLHOST')     ?: ($_ENV['MYSQLHOST']     ?? $_SERVER['MYSQLHOST']);
$dbname   = getenv('MYSQLDATABASE') ?: ($_ENV['MYSQLDATABASE'] ?? $_SERVER['MYSQLDATABASE']);
$username = getenv('MYSQLUSER')     ?: ($_ENV['MYSQLUSER']     ?? $_SERVER['MYSQLUSER']);
$password = getenv('MYSQLPASSWORD') ?: ($_ENV['MYSQLPASSWORD'] ?? $_SERVER['MYSQLPASSWORD']);
$port     = getenv('MYSQLPORT')     ?: ($_ENV['MYSQLPORT']     ?? $_SERVER['MYSQLPORT']);
$charset = 'utf8mb4';
$dsn = "mysql:host=$host;port=$port;dbname=$dbname;charset=$charset";

try {
    $conexion = new PDO($dsn, $username, $password);
    $conexion->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    error_log('Gamerbook database connection failed: ' . $e->getMessage());
}
?>