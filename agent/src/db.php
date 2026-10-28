<?php
function testDbConnection($host, $name, $user, $pass, $port = 3306) {
    try {
        $pdo = new PDO(
            "mysql:host=$host;port=$port;dbname=$name;charset=utf8mb4",
            $user,
            $pass,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_TIMEOUT => 5,
            ]
        );
        $pdo->query('SELECT 1');
        return true;
    } catch (PDOException $e) {
        return $e->getMessage();
    }
}
