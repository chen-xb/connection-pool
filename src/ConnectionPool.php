<?php

namespace Chenxb\ConnectionPool;

use Chenxb\ConnectionPool\Contracts\ConnectionPoolInterface;
use Chenxb\ConnectionPool\Contracts\ConnectorInterface;
use Chenxb\ConnectionPool\Exceptions\PopConnectionException;
use Chenxb\ConnectionPool\Exceptions\RuntimeException;
use Swoole\Coroutine;
use Swoole\Coroutine\Channel;
use Swoole\Timer;

class ConnectionPool implements ConnectionPoolInterface
{

    /**
     * channel操作的超时时间
     */
    const CHANNEL_TIMEOUT = 0.001;

    /**
     * 连接资源上次操作时间
     */
    const CONNECTION_LAST_ACTIVE_TIME = 'lastActiveTime';

    /**
     * @var ConnectionPoolInterface
     */
    protected static $instance;

    /**
     * 连接池
     *
     * @var Channel
     */
    protected $pool;

    /**
     * 是否已经关闭
     *
     * @var bool
     */
    protected $closed = false;

    /**
     * 最小连接数
     *
     * @var int
     */
    protected $minActive;

    /**
     * 最大连接数
     *
     * @var int
     */
    protected $maxActive;

    /**
     * 从连接池获取连接对象的最长等待时间
     *
     * @var int
     */
    protected $maxWaitTime;

    /**
     * 最大空闲时间
     *
     * @var int
     */
    protected $maxIdleTime;

    /**
     * 空闲检查时间
     *
     * @var int
     */
    protected $idleCheckInterval;

    /**
     * 连接器
     *
     * @var ConnectorInterface
     */
    protected $connector;

    /**
     * 连接数数量
     *
     * @var int
     */
    protected $connectionCount;

    /**
     * 连接配置
     *
     * @var array
     */
    protected $connectionConfig;

    /**
     * 定时器ID
     *
     * @var int
     */
    protected $timerId;

    /**
     * 创建连接池
     *
     * @param array $poolConfig
     * @param ConnectorInterface $connector
     * @param array $connectionConfig
     * @return ConnectionPoolInterface
     */
    public static function make(array $poolConfig, ConnectorInterface $connector, array $connectionConfig): ConnectionPoolInterface
    {
        if (!static::$instance) {
            static::$instance = new static($poolConfig, $connector, $connectionConfig);
        }

        return static::$instance;
    }

    /**
     * 获取连接池对象
     *
     * @return ConnectionPoolInterface
     * @throws RuntimeException
     */
    public static function getInstance(): ConnectionPoolInterface
    {
        if (!static::$instance) {
            throw new RuntimeException('Please initialize the connection pool first, call ConnectionPool::make().');
        }

        return static::$instance;
    }

    /**
     * 创建连接池
     *
     * @param array $poolConfig
     * @param ConnectorInterface $connector
     * @param array $connectionConfig
     */
    protected function __construct(array $poolConfig, ConnectorInterface $connector, array $connectionConfig)
    {
        $this->initConfig($poolConfig, $connector, $connectionConfig);
        $this->initPool();
        $this->initHeartbeat();
    }

    /**
     * 销毁连接池
     */
    public function __destruct()
    {
        $this->close();
    }

    /**
     * 初始化配置信息
     *
     * @param array $poolConfig
     * @param ConnectorInterface $connector
     * @param array $connectionConfig
     */
    protected function initConfig(array $poolConfig, ConnectorInterface $connector, array $connectionConfig)
    {
        $this->minActive = $poolConfig['min_active'] ?? 20;
        $this->maxActive = $poolConfig['max_active'] ?? 100;
        $this->maxWaitTime = $poolConfig['max_wait_time'] ?? 5;
        $this->maxIdleTime = $poolConfig['max_idle_time'] ?? 30;
        $this->idleCheckInterval = $poolConfig['idle_check_interval'] ?? 10;
        $this->connector = $connector;
        $this->connectionConfig = $connectionConfig;
    }

