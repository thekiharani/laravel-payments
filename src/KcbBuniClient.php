<?php

namespace NoriaLabs\Payments;

use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Http\Client\Factory;
use NoriaLabs\Payments\Contracts\AccessTokenProvider;
use NoriaLabs\Payments\Exceptions\ConfigurationException;
use NoriaLabs\Payments\Support\CachedAccessTokenProvider;
use NoriaLabs\Payments\Support\ClientCredentialsTokenProvider;
use NoriaLabs\Payments\Support\Hooks;
use NoriaLabs\Payments\Support\HttpTransport;
use NoriaLabs\Payments\Support\Payload;
use NoriaLabs\Payments\Support\RequestOptions;
use NoriaLabs\Payments\Support\RetryPolicy;

class KcbBuniClient
{
    public const BASE_URLS = [
        'uat' => 'https://uat.buni.kcbgroup.com',
    ];

    public const ENDPOINTS = [
        'mpesa_stk_push' => '/mm/api/request/1.0.0/stkpush',
        'funds_transfer' => '/fundstransfer/1.0.0/api/v1/transfer',
        'query_core_transaction_status' => '/v1/core/t24/querytransaction/1.0.0/api/transactioninfo',
        'query_transaction_details' => '/kcb/transaction/query/1.0.0/api/v1/payment/query/{identifier}',
        'vending_validate_request' => '/kcb/vendingGateway/v1/1.0.0/api/validate-request',
        'vending_vendor_confirmation' => '/kcb/vendingGateway/v1/1.0.0/api/vendor-confirmation',
        'vending_transaction_status' => '/kcb/vendingGateway/v1/1.0.0/api/query/transaction-status',
    ];

    /**
     * @param  array<string, string>  $endpoints
     * @param  array<string, mixed>  $mpesaExpress
     */
    public function __construct(
        private readonly HttpTransport $http,
        private readonly AccessTokenProvider $tokens,
        private readonly array $endpoints = self::ENDPOINTS,
        private readonly array $mpesaExpress = [],
    ) {}

    public static function make(
        Factory $httpFactory,
        array $config = [],
        ?AccessTokenProvider $tokenProvider = null,
        ?Hooks $hooks = null,
        ?CacheFactory $cacheFactory = null,
    ): self {
        $baseUrl = self::resolveBaseUrl($config);

        $transport = new HttpTransport(
            http: $httpFactory,
            baseUrl: $baseUrl,
            timeoutSeconds: isset($config['timeout_seconds']) ? (float) $config['timeout_seconds'] : null,
            defaultHeaders: self::resolveDefaultHeaders($config),
            retry: RetryPolicy::fromArray($config['retry'] ?? null),
            hooks: $hooks,
        );

        return new self(
            http: $transport,
            tokens: $tokenProvider ?? self::tokenProvider($httpFactory, $config, $baseUrl, $cacheFactory),
            endpoints: self::resolveEndpoints($config),
            mpesaExpress: (array) ($config['mpesa_express'] ?? []),
        );
    }

    public function getAccessToken(bool $forceRefresh = false): string
    {
        return $this->tokens->getAccessToken($forceRefresh);
    }

    public function mpesaStkPush(
        array $payload,
        string $messageId,
        array|RequestOptions|null $options = null,
        ?string $routeCode = null,
    ): mixed {
        $requestOptions = $this->withOptionHeaders($options, [
            'routeCode' => $routeCode ?? $this->requiredMpesaRouteCode(),
            'operation' => self::nullableString($this->mpesaExpress['operation'] ?? null) ?? 'STKPush',
            'messageId' => $messageId,
        ]);

        return $this->authorizedRequest(
            path: $this->endpoint('mpesa_stk_push'),
            method: 'POST',
            payload: Payload::stringifyAmount($payload),
            query: null,
            options: $requestOptions,
        );
    }

    public function transferFunds(array $payload, array|RequestOptions|null $options = null): mixed
    {
        return $this->authorizedRequest($this->endpoint('funds_transfer'), 'POST', $payload, null, $options);
    }

    public function queryCoreTransactionStatus(array $payload, array|RequestOptions|null $options = null): mixed
    {
        return $this->authorizedRequest($this->endpoint('query_core_transaction_status'), 'POST', $payload, null, $options);
    }

    public function queryTransactionDetails(
        string|int $identifier,
        array $query = [],
        array|RequestOptions|null $options = null,
    ): mixed {
        return $this->authorizedRequest(
            path: $this->endpoint('query_transaction_details', ['identifier' => rawurlencode((string) $identifier)]),
            method: 'GET',
            payload: null,
            query: $query,
            options: $options,
        );
    }

    public function vendingValidateRequest(array $payload, array|RequestOptions|null $options = null): mixed
    {
        return $this->authorizedRequest($this->endpoint('vending_validate_request'), 'POST', $payload, null, $options);
    }

    public function vendingVendorConfirmation(array $payload, array|RequestOptions|null $options = null): mixed
    {
        return $this->authorizedRequest($this->endpoint('vending_vendor_confirmation'), 'POST', $payload, null, $options);
    }

    public function vendingTransactionStatus(array $payload, array|RequestOptions|null $options = null): mixed
    {
        return $this->authorizedRequest($this->endpoint('vending_transaction_status'), 'POST', $payload, null, $options);
    }

    private function authorizedRequest(
        string $path,
        string $method,
        ?array $payload,
        ?array $query,
        array|RequestOptions|null $options,
    ): mixed {
        $requestOptions = RequestOptions::fromArray($options);
        $token = $requestOptions->accessToken ?? $this->tokens->getAccessToken($requestOptions->forceTokenRefresh);

        $headers = array_merge($requestOptions->headers, [
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ]);

        return $this->http->send(
            path: $path,
            method: $method,
            headers: $headers,
            query: $query,
            body: $payload,
            timeoutSeconds: $requestOptions->timeoutSeconds,
            retry: $requestOptions->retry,
        );
    }

