<?php

namespace AgilePixel\AllsWell\Laravel\Checks;

use AgilePixel\AllsWell\Laravel\State\CacheStateStore;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Contracts\Cache\Repository;

/**
 * The times scheduled tasks failed in the last day, kept in the cache.
 *
 * Times only: a task's name or command can hold arguments.
 */
final class ScheduleFailures
{
    const CACHE_KEY = 'alls_well.schedule_failures';

    const WINDOW = 86400;

    const LIMIT = 100;

    /**
     * @var Repository
     */
    private $cache;

    /**
     * @var bool|null
     */
    private $eventExists;

    /**
     * @param  bool|null  $eventExists  Whether Laravel has the ScheduledTaskFailed event; looked up when null.
     */
    public function __construct(Repository $cache, $eventExists = null)
    {
        $this->cache = $cache;
        $this->eventExists = $eventExists;
    }

    /**
     * Whether an empty count means "none failed": the event exists and the
     * cache keeps what the listener wrote between runs.
     *
     * @return bool
     */
    public function canKnow()
    {
        $eventExists = $this->eventExists !== null ? $this->eventExists : class_exists(ScheduledTaskFailed::class);

        return $eventExists && CacheStateStore::outlivesProcess($this->cache);
    }

    /**
     * @param  int  $now
     * @return void
     */
    public function record($now)
    {
        try {
            $times = $this->within($now);
            $times[] = (int) $now;
            $this->cache->put(self::CACHE_KEY, array_slice($times, -self::LIMIT), self::WINDOW);
        } catch (\Throwable $e) {
            return;
        }
    }

    /**
     * @param  int  $now
     * @return int
     */
    public function recent($now)
    {
        try {
            return count($this->within($now));
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * @param  int  $now
     * @return int[]
     */
    private function within($now)
    {
        $stored = $this->cache->get(self::CACHE_KEY);
        $times = [];

        foreach (is_array($stored) ? $stored : [] as $time) {
            if (is_int($time) && $time > $now - self::WINDOW) {
                $times[] = $time;
            }
        }

        return $times;
    }
}
