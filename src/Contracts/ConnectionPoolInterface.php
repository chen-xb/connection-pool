<?php

namespace Chenxb\ConnectionPool\Contracts;

interface ConnectionPoolInterface
{
    /**
     * 归还连接资源
     *
     * @param $connection
     * @return bool
     */
    public function push($connection): bool;

    /**
     * 获取连接资源
     *
     * @return mixed
     */
    public function pop();

    /**
     * 关闭连接池
     *
     * @return bool
     */
    public function close(): bool;
}