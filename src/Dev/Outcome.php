<?php

declare(strict_types=1);

namespace Hanji\Dev;

use Hanji\BuildException;
use Hanji\BuildResult;
use Hanji\Diagnostic;
use Hanji\Support\Maps;

final readonly class Outcome
{
    /** @param list<string> $dependencies */
    public function __construct(public ?Project $project, public ?BuildResult $result = null, public ?Diagnostic $error = null, public bool $configurationError = false, public array $dependencies = []) {}

    public static function decode(mixed $value): self
    {
        $data = Maps::validate($value, 'Development build');
        $project = ($data['project'] ?? null) === null ? null : Project::decode($data['project']);
        $error = ($data['error'] ?? null) === null ? null : Diagnostic::decode($data['error']);
        $configuration = $data['configurationError'] ?? false;
        $dependencies = $data['dependencies'] ?? [];
        if (!is_bool($configuration) || !is_array($dependencies) || !array_is_list($dependencies)) {
            throw new BuildException('Invalid development build result.');
        }
        foreach ($dependencies as $path) {
            if (!is_string($path)) {
                throw new BuildException('Invalid development dependency.');
            }
        }
        $result = null;
        if (($data['result'] ?? null) !== null) {
            $built = Maps::validate($data['result'], 'Build result');
            if (!is_int($built['pages'] ?? null) || !is_int($built['assets'] ?? null) || !is_string($built['output'] ?? null) || (!is_float($built['seconds'] ?? null) && !is_int($built['seconds'] ?? null))) {
                throw new BuildException('Invalid development build statistics.');
            }
            $result = new BuildResult($built['pages'], $built['assets'], $built['output'], $built['seconds']);
        }
        return new self($project, $result, $error, $configuration, $dependencies);
    }
}
