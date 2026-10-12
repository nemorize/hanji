<?php

declare(strict_types=1);

namespace Hanji\Console;

use Symfony\Component\Console\Formatter\OutputFormatter;

final class Style
{
    public static function color(string $text, string $color, bool $bold = false): string
    {
        return '<fg=' . $color . ($bold ? ';options=bold' : '') . '>' . OutputFormatter::escape($text) . '</>';
    }

    public static function pad(string $text, int $width): string
    {
        return $text . str_repeat(' ', max(0, $width - mb_strwidth($text, 'UTF-8')));
    }

    public static function fit(string $text, int $width): string
    {
        if ($width < 1) {
            return '';
        }
        if (mb_strwidth($text, 'UTF-8') <= $width) {
            return $text;
        }
        if ($width < 5) {
            return mb_strimwidth($text, 0, $width, '…', 'UTF-8');
        }
        $endWidth = intdiv($width - 1, 2);
        $end = '';
        foreach (array_reverse(mb_str_split($text, 1, 'UTF-8')) as $character) {
            if (mb_strwidth($character . $end, 'UTF-8') > $endWidth) {
                break;
            }
            $end = $character . $end;
        }
        return mb_strimwidth($text, 0, $width - $endWidth - 1, '', 'UTF-8') . '…' . $end;
    }

    public static function row(string $icon, string $label, string $detail, string $color = 'cyan', string $time = '', ?int $width = null): string
    {
        if ($width !== null && $width < 20) {
            return ' ' . self::color($icon, $color) . ' ' . self::color(self::fit($label . ' ' . $detail, max(0, $width - 3)), 'gray');
        }
        $space = $width === null ? mb_strwidth($detail, 'UTF-8') : max(0, $width - 13 - ($time === '' ? 0 : strlen($time) + 2));
        $detail = $width === null ? $detail : self::fit($detail, $space);
        return '  ' . self::color($icon, $color) . ' ' . self::color(self::pad($label, 8), 'blue') . ' ' . self::color(self::pad($detail, $space), 'cyan') . ($time === '' ? '' : '  ' . self::color($time, 'gray'));
    }

    public static function duration(float $seconds): string
    {
        return $seconds < 1 ? (string) round($seconds * 1000) . 'ms' : number_format($seconds, 2, '.', '') . 's';
    }
}
