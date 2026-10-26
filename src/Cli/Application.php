<?php

namespace AgilePixel\AllsWell\Cli;

use AgilePixel\AllsWell\AllsWell;
use AgilePixel\AllsWell\Client;
use AgilePixel\AllsWell\Config;
use AgilePixel\AllsWell\Http\CurlTransport;
use AgilePixel\AllsWell\Http\Transport;
use AgilePixel\AllsWell\Report\ComposerPackages;
use AgilePixel\AllsWell\Reporter;
use AgilePixel\AllsWell\Result;
use AgilePixel\AllsWell\Support\ClassName;
use AgilePixel\AllsWell\Support\StatusLines;

/**
 * The alls-well command: report, connect, disconnect and status for apps
 * that run it from cron.
 *
 * Cron emails whatever a job prints, so `report` is quiet unless something
 * needs fixing; the exit code still says whether it worked. Nothing escapes
 * run(): an unexpected exception becomes one line naming only its class.
 */
final class Application
{
    const COMMANDS = ['report', 'connect', 'disconnect', 'status'];

    /** @var callable|null */
    private $transportFactory;

    /** @var resource */
    private $stdout;

    /** @var resource */
    private $stderr;

    /** @var ComposerPackages|null */
    private $packages;

    /** @var Config|null */
    private $config;

    /**
     * @param  callable|null  $transportFactory  Returns the Transport to send with; cURL when null.
     * @param  resource|null  $stdout
     * @param  resource|null  $stderr
     * @param  ComposerPackages|null  $packages  The installed packages; read from Composer when null.
     */
    public function __construct(?callable $transportFactory = null, $stdout = null, $stderr = null, ?ComposerPackages $packages = null)
    {
        $this->transportFactory = $transportFactory;
        $this->stdout = $stdout !== null ? $stdout : STDOUT;
        $this->stderr = $stderr !== null ? $stderr : STDERR;
        $this->packages = $packages;
    }

    /**
     * @param  array<int, string>  $argv  As PHP's $argv: the script name first.
     * @param  array<string, mixed>  $env  The process environment.
     * @param  string  $projectRoot  The directory .env, alls-well.php and artisan are looked for in.
     * @return int The exit code: 0 worked, 1 did not, 2 was used wrongly.
     */
    public function run(array $argv, array $env, string $projectRoot): int
    {
        $this->config = null;
        set_error_handler(function ($level) {
            return $this->suppress($level);
        });

        try {
            return $this->dispatch(array_slice(array_values($argv), 1), $env, $projectRoot);
        } catch (\Throwable $e) {
            $this->line($this->stderr, 'Unexpected error ('.ClassName::of($e).').');

            return 1;
        } finally {
            restore_error_handler();
        }
    }

    /**
     * @param  array<int, string>  $arguments
     * @param  array<string, mixed>  $env
     */
    private function dispatch(array $arguments, array $env, string $projectRoot): int
    {
        $command = null;
        $configFile = null;
        $manual = false;
        $verbose = false;

        foreach ($arguments as $argument) {
            if ($argument === '--help' || $argument === '-h') {
                return $this->help();
            }
            if ($argument === '--version') {
                fwrite($this->stdout, "All's Well PHP ".AllsWell::VERSION."\n");

                return 0;
            }
            if ($argument === '--manual') {
                $manual = true;
            } elseif ($argument === '--verbose' || $argument === '-v') {
                $verbose = true;
            } elseif (strncmp($argument, '--config=', 9) === 0) {
                $configFile = substr($argument, 9);
            } elseif ($argument !== '' && $argument[0] === '-') {
                return $this->usage("Unknown option '".$argument."'.");
            } elseif ($command === null) {
                $command = $argument;
            } else {
                return $this->usage("Unexpected argument '".$argument."'.");
            }
        }

        if ($command === 'help') {
            return $this->help();
        }
        if ($command === null) {
            return $this->usage('Give a command.');
        }
        if (! in_array($command, self::COMMANDS, true)) {
            return $this->usage("Unknown command '".$command."'.");
        }
        if ($manual && $command !== 'report') {
            return $this->usage('--manual only goes with report.');
        }
        if ($configFile === '') {
            return $this->usage('--config needs a path.');
        }

        if ($this->isLaravelApp($projectRoot)) {
            fwrite($this->stderr, "This is a Laravel app: use php artisan alls-well:report (and the other alls-well: commands) instead.\n");

            return 1;
        }

        try {
            $config = ConfigLoader::load($env, $projectRoot, $configFile);
        } catch (ConfigFileException $e) {
            $this->line($this->stderr, $e->getMessage());

            return 1;
        }
        $this->config = $config;

        $reporter = new Reporter($config, new Client($this->transport()), $this->packages);

        if ($command === 'status') {
            $this->printStatus($reporter->status());

            return 0;
        }

        if ($command === 'report') {
            $result = $reporter->report($manual ? 'manual' : 'scheduled');
            if ($verbose || $result->needsAttention() || $this->isTerminal($this->stdout)) {
                $this->say($result);
            }
        } else {
            $result = $command === 'connect' ? $reporter->connect() : $reporter->disconnect();
            $this->say($result);
        }

        return $result->isSuccess() ? 0 : 1;
    }

