<?php

namespace AgilePixel\AllsWell\Laravel\Commands;

use AgilePixel\AllsWell\Result;

/**
 * Sends the hourly report. Quiet unless asked or something needs fixing,
 * because the scheduler discards output.
 */
class ReportCommand extends AllsWellCommand
{
    /**
     * @var string
     */
    protected $signature = 'alls-well:report {--manual : Send now, even if a report is not due}';

    /**
     * @var string
     */
    protected $description = "Send this app's report to All's Well";

    /**
     * @return int
     */
    protected function perform()
    {
        $skipped = $this->skippedEnvironment();
        if ($skipped !== null) {
            $this->say('Reporting is off in the '.$skipped.' environment (alls_well.skip_environments).');

            return 0;
        }

        $result = $this->reporter()->report($this->option('manual') ? 'manual' : 'scheduled');

        if ($this->getOutput()->isVerbose() || $this->getOutput()->isDecorated() || $result->needsAttention()) {
            $this->say($result->message());
        }
        if ($result->needsAttention()) {
            $this->logWarning($result->message());
        }

        return $result->isSuccess() || ($result->outcome() === Result::PAUSED && ! $result->needsAttention()) ? 0 : 1;
    }

    /**
     * @param  string  $message
     * @return void
     */
    private function logWarning($message)
    {
        try {
            $this->laravel->make('log')->warning("All's Well: ".$message);
        } catch (\Throwable $e) {
            return;
        }
    }
}
