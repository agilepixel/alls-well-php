<?php

namespace AgilePixel\AllsWell\Laravel\Commands;

use AgilePixel\AllsWell\Laravel\AllsWellServiceProvider;
use AgilePixel\AllsWell\Laravel\State\StateStores;
use AgilePixel\AllsWell\Support\Credentials;

/**
 * The one step after setting ALLS_WELL_TOKEN: publishes the config, creates
 * the state table, connects and says what runs next.
 */
class InstallCommand extends AllsWellCommand
{
    /**
     * @var string
     */
    protected $signature = 'alls-well:install';

    /**
     * @var string
     */
    protected $description = "Set this app up with All's Well and send its first report";

    /**
     * @return int
     */
    protected function perform()
    {
        $this->publishConfig();

        $settings = $this->laravel->make('config');
        $credentials = Credentials::fromValues($this->text($settings->get('alls_well.token')), $this->text($settings->get('alls_well.key')));
        if ($credentials->siteToken() === null && $credentials->enrolmentKey() === null) {
            return $this->noCredentials($credentials);
        }
        $keyMode = $credentials->siteToken() === null;

        if (! StateStores::hasTable($this->laravel) && ! $this->createTable($keyMode)) {
            return 1;
        }

        $skipped = $this->skippedEnvironment();
        if ($skipped !== null) {
            $this->say($this->refusalIn($skipped));

            return 1;
        }

        $result = $this->reporter()->connect();
        $code = $this->sayResult($result);
        if ($code === 0) {
            $this->line("Reports go out hourly from Laravel's scheduler, so php artisan schedule:run must run every minute.");
        }

        return $code;
    }

    /**
     * @return int
     */
    private function noCredentials(Credentials $credentials)
    {
        if ($this->configIsCached()) {
            $this->say(StatusCommand::CACHED_CONFIG);
        } elseif ($credentials->errors() !== []) {
            $this->say($credentials->errors()[0]);
        } else {
            $this->say('Add ALLS_WELL_TOKEN (the site token from All\'s Well) or ALLS_WELL_KEY (your team\'s enrolment key) to .env, then run php artisan alls-well:install again.');
        }

        return 1;
    }

    /**
     * @param  bool  $keyMode
     * @return bool Whether to go on.
     */
    private function createTable($keyMode)
    {
        if ($this->input->isInteractive()
            && ! $this->confirm("Create the alls_well_state table, where All's Well keeps this app's connection?", true)) {
            if ($keyMode) {
                $this->say('With ALLS_WELL_KEY the table is needed: run php artisan migrate.');

                return false;
            }

            return true;
        }

        try {
            $migrated = $this->call('migrate', [
                '--path' => realpath(AllsWellServiceProvider::MIGRATIONS),
                '--realpath' => true,
                '--force' => true,
            ]);
        } catch (\Throwable $e) {
            $migrated = 1;
        }
        if ($migrated !== 0) {
            $this->say('The alls_well_state table could not be created: run php artisan migrate.');

            return ! $keyMode;
        }

        return true;
    }

    /**
     * The config file is optional: a config/ that can't be written to leaves the defaults.
     *
     * @return void
     */
    private function publishConfig()
    {
        try {
            $this->call('vendor:publish', ['--tag' => 'alls-well-config']);
        } catch (\Throwable $e) {
            $this->say("The config file couldn't be published (config/ isn't writable); the defaults apply.");
        }
    }

    /**
     * @param  mixed  $value
     * @return string|null
     */
    private function text($value)
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
