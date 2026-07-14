<?php

$root = dirname(__DIR__);
$directories = [$root . '/app', $root . '/tests', $root . '/scripts'];
$failed = false;

foreach ($directories as $directory) {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory));

    foreach ($iterator as $file) {
        if (!$file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        $command = escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($file->getPathname());
        exec($command, $output, $exitCode);
        if ($exitCode !== 0) {
            $failed = true;
            fwrite(STDERR, implode(PHP_EOL, $output) . PHP_EOL);
        }
        $output = [];
    }
}

if ($failed) {
    exit(1);
}

echo "Syntax validation passed.\n";
