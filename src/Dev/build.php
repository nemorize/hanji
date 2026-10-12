<?php

declare(strict_types=1);

use Hanji\Builder;
use Hanji\BuildProgress;
use Hanji\ConfigLoader;
use Hanji\ConfigurationException;
use Hanji\Dev\Outcome;
use Hanji\Dev\Project;
use Hanji\Dev\Protocol;
use Hanji\Diagnostic;

require __DIR__ . '/bootstrap.php';
$request = Protocol::read($argv[1] ?? '');
$directory = $request['directory'] ?? null;
$config = $request['config'] ?? null;
$response = $request['response'] ?? null;
if (!is_string($directory) || ($config !== null && !is_string($config)) || !is_string($response)) {
    throw new RuntimeException('Invalid development build request.');
}
$project = null;
ob_start();
try {
    $settings = (new ConfigLoader())->load($directory, $config);
    $project = Project::from($settings);
    fwrite(STDOUT, json_encode(['type' => 'project', 'data' => $project], JSON_THROW_ON_ERROR) . "\n");
    $result = (new Builder())->build($settings, static function (BuildProgress $progress): void {
        fwrite(STDOUT, json_encode(['type' => 'progress', 'data' => $progress], JSON_THROW_ON_ERROR) . "\n");
    });
    $outcome = new Outcome($project, $result, dependencies: get_included_files());
} catch (Throwable $error) {
    $outcome = new Outcome($project, error: Diagnostic::from($error, $project?->cache), configurationError: $error instanceof ConfigurationException, dependencies: get_included_files());
} finally {
    ob_end_clean();
}
Protocol::write($response, $outcome);
