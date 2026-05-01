<?php

use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Response;
use NoriaLabs\Payments\Exceptions\AuthenticationException;
use NoriaLabs\Payments\Exceptions\NetworkException;
use NoriaLabs\Payments\Exceptions\TimeoutException;
use NoriaLabs\Payments\Support\AfterResponseContext;
use NoriaLabs\Payments\Support\ErrorContext;
use NoriaLabs\Payments\Support\Hooks;
use NoriaLabs\Payments\Support\RetryPolicy;

it('builds AfterResponseContext and ErrorContext with expected fields', function (): void {
    $response = new Response(Factory::psr7Response(['ok' => true], 200));

    $after = new AfterResponseContext(
        url: 'https://example.test/after',
        path: '/after',
        method: 'GET',
        headers: ['X-Test' => '1'],
        body: null,
        attempt: 1,
        response: $response,
        responseBody: ['ok' => true],
    );

    $error = new ErrorContext(
        url: 'https://example.test/error',
        path: '/error',
        method: 'POST',
        headers: [],
        body: ['payload' => true],
        attempt: 2,
        error: new RuntimeException('failed'),
        response: $response,
        responseBody: ['failed' => true],
    );

    expect($after->responseBody)->toBe(['ok' => true])
        ->and($error->responseBody)->toBe(['failed' => true])
        ->and($error->attempt)->toBe(2);
});

it('normalizes single callables and arrays of callables in Hooks', function (): void {
    $callable = fn () => null;

    $hooks = new Hooks(
        beforeRequest: $callable,
        afterResponse: [$callable],
        onError: [$callable],
    );

    expect((new Hooks)->beforeRequestCallbacks())->toBe([])
        ->and($hooks->beforeRequestCallbacks())->toHaveCount(1)
        ->and($hooks->afterResponseCallbacks())->toHaveCount(1)
        ->and($hooks->onErrorCallbacks())->toHaveCount(1);
});

it('returns null from RetryPolicy::fromArray for null and false', function (): void {
    expect(RetryPolicy::fromArray(null))->toBeNull()
        ->and(RetryPolicy::fromArray(false))->toBeNull();
});

it('returns the same RetryPolicy instance when fromArray receives one', function (): void {
    $policy = new RetryPolicy(maxAttempts: 3);

    expect(RetryPolicy::fromArray($policy))->toBe($policy);
});

it('builds a RetryPolicy from an array including new jitter and retry-after fields', function (): void {
    $callback = fn () => true;

    $policy = RetryPolicy::fromArray([
        'max_attempts' => 3,
        'retry_methods' => ['GET'],
        'retry_on_statuses' => [429],
        'retry_on_network_error' => true,
        'base_delay_seconds' => 0.01,
        'max_delay_seconds' => 0.02,
        'backoff_multiplier' => 1.5,
        'jitter_seconds' => 0.05,
        'respect_retry_after' => false,
        'should_retry' => $callback,
        'sleeper' => $callback,
    ]);

    expect($policy)->not->toBeNull()
        ->and($policy?->maxAttempts)->toBe(3)
        ->and($policy?->retryMethods)->toBe(['GET'])
        ->and($policy?->retryOnStatuses)->toBe([429])
        ->and($policy?->retryOnNetworkError)->toBeTrue()
        ->and($policy?->baseDelaySeconds)->toBe(0.01)
        ->and($policy?->maxDelaySeconds)->toBe(0.02)
        ->and($policy?->backoffMultiplier)->toBe(1.5)
        ->and($policy?->jitterSeconds)->toBe(0.05)
        ->and($policy?->respectRetryAfter)->toBeFalse();
});

it('exposes machine-readable codeName on every typed exception', function (): void {
    expect((new AuthenticationException('auth'))->codeName)->toBe('AUTHENTICATION_ERROR')
        ->and((new NetworkException('network'))->codeName)->toBe('NETWORK_ERROR')
        ->and((new TimeoutException('timeout'))->codeName)->toBe('TIMEOUT_ERROR');
});
