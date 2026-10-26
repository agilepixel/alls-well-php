<?php

namespace AgilePixel\AllsWell\Laravel\Commands;

use AgilePixel\AllsWell\Laravel\LaravelConfig;
use AgilePixel\AllsWell\Laravel\ReporterFactory;
use AgilePixel\AllsWell\Reporter;
use AgilePixel\AllsWell\Result;
use AgilePixel\AllsWell\Support\ClassName;
use Illuminate\Console\Command;
use Illuminate\Foundation\Application;

/**
 * What the alls-well commands share: the CLI's output format, the skipped
 * environment and the catch-all that names an unexpected exception by class.
 */
abstract class AllsWellCommand extends Command
{
    /**
     * @return int
     */
    public function handle()
    {
        try {
            return $this->perform();
        } catch (\Throwable $e) {
            $this->say('Unexpected error ('.ClassName::of($e).').');

            return 1;
        }
    }

    /**
     * @return int The exit code.
     */
    abstract protected function perform();

    /**
     * @param  string  $message
     * @return void
     */
    protected function say($message)
    {
        $this->line("All's Well: ".$message);
    }

    /**
     * @return string|null
     */
    protected function skippedEnvironment()
    {
        return LaravelConfig::skippedEnvironment($this->laravel);
    }

    /**
     * @return bool
     */
    protected function configIsCached()
    {
        /** @var Application $app */
        $app = $this->laravel;

        return (bool) $app->configurationIsCached();
    }

    /**
     * Built on demand, so a command that creates the state table builds it afterwards.
     *
     * @return Reporter
     */
    protected function reporter()
    {
        return ReporterFactory::make($this->laravel);
    }

    /**
     * @param  string  $environment
     * @return string
     */
    protected function refusalIn($environment)
    {
        return 'This app is in the '.$environment.' environment, which alls_well.skip_environments leaves out.';
    }

    /**
     * Prints a result and returns its exit code.
     *
     * @return int
     */
    protected function sayResult(Result $result)
    {
        $this->say($result->message());

        return $result->isSuccess() ? 0 : 1;
    }
}
