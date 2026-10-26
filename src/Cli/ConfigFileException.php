<?php

namespace AgilePixel\AllsWell\Cli;

/**
 * A problem ConfigLoader found itself, whose message is safe to print. Any
 * other exception from loading the config comes from the owner's own file or
 * the libraries it calls, and is reported by class name only.
 */
final class ConfigFileException extends \InvalidArgumentException {}
