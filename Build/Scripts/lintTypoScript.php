<?php

declare(strict_types=1);

/**
 * Minimal TypoScript sanity check for extension CI (brace balance + readable files).
 */
$root = dirname(__DIR__, 2);
$paths = [
    $root . '/Configuration/TypoScript',
    $root . '/Configuration/Sets',
];

$errors = [];
foreach ($paths as $path) {
    if (!is_dir($path)) {
        continue;
    }
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path));
    foreach ($iterator as $file) {
        if (!$file->isFile() || !str_ends_with($file->getFilename(), '.typoscript')) {
            continue;
        }
        $contents = file_get_contents($file->getPathname());
        if ($contents === false) {
            $errors[] = 'Unreadable: ' . $file->getPathname();
            continue;
        }
        $open = substr_count($contents, '{');
        $close = substr_count($contents, '}');
        if ($open !== $close) {
            $errors[] = sprintf('Unbalanced braces in %s (%d open, %d close)', $file->getPathname(), $open, $close);
        }
    }
}

if ($errors !== []) {
    fwrite(STDERR, implode(PHP_EOL, $errors) . PHP_EOL);
    exit(1);
}

echo 'TypoScript lint OK' . PHP_EOL;
