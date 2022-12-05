<?php


namespace icy8\WebSpider\queue;


use icy8\WebSpider\entity\Link;
use icy8\WebSpider\entity\QueueEntity;

class Redis implements Queue
{
    protected $redis;
    protected $key       = 'icy8:web-spider:queue:';
    protected $hashKey   = 'icy8:web-spider:queue:hash';
    protected $queueHash = [];
    /* @var QueueEntity $entity */
    protected $entity = Link::class;

    public function __construct($config = [])
    {
        $this->redis = new \Redis();
        $this->redis->connect(
            $config['host'],
            $config['port'] ?? 6379,
            $config['timeout'] ?? 0.0,
            $config['reserved'] ?? null,
            $config['retry_interval'] ?? 0,
            $config['read_timeout'] ?? 0.0
        );
        if (isset($config['password']) && trim($config['password']) !== '') {
            $this->redis->auth($config['password']);
        }
        $this->key .= $config['key'] ?? 'default';
    }

    public function setEntity($class)
    {
        $this->entity = $class;
        return $this;
    }

    public function push(QueueEntity $value)
    {
        $res = $this->redis->rPush($this->key, (string)$value);
        if ($res) {
            $key = $value->hash();
            $this->redis->hSetNx($this->hashKey, $key, 'stacked');
        }
        return $res;
    }

    public function unshift(QueueEntity $value)
    {
        $res = $this->redis->lPush($this->key, (string)$value);
        if ($res) {
            $key = $value->hash();
            $this->redis->hSetNx($this->hashKey, $key, 'stacked');
        }
        return $res;
    }

    public function pop()
    {
        $value = $this->redis->rPop($this->key);
        if ($value) {
            return $this->entity::fromJson($value);
        }
        return false;
    }

    public function shift()
    {
        $value = $this->redis->lPop($this->key);
        if ($value) {
            return $this->entity::fromJson($value);
        }
        return false;
    }

    public function isStacked(QueueEntity $value)
    {
        $field = $value->hash();
        return !$this->redis->hSetNx($this->hashKey, $field, 'stacked');// HEXISTS 和 HGET都不是原子操作
    }

    public function clear()
    {
        return $this->redis->del($this->key, $this->hashKey);
    }

    public function isEmpty()
    {
        return $this->redis->lLen($this->key) <= 0;
    }

    public function __destruct()
    {
        $this->redis->close();
    }
}
