<?php

declare(strict_types=1);

namespace Hanji\Source;

use Hanji\BuildException;

final class Metadata
{
    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public static function validate(array $data, string $timezone): array
    {
        if (array_key_exists('title', $data) && !is_string($data['title'])) {
            throw new BuildException('title must be a string.');
        }
        if (array_key_exists('tags', $data)) {
            if (!is_array($data['tags']) || !array_is_list($data['tags'])) {
                throw new BuildException('tags must be a list of strings.');
            }
            $tags = [];
            foreach ($data['tags'] as $tag) {
                if (!is_string($tag) || $tag === '' || $tag === 'all') {
                    throw new BuildException('Tags must be nonempty strings; all is reserved.');
                }
                $tags[$tag] = $tag;
            }
            $data['tags'] = array_values($tags);
        }
        if (array_key_exists('layout', $data) && !is_bool($data['layout'])) {
            throw new BuildException('layout must be a boolean.');
        }
        if (array_key_exists('permalink', $data) && !is_string($data['permalink'])) {
            throw new BuildException('permalink must be a string.');
        }
        if (isset($data['date']) && !$data['date'] instanceof \DateTimeImmutable) {
            if (!is_string($data['date'])) {
                throw new BuildException('date must be a date-only or RFC 3339 string, or DateTimeImmutable.');
            }
            $value = $data['date'];
            $format = preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value) ? '!Y-m-d' : (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})$/D', $value) ? (str_contains($value, '.') ? 'Y-m-d\TH:i:s.uP' : 'Y-m-d\TH:i:sP') : null);
            $date = $format === null ? false : \DateTimeImmutable::createFromFormat($format, $value, new \DateTimeZone($timezone));
            $errors = \DateTimeImmutable::getLastErrors();
            if ($date === false || ($errors !== false && ($errors['warning_count'] || $errors['error_count']))) {
                throw new BuildException("Invalid date: {$value}");
            }
            $data['date'] = $date;
        }
        return $data;
    }
}
