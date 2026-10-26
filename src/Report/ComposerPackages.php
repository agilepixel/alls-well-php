<?php

namespace AgilePixel\AllsWell\Report;

use Composer\InstalledVersions;

/**
 * The installed Composer packages, as protocol components.
 */
final class ComposerPackages
{
    /**
     * Frameworks in the order they are looked for: package => label.
     *
     * @var array<string, string>
     */
    const FRAMEWORKS = [
        'laravel/framework' => 'Laravel',
        'symfony/framework-bundle' => 'Symfony',
        'codeigniter4/framework' => 'CodeIgniter',
        'slim/slim' => 'Slim',
        'yiisoft/yii2' => 'Yii',
        'laminas/laminas-mvc' => 'Laminas',
        'cakephp/cakephp' => 'CakePHP',
    ];

    /**
     * @var array<string, array<string, mixed>>
     */
    private $packages = [];

    /**
     * @var array<string, true>
     */
    private $known = [];

    /**
     * @var bool
     */
    private $hasDev = false;

    /**
     * @var bool
     */
    private $available = true;

    /**
     * @param  array<string, mixed>|null  $installed  Shaped like one entry of InstalledVersions::getAllRawData().
     */
    public function __construct($installed = null)
    {
        try {
            if ($installed === null) {
                $installed = self::read();
            }
            if ($installed === null) {
                $this->available = false;

                return;
            }
            $this->load($installed);
        } catch (\Throwable $e) {
            $this->packages = [];
            $this->known = [];
            $this->hasDev = false;
            $this->available = false;
        }
    }

    /**
     * @return array<string, mixed>|null Null when Composer's data can't be found.
     */
    private static function read()
    {
        if (! class_exists('Composer\InstalledVersions')) {
            return null;
        }

        /** @var array<int, mixed> $datasets */
        $datasets = InstalledVersions::getAllRawData();
        foreach ($datasets as $dataset) {
            if (is_array($dataset) && isset($dataset['root'])) {
                return $dataset;
            }
        }

        return null;
    }

    /**
     * Whether the installed packages could be read. When they couldn't, the
     * report leaves components out rather than send an empty list, which
     * would clear the site's stored one.
     *
     * @return bool
     */
    public function isAvailable()
    {
        return $this->available;
    }

    /**
     * @param  array<string, mixed>  $installed
     * @return void
     */
    private function load($installed)
    {
        $root = isset($installed['root']['name']) ? $installed['root']['name'] : null;
        $versions = isset($installed['versions']) && is_array($installed['versions']) ? $installed['versions'] : [];

        foreach ($versions as $name => $package) {
            if (! is_string($name) || ! is_array($package) || $name === $root) {
                continue;
            }
            if (! isset($package['pretty_version']) || ! is_string($package['pretty_version'])) {
                continue;
            }
            $this->known[$name] = true;
            if (! empty($package['dev_requirement'])) {
                $this->hasDev = true;

                continue;
            }
            if (isset($package['type']) && $package['type'] === 'metapackage') {
                continue;
            }
            $this->packages[$name] = $package;
        }

        ksort($this->packages);
    }

    /**
     * @param  string  $version
     * @return string
     */
    private static function clean($version)
    {
        return preg_replace('/^v(?=\d)/', '', $version);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function components()
    {
        $framework = $this->framework();
        $components = [];

        foreach ($this->packages as $name => $package) {
            $core = $framework !== null && $framework['name'] === $name;
            $components[] = [
                'type' => $core ? 'core' : 'package',
                'slug' => $name,
                'name' => $name,
                'version' => self::clean($package['pretty_version']),
                'latest_version' => null,
                'active' => true,
                'auto_update' => null,
                'package' => ['ecosystem' => 'Packagist', 'name' => $name],
            ];
        }

        return $components;
    }

    /**
     * @param  string  $name
     * @return string|null
     */
    public function version($name)
    {
        return isset($this->packages[$name]) ? self::clean($this->packages[$name]['pretty_version']) : null;
    }

    /**
     * Whether the package is installed, dev or not.
     *
     * @param  string  $name
     * @return bool
     */
    public function has($name)
    {
        return isset($this->known[$name]);
    }

    /**
     * @return bool
     */
    public function hasDevPackages()
    {
        return $this->hasDev;
    }

    /**
     * @return array{name: string, label: string, version: ?string}|null
     */
    public function framework()
    {
        foreach (self::FRAMEWORKS as $name => $label) {
            if (isset($this->packages[$name])) {
                return ['name' => $name, 'label' => $label, 'version' => $this->version($name)];
            }
        }

        return null;
    }
}
