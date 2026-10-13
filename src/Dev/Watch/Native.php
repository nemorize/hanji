<?php

declare(strict_types=1);

namespace Hanji\Dev\Watch;

use Hanji\BuildException;

final readonly class Native
{
    private \FFI $ffi;

    public function __construct(string $declarations, ?string $library = null)
    {
        $this->ffi = \FFI::cdef($declarations, $library);
    }

    public function call(string $function, mixed ...$arguments): mixed
    {
        return $this->ffi->$function(...$arguments);
    }

    public function buffer(int $length): \FFI\CData
    {
        return $this->ffi->new('char[' . $length . ']');
    }

    public function allocate(string $type): \FFI\CData
    {
        return $this->ffi->new($type);
    }

    public function cast(string $type, \FFI\CData $value): \FFI\CData
    {
        return $this->ffi->cast($type, $value);
    }

    public function set(\FFI\CData $value, string $member, mixed $data): void
    {
        $value->$member = $data;
    }

    public function member(\FFI\CData $value, string $member): mixed
    {
        return $value->$member;
    }

    public function pointerValue(\FFI\CData $pointer): int
    {
        $value = $this->allocate('intptr_t');
        // Copy the pointer itself; casting an opaque handle to a scalar dereferences it.
        \FFI::memcpy(\FFI::addr($value), \FFI::addr($pointer), \FFI::sizeof($value));
        $integer = $this->member($value, 'cdata');
        if (!is_int($integer)) {
            throw new BuildException('Invalid native pointer value.');
        }
        return $integer;
    }

    public function pointer(string $function, mixed ...$arguments): \FFI\CData
    {
        $value = $this->call($function, ...$arguments);
        if (!$value instanceof \FFI\CData || \FFI::isNull($value)) {
            throw new BuildException("Native watcher call {$function} returned no pointer.");
        }
        return $value;
    }

    public function integer(string $function, mixed ...$arguments): int
    {
        $value = $this->call($function, ...$arguments);
        if (!is_int($value)) {
            throw new BuildException("Native watcher call {$function} returned no integer.");
        }
        return $value;
    }

    public function stringAt(\FFI\CData $paths, int $index): string
    {
        $value = $this->ffi->cast('char **', $paths)[$index];
        if (!$value instanceof \FFI\CData) {
            throw new BuildException('Invalid native watcher path.');
        }
        return \FFI::string($value);
    }
}
