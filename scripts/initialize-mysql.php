<?php

$root = dirname(__DIR__);
$env = parse_ini_file($root.'/.env', false, INI_SCANNER_RAW);
$pdo = new PDO('mysql:host=127.0.0.1;port=3307', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$password = $pdo->quote($env['DB_PASSWORD']);
$pdo->exec('CREATE DATABASE IF NOT EXISTS revilo CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$pdo->exec("CREATE USER IF NOT EXISTS 'revilo'@'127.0.0.1' IDENTIFIED BY $password");
$pdo->exec("GRANT ALL PRIVILEGES ON revilo.* TO 'revilo'@'127.0.0.1'");
$pdo->exec('CREATE DATABASE IF NOT EXISTS revilo_testing CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$pdo->exec("GRANT ALL PRIVILEGES ON revilo_testing.* TO 'revilo'@'127.0.0.1'");
$pdo->exec("ALTER USER 'root'@'localhost' IDENTIFIED BY ".$pdo->quote($env['MYSQL_ROOT_PASSWORD']));
echo "Project databases and restricted development user created.\n";
