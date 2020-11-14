<?php
namespace yzh52521\cron;

use Carbon\Carbon;
use Closure;
use Cron\CronExpression;
use think\facade\Cache;
use DateTimeZone;
abstract class Task
{
    /** @var DateTimeZone|string 时区 */
    public $timezone;
    /** @var string 任务周期 */
    public $expression = '* * * * *';

    /** @var bool 任务是否可以重叠执行 */
    public $withoutOverlapping = false;

    /** @var int 最大执行时间(重叠执行检查用) */
    public $expiresAt = 1440;

    /** @var bool 分布式部署 是否仅在一台服务器上运行 */
    public $onOneServer = false;

    protected $filters = [];
    protected $rejects = [];

    //@var array 附加参数
    public $payload = [];

    //@var string 任务处理结果
    public $statusDesc = null;

    public function __construct($taskTime=false)
    {
        if($taskTime){
            $this->expression = $taskTime;
        }else{
            $this->configure();
        }
    }

    /**
     * 是否到期执行
     * @return bool
     */
    public function isDue()
    {
        $date = Carbon::now($this->timezone);
        return CronExpression::factory($this->expression)->isDue($date->toDateTimeString());
    }

    /**
     * 下一次执行
     * @return bool
     */
    public function NextRun($dateTime=null)
    {
        return CronExpression::factory($this->expression)->getNextRunDate($dateTime)->format('Y-m-d H:i:s');
    }


    /**
     * 配置任务
     */
    protected function configure()
    {

    }

    /**
     * 执行任务
     * @return mixed
     */
    abstract protected function execute();

    final public function run()
    {
        if ($this->withoutOverlapping &&
            !$this->createMutex()) {
            return false;
        }

        register_shutdown_function(function () {
            $this->removeMutex();
        });

        try {
            return $this->execute();
        } finally {
            $this->removeMutex();
        }

    }

    /**
     * 过滤
     * @return bool
     */
    public function filtersPass()
    {
        foreach ($this->filters as $callback) {
            if (!call_user_func($callback)) {
                return false;
            }
        }

        foreach ($this->rejects as $callback) {
            if (call_user_func($callback)) {
                return false;
            }
        }

        return true;
    }

    /**
     * 任务标识
     */
    public function mutexName()
    {
        return 'task-' . sha1(static::class);
    }

    protected function removeMutex()
    {
        return Cache::rm($this->mutexName());
    }

    protected function createMutex()
    {
        $name = $this->mutexName();
        if (!Cache::has($name)) {
            Cache::set($name, true, $this->expiresAt);
            return true;
        }
        return false;
    }

    protected function existsMutex()
    {
        return Cache::has($this->mutexName());
    }

    public function when(Closure $callback)
    {
        $this->filters[] = $callback;

        return $this;
    }

    public function skip(Closure $callback)
    {
        $this->rejects[] = $callback;

        return $this;
    }

    public function withoutOverlapping($expiresAt = 1440)
    {
        $this->withoutOverlapping = true;

        $this->expiresAt = $expiresAt;

        return $this->skip(function () {
            return $this->existsMutex();
        });
    }

    public function onOneServer()
    {
        $this->onOneServer = true;

        return $this;
    }

    /**
     * 设置区间时间
     *
     * @param  string $startTime
     * @param  string $endTime
     * @return $this
     */
    public function between($startTime, $endTime)
    {
        return $this->when($this->inTimeInterval($startTime, $endTime));
    }

    /**
     * 排除区间时间
     *
     * @param  string $startTime
     * @param  string $endTime
     * @return $this
     */
    public function unlessBetween($startTime, $endTime)
    {
        return $this->skip($this->inTimeInterval($startTime, $endTime));
    }

    private function inTimeInterval($startTime, $endTime)
    {
        return function () use ($startTime, $endTime) {
            return Date::now($this->timezone)->between(
                Date::parse($startTime, $this->timezone),
                Date::parse($endTime, $this->timezone),
                true
            );
        };
    }

    /**
     * 按小时执行
     *
     * @return $this
     */
    public function hourly()
    {
        return $this->spliceIntoPosition(1, 0);
    }

    /**
     * 按小时延期执行
     *
     * @param  int $offset
     * @return $this
     */
    public function hourlyAt($offset)
    {
        return $this->spliceIntoPosition(1, $offset);
    }

    /**
     * 按天执行
     *
     * @return $this
     */
    public function daily()
    {
        return $this->spliceIntoPosition(1, 0)
            ->spliceIntoPosition(2, 0);
    }

    /**
     * 指定时间执行
     *
     * @param  string $time
     * @return $this
     */
    public function at($time)
    {
        return $this->dailyAt($time);
    }

    /**
     * 指定时间执行
     *
     * @param  string $time
     * @return $this
     */
    public function dailyAt($time)
    {
        $segments = explode(':', $time);

        return $this->spliceIntoPosition(2, (int)$segments[0])
            ->spliceIntoPosition(1, count($segments) == 2 ? (int)$segments[1] : '0');
    }

