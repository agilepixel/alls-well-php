<?php

namespace AgilePixel\AllsWell\Laravel\State;

use AgilePixel\AllsWell\Laravel\LaravelConfig;
use AgilePixel\AllsWell\State\StateStore;
use Illuminate\Contracts\Container\Container;

/**
 * Picks where the state lives: the table when it exists, or whenever the
 * site uses a key (so a missing table is reported, not papered over), and
 * the cache only when a token-mode site's table is known to be missing. A
 * database that can't be reached keeps the database store, which is then
 * unreadable, so the core stops instead of reporting past a hidden pause.
 */
final class StateStores
{
    /**
     * alls_well.store: "database" or "cache" overrides the choice above for
     * an app that has no database (or no cache worth keeping state in).
     *
     * @param  bool  $keyMode
     * @return StateStore
     */
    public static function make(Container $app, $keyMode)
    {
        $setting = $app->make('config')->get('alls_well.store');
        $setting = is_string($setting) ? strtolower(trim($setting)) : 'auto';
        $database = new DatabaseStateStore($app->make('db'));

        if ($setting === 'database') {
            return $database;
        }
        if ($setting === 'cache') {
            return self::cache($app);
        }

        if ($keyMode || $database->problem() !== DatabaseStateStore::NO_TABLE) {
            return $database;
        }

        return self::cache($app);
    }

    /**
     * @return CacheStateStore
     */
    private static function cache(Container $app)
    {
        return new CacheStateStore($app->make('cache')->store(), (string) LaravelConfig::siteUrl($app));
    }

    /**
     * Never throws.
     *
     * @return bool
     */
    public static function hasTable(Container $app)
    {
        try {
            return (bool) $app->make('db')->connection()->getSchemaBuilder()->hasTable(DatabaseStateStore::TABLE);
        } catch (\Throwable $e) {
            return false;
        }
    }
}
