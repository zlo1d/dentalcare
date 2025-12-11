<?php
// config.php
$host = '127.0.0.1:3306'; // или 'localhost'
$dbname = 'dentalcare';
$username = 'root'; // ваш пользователь БД
$password = ''; // ваш пароль БД

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch(PDOException $e) {
    die("Ошибка подключения: " . $e->getMessage());
}