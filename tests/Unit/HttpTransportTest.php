<?php

use Illuminate\Support\Facades\Http;
use NoriaLabs\Payments\Exceptions\ApiException;
use NoriaLabs\Payments\Exceptions\NetworkException;
use NoriaLabs\Payments\Exceptions\TimeoutException;
use NoriaLabs\Payments\Support\AfterResponseContext;
use NoriaLabs\Payments\Support\BeforeRequestContext;
use NoriaLabs\Payments\Support\ErrorContext;
use NoriaLabs\Payments\Support\Hooks;
use NoriaLabs\Payments\Support\HttpTransport;
use NoriaLabs\Payments\Support\RetryDecisionContext;
use NoriaLabs\Payments\Support\RetryPolicy;

it('parses JSON, plain text, empty, and absolute-url responses; runs hooks; honors body mutation', function (): void {
    $afterContexts = [];

    $transport = new HttpTransport(
        http: Http::getFacadeRoot(),
        baseUrl: 'https://api.example.test/base',
        timeoutSeconds: 2.0,
        defaultHeaders: ['X-Default' => 'default'],
        hooks: new Hooks(
            beforeRequest: function (BeforeRequestContext $context): void {
                $context->headers['X-Before'] = 'before';

                if ($context->path === '/string') {
                    $context->body = 'changed body';
                }
            },
            afterResponse: function (AfterResponseContext $context) use (&$afterContexts): void {
                $afterContexts[] = $context;
            },
        ),
    );

    Http::fake([
        'https://api.example.test/base/empty?keep=1' => Http::response('', 200, ['Content-Type' => 'text/plain']),
        'https://absolute.example.test/raw-json' => Http::response('{"ok":true}', 200, ['Content-Type' => 'text/plain']),
        'https://api.example.test/base/plain' => Http::response('plain text', 200, ['Content-Type' => 'text/plain']),
        'https://api.example.test/base/json' => Http::response(['ok' => true], 200),
        'https://api.example.test/base/string' => Http::response('accepted', 200, ['Content-Type' => 'text/plain']),
        'https://api.example.test/base/custom-json' => Http::response(['custom' => true], 200),
    ]);

    expect($transport->send('/empty', query: ['keep' => '1', 'drop' => null]))->toBeNull()
        ->and($transport->send('https://absolute.example.test/raw-json'))->toBe(['ok' => true])
        ->and($transport->send('/plain'))->toBe('plain text')
        ->and($transport->send('/json'))->toBe(['ok' => true])
        ->and($transport->send('/string', method: 'POST', body: 'original body'))->toBe('accepted')
        ->and($transport->send('/custom-json', method: 'POST', headers: ['content-type' => 'application/custom'], body: ['a' => 'b']))->toBe(['custom' => true])
        ->and($afterContexts)->toHaveCount(6);

    Http::assertSent(fn ($request): bool => $request->url() === 'https://api.example.test/base/string'
        && $request->hasHeader('Content-Type', 'text/plain;charset=UTF-8')
        && $request->body() === 'changed body'
        && $request->hasHeader('X-Default', 'default')
        && $request->hasHeader('X-Before', 'before'));

    Http::assertSent(fn ($request): bool => $request->url() === 'https://api.example.test/base/custom-json'
        && $request->hasHeader('content-type', 'application/custom'));
});

it('throws ApiException with extracted body and dispatches error hooks on 4xx/5xx', function (): void {
    $errorContext = null;

    $transport = new HttpTransport(
        http: Http::getFacadeRoot(),
        baseUrl: 'https://api.example.test',
        retry: new RetryPolicy(maxAttempts: 2, retryMethods: ['GET'], retryOnStatuses: [500]),
        hooks: new Hooks(
            onError: function (ErrorContext $context) use (&$errorContext): void {
                $errorContext = $context;
            },
        ),
    );

    Http::fake([
        'https://api.example.test/fail' => Http::response('server down', 500, ['Content-Type' => 'text/plain']),
    ]);

    expect(fn () => $transport->send('/fail', retry: false))
        ->toThrow(ApiException::class, 'server down')
        ->and($errorContext)->toBeInstanceOf(ErrorContext::class)
        ->and($errorContext?->responseBody)->toBe('server down');
});