    private function endpoint(string $name, array $replacements = []): string
    {
        $endpoint = $this->endpoints[$name];

        foreach ($replacements as $key => $value) {
            $endpoint = str_replace('{'.$key.'}', (string) $value, $endpoint);
        }

        return $endpoint;
    }

    private function requiredMpesaRouteCode(): string
    {
        $routeCode = self::nullableString($this->mpesaExpress['route_code'] ?? null);

        if ($routeCode === null) {
            throw new ConfigurationException(
                'KcbBuniClient M-PESA STK Push requires a routeCode. Pass routeCode to mpesaStkPush() or configure payments.kcb_buni.mpesa_express.route_code.'
            );
        }

        return $routeCode;
    }

    private function withOptionHeaders(array|RequestOptions|null $options, array $headers): RequestOptions
    {
        $requestOptions = RequestOptions::fromArray($options);

        return new RequestOptions(
            headers: array_merge($requestOptions->headers, $headers),
            timeoutSeconds: $requestOptions->timeoutSeconds,
            retry: $requestOptions->retry,
            accessToken: $requestOptions->accessToken,
            forceTokenRefresh: $requestOptions->forceTokenRefresh,
        );
    }

    private static function tokenProvider(
        Factory $httpFactory,
        array $config,
        string $baseUrl,
        ?CacheFactory $cacheFactory,
    ): AccessTokenProvider {
        $consumerKey = self::nullableString($config['consumer_key'] ?? null);
        $consumerSecret = self::nullableString($config['consumer_secret'] ?? null);

        if ($consumerKey === null || $consumerSecret === null) {
            throw new ConfigurationException(
                'KcbBuniClient requires either consumer_key and consumer_secret, or a custom token provider.'
            );
        }

        $skew = (int) ($config['token_cache_skew_seconds'] ?? 60);
        $provider = new ClientCredentialsTokenProvider(
            http: $httpFactory,
            tokenUrl: self::resolveTokenUrl($config, $baseUrl),
            clientId: $consumerKey,
            clientSecret: $consumerSecret,
            timeoutSeconds: isset($config['timeout_seconds']) ? (float) $config['timeout_seconds'] : null,
            cacheSkewSeconds: $skew,
            method: 'POST',
            body: ['grant_type' => 'client_credentials'],
            asForm: true,
        );

        $cacheStore = $config['cache_store'] ?? null;

        if ($cacheFactory === null || $cacheStore === null || $cacheStore === false) {
            return $provider;
        }

        $repository = $cacheStore === true || $cacheStore === '' || $cacheStore === 'default'
            ? $cacheFactory->store()
            : $cacheFactory->store((string) $cacheStore);

        return new CachedAccessTokenProvider(
            inner: $provider,
            cache: $repository,
            cacheKey: self::tokenCacheKey($config, $baseUrl, $consumerKey),
            cacheSkewSeconds: $skew,
            cacheTtlSeconds: isset($config['cache_ttl_seconds']) ? (int) $config['cache_ttl_seconds'] : null,
        );
    }

    private static function resolveBaseUrl(array $config): string
    {
        $baseUrl = self::nullableString($config['base_url'] ?? null);

        if ($baseUrl !== null) {
            return $baseUrl;
        }

        $environment = (string) ($config['environment'] ?? 'uat');

        if (isset(self::BASE_URLS[$environment])) {
            return self::BASE_URLS[$environment];
        }

        throw new ConfigurationException(
            'KcbBuniClient base_url must be provided explicitly for environments other than uat.'
        );
    }

    private static function resolveTokenUrl(array $config, string $baseUrl): string
    {
        $tokenUrl = self::nullableString($config['token_url'] ?? null);

        if ($tokenUrl !== null) {
            return $tokenUrl;
        }

        $tokenPath = self::nullableString($config['token_path'] ?? null) ?? '/token';

        return rtrim($baseUrl, '/').'/'.ltrim($tokenPath, '/');
    }

    private static function resolveEndpoints(array $config): array
    {
        $endpoints = self::ENDPOINTS;

        foreach ((array) ($config['endpoints'] ?? []) as $name => $path) {
            if ($path !== null && $path !== '') {
                $endpoints[$name] = (string) $path;
            }
        }

        return $endpoints;
    }

    private static function resolveDefaultHeaders(array $config): array
    {
        $headers = (array) ($config['default_headers'] ?? []);
        $userAgent = $config['user_agent'] ?? null;

        if (is_string($userAgent) && $userAgent !== '' && ! self::hasHeader($headers, 'User-Agent')) {
            $headers['User-Agent'] = $userAgent;
        }

        $apiKey = self::nullableString($config['api_key'] ?? null);

        if ($apiKey !== null && ! self::hasHeader($headers, 'apikey')) {
            $headers['apikey'] = $apiKey;
        }

        return $headers;
    }

    private static function hasHeader(array $headers, string $name): bool
    {
        foreach (array_keys($headers) as $key) {
            if (is_string($key) && strcasecmp($key, $name) === 0) {
                return true;
            }
        }

        return false;
    }

    private static function tokenCacheKey(array $config, string $baseUrl, string $consumerKey): string
    {
        $env = (string) ($config['environment'] ?? 'uat');

        return 'payments:kcb_buni:token:'.sha1($env.'|'.$baseUrl.'|'.$consumerKey);
    }

    private static function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
