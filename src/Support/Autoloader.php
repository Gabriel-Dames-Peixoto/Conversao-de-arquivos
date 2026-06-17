<?php

declare(strict_types=1);

namespace App\Support;

final class Autoloader
{
    public static function register(string $basePath): void
    {
        spl_autoload_register(static function (string $class) use ($basePath): void {
            $prefix = 'App\\';

            if (\strncmp($class, $prefix, \strlen($prefix)) !== 0) {
                return;
            }

            $relativeClass = \substr($class, \strlen($prefix));
            $file = \rtrim($basePath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR
                . \str_replace('\\', DIRECTORY_SEPARATOR, $relativeClass)
                . '.php';

            if (\is_file($file)) {
                require $file;
            }
        });
    }
}
