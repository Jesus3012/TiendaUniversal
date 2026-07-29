<?php
declare(strict_types=1);

function api_db(): mysqli
{
    static $db = null;
    if ($db instanceof mysqli) {
        return $db;
    }
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $host = getenv('TP_DB_HOST') ?: '127.0.0.1';
    $port = (int) (getenv('TP_DB_PORT') ?: 3306);
    $name = getenv('TP_DB_NAME') ?: 'tienda_pescadores';
    $user = getenv('TP_DB_USER') ?: 'root';
    $password = getenv('TP_DB_PASSWORD') ?: '';
    $db = new mysqli($host, $user, $password, $name, $port);
    $db->set_charset('utf8mb4');
    $db->query("SET time_zone = '+00:00'");
    return $db;
}

