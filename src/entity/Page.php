<?php

namespace icy8\WebSpider\entity;

class Page implements QueueEntity
{
    /* @var Link $link */
    public $link;
    public $response;
    public $context;// 上下文
    public $linkDigging = true;// 链接挖掘

    public static function fromArray($data)
    {
        $page           = new static();
        $page->link     = $data['link'] ?? null;
        $page->response = $data['response'] ?? null;
        $page->context  = $data['context'] ?? null;
        return $page;
    }

    public static function fromJson($data)
    {
        return self::fromArray(json_decode($data, true));
    }

    public function __toString()
    {
        return json_encode($this);
    }

    public function hash()
    {
        return uniqid();
    }
}