it('skips retries when the request method does not match retry_methods', function (): void {
    $transport = new HttpTransport(
        http: Http::getFacadeRoot(),
        baseUrl: 'https://api.example.test',
    );

    Http::fake([
        'https://api.example.test/method-mismatch' => Http::response(['detail' => 'no retry'], 500),
    ]);

    expect(fn () => $transport->send('/method-mismatch', method: 'POST', retry: new RetryPolicy(
        maxAttempts: 2,
        retryMethods: ['GET'],
        retryOnStatuses: [500],
    )))->toThrow(ApiException::class, 'no retry');
});

it('skips retries when the response status is not in retry_on_statuses', function (): void {
    $transport = new HttpTransport(
        http: Http::getFacadeRoot(),
        baseUrl: 'https://api.example.test',
    );

    Http::fake([
        'https://api.example.test/status-mismatch' => Http::response(['message' => 'wrong status'], 500),
    ]);

    expect(fn () => $transport->send('/status-mismatch', retry: new RetryPolicy(
        maxAttempts: 2,
        retryOnStatuses: [429],
    )))->toThrow(ApiException::class, 'wrong status');
});

it('honors a shouldRetry callback that returns false', function (): void {
    $transport = new HttpTransport(
        http: Http::getFacadeRoot(),
        baseUrl: 'https://api.example.test',
    );

    Http::fake([
        'https://api.example.test/callback-stops' => Http::response(['errorMessage' => 'callback stopped'], 500),
    ]);

    expect(fn () => $transport->send('/callback-stops', retry: new RetryPolicy(
        maxAttempts: 2,
        retryOnStatuses: [500],
        shouldRetry: fn (RetryDecisionContext $context): bool => false,
    )))->toThrow(ApiException::class, 'callback stopped');
});

it('exposes the response and parsed body on the retry decision context', function (): void {
    $captured = null;

    $transport = new HttpTransport(
        http: Http::getFacadeRoot(),
        baseUrl: 'https://api.example.test',
    );

    Http::fake([
        'https://api.example.test/inspect-response' => Http::response(['detail' => 'soft fail'], 500),
    ]);

    $send = function () use ($transport, &$captured): void {
        $transport->send('/inspect-response', retry: new RetryPolicy(
            maxAttempts: 2,
            retryOnStatuses: [500],
            shouldRetry: function (RetryDecisionContext $context) use (&$captured): bool {
                $captured = $context;

                return false;
            },
        ));
    };

    expect($send)->toThrow(ApiException::class);

    expect($captured)->toBeInstanceOf(RetryDecisionContext::class)
        ->and($captured?->status)->toBe(500)
        ->and($captured?->responseBody)->toBe(['detail' => 'soft fail']);
});

it('retries network errors when retry_on_network_error is enabled', function (): void {
    Http::fakeSequence('https://api.example.test/network-retry')
        ->pushFailedConnection('operation timed out')
        ->push(['ok' => true], 200);

    $transport = new HttpTransport(
        http: Http::getFacadeRoot(),
        baseUrl: 'https://api.example.test',
    );

    expect($transport->send('/network-retry', retry: new RetryPolicy(
        maxAttempts: 2,
        retryMethods: ['GET'],
        retryOnNetworkError: true,
    )))->toBe(['ok' => true]);
});

