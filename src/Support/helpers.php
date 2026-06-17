<?php

declare(strict_types=1);

function base_path(string $path = ''): string
{
    $base = __DIR__ . '/../..';

    return $path === '' ? $base : $base . '/' . ltrim($path, '/');
}

function storage_path(string $path = ''): string
{
    return base_path('storage' . ($path !== '' ? '/' . ltrim($path, '/') : ''));
}

function config(string $key, $default = null)
{
    [$file, $item] = array_pad(explode('.', $key, 2), 2, null);
    $config = $GLOBALS[$file . '_config'] ?? null;

    if (!is_array($config)) {
        return $default;
    }

    if ($item === null) {
        return $config;
    }

    $segments = explode('.', $item);
    $value = $config;

    foreach ($segments as $segment) {
        if (!is_array($value) || !array_key_exists($segment, $value)) {
            return $default;
        }

        $value = $value[$segment];
    }

    return $value;
}
