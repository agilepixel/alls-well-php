<?php

namespace AgilePixel\AllsWell\Laravel\Commands;

/**
 * Connects the app, or connects it again after a disconnect or a deletion.
 */
class ConnectCommand extends AllsWellCommand
{
    /**
     * @var string
     */
    protected $signature = 'alls-well:connect';

    /**
     * @var string
     */
    protected $description = "Connect this app to All's Well and send its first report";

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

        return $this->sayResult($this->reporter()->connect());
    }
}