    /**
     * 初始化连接池
     */
    protected function initPool()
    {
        $this->pool = new Channel($this->maxActive);
        Coroutine::create(function () {
            for ($i = 0; $i < $this->minActive; $i++) {
                $connection = $this->createConnection();
                if ($this->pool->push($connection, static::CHANNEL_TIMEOUT) === false) {
                    $this->removeConnection($connection);
                }
            }
        });
    }

    /**
     * 初始化心跳检查
     */
    protected function initHeartbeat()
    {
        $this->timerId = Timer::tick($this->idleCheckInterval * 1000, function () {
            $now = time();
            $validConnections = [];
            while (true) {
                if ($this->closed) {
                    break;
                }
                if ($this->connectionCount <= $this->minActive) {
                    break;
                }
                if ($this->pool->isEmpty()) {
                    break;
                }

                $connection = $this->pool->pop(static::CHANNEL_TIMEOUT);
                if (!$connection) {
                    break;
                }

                if ($now - $connection->{static::CONNECTION_LAST_ACTIVE_TIME} < $this->maxIdleTime) {
                    $validConnections[] = $connection;
                } else {
                    $this->removeConnection($connection);
                }
            }

            foreach ($validConnections as $validConnection) {
                if (!$this->pool->push($validConnection, static::CHANNEL_TIMEOUT)) {
                    $this->removeConnection($validConnection);
                }
            }
        });
    }

    /**
     * 创建连接资源
     *
     * @return mixed
     */
    protected function createConnection()
    {
        $this->connectionCount++;
        $connection = $this->connector->connect($this->connectionConfig);
        $connection->{static::CONNECTION_LAST_ACTIVE_TIME} = time();
        return $connection;
    }

    /**
     * 删除连接资源
     *
     * @param $connection
     */
    protected function removeConnection($connection)
    {
        $this->connectionCount--;
        Coroutine::create(function () use ($connection) {
            try {
                $this->connector->disconnect($connection);
            } catch (\Throwable $e) {
                // Ignore this exception.
            }
        });
    }

    /**
     * 获取连接数量
     *
     * @return int
     */
    public function getConnectionCount(): int
    {
        return $this->connectionCount;
    }

    /**
     * 获取空闲数量
     *
     * @return int
     */
    public function getIdleCount(): int
    {
        return $this->pool->length();
    }

    /**
     * 归还连接资源
     *
     * @param $connection
     * @return bool
     * @throws RuntimeException
     */
    public function push($connection): bool
    {
        if ($this->connector->validate($connection)) {
            throw new RuntimeException('Connection of unexpected type.');
        }

        if ($this->pool->isFull()) {
            $this->removeConnection($connection);
            return true;
        }

        $connection->{static::CONNECTION_LAST_ACTIVE_TIME} = time();
        if (!$this->pool->push($connection, static::CHANNEL_TIMEOUT)) {
            $this->removeConnection($connection);
        }

        return true;
    }

    /**
     * 获取连接资源
     *
     * @return mixed
     * @throws PopConnectionException
     */
    public function pop()
    {
        if ($this->pool->isEmpty() && $this->connectionCount < $this->maxActive) {
            return $this->createConnection();
        }

        $connection = $this->pool->pop($this->maxWaitTime);
        if (!$connection) {
            throw new PopConnectionException(sprintf(
                'pop the connection timeout in %.2f(s), connections in pool: %d, all connections: %d',
                $this->maxWaitTime,
                $this->pool->length(),
                $this->connectionCount
            ));
        }

        if (!$this->connector->isConnected($connection)) {
            $this->removeConnection($connection);
            $connection = $this->createConnection();
        }

        return $connection;
    }

    /**
     * 关闭连接池
     *
     * @return bool
     */
    public function close(): bool
    {
        if ($this->closed) {
            return true;
        }

        Timer::clear($this->timerId);

        Coroutine::create(function () {
            while (true) {
                if ($this->pool->isEmpty()) {
                    return true;
                }

                if ($connection = $this->pool->pop(static::CHANNEL_TIMEOUT)) {
                    $this->connector->disconnect($connection);
                }
            }
            $this->pool->close();

            return true;
        });

        return true;
    }
}