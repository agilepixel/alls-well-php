<?php

namespace AgilePixel\AllsWell\Report;

use AgilePixel\AllsWell\AllsWell;
use AgilePixel\AllsWell\Checks\CheckResult;
use AgilePixel\AllsWell\Checks\CheckRunner;
use AgilePixel\AllsWell\Checks\DevPackagesCheck;
use AgilePixel\AllsWell\Config;
use AgilePixel\AllsWell\Support\Mask;

/**
 * Builds a protocol-v1 report. Every value it emits fits the schema, and it
 * never throws: whatever the owner's callables do, the report still builds.
 */
final class ReportBuilder
{
    const LIGHT_REASONS = ['deactivated', 'uninstalled'];

    const MAX_COMPONENTS = 1000;

    const MAX_CHECKS = 100;

    const MAX_ADMINISTRATORS = 200;

    /**
     * @var Config
     */
    private $config;

    /**
     * @var ComposerPackages
     */
    private $packages;

    public function __construct(Config $config, ComposerPackages $packages)
    {
        $this->config = $config;
        $this->packages = $packages;
    }

    /**
     * Anything the owner's checks, PDO callable or administrators resolver
     * print is discarded, buffers they leave open included: cron would mail
     * it on every run. A buffer PHP won't let go of stops the unwinding
     * there, rather than looping for ever.
     *
     * @param  string  $reason
     * @param  int  $now
     * @return array<string, mixed>
     */
    public function build($reason, $now)
    {
        $level = ob_get_level();
        ob_start();

        try {
            return $this->assemble($reason, $now);
        } finally {
            while (ob_get_level() > $level) {
                if (! @ob_end_clean()) {
                    break;
                }
            }
        }
    }

    /**
     * @param  string  $reason
     * @param  int  $now
     * @return array<string, mixed>
     */
    private function assemble($reason, $now)
    {
        $report = [
            'protocol' => 1,
            'platform' => $this->config->platform(),
            'addon_version' => AllsWell::VERSION,
            'reported_at' => gmdate('c', $now),
            'reason' => $reason,
            'site' => [
                'url' => (string) $this->config->siteUrl(),
                'name' => self::cut($this->config->siteName(), 255),
                'environment' => self::environment($this->config->environment()),
                'multisite' => null,
            ],
            'runtime' => [
                'platform_version' => $this->platformVersion(),
                'language' => ['name' => 'php', 'version' => PHP_VERSION],
                'database' => $this->database(),
                'server' => null,
            ],
        ];

        if (in_array($reason, self::LIGHT_REASONS, true)) {
            return $report;
        }

        $trimmed = null;
        if ($this->packages->isAvailable()) {
            $components = $this->components();
            $trimmed = count($components) > self::MAX_COMPONENTS ? count($components) : null;
            $report['components'] = array_slice($components, 0, self::MAX_COMPONENTS);
        }
        $report['checks'] = $this->checks($trimmed);

        $administrators = $this->administrators();
        if ($administrators !== null) {
            $report['administrators'] = $administrators;
        }

        return $report;
    }

    /**
     * @return string
     */
    private function platformVersion()
    {
        $version = Runtime::platformVersion($this->config->platform(), $this->packages);

        return $version === '' || strlen($version) > 64 ? PHP_VERSION : $version;
    }

    /**
     * @return array{type: string, version: string}|null
     */
    private function database()
    {
        $database = Runtime::database($this->config->pdo());

        if ($database === null || strlen($database['type']) > 32 || strlen($database['version']) > 64) {
            return null;
        }

        return $database;
    }

    /**
     * @param  string|null  $environment
     * @return string|null
     */
    private static function environment($environment)
    {
        return $environment === null || strlen($environment) > 32 ? null : $environment;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function components()
    {
        $components = [];

        foreach ($this->packages->components() as $component) {
            if (strlen($component['slug']) > 191 || strlen((string) $component['name']) > 255) {
                continue;
            }
            foreach (['version', 'latest_version'] as $field) {
                if (is_string($component[$field]) && strlen($component[$field]) > 64) {
                    $component[$field] = null;
                }
            }
            $components[] = $component;
        }

        return $components;
    }

    /**
     * @param  int|null  $trimmedFrom  The component count before trimming, when it was trimmed.
     * @return array<int, array<string, mixed>>
     */
    private function checks($trimmedFrom)
    {
        $registry = $this->config->checks();
        unset($registry[DevPackagesCheck::KEY]);
        $registry[DevPackagesCheck::KEY] = new DevPackagesCheck($this->packages, $this->config->environment());

        $results = (new CheckRunner)->run($registry);

        if ($trimmedFrom !== null) {
            $results = self::makeRoom($results);
            $results[] = CheckResult::info('Components were trimmed to the limit', 'low', $trimmedFrom)
                ->toArray('addon.components_trimmed');
        }

        return $results;
    }

    /**
     * Drops the last ordinary result when the list is full, keeping the package's own.
     *
     * @param  array<int, array<string, mixed>>  $results
     * @return array<int, array<string, mixed>>
     */
    private static function makeRoom(array $results)
    {
        if (count($results) < self::MAX_CHECKS) {
            return $results;
        }

        for ($i = count($results) - 1; $i >= 0; $i--) {
            if (strpos($results[$i]['key'], 'addon.') !== 0) {
                unset($results[$i]);

                break;
            }
        }

        return array_values($results);
    }

    /**
     * Null (so the key is left out) when there is no resolver, it failed, or
     * none of the items it returned could be used: an empty list would
     * clear the site's stored administrators. An empty iterable is sent as [].
     *
     * @return array<int, array<string, string|null>>|null
     */
    private function administrators()
    {
        $resolver = $this->config->administrators();
        if ($resolver === null) {
            return null;
        }

        try {
            $items = call_user_func($resolver);
            if (! is_array($items) && ! $items instanceof \Traversable) {
                return null;
            }

            $administrators = [];
            $seen = 0;
            foreach ($items as $item) {
                $seen++;
                $administrator = self::administrator($item);
                if ($administrator === null) {
                    continue;
                }
                $administrators[] = $administrator;
                if (count($administrators) >= self::MAX_ADMINISTRATORS) {
                    break;
                }
            }

            return $seen > 0 && $administrators === [] ? null : $administrators;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * @param  mixed  $item
     * @return array<string, string|null>|null
     */
    private static function administrator($item)
    {
        if (! is_array($item) || ! isset($item['id'], $item['login'])) {
            return null;
        }
        if (! is_scalar($item['id']) || is_bool($item['id']) || ! is_string($item['login'])) {
            return null;
        }

        $id = (string) $item['id'];
        $login = Mask::login($item['login']);
        if ($id === '' || strlen($id) > 64 || $login === '') {
            return null;
        }

        return [
            'id' => $id,
            'login' => $login,
            'registered_at' => self::registeredAt(isset($item['registered_at']) ? $item['registered_at'] : null),
        ];
    }

    /**
     * @param  mixed  $value
     * @return string|null
     */
    private static function registeredAt($value)
    {
        if ($value instanceof \DateTimeInterface) {
            return gmdate(DATE_ATOM, $value->getTimestamp());
        }
        if (is_string($value)) {
            $time = strtotime($value);

            return $time === false ? null : gmdate(DATE_ATOM, $time);
        }

        return null;
    }

    /**
     * @param  string  $value
     * @param  int  $length
     * @return string
     */
    private static function cut($value, $length)
    {
        return function_exists('mb_substr') ? mb_substr($value, 0, $length, 'UTF-8') : substr($value, 0, $length);
    }
}
