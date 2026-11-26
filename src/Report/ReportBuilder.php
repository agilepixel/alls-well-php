<?php

namespace AgilePixel\AllsWell\Report;

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

    const COMPONENT_TYPES = ['core', 'plugin', 'theme', 'module', 'package'];

    /**
     * The check keys only the package itself reports.
     */
    const PACKAGE_CHECKS = [DevPackagesCheck::KEY, 'addon.components_trimmed', 'addon.invalid_checks'];

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
            'addon_version' => $this->config->addonVersion(),
            'reported_at' => gmdate('c', $now),
            'reason' => $reason,
            'site' => [
                'url' => (string) $this->config->siteUrl(),
                'name' => self::cut($this->config->siteName(), 255),
                'environment' => self::environment($this->config->environment()),
                'multisite' => $this->config->multisite(),
            ],
            'runtime' => [
                'platform_version' => $this->platformVersion(),
                'language' => ['name' => 'php', 'version' => $this->phpVersion()],
                'database' => $this->database(),
                'server' => null,
            ],
        ];

        if (in_array($reason, self::LIGHT_REASONS, true)) {
            return $report;
        }

        $trimmed = null;
        $components = $this->components();
        if ($components !== null) {
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
        $version = $this->config->platformVersion();
        if ($version === null) {
            $version = Runtime::platformVersion($this->config->platform(), $this->packages, $this->phpVersion());
        }

        return $version === '' || strlen($version) > 64 ? $this->phpVersion() : $version;
    }

    /**
     * @return string
     */
    private function phpVersion()
    {
        $version = $this->config->phpVersion();

        return $version === null ? PHP_VERSION : $version;
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
     * The host's components, then Composer's: the host knows its plugins by
     * their own names, so its entry replaces Composer's for the same package,
     * and its core makes Composer's framework an ordinary package.
     *
     * @return array<int, array<string, mixed>>|null Null when either couldn't be read: sending what is left would clear the stored rest.
     */
    private function components()
    {
        $components = $this->hostComponents();
        if ($components === null || ! $this->packages->isAvailable()) {
            return null;
        }

        $replaced = [];
        $taken = [];
        $core = false;
        foreach ($components as $component) {
            $taken[$component['type'].':'.$component['slug']] = true;
            $core = $core || $component['type'] === 'core';
            if ($component['package']['ecosystem'] === 'Packagist') {
                $replaced[strtolower($component['package']['name'])] = true;
            }
        }

        foreach ($this->packages->components() as $component) {
            if ($core) {
                $component['type'] = 'package';
            }
            if (isset($replaced[strtolower($component['slug'])]) || isset($taken[$component['type'].':'.$component['slug']])) {
                continue;
            }
            if (strlen($component['slug']) > 191 || strlen((string) $component['name']) > 255) {
                continue;
            }
            foreach (['version', 'latest_version'] as $field) {
                $component[$field] = self::shortText($component[$field], 64);
            }
            $components[] = $component;
        }

        return $components;
    }

    /**
     * What the host's resolver returned, one per type and slug. Null when it
     * failed or none of its items could be used; an empty list is one.
     *
     * @return array<int, array<string, mixed>>|null
     */
    private function hostComponents()
    {
        $resolver = $this->config->components();
        if ($resolver === null) {
            return [];
        }

        try {
            $items = call_user_func($resolver);
            if (! is_array($items) && ! $items instanceof \Traversable) {
                return null;
            }

            $components = [];
            $seen = 0;
            foreach ($items as $item) {
                $seen++;
                $component = self::hostComponent($item);
                if ($component !== null) {
                    $components += [$component['type'].':'.$component['slug'] => $component];
                }
            }

            return $seen > 0 && $components === [] ? null : array_values($components);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * @param  mixed  $item
     * @return array<string, mixed>|null
     */
    private static function hostComponent($item)
    {
        if (! is_array($item) || ! isset($item['type'], $item['slug'], $item['package']) || ! is_array($item['package'])) {
            return null;
        }

        $slug = self::shortText($item['slug'], 191);
        $ecosystem = self::shortText(isset($item['package']['ecosystem']) ? $item['package']['ecosystem'] : null, 32);
        $package = self::shortText(isset($item['package']['name']) ? $item['package']['name'] : null, 191);
        if (! in_array($item['type'], self::COMPONENT_TYPES, true) || $slug === null || $ecosystem === null || $package === null) {
            return null;
        }

        return [
            'type' => $item['type'],
            'slug' => $slug,
            'name' => isset($item['name']) && is_string($item['name']) ? self::cut($item['name'], 255) : null,
            'version' => self::shortText(isset($item['version']) ? $item['version'] : null, 64),
            'latest_version' => self::shortText(isset($item['latest_version']) ? $item['latest_version'] : null, 64),
            'active' => ! isset($item['active']) || $item['active'] !== false,
            'auto_update' => isset($item['auto_update']) && is_bool($item['auto_update']) ? $item['auto_update'] : null,
            'package' => ['ecosystem' => $ecosystem, 'name' => $package],
        ];
    }

    /**
     * @param  mixed  $value
     * @param  int  $limit
     * @return string|null Null unless it is text the protocol has room for.
     */
    private static function shortText($value, $limit)
    {
        return is_string($value) && $value !== '' && strlen($value) <= $limit ? $value : null;
    }

    /**
     * @param  int|null  $trimmedFrom  The component count before trimming, when it was trimmed.
     * @return array<int, array<string, mixed>>
     */
    private function checks($trimmedFrom)
    {
        $host = array_diff_key($this->config->hostChecks(), array_flip(self::PACKAGE_CHECKS));
        $registry = $this->config->checks();
        unset($registry[DevPackagesCheck::KEY]);
        $registry[DevPackagesCheck::KEY] = new DevPackagesCheck($this->packages, $this->config->environment());

        $results = (new CheckRunner)->run($registry, $host);

        if ($trimmedFrom !== null) {
            $results = self::makeRoom($results);
            $results[] = CheckResult::info('Components were trimmed to the limit', 'low', $trimmedFrom)
                ->toArray('addon.components_trimmed');
        }

        return $results;
    }

    /**
     * Drops the last result but the package's own when the list is full.
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
            if (! in_array($results[$i]['key'], self::PACKAGE_CHECKS, true)) {
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
