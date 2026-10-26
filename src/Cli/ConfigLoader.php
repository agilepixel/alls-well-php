<?php

namespace AgilePixel\AllsWell\Cli;

use AgilePixel\AllsWell\Config;
use AgilePixel\AllsWell\State\FileStateStore;
use AgilePixel\AllsWell\State\StateStore;

/**
 * Builds the command's Config from the project: an optional alls-well.php,
 * then .env, then .env.local, then the process environment, each overriding
 * the one before. Only the ALLS_WELL_* variables and APP_ENV are read.
 */
final class ConfigLoader
{
    const FILE_NAME = 'alls-well.php';

    /**
     * @param  array<string, mixed>  $env  The process environment.
     * @param  string|null  $configFile  A config file named on the command line; relative to the project root.
     *
     * @throws ConfigFileException When a config file is missing or does not return an array.
     */
    public static function load(array $env, string $projectRoot, ?string $configFile, ?StateStore $stateOverride = null): Config
    {
        $root = rtrim($projectRoot, '/\\');
        $settings = self::fromFile($root, $configFile);

        $merged = array_merge(
            DotEnv::read($root.'/.env'),
            DotEnv::read($root.'/.env.local'),
            self::strings($env)
        );

        foreach (['token' => 'ALLS_WELL_TOKEN', 'key' => 'ALLS_WELL_KEY', 'origin' => 'ALLS_WELL_URL', 'site_url' => 'ALLS_WELL_SITE_URL', 'site_name' => 'ALLS_WELL_SITE_NAME'] as $setting => $variable) {
            if (isset($merged[$variable]) && trim($merged[$variable]) !== '') {
                $settings[$setting] = $merged[$variable];
            }
        }

        $environment = self::environment($merged, $settings);
        if ($environment !== null) {
            $settings['environment'] = $environment;
        }

        if (isset($merged['ALLS_WELL_ENABLED']) && trim($merged['ALLS_WELL_ENABLED']) !== '') {
            $settings['enabled'] = ! in_array(strtolower(trim($merged['ALLS_WELL_ENABLED'])), ['0', 'false', 'off', 'no'], true);
        }

        if (isset($merged['ALLS_WELL_STATE_PATH']) && trim($merged['ALLS_WELL_STATE_PATH']) !== '') {
            $settings['state'] = new FileStateStore(self::absolute($root, trim($merged['ALLS_WELL_STATE_PATH'])));
        }

        if ($stateOverride !== null) {
            $settings['state'] = $stateOverride;
        }

        return Config::fromArray($settings);
    }

    /**
     * ALLS_WELL_ENVIRONMENT from any layer wins. APP_ENV, which most apps
     * commit in .env, only fills in when the config file names no
     * environment, so the file's choice can take effect.
     *
     * @param  array<string, string>  $merged
     * @param  array<string, mixed>  $settings  From the config file.
     */
    private static function environment(array $merged, array $settings): ?string
    {
        if (isset($merged['ALLS_WELL_ENVIRONMENT']) && trim($merged['ALLS_WELL_ENVIRONMENT']) !== '') {
            return $merged['ALLS_WELL_ENVIRONMENT'];
        }

        if (isset($settings['environment']) && is_string($settings['environment']) && trim($settings['environment']) !== '') {
            return null;
        }

        return isset($merged['APP_ENV']) && trim($merged['APP_ENV']) !== '' ? $merged['APP_ENV'] : null;
    }

    /**
     * @return array<string, mixed>
     */
    private static function fromFile(string $root, ?string $configFile): array
    {
        if ($configFile !== null) {
            $path = self::absolute($root, $configFile);
            if (! is_file($path)) {
                throw new ConfigFileException('The config file '.$configFile.' does not exist.');
            }
        } else {
            $path = $root.'/'.self::FILE_NAME;
            if (! is_file($path)) {
                return [];
            }
        }

        $settings = self::requireFile($path);
        if (! is_array($settings)) {
            throw new ConfigFileException('The config file '.($configFile !== null ? $configFile : self::FILE_NAME).' must return an array.');
        }

        return $settings;
    }

    /**
     * Includes the file where none of the loader's variables are in scope,
     * discarding anything it prints so the command's output stays its own. A
     * buffer it leaves that PHP won't let go of stops the unwinding there.
     *
     * @return mixed
     */
    private static function requireFile(string $path)
    {
        $level = ob_get_level();
        ob_start();

        try {
            return (static function () use ($path) {
                return require $path;
            })();
        } finally {
            while (ob_get_level() > $level) {
                if (! @ob_end_clean()) {
                    break;
                }
            }
        }
    }

    private static function absolute(string $root, string $path): string
    {
        if ($path !== '' && ($path[0] === '/' || $path[0] === '\\' || preg_match('/\A[A-Za-z]:[\/\\\\]/', $path) === 1)) {
            return $path;
        }

        return $root.'/'.$path;
    }

    /**
     * @param  array<string, mixed>  $env
     * @return array<string, string>
     */
    private static function strings(array $env): array
    {
        $strings = [];

        foreach ($env as $key => $value) {
            if (is_string($key) && is_string($value) && ($key === 'APP_ENV' || strncmp($key, 'ALLS_WELL_', 10) === 0)) {
                $strings[$key] = $value;
            }
        }

        return $strings;
    }
}
