<?php

namespace icy8\WebSpider\queue;

use icy8\WebSpider\entity\QueueEntity;

interface Queue
{

    public function push(QueueEntity $value);

    public function unshift(QueueEntity $value);

    public function pop();

    public function shift();

    public function isEmpty();

    public function isStacked(QueueEntity $value);
}
