<?php

declare(strict_types=1);

/**
 * XLIFF check for extension CI: well-formed files, unique unit ids, and translation
 * files (<lang>.<name>.xlf) that declare their target language and translate exactly
 * the units of their source file.
 */
$root = dirname(__DIR__, 2);
$paths = [
    $root . '/Resources/Private/Language',
    $root . '/Configuration/Sets',
];

$errors = [];

/**
 * @return array{targetLanguage: string, units: array<string, array{source: string, target: ?string}>}|null
 */
$readXliff = static function (string $file) use (&$errors): ?array {
    $document = new DOMDocument();
    $previous = libxml_use_internal_errors(true);
    $loaded = $document->load($file, LIBXML_NONET);
    $xmlErrors = libxml_get_errors();
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
    if (!$loaded) {
        $message = $xmlErrors !== [] ? trim($xmlErrors[0]->message) : 'unknown error';
        $errors[] = sprintf('Not well-formed: %s (%s)', $file, $message);
        return null;
    }

    $fileElement = $document->getElementsByTagName('file')->item(0);
    if (!$fileElement instanceof DOMElement) {
        $errors[] = 'Missing <file> element: ' . $file;
        return null;
    }

    $units = [];
    foreach ($document->getElementsByTagName('trans-unit') as $unit) {
        $id = $unit->getAttribute('id');
        if ($id === '') {
            $errors[] = 'trans-unit without id in ' . $file;
            continue;
        }
        if (isset($units[$id])) {
            $errors[] = sprintf('Duplicate trans-unit "%s" in %s', $id, $file);
        }
        $target = $unit->getElementsByTagName('target')->item(0);
        $units[$id] = [
            'source' => (string)$unit->getElementsByTagName('source')->item(0)?->textContent,
            'target' => $target?->textContent,
        ];
    }

    return [
        'targetLanguage' => $fileElement->getAttribute('target-language'),
        'units' => $units,
    ];
};

foreach ($paths as $path) {
    if (!is_dir($path)) {
        continue;
    }
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path));
    foreach ($iterator as $file) {
        if (!$file->isFile() || !str_ends_with($file->getFilename(), '.xlf')) {
            continue;
        }
        $translation = $readXliff($file->getPathname());
        if ($translation === null || !preg_match('/^([a-z]{2}(?:[-_][A-Za-z]{2})?)\.(.+\.xlf)$/', $file->getFilename(), $matches)) {
            continue;
        }

        [, $language, $sourceName] = $matches;
        $sourceFile = $file->getPath() . '/' . $sourceName;
        if (!is_file($sourceFile)) {
            $errors[] = sprintf('Translation without source file: %s', $file->getPathname());
            continue;
        }
        if ($translation['targetLanguage'] !== $language) {
            $errors[] = sprintf('target-language "%s" does not match "%s" in %s', $translation['targetLanguage'], $language, $file->getPathname());
        }

        $source = $readXliff($sourceFile);
        if ($source === null) {
            continue;
        }
        foreach ($source['units'] as $id => $unit) {
            if (!isset($translation['units'][$id])) {
                $errors[] = sprintf('Untranslated "%s" in %s', $id, $file->getPathname());
            } elseif (trim((string)$translation['units'][$id]['target']) === '') {
                $errors[] = sprintf('Empty target for "%s" in %s', $id, $file->getPathname());
            }
        }
        foreach (array_diff_key($translation['units'], $source['units']) as $id => $_) {
            $errors[] = sprintf('"%s" in %s does not exist in %s', $id, $file->getPathname(), $sourceName);
        }
    }
}

if ($errors !== []) {
    fwrite(STDERR, implode(PHP_EOL, $errors) . PHP_EOL);
    exit(1);
}

echo 'XLIFF lint OK' . PHP_EOL;
