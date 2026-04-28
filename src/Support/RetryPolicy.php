<?php

namespace NoriaLabs\Payments\Support;

class RetryPolicy
{
    /**
     * @param  array<int, string>  $retryMethods
     * @param  array<int, int>  $retryOnStatuses
     */
    public function __construct(
        public readonly int $maxAttempts = 1,
        public readonly array $retryMethods = [],
        public readonly array $retryOnStatuses = [],
        public readonly bool $retryOnNetworkError = false,
        public readonly float $baseDelaySeconds = 0.0,
        public readonly float $maxDelaySeconds = 60.0,
        public readonly float $backoffMultiplier = 2.0,
        public readonly float $jitterSeconds = 0.0,
        public readonly bool $respectRetryAfter = true,
        public readonly mixed $shouldRetry = null,
        public readonly mixed $sleeper = null,
    ) {
    }

    public static function fromArray(array|self|null|false $value): ?self
    {
        if ($value === false || $value === null) {
            return null;
        }

        if ($value instanceof self) {
            return $value;
        }

        return new self(
            maxAttempts: (int) ($value['max_attempts'] ?? 1),
            retryMethods: array_values($value['retry_methods'] ?? []),
            retryOnStatuses: array_values($value['retry_on_statuses'] ?? []),
            retryOnNetworkError: (bool) ($value['retry_on_network_error'] ?? false),
            baseDelaySeconds: (float) ($value['base_delay_seconds'] ?? 0.0),
            maxDelaySeconds: (float) ($value['max_delay_seconds'] ?? 60.0),
            backoffMultiplier: (float) ($value['backoff_multiplier'] ?? 2.0),
            jitterSeconds: (float) ($value['jitter_seconds'] ?? 0.0),
            respectRetryAfter: (bool) ($value['respect_retry_after'] ?? true),
            shouldRetry: $value['should_retry'] ?? null,
            sleeper: $value['sleeper'] ?? null,
        );
    }
}
