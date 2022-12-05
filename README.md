# 简单的web端爬虫框架
基于链接挖掘方案的web爬虫框架，实现了多进程采集和xpath选择器等基础功能。

### 软件架构

1. guzzlehttp
2. php>=7.2
3. 多进程模型
4. Redis

### 安装

1. 在线安装

    ```shell
    composer require icy8/web-spider
    ```

2. 离线安装

    下载项目解压到目录`vendor/icy8`

    编辑`composer.json`：

    ```json
    {
        "require": {
            "icy8/web-spider": "dev-master"
        },
        "repositories": [
            {
            "type": "path",
            "url": "vendor/icy8/web-spider"
            }
        ]
    }
    ```

    安装
    ```shell
    composer install
    ```

### 原理

1. 链接挖掘
    - 框架本身是基于链接的，大致分为列表页和内容页链接。其中内容页链接最为重要，是数据的来源页。
    - “挖掘”理解为字面意思，从页面中收集链接，然后筛选我们需要的链接，将其写入链接池中，这是一个简单的筛选过程。

2. 链接池
    - 内置有基于`SplQueue`、`Redis`作为链接存储的容器。
    - 基于`SplQueue`的容器：这个原理很简单，就是利用`PHP`自带的`SplQueue`类来作为队列，存储合格的链接。他是基于单进程的，多进程不建议使用这个容器来运行框架。
    - 基于`Redis`的容器：利用的是`列表`的数据类型存储，因为Redis大部分都是原子操作，所以可以利用`Redis`做多进程的数据容器。

3. 选择器
    - 数据的匹配是基于各种选择器的，目前设想的有`xpath`和`正则`两种方案。
    - xpath：基于`DOMXPath`封装的选择器，xpath相关语法需自行在网上查阅。
    - regex：基于正则表达式的选择器，暂未实现。

### 说明

1. 本身只提供数据匹配，产生的数据需要自行写入库。
2. 多进程采集要使用`Redis`作为链接池容器，否则会造成页面的重复采集，如果不介意这个问题的话可以忽略这一条。
3. 多进程不支持windows系统。
4. 不建议同一个脚本同时重复执行。

### 用例

1. 编辑脚本`crawl.php`

    ```php
    <?php
    use icy8\WebSpider\Spider;

    include "../vendor/autoload.php";
    $config        = [
        'entry_link'        => ['https://www.xxx.com'],// 入口页面
        'content_link_rule' => [// 内容页链接规则
            '#^https://www\.xxx\.com/e/\d+/[a-zA-Z0-9]+\.shtml$#is',
        ],
        'list_link_rule'    => [// 列表页链接规则
            '#^https\://www\.xxx\.com/xiaoxue[/]*$#is',
            '#^https\://www\.xxx\.com/chuzhong[/]*$#is',
        ],
        'fields'            => [// 数据字段
            [
                'name'     => 'title',// 字段名
                'rule'     => '//h1[@class="h_title"]',// 匹配规则
                'required' => true,// 字段不为空
                // 如果你希望得到字段值后做一些过滤操作，那么可以定义handle闭包实现。
                // 如果rule没有配置，handle闭包也会被执行，此时的$value参数为null。
                // 也就是说，你如果不希望框架自动匹配时，你可以定义handle闭包来自行匹配字段值。
                'handle'   => function ($page, $value) {
                    return trim($value);
                },
            ],
            [
                'name'     => 'content',
                'rule'     => '//div[@class="con_content"]',
                'required' => true,
            ],
        ],
    ];
    // 配置链接池，如果不配置默认使用SplQueue
    $spider->queueConfig = [
        'type'     => 'redis',
        'host'     => '127.0.0.1',
        'password' => '',
    ];
    
    //$spider->count = 30;// 启动30个进程并行采集
    //$spider->daemonize = true;// 守护进程运行
    $spider        = new Spider($config);
    $spider->on('onContentPage', function ($page, $data) {
        // 这里会得到匹配到的数据$data
        // 如果希望自己来匹配，这时候将fields数组留空即可，此时闭包的$data值是false
        // $page是Page类的实例，附带了内容页的完整html
    });
    $spider->run();
    ```

