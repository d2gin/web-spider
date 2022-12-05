<?php


namespace icy8\WebSpider\selectors;


interface Selector
{
    public function setData($data);

    public function select($rule);

    public function fetch($rule);

    public static function shortcut();
}
