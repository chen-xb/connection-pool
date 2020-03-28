<?php

namespace Chenxb\ConnectionPool\Contracts;

interface ConnectorInterface
{
    public function connect(array $config);

    public function disconnect($connection);

    public function isConnected($connection): bool;

    public function reset($connection, array $config);

    public function validate($connection): bool;
}