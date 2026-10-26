<?php

namespace AgilePixel\AllsWell\Report;

/**
 * What the app runs on: the platform version and the database server.
 */
final class Runtime
{
    /**
     * @param  string  $platform
     * @return string
     */
    public static function platformVersion($platform, ComposerPackages $packages)
    {
        if ($platform === 'laravel') {
            $version = $packages->version('laravel/framework');
            if ($version !== null && $version !== '') {
                return $version;
            }
        }

        return PHP_VERSION;
    }

    /**
     * @param  \PDO|null  $pdo
     * @return array{type: string, version: string}|null
     */
    public static function database($pdo)
    {
        if (! $pdo instanceof \PDO) {
            return null;
        }

        try {
            $driver = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
            $server = $pdo->getAttribute(\PDO::ATTR_SERVER_VERSION);
            if (! is_string($driver) || ! is_string($server)) {
                return null;
            }

            return self::parse($driver, $server);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * @param  string  $driver
     * @param  string  $server
     * @return array{type: string, version: string}|null
     */
    private static function parse($driver, $server)
    {
        $type = $driver;
        $version = null;

        if ($driver === 'mysql') {
            if (stripos($server, 'MariaDB') !== false) {
                $type = 'mariadb';
                $version = preg_match('/(\d+\.\d+\.\d+)-MariaDB/i', $server, $m) ? $m[1] : null;
            } else {
                $version = preg_match('/^\d+\.\d+\.\d+/', $server, $m) ? $m[0] : null;
            }
        } elseif ($driver === 'pgsql') {
            $parts = preg_split('/\s+/', trim($server));
            $version = $parts[0] !== '' ? $parts[0] : null;
        } elseif ($driver === 'sqlite') {
            $version = trim($server) !== '' ? trim($server) : null;
        }

        return $version === null ? null : ['type' => $type, 'version' => $version];
    }
}
