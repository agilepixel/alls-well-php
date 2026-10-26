<?php

namespace AgilePixel\AllsWell\Cli;

/**
 * A small .env reader that keeps only what the command needs: the
 * ALLS_WELL_* settings and APP_ENV. Everything else in the file, such as
 * database passwords, is dropped as it is read.
 */
final class DotEnv
{
    /**
     * @return array<string, string>
     */
    public static function read(string $path): array
    {
        if (! is_file($path) || ! is_readable($path)) {
            return [];
        }

        $contents = @file_get_contents($path);
        if (! is_string($contents)) {
            return [];
        }

        $contents = preg_replace('/^\xEF\xBB\xBF/', '', $contents);
        $values = [];

        foreach (preg_split('/\r\n|\n|\r/', (string) $contents) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') {
                continue;
            }

            $line = preg_replace('/^export\s+/', '', $line);
            $position = strpos((string) $line, '=');
            if ($position === false) {
                continue;
            }

            $key = trim(substr((string) $line, 0, $position));
            if (! self::wanted($key)) {
                continue;
            }

            $values[$key] = self::value(substr((string) $line, $position + 1));
        }

        return $values;
    }

    private static function wanted(string $key): bool
    {
        return $key === 'APP_ENV' || preg_match('/\AALLS_WELL_[A-Z0-9_]+\z/', $key) === 1;
    }

    private static function value(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return '';
        }

        $quote = $raw[0];
        if ($quote === '"' || $quote === "'") {
            $value = '';
            $length = strlen($raw);
            for ($i = 1; $i < $length; $i++) {
                $character = $raw[$i];
                if ($quote === '"' && $character === '\\' && $i + 1 < $length && ($raw[$i + 1] === '"' || $raw[$i + 1] === '\\')) {
                    $value .= $raw[++$i];

                    continue;
                }
                if ($character === $quote) {
                    return $value;
                }
                $value .= $character;
            }

            return $value;
        }

        $comment = preg_match('/\s#/', $raw, $match, PREG_OFFSET_CAPTURE);
        if ($comment === 1) {
            $raw = substr($raw, 0, $match[0][1]);
        }

        return trim($raw);
    }
}