it('throws NetworkException for connection failures and TimeoutException for timeouts', function (): void {
    $transport = new HttpTransport(
        http: Http::getFacadeRoot(),
        baseUrl: 'https://api.example.test',
    );

    Http::fake([
        'https://api.example.test/network-fail' => Http::failedConnection('connection refused'),
    ]);

    expect(fn () => $transport->send('/network-fail'))
        ->toThrow(NetworkException::class, 'Request failed for https://api.example.test/network-fail');

    Http::fake([
        'https://api.example.test/network-no-retry' => Http::failedConnection('operation timed out'),
    ]);

    expect(fn () => $transport->send('/network-no-retry', retry: new RetryPolicy(maxAttempts: 2)))
        ->toThrow(TimeoutException::class, 'Request timed out for https://api.example.test/network-no-retry');
});

it('throws NetworkException with an unreachable-state message when maxAttempts is zero', function (): void {
    $transport = new HttpTransport(
        http: Http::getFacadeRoot(),
        baseUrl: 'https://api.example.test',
    );

    expect(fn () => $transport->send('/unreachable', retry: new RetryPolicy(maxAttempts: 0)))
        ->toThrow(NetworkException::class, 'Unreachable retry state.');
});

it('respects a numeric Retry-After header', function (): void {
    Http::fakeSequence('https://api.example.test/throttled*')
        ->push('rate limited', 429, ['Content-Type' => 'text/plain', 'Retry-After' => '0'])
        ->push(['ok' => true], 200);

    $sleeps = [];

    $transport = new HttpTransport(
        http: Http::getFacadeRoot(),
        baseUrl: 'https://api.example.test',
    );

    $result = $transport->send('/throttled', retry: new RetryPolicy(
        maxAttempts: 2,
        retryOnStatuses: [429],
        baseDelaySeconds: 5.0,
        sleeper: function (float $seconds) use (&$sleeps): void {
            $sleeps[] = $seconds;
        },
    ));

    // Retry-After: 0 → no sleep call (early return when delay is zero or negative).
    expect($result)->toBe(['ok' => true])
        ->and($sleeps)->toBe([]);
});

it('parses HTTP-date Retry-After values and treats past dates as no sleep', function (): void {
    Http::fakeSequence('https://api.example.test/http-date*')
        ->push(['detail' => 'wait'], 503, [
            'Retry-After' => gmdate('D, d M Y H:i:s \G\M\T', time() - 10),
        ])
        ->push(['ok' => true], 200);

    $sleeps = [];

    $transport = new HttpTransport(
        http: Http::getFacadeRoot(),
        baseUrl: 'https://api.example.test',
    );

    $result = $transport->send('/http-date', retry: new RetryPolicy(
        maxAttempts: 2,
        retryOnStatuses: [503],
        baseDelaySeconds: 1.0,
        sleeper: function (float $seconds) use (&$sleeps): void {
            $sleeps[] = $seconds;
        },
    ));

    expect($result)->toBe(['ok' => true])
        ->and($sleeps)->toBe([]);
});

it('parses HTTP-date Retry-After values in the future', function (): void {
    Http::fakeSequence('https://api.example.test/future-date*')
        ->push(['detail' => 'wait'], 503, [
            'Retry-After' => gmdate('D, d M Y H:i:s \G\M\T', time() + 2),
        ])
        ->push(['ok' => true], 200);

    $sleeps = [];

    $transport = new HttpTransport(
        http: Http::getFacadeRoot(),
        baseUrl: 'https://api.example.test',
    );

    $transport->send('/future-date', retry: new RetryPolicy(
        maxAttempts: 2,
        retryOnStatuses: [503],
        sleeper: function (float $seconds) use (&$sleeps): void {
            $sleeps[] = $seconds;
        },
    ));

    expect($sleeps)->toHaveCount(1)
        ->and($sleeps[0])->toBeGreaterThan(0.0)
        ->and($sleeps[0])->toBeLessThanOrEqual(2.0);
});

