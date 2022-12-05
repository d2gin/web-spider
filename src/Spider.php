<?php

namespace icy8\WebSpider;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use icy8\process\Daemon;
use icy8\process\Worker;
use icy8\WebSpider\entity\Link;
use icy8\WebSpider\entity\Page;
use icy8\WebSpider\queue\Redis;
use icy8\WebSpider\queue\Spl;
use icy8\WebSpider\selectors\Xpath;

class Spider
{
    protected $config      = [
        'entry_link'        => [],// 入口链接，完整的链接
        'content_link_rule' => [],// 内容页链接规则
        'list_link_rule'    => [],// 列表页链接规则
        'max_retry_times'   => 3,
        'fields'            => [],
    ];
    public    $queueConfig = [
        'type'     => 'redis',
        'host'     => '127.0.0.1',
        'password' => '',
    ];
    protected $queue;
    protected $currentPage;
    protected $guzzle;
    protected $event;
    public    $pidFile;
    protected $statisticsFile;
    protected $os          = 'linux';
    public    $count       = 1;
    public    $daemonize   = false;
    protected $statistics  = [
        'status'                      => 'finish',
        'pid'                         => '',
        'digging_total'               => 0,
        'content_page_total'          => 0,
        'list_page_total'             => 0,
        'complete_count'              => 0,
        'content_page_complete_count' => 0,
        'list_page_complete_count'    => 0,
    ];

    public function __construct($config = [])
    {
        if (\PHP_SAPI !== 'cli') {
            exit("请在命令行中运行 \n");
        }
        $this->event = new Event();
        $this->queue = $this->resolveQueue();
        $this->queue->clear();
        $this->guzzle = new Client();
        $this->config = array_merge($this->config, $config);
        if (\DIRECTORY_SEPARATOR === '\\') {
            $this->os = 'windows';
        }
        $this->statisticsFile = __DIR__ . '/../spider-statistics.log';

        // 清空日志文件
        register_shutdown_function(function () {
            if (!empty($this->pidFile) && is_file($this->pidFile)) {
                @unlink($this->pidFile);
            }
            $gl = glob(__DIR__ . '/../spider-statistics*.log');
            foreach ($gl as $file) {
                @unlink($file);
            }
        });
    }

    public function run()
    {
        global $argv;
        if (empty($this->pidFile)) {
            $backtrace     = \debug_backtrace();
            $_startFile    = $backtrace[\count($backtrace) - 1]['file'];
            $unique_prefix = \str_replace('/', '_', $_startFile);
            $this->pidFile = __DIR__ . '/../' . $unique_prefix . '.pid';
        }
        $command = $argv[1] ?? null;
        $opt     = $argv[2] ?? null;
        if ($command == 'start') {
            if ($opt == '-d') {
                $this->daemonize = true;
            }
            $this->start();
        } else if ($command == 'stop') {
            $pid = @file_get_contents($this->pidFile);
            if ($pid) {
                posix_kill($pid, SIGTERM);
            }
            exit(0);
        } else if ($command == 'status') {
            $this->displayStatisticUI();
            exit(0);
        }
    }

    /**
     * 启动
     * @throws \Exception
     */
    public function start()
    {
        $this->clearEcho();
        $this->displayUI();
        foreach ($this->config['entry_link'] as $url) {
            $link = Link::fromArray(['url' => $url]);
            $this->pushLink($link);
        }
        if ($this->daemonize && $this->os == 'linux') {
            // 守护进程
            $daemon = new Daemon();
            $daemon->start();
        }
        @file_put_contents($this->pidFile, getmypid());
        if ($this->count > 1 && $this->os == 'linux') {
            // 多进程模型
            $process        = new Worker();
            $process->total = $this->count;
            // 运行进程
            $process->run(function () {
                $this->statistics['pid'] = getmypid();
                $this->statisticsFile    = __DIR__ . '/../spider-statistics-' . $this->statistics['pid'] . '.log';
                $this->queue             = $this->resolveQueue();
                // 数据统计
                $this->saveStatisticsData();
                // 如果其他进程还在工作，则当前进程继续待命
                while (count(glob(__DIR__ . '/../spider-statistics-*.log'))) {
                    // 采集
                    $this->crawl();
                    $this->removeStatisticsFile();
                }
            });
        } else {
            $this->statistics['pid']    = getmypid();
            $this->statistics['status'] = 'running';
            $this->crawl();
            $this->statistics['status'] = 'finished';
            if (!$this->daemonize) {
                $this->removeStatisticsFile();
                echo $this->generateStatisticUI([$this->statistics]);
            } else $this->saveStatisticsData();
        }
    }

