<?php

namespace NoriaLabs\Payments\Support;

class RequestOptions
{
    /**
     * @param  array<string, string>  $headers
     */
    public function __construct(
        public readonly array $headers = [],
        public readonly ?float $timeoutSeconds = null,
        public readonly RetryPolicy|false|null $retry = null,
        public readonly ?string $accessToken = null,
        public readonly bool $forceTokenRefresh = false,
    ) {
    }

    public static function fromArray(array|self|null $value): self
    {
        if ($value instanceof self) {
            return $value;
        }

        if ($value === null) {
            return new self();
        }

        return new self(
            headers: $value['headers'] ?? [],
            timeoutSeconds: isset($value['timeout_seconds']) ? (float) $value['timeout_seconds'] : null,
            retry: array_key_exists('retry', $value) ? (RetryPolicy::fromArray($value['retry']) ?? false) : null,
            accessToken: $value['access_token'] ?? null,
            forceTokenRefresh: (bool) ($value['force_token_refresh'] ?? false),
        );
    }
}
