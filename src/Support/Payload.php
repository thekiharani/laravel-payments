<?php

namespace NoriaLabs\Payments\Support;

class Payload
{
    public static function normalizeAmount(array $payload, mixed $normalization = 'string'): array
    {
        if (self::resolveAmountNormalization($normalization) === 'none') {
            return $payload;
        }

        return self::stringifyAmount($payload);
    }

    public static function stringifyAmount(array $payload): array
    {
        foreach (['Amount', 'amount'] as $key) {
            if (array_key_exists($key, $payload) && is_scalar($payload[$key])) {
                $payload[$key] = self::amountToString($payload[$key]);
            }
        }

        return $payload;
    }

    /**
     * A plain `(string)` cast on a float leaks binary rounding artefacts —
     * `0.1 + 0.2` becomes `"0.30000000000000004"`, which providers reject.
     */
    public static function amountToString(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_float($value)) {
            if (! is_finite($value)) {
                return (string) $value;
            }

            $formatted = number_format($value, 8, '.', '');

            return str_contains($formatted, '.')
                ? rtrim(rtrim($formatted, '0'), '.')
                : $formatted;
        }

        return (string) $value;
    }

    public static function resolveAmountNormalization(mixed $value): string
    {
        $normalized = strtolower(trim((string) ($value ?? 'string')));

        return match ($normalized) {
            'none', 'raw', 'preserve' => 'none',
            default => 'string',
        };
    }
}
