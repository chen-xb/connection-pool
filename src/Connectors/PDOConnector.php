<?php
declare(strict_types=1);

namespace Chenxb\ConnectionPool\Connector;

use Chenxb\ConnectionPool\Contracts\ConnectorInterface;

class PDOConnector implements ConnectorInterface
{

    /**
     * 连接
     *
     * @param array $config
     * @return \PDO
     */
    public function connect(array $config)
    {
        $dsn = "{$config['type']}:host={$config['host']};port={$config['port']}";
        $dsn = "{$dsn};dbname={$config['dbname']};charset={$config['charset']}";

        $connection = new \PDO($dsn, $config['username'], $config['password'], [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC
        ]);

        return $connection;
    }

    /**
     * 关闭连接
     *
     * @param $connection
     */
    public function disconnect($connection)
    {
        $connection = null;
    }

    /**
     * 判断连接是否可用
     *
     * @param $connection
     * @return bool
     */
    public function isConnected($connection): bool
    {
        try {
            $connection->getAttribute(\PDO::ATTR_SERVER_VERSION);
        } catch (\PDOException $e) {
            return false;
        }

        return true;
    }

    /**
     * 重置资源
     *
     * @param $connection
     * @param array $config
     */
    public function reset($connection, array $config)
    {

    }

    /**
     * 检查连接资源是否是pdo
     *
     * @param $connection
     * @return bool
     */
    public function validate($connection): bool
    {
        return $connection instanceof \PDO;
    }
}