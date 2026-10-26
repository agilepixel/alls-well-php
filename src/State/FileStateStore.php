<?php

namespace AgilePixel\AllsWell\State;

/**
 * Keeps the state as JSON in a private file, replaced atomically.
 */
final class FileStateStore implements StateStore
{
    const UNREADABLE = "The state file (ALLS_WELL_STATE_PATH) exists but can't be read.";

    const UNWRITABLE = "The state file (ALLS_WELL_STATE_PATH) can't be saved: its directory isn't writable.";

    /** @var string */
    private $path;

    /**
     * @param  string  $path
     */
    public function __construct($path)
    {
        $this->path = $path;
    }

    public function load()
    {
        if (! is_file($this->path) || ! is_readable($this->path)) {
            return State::empty();
        }

        $json = @file_get_contents($this->path);
        if (! is_string($json) || $json === '') {
            return State::empty();
        }

        $data = json_decode($json, true);

        return is_array($data) ? State::fromArray($data) : State::empty();
    }

    public function save(State $state)
    {
        $json = json_encode($state->toArray());
        if (! is_string($json)) {
            throw new \RuntimeException('The state could not be encoded.');
        }

        $dir = dirname($this->path);
        if (! is_dir($dir) && ! @mkdir($dir, 0700, true) && ! is_dir($dir)) {
            throw new \RuntimeException('The state directory could not be created.');
        }

        $temp = $dir.'/.alls-well-'.bin2hex(random_bytes(6)).'.tmp';

        $handle = @fopen($temp, 'xb');
        if ($handle === false) {
            throw new \RuntimeException('The state file could not be written.');
        }

        $private = @chmod($temp, 0600);
        $written = $private ? @fwrite($handle, $json) : false;
        $closed = @fclose($handle);

        if ($written !== strlen($json) || ! $closed || ! @rename($temp, $this->path)) {
            @unlink($temp);

            throw new \RuntimeException('The state file could not be written.');
        }
    }

    public function clear()
    {
        if (is_file($this->path)) {
            @unlink($this->path);
        }
    }

    public function name()
    {
        return 'the state file (ALLS_WELL_STATE_PATH)';
    }

    public function isPersistent()
    {
        return true;
    }

    /**
     * A file load() would read as empty although it exists, or a directory
     * the file can't be written to: the nearest one that exists, since
     * save() creates the rest. Typically the state was saved by another
     * user than the one cron runs as.
     */
    public function problem()
    {
        if ($this->isUnreadable()) {
            return self::UNREADABLE;
        }

        $dir = dirname($this->path);
        while (! file_exists($dir) && dirname($dir) !== $dir) {
            $dir = dirname($dir);
        }

        return is_dir($dir) && is_writable($dir) ? null : self::UNWRITABLE;
    }

    public function isUnreadable()
    {
        return file_exists($this->path) && (! is_file($this->path) || ! is_readable($this->path));
    }
}
