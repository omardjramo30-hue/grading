<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
$errors = [];

foreach ($iterator as $file) {
    if (!$file->isFile() || $file->getExtension() !== 'php' || str_contains($file->getPathname(), DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR)) {
        continue;
    }
    $content = (string) file_get_contents($file->getPathname());
    foreach (['mysql_query(', 'mysql_connect(', 'sha1(', 'md5('] as $forbidden) {
        if (str_contains($content, $forbidden)) {
            $errors[] = $file->getPathname() . ' contains forbidden call ' . $forbidden;
        }
    }
}

if ($errors !== []) {
    fwrite(STDERR, implode("\n", $errors) . "\n");
    exit(1);
}

echo "Security static checks passed.\n";
