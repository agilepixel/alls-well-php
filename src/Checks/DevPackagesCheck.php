<?php

namespace AgilePixel\AllsWell\Checks;

use AgilePixel\AllsWell\Report\ComposerPackages;

/**
 * Flags Composer dev packages left installed in production. When Composer's
 * data couldn't be read it says so, rather than pass.
 */
final class DevPackagesCheck implements Check
{
    const KEY = 'php.dev_packages';

    /**
     * @var ComposerPackages
     */
    private $packages;

    /**
     * @var string|null
     */
    private $environment;

    /**
     * @param  string|null  $environment
     */
    public function __construct(ComposerPackages $packages, $environment)
    {
        $this->packages = $packages;
        $this->environment = $environment;
    }

    /**
     * @return CheckResult
     */
    public function run()
    {
        if (! $this->packages->isAvailable()) {
            return CheckResult::info("Composer packages couldn't be read", 'medium');
        }

        if (! $this->packages->hasDevPackages()) {
            return CheckResult::pass('No Composer dev packages installed', 'medium');
        }

        $environment = strtolower(trim((string) $this->environment));
        if (in_array($environment, ['production', 'prod', 'live'], true)) {
            return CheckResult::warn('Composer dev packages are installed', 'medium', true);
        }

        return CheckResult::info('Composer dev packages are installed', 'medium', true);
    }
}