    /**
     * 每天执行两次
     *
     * @param  int $first
     * @param  int $second
     * @return $this
     */
    public function twiceDaily($first = 1, $second = 13)
    {
        $hours = $first . ',' . $second;

        return $this->spliceIntoPosition(1, 0)
            ->spliceIntoPosition(2, $hours);
    }

    /**
     * 工作日执行
     *
     * @return $this
     */
    public function weekdays()
    {
        return $this->spliceIntoPosition(5, '1-5');
    }

    /**
     * 周末执行
     *
     * @return $this
     */
    public function weekends()
    {
        return $this->spliceIntoPosition(5, '0,6');
    }

    /**
     * 星期一执行
     *
     * @return $this
     */
    public function mondays()
    {
        return $this->days(1);
    }

    /**
     * 星期二执行
     *
     * @return $this
     */
    public function tuesdays()
    {
        return $this->days(2);
    }

    /**
     * 星期三执行
     *
     * @return $this
     */
    public function wednesdays()
    {
        return $this->days(3);
    }

    /**
     * 星期四执行
     *
     * @return $this
     */
    public function thursdays()
    {
        return $this->days(4);
    }

    /**
     * 星期五执行
     *
     * @return $this
     */
    public function fridays()
    {
        return $this->days(5);
    }

    /**
     * 星期六执行
     *
     * @return $this
     */
    public function saturdays()
    {
        return $this->days(6);
    }

    /**
     * 星期天执行
     *
     * @return $this
     */
    public function sundays()
    {
        return $this->days(0);
    }

    /**
     * 按周执行
     *
     * @return $this
     */
    public function weekly()
    {
        return $this->spliceIntoPosition(1, 0)
            ->spliceIntoPosition(2, 0)
            ->spliceIntoPosition(5, 0);
    }

    /**
     * 指定每周的时间执行
     *
     * @param  int $day
     * @param  string $time
     * @return $this
     */
    public function weeklyOn($day, $time = '0:0')
    {
        $this->dailyAt($time);

        return $this->spliceIntoPosition(5, $day);
    }

    /**
     * 按月执行
     *
     * @return $this
     */
    public function monthly()
    {
        return $this->spliceIntoPosition(1, 0)
            ->spliceIntoPosition(2, 0)
            ->spliceIntoPosition(3, 1);
    }

    /**
     * 指定每月的执行时间
     *
     * @param  int $day
     * @param  string $time
     * @return $this
     */
    public function monthlyOn($day = 1, $time = '0:0')
    {
        $this->dailyAt($time);

        return $this->spliceIntoPosition(3, $day);
    }

    /**
     * 每月执行两次
     *
     * @param  int $first
     * @param  int $second
     * @return $this
     */
    public function twiceMonthly($first = 1, $second = 16)
    {
        $days = $first . ',' . $second;

        return $this->spliceIntoPosition(1, 0)
            ->spliceIntoPosition(2, 0)
            ->spliceIntoPosition(3, $days);
    }

    /**
     * 按季度执行
     *
     * @return $this
     */
    public function quarterly()
    {
        return $this->spliceIntoPosition(1, 0)
            ->spliceIntoPosition(2, 0)
            ->spliceIntoPosition(3, 1)
            ->spliceIntoPosition(4, '*/3');
    }

    /**
     * 按年执行
     *
     * @return $this
     */
    public function yearly()
    {
        return $this->spliceIntoPosition(1, 0)
            ->spliceIntoPosition(2, 0)
            ->spliceIntoPosition(3, 1)
            ->spliceIntoPosition(4, 1);
    }

    /**
     * 每分钟执行
     *
     * @return $this
     */
    public function everyMinute()
    {
        return $this->spliceIntoPosition(1, '*');
    }

    /**
     * 每5分钟执行
     *
     * @return $this
     */
    public function everyFiveMinutes()
    {
        return $this->spliceIntoPosition(1, '*/5');
    }

    /**
     * 每10分钟执行
     *
     * @return $this
     */
    public function everyTenMinutes()
    {
        return $this->spliceIntoPosition(1, '*/10');
    }

    /**
     * 每30分钟执行
     *
     * @return $this
     */
    public function everyThirtyMinutes()
    {
        return $this->spliceIntoPosition(1, '0,30');
    }

    /**
     * 按周设置天执行
     *
     * @param  array|mixed $days
     * @return $this
     */
    public function days($days)
    {
        $days = is_array($days) ? $days : func_get_args();

        return $this->spliceIntoPosition(5, implode(',', $days));
    }

    /**
     * 设置时区
     *
     * @param  \DateTimeZone|string $timezone
     * @return $this
     */
    public function timezone($timezone)
    {
        $this->timezone = $timezone;

        return $this;
    }

    protected function spliceIntoPosition($position, $value)
    {
        $segments = explode(' ', $this->expression);

        $segments[$position - 1] = $value;

        return $this->expression(implode(' ', $segments));
    }
}
