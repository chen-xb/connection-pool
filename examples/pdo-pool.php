<?php

require __DIR__ . '/../vendor/autoload.php';

use Chenxb\ConnectionPool\ConnectionPool;
use Chenxb\ConnectionPool\Connector\PDOConnector;

Swoole\Runtime::enableCoroutine();
Co\run(function () {
    $pdoConnectionPool = ConnectionPool::make([
        'min_active' => 1,
        'max_active' => 5,
    ], new PDOConnector, [
        'type' => 'mysql',
        'host' => '127.0.0.1',
        'port' => 3306,
        'dbname' => 'web_user',
        'username' => 'homestead',
        'password' => 'secret',
        'charset' => 'utf8mb4'
    ]);

    $pdo = $pdoConnectionPool->pop();
    $userInfoRow = $pdo->query('SELECT id_card FROM user_info WHERE id = 48')->fetch();

    print_r($userInfoRow);
});


