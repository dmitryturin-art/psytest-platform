#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Reports whether the ignored local Graphify artefacts describe the current
 * checkout. It deliberately does not update the graph: semantic extraction
 * may consume an external provider or an agent budget and needs an explicit
 * work-package decision.
 */

$projectRoot = dirname(__DIR__);
$graphDirectory = $projectRoot . '/graphify-out';
$pythonPathFile = $graphDirectory . '/.graphify_python';
$manifestFile = $graphDirectory . '/manifest.json';

if (!is_file($pythonPathFile) || !is_file($manifestFile)) {
    fwrite(STDOUT, "STALE: Graphify has not been initialized in this checkout. Run graphify . --update.\n");
    exit(1);
}

$python = trim((string) file_get_contents($pythonPathFile));
if ($python === '' || !is_executable($python)) {
    fwrite(STDOUT, "UNKNOWN: Graphify runtime is unavailable. Reinitialize Graphify before relying on its graph.\n");
    exit(2);
}

$script = <<<'PYTHON'
import json
from pathlib import Path
from graphify.detect import detect_incremental

result = detect_incremental(Path('.'))
print(json.dumps({
    'new_total': result.get('new_total', 0),
    'deleted_total': len(result.get('deleted_files', [])),
    'code': len(result.get('new_files', {}).get('code', [])),
    'documents': len(result.get('new_files', {}).get('document', [])),
    'papers': len(result.get('new_files', {}).get('paper', [])),
    'images': len(result.get('new_files', {}).get('image', [])),
    'video': len(result.get('new_files', {}).get('video', [])),
}))
PYTHON;

$process = proc_open(
    [$python, '-c', $script],
    [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
    $pipes,
    $projectRoot,
);

if (!is_resource($process)) {
    fwrite(STDOUT, "UNKNOWN: Could not start Graphify freshness check.\n");
    exit(2);
}

$output = stream_get_contents($pipes[1]);
$error = stream_get_contents($pipes[2]);
fclose($pipes[1]);
fclose($pipes[2]);
$exitCode = proc_close($process);

if ($exitCode !== 0) {
    fwrite(STDOUT, "UNKNOWN: Graphify freshness check failed. " . trim($error) . "\n");
    exit(2);
}

/** @var array{new_total?: int, deleted_total?: int, code?: int, documents?: int, papers?: int, images?: int, video?: int}|null $result */
$result = json_decode($output, true);
if (!is_array($result)) {
    fwrite(STDOUT, "UNKNOWN: Graphify freshness check returned unreadable data.\n");
    exit(2);
}

$changed = (int) ($result['new_total'] ?? 0);
$deleted = (int) ($result['deleted_total'] ?? 0);
if ($changed === 0 && $deleted === 0) {
    fwrite(STDOUT, "CURRENT: Graphify matches this checkout.\n");
    exit(0);
}

fwrite(
    STDOUT,
    sprintf(
        'STALE: %d changed (%d code, %d documents, %d papers, %d images, %d video), %d deleted. Run Graphify incremental update before relying on it.%s',
        $changed,
        (int) ($result['code'] ?? 0),
        (int) ($result['documents'] ?? 0),
        (int) ($result['papers'] ?? 0),
        (int) ($result['images'] ?? 0),
        (int) ($result['video'] ?? 0),
        $deleted,
        PHP_EOL,
    ),
);
exit(1);
