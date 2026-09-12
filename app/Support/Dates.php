<?php
declare(strict_types=1);

namespace Rin\Support;

final class Dates
{
    public static function now(): int
    {
        return time();
    }

    public static function iso(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if ($value instanceof \DateTimeInterface) {
            return gmdate('Y-m-d\TH:i:s.000\Z', $value->getTimestamp());
        }
        if (is_numeric($value)) {
            return gmdate('Y-m-d\TH:i:s.000\Z', (int) $value);
        }
        $ts = strtotime((string) $value);
        return $ts === false ? (string) $value : gmdate('Y-m-d\TH:i:s.000\Z', $ts);
    }

    public static function parse(?string $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        $ts = strtotime($value);
        return $ts === false ? null : $ts;
    }
}
