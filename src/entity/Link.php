<?php

namespace icy8\WebSpider\entity;

class Link implements QueueEntity
{
    const CONTENT_PAGE = 1;
    const LIST_PAGE    = 2;
    const ENTRY_PAGE   = 3;

    public $url;
    public $retryTimes = 0;
    public $depth      = 1;
    public $method     = 'get';
    public $proxy      = '';
    public $type       = self::ENTRY_PAGE;
    public $referer    = '';

    public static function fromArray($data)
    {
        $link      = new static();
        $link->url = $data['url'] ?? null;
        if (isset($data['retryTimes'])) {
            $link->retryTimes = $data['retryTimes'];
        }
        if (isset($data['depth'])) {
            $link->depth = $data['depth'];
        }
        if (isset($data['method'])) {
            $link->method = $data['method'];
        }
        if (isset($data['proxy'])) {
            $link->proxy = $data['proxy'];
        }
        if (isset($data['type'])) {
            $link->type = $data['type'];
        }
        if (isset($data['referer'])) {
            $link->referer = $data['referer'];
        }
        return $link;
    }

    public static function fromJson($data)
    {
        return self::fromArray(json_decode($data, true));
    }

    public function hash()
    {
        return md5($this->url);
    }

    public function __toString()
    {
        return json_encode($this);
    }
}