    /**
     * 链接挖掘
     * @param Page $page
     */
    public function linkDigging($page)
    {
        $result = Xpath::shortcut()->setData($page->response)->select('//a/@href');
        $domain = parse_url($page->link->url, PHP_URL_HOST);
        $scheme = parse_url($page->link->url, PHP_URL_SCHEME);
        foreach ($result as $url) {
            $completeUrl = $this->completeUrl($url, $domain, $scheme);
            $link        = null;
            if ($this->isContentUrl($completeUrl)) {
                $link = Link::fromArray(['type' => Link::CONTENT_PAGE]);
            } else if ($this->isListUrl($completeUrl)) {
                $link = Link::fromArray(['type' => Link::LIST_PAGE]);
            }
            if (!empty($link)) {
                $link->referer = $page->link->url;
                $link->url     = $completeUrl;
                if ($this->event->trigger('onLinkDigging', $this, $link) !== false && !$this->queue->isStacked($link)) {
                    $this->pushLink($link);
                }
            }
        }
    }

    /**
     * 采集
     */
    public function crawl()
    {
        $context = null;
        // 统计
        $this->statistics['status'] = 'running';
        $this->saveStatisticsData();
        /* @var Link $link */
        while ($link = $this->queue->shift()) {
            try {
                $response = $this->guzzle->get($link->url);
                $html     = $response->getBody()->getContents();
                $page     = Page::fromArray([
                                                'link'     => $link,
                                                'response' => $html,
                                                'context'  => $context,
                                            ]);
                $this->event->trigger('onPageReady', $page);
                if ($page->linkDigging) {
                    $this->linkDigging($page);
                }
                if ($page->link->type === Link::CONTENT_PAGE) {
                    $data = $this->getFields($page);
                    $this->statistics['content_page_complete_count']++;
                    $this->event->trigger('onContentPage', $page, $data);
                } else if ($page->link->type === Link::LIST_PAGE) {
                    $this->statistics['list_page_complete_count']++;
                    $this->event->trigger('onListPage', $page);
                } else if ($page->link->type === Link::ENTRY_PAGE) {
                    $this->event->trigger('onEntryPage', $page);
                }
                $this->statistics['complete_count']++;
                $this->saveStatisticsData();
            } catch (GuzzleException $e) {
                $link->retryTimes++;
                if ($link->retryTimes <= $this->config['max_retry_times'] && $this->event->trigger('onLinkRetry', $link) !== false) {
                    // 重试
                    $this->unshiftLink($link);
                }
            }
        }
        $this->statistics['status'] = 'finished';
        $this->saveStatisticsData();
    }

    public function getFields($page)
    {
        $response = $page->response;
        $fields   = $this->config['fields'];
        $data     = [];
        if (empty($fields)) {
            return false;
        }
        foreach ($fields as $field) {
            $name   = $field['name'] ?? '';
            $rule   = $field['rule'] ?? '';
            $handle = $field['handle'] ?? null;// 可以用作数据过滤，先取名handle吧
            if (!$name || (!$handle && !$rule)) {
                return false;
            }
            $required      = $field['required'] ?? false;
            $selectorClass = $field['selector'] ?? Xpath::class;
            $value         = null;
            if (class_exists($selectorClass)) {
                $selector = new $selectorClass;
            } else {
                $selector = Xpath::shortcut();
            }
            if ($rule) {
                $value = $selector->setData($response)->fetch($rule);
            }
            if ($handle) {
                $value = call_user_func_array($handle, [$page, $value]);
            }
            if ($value) {
                $data[$name] = $value;
            } else if ($required) {
                return false;
            }
        }
        return $data;
    }

    /**
     * @param Link $link
     */
    public function pushLink($link)
    {
        if ($link->retryTimes < 1) {
            $this->statistics['digging_total']++;
            if ($link->type === Link::CONTENT_PAGE) {
                $this->statistics['content_page_total']++;
            } else if ($link->type === Link::LIST_PAGE) {
                $this->statistics['list_page_total']++;
            }
        }
        $this->queue->push($link);
    }

    public function unshiftLink($link)
    {
        if ($link->retryTimes < 1) {
            $this->statistics['digging_total']++;
            if ($link->type === Link::CONTENT_PAGE) {
                $this->statistics['content_page_total']++;
            } else if ($link->type === Link::LIST_PAGE) {
                $this->statistics['list_page_total']++;
            }
        }
        $this->queue->unshift($link);
    }