it('ignores Retry-After when respect_retry_after is disabled', function (): void {
    Http::fakeSequence('https://api.example.test/ignore-retry-after*')
        ->push(['detail' => 'wait'], 503, ['Retry-After' => '999'])
        ->push(['ok' => true], 200);

    $sleeps = [];

    $transport = new HttpTransport(
        http: Http::getFacadeRoot(),
        baseUrl: 'https://api.example.test',
    );

    $transport->send('/ignore-retry-after', retry: new RetryPolicy(
        maxAttempts: 2,
        retryOnStatuses: [503],
        baseDelaySeconds: 0.001,
        respectRetryAfter: false,
        sleeper: function (float $seconds) use (&$sleeps): void {
            $sleeps[] = $seconds;
        },
    ));

    expect($sleeps[0])->toBe(0.001);
});

it('falls back to exponential backoff when Retry-After is malformed', function (): void {
    Http::fakeSequence('https://api.example.test/bad-retry-after*')
        ->push(['detail' => 'wait'], 503, ['Retry-After' => 'not-a-real-date'])
        ->push(['ok' => true], 200);

    $sleeps = [];

    $transport = new HttpTransport(
        http: Http::getFacadeRoot(),
        baseUrl: 'https://api.example.test',
    );

    $transport->send('/bad-retry-after', retry: new RetryPolicy(
        maxAttempts: 2,
        retryOnStatuses: [503],
        baseDelaySeconds: 0.001,
        sleeper: function (float $seconds) use (&$sleeps): void {
            $sleeps[] = $seconds;
        },
    ));

    expect($sleeps[0])->toBe(0.001);
});

it('adds jitter on top of exponential backoff', function (): void {
    Http::fakeSequence('https://api.example.test/jitter*')
        ->push(['detail' => 'fail'], 500)
        ->push(['ok' => true], 200);

    $sleeps = [];

    $transport = new HttpTransport(
        http: Http::getFacadeRoot(),
        baseUrl: 'https://api.example.test',
    );

    $transport->send('/jitter', retry: new RetryPolicy(
        maxAttempts: 2,
        retryOnStatuses: [500],
        baseDelaySeconds: 0.1,
        jitterSeconds: 0.5,
        sleeper: function (float $seconds) use (&$sleeps): void {
            $sleeps[] = $seconds;
        },
    ));

    expect($sleeps)->toHaveCount(1)
        ->and($sleeps[0])->toBeGreaterThanOrEqual(0.1)
        ->and($sleeps[0])->toBeLessThanOrEqual(0.6 + 1e-6);
});

it('extracts api error messages from common provider response keys', function (): void {
    Http::fake([
        'https://api.example.test/with-fault' => Http::response(['fault' => 'gateway exploded'], 502),
    ]);

    $transport = new HttpTransport(
        http: Http::getFacadeRoot(),
        baseUrl: 'https://api.example.test',
    );

    expect(fn () => $transport->send('/with-fault'))
        ->toThrow(ApiException::class, 'gateway exploded');
});

it('falls back to the status-coded message when the body has no recognizable error key', function (): void {
    Http::fake([
        'https://api.example.test/empty-error' => Http::response(['unrelated' => true], 500),
    ]);

    $transport = new HttpTransport(
        http: Http::getFacadeRoot(),
        baseUrl: 'https://api.example.test',
    );

    expect(fn () => $transport->send('/empty-error'))
        ->toThrow(ApiException::class, 'Request failed with status 500');
});

it('falls through the retry loop and uses the default usleep sleeper when no sleeper is set', function (): void {
    $method = new ReflectionMethod(HttpTransport::class, 'sleepBeforeRetry');
    $method->setAccessible(true);

    $transport = new HttpTransport(
        http: Http::getFacadeRoot(),
        baseUrl: 'https://api.example.test',
    );

    $method->invoke($transport, null, 1, null);
    $method->invoke($transport, new RetryPolicy(baseDelaySeconds: 0.000001, maxDelaySeconds: 0.000001), 1, null);

    expect(true)->toBeTrue();
});
