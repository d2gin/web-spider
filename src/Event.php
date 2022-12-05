<?php

namespace icy8\WebSpider;

class Event
{
    /**
     * @var array[][] 事件队列
     */
    protected $events = [];

    public function on($name, $event = null, $once = false)
    {
        if ($event) {
            $this->events[$name][] = ['event' => $event, 'once' => $once];
        } else if (is_array($name)) {
            $this->events = array_merge($this->events, $name);
        }
        return $this;
    }

    public function once($name, $event = null)
    {
        return $this->on($name, $event, true);
    }

    public function off($name, $handle = null)
    {
        if ($handle) {
            foreach ($this->events as $n => $es) {
                foreach ($es as $k => $e) {
                    if ($handle == $e['event']) {
                        unset($this->events[$name][$k]);
                    }
                }
            }
        } else unset($this->events[$name]);
        return $this;
    }

    public function trigger($name/*, $param = []*/)
    {
        $queue = $this->events[$name] ?? [];
        if (!is_array($queue)) {
            return true;
        }
        $result = [];
        $param  = array_slice(func_get_args(), 1);
        foreach ($queue as $row) {
            $event = $row['event'];
            $once  = $row['once'];
            $catch = call_user_func_array($event, $param);
            if ($once) {
                unset($this->events[$name][$key]);
            }
            if ($catch === false) {
                return $catch;
            }
            $result[] = $catch;
        }
        return count($result) === 1 ? current($result) : $result;
    }
}
