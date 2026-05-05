<?php

$host = 'localhost';
$user = 'root';
$pass = '';
$dbname = 'tienda_pescadores';

// Definir constante solo si no está definida previamente
if (!defined('DB_NAME')) {
    define('DB_NAME', $dbname);
}

$conn = new mysqli($host, $user, $pass, $dbname);
if ($conn->connect_error) {
    die("Error de conexion: ". $conn->connect_error);
}

?>