<?php

declare(strict_types=1);

namespace Hanji\Runtime;

use Hanji\BuildException;

final class ContextStack
{
    /** @var list<Context> */
    private static array $frames = [];

    public static function current(): Context
    {
        $key = array_key_last(self::$frames);
        return $key === null ? throw new BuildException('Hanji API called outside an active build context.') : self::$frames[$key];
    }

    /**
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    public static function run(Context $context, callable $callback): mixed
    {
        self::$frames[] = $context;
        try {
            return $callback();
        } finally {
            array_pop(self::$frames);
        }
    }
}
