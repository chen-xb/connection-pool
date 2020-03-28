<?php
declare(strict_types=1);

namespace Chenxb\ConnectionPool\Connector;

use Chenxb\ConnectionPool\Contracts\ConnectorInterface;

class RedisConnector implements ConnectorInterface
{

    /**
     * 创建连接
     *
     * @param array $config
     * @return \Redis
     */
    public function connect(array $config)
    {
        $connection = new \Redis();
        $connection->connect($config['host'], $config['port']);

        $connection['auth'] and $connection->auth($connection['auth']);
        $connection['db'] and $connection->select($connection['db']);

        return $connection;
    }

    /**
     * 断开连接
     *
     * @param $connection
     */
    public function disconnect($connection)
    {
        $connection->close();
    }

    /**
     * 检查是否还连接
     *
     * @param $connection
     * @return bool
     */
    public function isConnected($connection): bool
    {
        return $connection->ping() == 'PONG';
    }

    /**
     * 重置连接
     *
     * @param $connection
     * @param array $config
     */
    public function reset($connection, array $config)
    {

    }

    /**
     * 检查资源类型
     *
     * @param $connection
     * @return bool
     */
    public function validate($connection): bool
    {
        return $connection instanceof \Redis;
    }
}