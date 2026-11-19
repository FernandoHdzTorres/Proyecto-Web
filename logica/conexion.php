<?php
$host = getenv('MYSQLHOST') ?: 'localhost';
$port = getenv('MYSQLPORT') ?: '8889';
$db   = getenv('MYSQLDATABASE') ?: 'gamerbook';
$user = getenv('MYSQLUSER') ?: 'root';
$pass = getenv('MYSQLPASSWORD') ?: 'root';
$charset = 'utf8mb4';
$dsn = "mysql:host=$host;port=$port;dbname=$dbname;charset=$charset";

try {
    $conexion = new PDO($dsn, $username, $password);
    $conexion->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    error_log('Gamerbook database connection failed: ' . $e->getMessage());
}
?>