    public function isContentUrl($url)
    {
        if ($this->config['content_link_rule'] === false) {
            return false;
        } else if (
            empty($this->config['content_link_rule'])
            || array_values($this->config['content_link_rule'])[0] == 'x'
            || array_values($this->config['content_link_rule'])[0] == '*'
        ) {
            // 通配链接
            return true;
        } else if ($this->matchUrl($url, $this->config['content_link_rule'])) {
            return true;
        }
        return false;
    }

    public function isListUrl($url)
    {
        if ($this->config['list_link_rule'] === false) {
            return false;
        } else if (
            empty($this->config['list_link_rule'])
            || array_values($this->config['list_link_rule'])[0] == 'x'
            || array_values($this->config['list_link_rule'])[0] == '*'
        ) {
            // 通配链接
            return false;
        } else if ($this->matchUrl($url, $this->config['list_link_rule'])) {
            return true;
        }
        return false;
    }

    public function matchUrl($url, $regexs)
    {
        foreach ($regexs as $regex) {
            if (preg_match($regex, $url)) {
                return true;
            }
        }
        return false;
    }

    /**
     * url补全
     * @param $url
     * @param $domain
     * @param $scheme
     * @return string
     */
    public function completeUrl($url, $domain, $scheme)
    {
        $parsed_url = parse_url($url);
        if (empty($parsed_url['host'])) {
            $parsed_url['host'] = $domain;
        }
        if (empty($parsed_url['scheme'])) {
            $parsed_url['scheme'] = $scheme;
        }
        $scheme   = isset($parsed_url['scheme']) ? $parsed_url['scheme'] . '://' : '';
        $host     = isset($parsed_url['host']) ? $parsed_url['host'] : '';
        $port     = isset($parsed_url['port']) ? ':' . $parsed_url['port'] : '';
        $user     = isset($parsed_url['user']) ? $parsed_url['user'] : '';
        $pass     = isset($parsed_url['pass']) ? ':' . $parsed_url['pass'] : '';
        $pass     = ($user || $pass) ? "$pass@" : '';
        $path     = isset($parsed_url['path']) ? $parsed_url['path'] : '';
        $query    = isset($parsed_url['query']) ? '?' . $parsed_url['query'] : '';
        $fragment = isset($parsed_url['fragment']) ? '#' . $parsed_url['fragment'] : '';
        return "$scheme$user$pass$host$port$path$query$fragment";
    }

    public function on($name, $event)
    {
        $this->event->on($name, $event);
        return $this;
    }

    public function resolveQueue()
    {
        if (in_array($this->queueConfig['type'], ['redis', 'spl'])) {
            return new Redis($this->queueConfig);
        } else if (class_exists($this->queueConfig['type'])) {
            return new  $this->queueConfig['type']($this->queueConfig);
        }
        throw new \Exception('Queue class not found.');
    }

    public function clearEcho()
    {
        $arr = array(27, 91, 72, 27, 91, 50, 74);
        foreach ($arr as $a) {
            print chr($a);
        }
    }

    public function generateStatisticUI($list = [])
    {
        $panel = '---------------Spider Status---------------' . PHP_EOL;
        $panel .= "pid\tstatus\tdigging\tcomplete" . PHP_EOL;
        foreach ($list as $item) {
            $panel .= $item['pid'] . "\t";
            $panel .= $item['status'] . "\t";
            $panel .= $item['digging_total'] . "\t";
            $panel .= $item['complete_count'] . PHP_EOL;
        }
        return $panel;
    }

    public function displayStatisticUI()
    {
        try {
            $gl    = glob(dirname(__FILE__) . '/../spider-statistics-*.log');
            $panel = [];
            foreach ($gl as $log) {
                $panel[] = json_decode(@file_get_contents($log), true);
            }
            echo $this->generateStatisticUI($panel);
        } catch (\Throwable $e) {
        }
    }

    public function displayUI()
    {
        $panel = '---------------Spider Panel---------------' . PHP_EOL;
        $panel .= "target: " . implode(',', $this->config['entry_link']) . PHP_EOL;
        $panel .= "task: " . $this->count . PHP_EOL;
        $panel .= "date: " . date('Y-m-d H:i:s') . PHP_EOL;
        echo $panel;
    }

    public function saveStatisticsData()
    {
        $fp = fopen($this->statisticsFile, 'w+');
        flock($fp, LOCK_EX);
        fwrite($fp, json_encode($this->statistics));
        flock($fp, LOCK_UN);
        fclose($fp);
    }

    public function removeStatisticsFile()
    {
        @unlink($this->statisticsFile);
    }


}
