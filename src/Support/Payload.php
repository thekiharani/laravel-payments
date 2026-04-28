<?php

namespace NoriaLabs\Payments\Support;

class Payload
{
    public static function stringifyAmount(array $payload): array
    {
        foreach (['Amount', 'amount'] as $key) {
            if (array_key_exists($key, $payload)) {
                $payload[$key] = (string) $payload[$key];
            }
        }

        return $payload;
    }
}