2. 执行脚本

    ```shell
    # start stop status
    # -d 守护进程运行
    php crawl.php start -d
    ```


### Xpath选择器

用例：

```php
<?php
$rule = '//a/@href';
$html = '...';
// 取一条记录
$data = Xpath::shortcut()->setData($html)->fetch($rule);
// 获取多条记录，如果匹配成功将永远返回一个数组
$data = Xpath::shortcut()->setData($html)->select($rule);
// 如果你希望返回元素的html内容，比如规则//a，你希望返回<a>text</a>
$data = Xpath::shortcut()->setData($html)->select($rule, true);
```

### 事件

1. onLinkDigging：链接入栈前触发，如果返回的是false，那么会阻止这一次的链接入栈。

    |参数|类型|说明|
    |:-:|---|---|
    |$spider|\icy8\WebSpider\Spider|当前框架的实例|
    |$link|\icy8\WebSpider\entity\Link|准备入栈的链接实例|

2. onPageReady：页面请求成功后和匹配字段前触发，在这个事件中可以通过改变`$page->response`属性提前过滤一些不需要的内容。

    |参数|类型|说明|
    |:-:|---|---|
    |$page|\icy8\WebSpider\entity\Page|请求的页面实例|

3. onContentPage：页面请求成功后和字段匹配完成后触发。

    |参数|类型|说明|
    |:-:|---|---|
    |$page|\icy8\WebSpider\entity\Page|请求的页面实例|
    |$data|Array\|boolean|匹配得到的数据值，如果匹配失败会返回`false`|

4. onListPage：页面请求成功后触发

    |参数|类型|说明|
    |:-:|---|---|
    |$page|\icy8\WebSpider\entity\Page|请求的页面实例|

5. onEntryPage：页面请求成功后触发

    |参数|类型|说明|
    |:-:|---|---|
    |$page|\icy8\WebSpider\entity\Page|请求的页面实例|

6. onLinkRetry：页面请求失败后触发，如果返回的是`false`，那么会阻止这一次重试操作。

    |参数|类型|说明|
    |:-:|---|---|
    |$link|\icy8\WebSpider\entity\Link|请求的页面实例|

### 实体

目前内置的实体都是要求实现`\icy8\WebSpider\entity\QueueEntity`接口的，因为部分实体如`\icy8\WebSpider\entity\Link`需要入栈链接池的，需要一个接口标准。

1. \icy8\WebSpider\entity\Page：网页内容

    |属性|类型|说明|
    |---|---|---|
    |$link|\icy8\WebSpider\entity\Link|当前页的链接信息|
    |$response|string|当前页面的html|
    |$context|string|来源页的html，暂未实现|
    |$linkDigging|boolean|是否在这个页面挖掘链接，默认`true`|

2. \icy8\WebSpider\entity\Link：链接信息

    |属性|类型|说明|
    |---|---|---|
    |$url|string|链接的`url`|
    |$retryTimes|int|重试的次数，默认`0`|
    |$depth|int|当前连接的深度，暂未实现|
    |$method|string|规定请求时的方式，暂未实现|
    |$proxy|string|规定请求时的代理地址，暂未实现|
    |$type|int|链接类型，取值`Link::CONTENT_PAGE`、`Link::LIST_PAGE`、`Link::ENTRY_PAGE`|
    |$referer|string|来源页的`url`|

### 选择器接口

如果你想自定义一个选择器，你需要实现接口`\icy8\WebSpider\selectors\Selector`。接口说明：


```php
<?php

namespace icy8\WebSpider\selectors;


interface Selector
{
    // 设置选择器的数据内容，如html
    public function setData($data);

    // 执行选择，返回Array
    public function select($rule);
    
    // 执行选择，返回一条记录
    public function fetch($rule);

    // 返回一个新的实例，即new static()
    public static function shortcut();
}

```