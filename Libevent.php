<?php

namespace chaser\reactor;

use Throwable;

/**
 * 基于 libevent 扩展的事件反应类
 *
 * @package chaser\reactor
 */
class Libevent extends Reactor
{
    /**
     * 事件库
     *
     * @var resource
     */
    protected $eventBase;

    /**
     * 初始化事件库
     */
    public function __construct()
    {
        $this->eventBase = event_base_new();
    }

    /**
     * @inheritDoc
     */
    protected function addReadData(int $intFd, $fd, callable $callback)
    {
        return $this->makeEvent($fd, EV_READ | EV_PERSIST, $callback, $fd);
    }

    /**
     * @inheritDoc
     */
    protected function addWriteData(int $intFd, $fd, callable $callback)
    {
        return $this->makeEvent($fd, EV_WRITE | EV_PERSIST, $callback, $fd);
    }

    /**
     * @inheritDoc
     */
    protected function addSignalData(int $key, int $signal, callable $callback)
    {
        return $this->makeEvent($signal, EV_SIGNAL | EV_PERSIST, $callback, $signal);
    }

    /**
     * @inheritDoc
     */
    protected function addIntervalData(int $timerId, int $seconds, callable $callback)
    {
        $interval = $seconds * 1000000;

        $event = $this->makeEvent(0, EV_TIMEOUT, fn() => $this->timerCallback($timerId, self::EV_INTERVAL), $timerId, $interval);

        return $event ? [$event, $callback, $interval] : false;
    }

    /**
     * @inheritDoc
     */
    protected function addTimeoutData(int $timerId, int $seconds, callable $callback)
    {
        $event = $this->makeEvent(0, EV_TIMEOUT, fn() => $this->timerCallback($timerId, self::EV_TIMEOUT), $timerId, $seconds * 1000000);
        return $event ? [$event, $callback, 0] : false;
    }

    /**
     * 添加事件
     *
     * @param mixed $fd
     * @param int $flags
     * @param callable $callback
     * @param null $arg
     * @param int $timeout
     * @return false|resource
     */
    protected function makeEvent($fd, int $flags, callable $callback, $arg = null, int $timeout = -1)
    {
        $event = event_new();

        // 准备事件
        if (!event_set($event, $fd, $flags, $callback, $arg)) {
            return false;
        }

        // 事件关联事件库
        if (!event_base_set($event, $this->eventBase)) {
            return false;
        }

        // 向事件集添加事件
        if (!event_add($event, $timeout)) {
            return false;
        }

        return $event;
    }

    /**
     * @inheritDoc
     */
    protected function delCallback(int $flag, int $key): bool
    {
        switch ($flag) {
            case self::EV_READ:
            case self::EV_WRITE:
            case self::EV_SIGNAL:
                return event_del($this->events[$flag][$key]);
            case self::EV_INTERVAL:
            case self::EV_TIMEOUT:
                return event_del($this->events[$flag][$key][0]);
        }
        return false;
    }

    /**
     * 定时器事件处理程序
     *
     * @param int $timerId
     * @param int $flag
     */
    public function timerCallback(int $timerId, int $flag)
    {
        if (isset($this->events[$flag][$timerId])) {

            [$event, $callback, $timeout] = $this->events[$flag][$timerId];

            if ($flag === self::EV_TIMEOUT) {
                $this->delTimeout($timerId);
            } else {
                event_add($event, $timeout);
            }

            try {
                $callback($timerId);
            } catch (Throwable $e) {
                exit(250);
            }
        }
    }

    /**
     * @inheritDoc
     */
    public function loop(): void
    {
        event_base_loop($this->eventBase);
    }

    /**
     * @inheritDoc
     */
    public function destroy(): void
    {
        event_base_loopbreak($this->eventBase);
    }
}