    /**
     * Keeps PHP's warnings, notices and deprecations off the output, where
     * cron would mail them every run. Each goes to the logger by level only,
     * since the message and file can carry paths or values. An error the
     * current error_reporting() excludes is left to PHP.
     *
     * @param  int  $level
     */
    private function suppress($level): bool
    {
        if (! (error_reporting() & $level)) {
            return false;
        }

        if ($this->config !== null) {
            if ($level === E_DEPRECATED || $level === E_USER_DEPRECATED) {
                $kind = 'deprecated';
            } elseif ($level === E_NOTICE || $level === E_USER_NOTICE) {
                $kind = 'notice';
            } else {
                $kind = 'warning';
            }
            $this->config->log("All's Well: PHP ".$kind.' suppressed.');
        }

        return true;
    }

    private function say(Result $result): void
    {
        $this->line($result->isSuccess() ? $this->stdout : $this->stderr, $result->message());
    }

    /**
     * @param  resource  $stream
     */
    private function line($stream, string $message): void
    {
        fwrite($stream, "All's Well: ".$message."\n");
    }

    /**
     * @param  resource  $stream
     */
    private function isTerminal($stream): bool
    {
        return function_exists('stream_isatty') && @stream_isatty($stream);
    }

    private function transport(): Transport
    {
        if ($this->transportFactory === null) {
            return new CurlTransport;
        }

        return call_user_func($this->transportFactory);
    }

    private function isLaravelApp(string $projectRoot): bool
    {
        $packages = $this->packages !== null ? $this->packages : new ComposerPackages;

        return $packages->has('laravel/framework') && is_file(rtrim($projectRoot, '/\\').'/artisan');
    }

    /**
     * @param  array<string, mixed>  $status
     */
    private function printStatus(array $status): void
    {
        $errors = isset($status['errors']) && is_array($status['errors']) ? $status['errors'] : [];
        unset($status['errors']);

        foreach (StatusLines::lines($status) as $line) {
            fwrite($this->stdout, $line[0].': '.$line[1]."\n");
        }

        if ($errors === []) {
            fwrite($this->stdout, "errors: -\n");

            return;
        }

        fwrite($this->stdout, "errors:\n");
        foreach ($errors as $error) {
            fwrite($this->stdout, '  - '.preg_replace('/\s+/', ' ', (string) $error)."\n");
        }
    }

    private function usage(string $problem): int
    {
        fwrite($this->stderr, "All's Well: ".$problem."\n\n".self::usageText());

        return 2;
    }

    private function help(): int
    {
        fwrite($this->stdout, self::usageText());

        return 0;
    }

    private static function usageText(): string
    {
        return <<<'TEXT'
Usage: alls-well <command> [options]

Commands:
  report       Send a report to All's Well. Run `report` hourly from cron: it is
               quiet unless something needs fixing (-v shows every outcome), and the
               exit code says whether it worked.
  connect      Connect (or reconnect) this site now and send a first report.
  disconnect   Tell All's Well this site is going and stop reporting.
  status       Show the settings and state, without contacting All's Well.

Options:
  --manual       With report: send it as a manual report, whether or not one is due.
  -v, --verbose  With report: print every outcome, not only the ones needing a fix.
  --config=PATH  Read settings from this PHP file (relative to the project root).
                 Without it, alls-well.php in the project root is used if present.
  --help         Show this help.
  --version      Show the package version.

Settings come from alls-well.php, then .env, then .env.local, then the environment:
  ALLS_WELL_TOKEN, ALLS_WELL_KEY, ALLS_WELL_URL, ALLS_WELL_SITE_URL,
  ALLS_WELL_SITE_NAME, ALLS_WELL_ENVIRONMENT (or APP_ENV), ALLS_WELL_ENABLED,
  ALLS_WELL_STATE_PATH.

Exit codes: 0 worked, 1 did not, 2 wrong usage.

TEXT;
    }
}
