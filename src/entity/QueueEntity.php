<?php


namespace icy8\WebSpider\entity;


interface QueueEntity
{
    public static function fromArray($data);

    public static function fromJson($data);

    public function hash();
}
