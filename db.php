<?php
// db.php
$host = 'localhost';
$db   = 'u3490612_db_vpn'; 
$user = 'u3490612_db_admin';
$pass = 'Tema1212rus';

try {
    // Используем PDO для защиты от SQL-инъекций
    $pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8mb4", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    header('Content-Type: application/json');
    die(json_encode(['success' => false, 'message' => 'Ошибка подключения к БД']));
}
?>