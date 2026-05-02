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
            if (array_key_exists($key, $payload)) {
                $payload[$key] = (string) $payload[$key];
            }
        }

        return $payload;
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
