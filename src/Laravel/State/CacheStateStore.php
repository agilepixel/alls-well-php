<?php

namespace AgilePixel\AllsWell\Laravel\State;

use AgilePixel\AllsWell\State\State;
use AgilePixel\AllsWell\State\StateStore;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\NullStore;
use Illuminate\Contracts\Cache\Repository;

/**
 * Keeps the state in the application's cache: the fallback for a site in
 * token mode whose table hasn't been migrated.
 */
final class CacheStateStore implements StateStore
{
    const KEY = 'alls_well.state.';

    /** @var Repository */
    private $cache;

    /** @var string */
    private $key;

    /**
     * @param  string  $siteUrl  Namespaces the key, so apps sharing one cache keep their own state.
     */
    public function __construct(Repository $cache, $siteUrl = '')
    {
        $this->cache = $cache;
        $this->key = self::KEY.sha1((string) $siteUrl);
    }

    public function load()
    {
        try {
            $json = $this->cache->get($this->key);
        } catch (\Throwable $e) {
            return State::empty();
        }

        $data = is_string($json) ? json_decode($json, true) : null;

        return is_array($data) ? State::fromArray($data) : State::empty();
    }

    public function save(State $state)
    {
        $json = json_encode($state->toArray());
        $failed = ! is_string($json);

        if (! $failed) {
            try {
                $failed = $this->cache->forever($this->key, $json) === false;
            } catch (\Throwable $e) {
                $failed = true;
            }
        }

        if ($failed) {
            throw new \RuntimeException('The state could not be saved.');
        }
    }

    public function clear()
    {
        try {
            $this->cache->forget($this->key);
        } catch (\Throwable $e) {
            return;
        }
    }

    public function name()
    {
        return 'the cache';
    }

    /**
     * False for the array and null drivers, which forget everything between runs.
     */
    public function isPersistent()
    {
        return self::outlivesProcess($this->cache);
    }

    /**
     * @return bool
     */
    public static function outlivesProcess(Repository $cache)
    {
        if (! method_exists($cache, 'getStore')) {
            return true;
        }

        $store = $cache->getStore();

        return ! ($store instanceof ArrayStore || $store instanceof NullStore);
    }

    public function problem()
    {
        return null;
    }

    public function isUnreadable()
    {
        return false;
    }
}
