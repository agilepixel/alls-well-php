<?php

namespace AgilePixel\AllsWell\Laravel\Commands;

/**
 * Stops reporting until `alls-well:connect`.
 */
class DisconnectCommand extends AllsWellCommand
{
    /**
     * @var string
     */
    protected $signature = 'alls-well:disconnect {--force : Do not ask first}';

    /**
     * @var string
     */
    protected $description = "Stop reporting to All's Well from this app";

    /**
     * @return int
     */
    protected function perform()
    {
        $skipped = $this->skippedEnvironment();
        if ($skipped !== null) {
            $this->say($this->refusalIn($skipped));

            return 1;
        }

        if (! $this->option('force')) {
            if (! $this->input->isInteractive()) {
                $this->say('Add --force to disconnect without being asked.');

                return 1;
            }
            if (! $this->confirm("Stop reporting to All's Well from this app?", false)) {
                return 1;
            }
        }

        return $this->sayResult($this->reporter()->disconnect());
    }
}
