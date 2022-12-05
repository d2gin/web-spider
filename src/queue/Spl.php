<?php


namespace icy8\WebSpider\queue;


use icy8\WebSpider\entity\QueueEntity;

class Spl implements Queue
{

    protected $instance;
    protected $queueHash = [];

    public function __construct()
    {
        $this->instance = new \SplQueue();
    }

    public function push(QueueEntity $value)
    {
        $this->instance->push($value);
        $key                   = $value->hash();
        $this->queueHash[$key] = $value;
        return true;
    }

    public function unshift(QueueEntity $value)
    {
        $this->instance->unshift($value);
        $key                   = $value->hash();
        $this->queueHash[$key] = $value;
        return true;
    }

    public function pop()
    {
        return $this->instance->pop();
    }

    public function shift()
    {
        return $this->instance->shift();
    }

    public function isStacked(QueueEntity $value)
    {
        $key = $value->hash();
        return isset($this->queueHash[$key]);
    }

    public function isEmpty()
    {
        return $this->instance->isEmpty();
    }
}
