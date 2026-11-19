<?php

$host = 'localhost';
$dbname = 'gamerbook';
$username = 'root';
$password = 'root';
$port = '8889';
$charset = 'utf8mb4';
$dsn = "mysql:host=$host;port=$port;dbname=$dbname;charset=$charset";

try {
    $conexion = new PDO($dsn, $username, $password);
    $conexion->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    error_log('Gamerbook database connection failed: ' . $e->getMessage());
}
