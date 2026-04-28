<?php

namespace NoriaLabs\Payments;

use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Http\Client\Factory;
use NoriaLabs\Payments\Contracts\AccessTokenProvider;
use NoriaLabs\Payments\Exceptions\ConfigurationException;
use NoriaLabs\Payments\Support\ClientCredentialsTokenProvider;
use NoriaLabs\Payments\Support\Hooks;
use NoriaLabs\Payments\Support\HttpTransport;
use NoriaLabs\Payments\Support\Payload;
use NoriaLabs\Payments\Support\RequestOptions;
use NoriaLabs\Payments\Support\RetryPolicy;

class SasaPayClient
{
    public const SANDBOX_BASE_URL = 'https://sandbox.sasapay.app/api/v1';

    public const WAAS_SANDBOX_BASE_URL = 'https://sandbox.sasapay.app/api/v2/waas';

    public const TOKEN_PATH = '/auth/token/';

    public const ENDPOINTS = [
        'request_payment' => '/payments/request-payment/',
        'process_payment' => '/payments/process-payment/',
        'b2c_payment' => '/payments/b2c/',
        'b2b_payment' => '/payments/b2b/',
        'card_payment' => '/payments/card-payments/',
        'pre_approved_payment' => '/payments/approved/',
        'remittance_payment' => '/remittances/remittance-payments/',
        'account_validation' => '/accounts/account-validation/',
        'internal_fund_movement' => '/transactions/fund-movement/',
        'transaction_status' => '/transactions/status-query/',
        'merchant_balance' => '/payments/check-balance/',
        'verify_transaction' => '/transactions/verify/',
        'business_to_beneficiary' => '/payments/b2c/beneficiary/',
        'register_ipn_url' => '/payments/register-ipn-url/',
        'lipa_fare' => '/payments/lipa-fare/',
        'transactions' => '/transactions/',
        'channel_codes' => '/payments/channel-codes/',
        'utility_payment' => '/utilities/',
        'utility_bill_query' => '/utilities/bill-query',
        'bulk_payment' => '/payments/bulk-payments/',
        'bulk_payment_status' => '/payments/bulk-payments/status/',
        'dealer_business_types' => '/accounts/business-types/',
        'dealer_countries' => '/accounts/countries/',
        'dealer_sub_counties' => '/accounts/sub-counties/',
        'dealer_industries' => '/accounts/industries/',
        'available_bill_number' => '/accounts/available-bill-number/',
        'merchant_onboarding' => '/accounts/merchant-onboarding/',
    ];

    public const WAAS_ENDPOINTS = [
        'personal_onboarding' => '/personal-onboarding/',
        'personal_onboarding_confirmation' => '/personal-onboarding/confirmation/',
        'personal_kyc' => '/personal-onboarding/kyc/',
        'business_onboarding' => '/business-onboarding/',
        'business_onboarding_confirmation' => '/business-onboarding/confirmation/',
        'business_kyc' => '/business-onboarding/kyc/',
        'customers' => '/customers/',
        'customer_details' => '/customer-details/',
        'customer_details_update' => '/customer-details/update/',
        'request_payment' => '/payments/request-payment/',
        'process_payment' => '/payments/process-payment/',
        'merchant_transfers' => '/payments/merchant-transfers/',
        'send_money' => '/payments/send-money/',
        'pay_bills' => '/payments/pay-bills/',
        'create_sub_wallet' => '/sub-wallets/',
        'transactions' => '/transactions/',
        'transaction_status' => '/transactions/status/',
        'verify_transaction' => '/transactions/verify/',
        'merchant_balance' => '/merchant-balances/',
        'channel_codes' => '/channel-codes/',
        'countries' => '/countries/',
        'country_sub_regions' => '/countries/sub-regions/',
        'industries' => '/industries/',
        'sub_industries' => '/sub-industries/',
        'business_types' => '/business-types/',
        'products' => '/products/',
        'nearest_agents' => '/nearest-agent/',
        'utility_payment' => '/utilities/',
    ];

    /**
     * @param  array<string, string>  $endpoints
     * @param  array<string, string>  $waasEndpoints
     */
    public function __construct(
        private readonly HttpTransport $http,
        private readonly AccessTokenProvider $tokens,
        private readonly array $endpoints = self::ENDPOINTS,
        private readonly ?HttpTransport $waasHttp = null,
        private readonly ?AccessTokenProvider $waasTokens = null,
        private readonly array $waasEndpoints = self::WAAS_ENDPOINTS,
    ) {
    }

    public static function make(
        Factory $httpFactory,
        array $config = [],
        ?AccessTokenProvider $tokenProvider = null,
        ?Hooks $hooks = null,
        ?CacheFactory $cacheFactory = null,
    ): self {
        $baseUrl = self::resolveBaseUrl($config);
        $waasBaseUrl = self::resolveWaasBaseUrl($config);
        $defaultHeaders = self::resolveDefaultHeaders($config);

        $transport = new HttpTransport(
            http: $httpFactory,
            baseUrl: $baseUrl,
            timeoutSeconds: isset($config['timeout_seconds']) ? (float) $config['timeout_seconds'] : null,
            defaultHeaders: $defaultHeaders,
            retry: RetryPolicy::fromArray($config['retry'] ?? null),
            hooks: $hooks,
        );

        $tokens = $tokenProvider ?? ClientCredentialsTokenProvider::forConfig(
            httpFactory: $httpFactory,
            tokenUrl: rtrim($baseUrl, '/').self::TOKEN_PATH,
            config: $config,
            idKey: 'client_id',
            secretKey: 'client_secret',
            missingCredentialsMessage: 'SasaPayClient requires either client_id and client_secret, or a custom token provider.',
            cacheFactory: $cacheFactory,
            cacheKey: self::tokenCacheKey('v1', $config, $baseUrl, (string) ($config['client_id'] ?? '')),
        );

        $waasTransport = $waasBaseUrl === null ? null : new HttpTransport(
            http: $httpFactory,
            baseUrl: $waasBaseUrl,
            timeoutSeconds: isset($config['timeout_seconds']) ? (float) $config['timeout_seconds'] : null,
            defaultHeaders: $defaultHeaders,
            retry: RetryPolicy::fromArray($config['retry'] ?? null),
            hooks: $hooks,
        );

        $waasTokens = $waasBaseUrl === null
            ? null
            : ($tokenProvider ?? self::resolveWaasTokenProvider($httpFactory, $waasBaseUrl, $config, $cacheFactory));

        return new self(
            http: $transport,
            tokens: $tokens,
            endpoints: self::resolveEndpoints($config, 'endpoints', self::ENDPOINTS),
            waasHttp: $waasTransport,
            waasTokens: $waasTokens,
            waasEndpoints: self::resolveEndpoints($config, 'waas_endpoints', self::WAAS_ENDPOINTS),
        );
    }

    public function getAccessToken(bool $forceRefresh = false): string
    {
        return $this->tokens->getAccessToken($forceRefresh);
    }

    public function getWaasAccessToken(bool $forceRefresh = false): string
    {
        return $this->ensureWaasTokens()->getAccessToken($forceRefresh);
    }

    public function requestPayment(array $payload, array|RequestOptions|null $options = null): mixed
    {
        return $this->authorizedRequest($this->endpoint('request_payment'), $this->withAmount($payload), $options);
    }

    public function processPayment(array $payload, array|RequestOptions|null $options = null): mixed
    {
        return $this->authorizedRequest($this->endpoint('process_payment'), $payload, $options);
    }

    public function b2cPayment(array $payload, array|RequestOptions|null $options = null): mixed
    {
        return $this->authorizedRequest($this->endpoint('b2c_payment'), $this->withAmount($payload), $options);
    }

    public function b2bPayment(array $payload, array|RequestOptions|null $options = null): mixed
    {
        return $this->authorizedRequest($this->endpoint('b2b_payment'), $this->withAmount($payload), $options);
    }

    public function cardPayment(array $payload, array|RequestOptions|null $options = null): mixed
    {
        return $this->authorizedRequest($this->endpoint('card_payment'), $this->withAmount($payload), $options);
    }

    public function preApprovedPayment(array $payload, array|RequestOptions|null $options = null): mixed
    {
        return $this->authorizedRequest($this->endpoint('pre_approved_payment'), $this->withAmount($payload), $options);
    }

    public function remittancePayment(array $payload, array|RequestOptions|null $options = null): mixed
    {
        return $this->authorizedRequest($this->endpoint('remittance_payment'), $this->withAmount($payload), $options);
    }

    public function accountValidation(array $payload, array|RequestOptions|null $options = null): mixed
    {
        return $this->authorizedRequest($this->endpoint('account_validation'), $payload, $options);
    }

    public function internalFundMovement(array $payload, array|RequestOptions|null $options = null): mixed
    {
        return $this->authorizedRequest($this->endpoint('internal_fund_movement'), $payload, $options);
    }

    public function transactionStatus(array $payload, array|RequestOptions|null $options = null): mixed
    {
        return $this->authorizedRequest($this->endpoint('transaction_status'), $payload, $options);
    }

    public function merchantBalance(string|int $merchantCode, array|RequestOptions|null $options = null): mixed
    {
        return $this->authorizedGet($this->endpoint('merchant_balance'), [
            'MerchantCode' => (string) $merchantCode,
        ], $options);
    }

    public function verifyTransaction(array $payload, array|RequestOptions|null $options = null): mixed
    {
        return $this->authorizedRequest($this->endpoint('verify_transaction'), $payload, $options);
    }

    public function businessToBeneficiary(array $payload, array|RequestOptions|null $options = null): mixed
    {
        return $this->authorizedRequest($this->endpoint('business_to_beneficiary'), $this->withAmount($payload), $options);
    }

    public function registerIpnUrl(array $payload, array|RequestOptions|null $options = null): mixed
    {
        return $this->authorizedRequest($this->endpoint('register_ipn_url'), $payload, $options);
    }

    public function lipaFare(array $payload, array|RequestOptions|null $options = null): mixed
    {
        return $this->authorizedRequest($this->endpoint('lipa_fare'), $this->withAmount($payload), $options);
    }

    public function transactions(array $query, array|RequestOptions|null $options = null): mixed
    {
        return $this->authorizedGet($this->endpoint('transactions'), $query, $options);
    }

    public function channelCodes(array|RequestOptions|null $options = null): mixed
    {
        return $this->authorizedGet($this->endpoint('channel_codes'), options: $options);
    }

    public function utilityPayment(array $payload, array|RequestOptions|null $options = null): mixed
    {
        return $this->authorizedRequest($this->endpoint('utility_payment'), $payload, $options);
    }

    public function utilityBillQuery(array $payload, array|RequestOptions|null $options = null): mixed
    {
        return $this->authorizedRequest($this->endpoint('utility_bill_query'), $payload, $options);
    }

    public function bulkPayment(array $payload, array|RequestOptions|null $options = null): mixed
    {
        return $this->authorizedRequest($this->endpoint('bulk_payment'), $payload, $options);
    }

    public function bulkPaymentStatus(array $payload, array|RequestOptions|null $options = null): mixed
    {
        return $this->authorizedRequest($this->endpoint('bulk_payment_status'), $payload, $options);
    }

    public function dealerBusinessTypes(array|RequestOptions|null $options = null): mixed
    {
        return $this->authorizedGet($this->endpoint('dealer_business_types'), options: $options);
    }

    public function dealerCountries(array|RequestOptions|null $options = null): mixed
    {
        return $this->authorizedGet($this->endpoint('dealer_countries'), options: $options);
    }

    public function dealerSubCounties(string|int $countyId, array|RequestOptions|null $options = null): mixed
    {
        return $this->authorizedGet($this->endpoint('dealer_sub_counties'), [
            'county_id' => (string) $countyId,
        ], $options);
    }

    public function dealerIndustries(array|RequestOptions|null $options = null): mixed
    {
        return $this->authorizedGet($this->endpoint('dealer_industries'), options: $options);
    }

    public function availableBillNumber(array $query = [], array|RequestOptions|null $options = null): mixed
    {
        return $this->authorizedGet($this->endpoint('available_bill_number'), $query, $options);
    }

    public function merchantOnboarding(array $payload, array|RequestOptions|null $options = null): mixed
    {
        return $this->authorizedRequest($this->endpoint('merchant_onboarding'), $payload, $options);
    }

    public function waasPersonalOnboarding(array $payload, array|RequestOptions|null $options = null): mixed
    {
        return $this->waasAuthorizedRequest($this->waasEndpoint('personal_onboarding'), $payload, $options);
    }

    public function waasConfirmPersonalOnboarding(array $payload, array|RequestOptions|null $options = null): mixed
    {
        return $this->waasAuthorizedRequest($this->waasEndpoint('personal_onboarding_confirmation'), $payload, $options);
    }

    public function waasPersonalKyc(array $payload, array|RequestOptions|null $options = null): mixed
    {
        return $this->waasAuthorizedRequest($this->waasEndpoint('personal_kyc'), $payload, $options);
    }

    public function waasBusinessOnboarding(array $payload, array|RequestOptions|null $options = null): mixed
    {
        return $this->waasAuthorizedRequest($this->waasEndpoint('business_onboarding'), $payload, $options);
    }

    public function waasConfirmBusinessOnboarding(array $payload, array|RequestOptions|null $options = null): mixed
    {
        return $this->waasAuthorizedRequest($this->waasEndpoint('business_onboarding_confirmation'), $payload, $options);
    }

    public function waasBusinessKyc(array $payload, array|RequestOptions|null $options = null): mixed
    {
        return $this->waasAuthorizedRequest($this->waasEndpoint('business_kyc'), $payload, $options);
    }

    public function waasCustomers(array $query, array|RequestOptions|null $options = null): mixed
    {
        return $this->waasAuthorizedGet($this->waasEndpoint('customers'), $query, $options);
    }

    public function waasCustomerDetails(array $payload, array|RequestOptions|null $options = null): mixed
    {
        return $this->waasAuthorizedRequest($this->waasEndpoint('customer_details'), $payload, $options);
    }

    public function waasUpdateCustomerDetails(array $payload, array|RequestOptions|null $options = null): mixed
    {
        return $this->waasAuthorizedRequest($this->waasEndpoint('customer_details_update'), $payload, $options);
    }

    public function waasRequestPayment(array $payload, array|RequestOptions|null $options = null): mixed
    {
        return $this->waasAuthorizedRequest($this->waasEndpoint('request_payment'), $payload, $options);
    }

    public function waasProcessPayment(array $payload, array|RequestOptions|null $options = null): mixed
    {
        return $this->waasAuthorizedRequest($this->waasEndpoint('process_payment'), $payload, $options);
    }

    public function waasMerchantTransfer(array $payload, array|RequestOptions|null $options = null): mixed
    {
        return $this->waasAuthorizedRequest($this->waasEndpoint('merchant_transfers'), $payload, $options);
    }

    public function waasSendMoney(array $payload, array|RequestOptions|null $options = null): mixed
    {
        return $this->waasAuthorizedRequest($this->waasEndpoint('send_money'), $payload, $options);
    }

    public function waasPayBill(array $payload, array|RequestOptions|null $options = null): mixed
    {
        return $this->waasAuthorizedRequest($this->waasEndpoint('pay_bills'), $payload, $options);
    }

    public function waasBulkPayment(array $payload, array|RequestOptions|null $options = null): mixed
    {
        return $this->bulkPayment($payload, $options);
    }

    public function waasBulkPaymentStatus(array $payload, array|RequestOptions|null $options = null): mixed
    {
        return $this->bulkPaymentStatus($payload, $options);
    }

    public function waasCreateSubWallet(array $payload, array|RequestOptions|null $options = null): mixed
    {
        return $this->waasAuthorizedRequest($this->waasEndpoint('create_sub_wallet'), $payload, $options);
    }

    public function waasTransactions(array $query, array|RequestOptions|null $options = null): mixed
    {
        return $this->waasAuthorizedGet($this->waasEndpoint('transactions'), $query, $options);
    }

    public function waasTransactionStatus(array $payload, array|RequestOptions|null $options = null): mixed
    {
        return $this->waasAuthorizedRequest($this->waasEndpoint('transaction_status'), $payload, $options);
    }

    public function waasVerifyTransaction(array $payload, array|RequestOptions|null $options = null): mixed
    {
        return $this->waasAuthorizedRequest($this->waasEndpoint('verify_transaction'), $payload, $options);
    }

    public function waasMerchantBalance(string|int $merchantCode, array|RequestOptions|null $options = null): mixed
    {
        return $this->waasAuthorizedGet($this->waasEndpoint('merchant_balance'), [
            'merchantCode' => (string) $merchantCode,
        ], $options);
    }

    public function waasChannelCodes(array|RequestOptions|null $options = null): mixed
    {
        return $this->waasAuthorizedGet($this->waasEndpoint('channel_codes'), options: $options);
    }

    public function waasCountries(array|RequestOptions|null $options = null): mixed
    {
        return $this->waasAuthorizedGet($this->waasEndpoint('countries'), options: $options);
    }

    public function waasCountrySubRegions(string|int $callingCode, array|RequestOptions|null $options = null): mixed
    {
        return $this->waasAuthorizedGet($this->waasEndpoint('country_sub_regions'), [
            'callingCode' => (string) $callingCode,
        ], $options);
    }

    public function waasIndustries(array|RequestOptions|null $options = null): mixed
    {
        return $this->waasAuthorizedGet($this->waasEndpoint('industries'), options: $options);
    }

    public function waasSubIndustries(string|int $industryId, array|RequestOptions|null $options = null): mixed
    {
        return $this->waasAuthorizedGet($this->waasEndpoint('sub_industries'), [
            'industryId' => (string) $industryId,
        ], $options);
    }

    public function waasBusinessTypes(array|RequestOptions|null $options = null): mixed
    {
        return $this->waasAuthorizedGet($this->waasEndpoint('business_types'), options: $options);
    }

    public function waasProducts(array|RequestOptions|null $options = null): mixed
    {
        return $this->waasAuthorizedGet($this->waasEndpoint('products'), options: $options);
    }

    public function waasNearestAgents(string|float $longitude, string|float $latitude, array|RequestOptions|null $options = null): mixed
    {
        return $this->waasAuthorizedGet($this->waasEndpoint('nearest_agents'), [
            'Longitude' => (string) $longitude,
            'Latitude' => (string) $latitude,
        ], $options);
    }

    public function waasUtilityPayment(array $payload, array|RequestOptions|null $options = null): mixed
    {
        return $this->waasAuthorizedRequest($this->waasEndpoint('utility_payment'), $payload, $options);
    }

    public function waasUtilityBillQuery(array $payload, array|RequestOptions|null $options = null): mixed
    {
        return $this->utilityBillQuery($payload, $options);
    }

    private function authorizedRequest(string $path, array $payload, array|RequestOptions|null $options): mixed
    {
        return $this->sendAuthorized($this->http, $this->tokens, $path, 'POST', $payload, null, $options);
    }

    private function authorizedGet(
        string $path,
        array $query = [],
        array|RequestOptions|null $options = null,
    ): mixed {
        return $this->sendAuthorized($this->http, $this->tokens, $path, 'GET', null, $query, $options);
    }

    private function waasAuthorizedRequest(string $path, array $payload, array|RequestOptions|null $options): mixed
    {
        return $this->sendAuthorized($this->ensureWaasHttp(), $this->ensureWaasTokens(), $path, 'POST', $payload, null, $options);
    }

    private function waasAuthorizedGet(
        string $path,
        array $query = [],
        array|RequestOptions|null $options = null,
    ): mixed {
        return $this->sendAuthorized($this->ensureWaasHttp(), $this->ensureWaasTokens(), $path, 'GET', null, $query, $options);
    }

    private function sendAuthorized(
        HttpTransport $transport,
        AccessTokenProvider $tokens,
        string $path,
        string $method,
        ?array $payload,
        ?array $query,
        array|RequestOptions|null $options,
    ): mixed {
        $requestOptions = RequestOptions::fromArray($options);
        $token = $requestOptions->accessToken ?? $tokens->getAccessToken($requestOptions->forceTokenRefresh);

        $headers = array_merge($requestOptions->headers, [
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ]);

        return $transport->send(
            path: $path,
            method: $method,
            headers: $headers,
            query: $query,
            body: $payload,
            timeoutSeconds: $requestOptions->timeoutSeconds,
            retry: $requestOptions->retry,
        );
    }

    private function withAmount(array $payload): array
    {
        return Payload::stringifyAmount($payload);
    }

    private function endpoint(string $name): string
    {
        return $this->endpoints[$name] ?? self::ENDPOINTS[$name];
    }

    private function waasEndpoint(string $name): string
    {
        return $this->waasEndpoints[$name] ?? self::WAAS_ENDPOINTS[$name];
    }

    private static function resolveBaseUrl(array $config): string
    {
        if (! empty($config['base_url'])) {
            return (string) $config['base_url'];
        }

        if (($config['environment'] ?? 'sandbox') === 'sandbox') {
            return self::SANDBOX_BASE_URL;
        }

        throw new ConfigurationException(
            'SasaPay production base_url must be provided explicitly. The reviewed docs clearly document the sandbox host but do not clearly state a production API host.'
        );
    }

    private static function resolveWaasBaseUrl(array $config): ?string
    {
        if (! empty($config['waas_base_url'])) {
            return (string) $config['waas_base_url'];
        }

        if (($config['environment'] ?? 'sandbox') === 'sandbox') {
            return self::WAAS_SANDBOX_BASE_URL;
        }

        return null;
    }

    private function ensureWaasHttp(): HttpTransport
    {
        if ($this->waasHttp === null) {
            throw new ConfigurationException(
                'SasaPay WAAS production waas_base_url must be provided explicitly.'
            );
        }

        return $this->waasHttp;
    }

    private function ensureWaasTokens(): AccessTokenProvider
    {
        if ($this->waasTokens === null) {
            throw new ConfigurationException(
                'SasaPay WAAS requires either WAAS credentials, shared SasaPay credentials, or a custom token provider.'
            );
        }

        return $this->waasTokens;
    }

    private static function resolveWaasTokenProvider(
        Factory $httpFactory,
        string $baseUrl,
        array $config,
        ?CacheFactory $cacheFactory,
    ): AccessTokenProvider {
        $clientId = $config['waas_client_id'] ?? $config['client_id'] ?? null;
        $clientSecret = $config['waas_client_secret'] ?? $config['client_secret'] ?? null;

        if (empty($clientId) || empty($clientSecret)) {
            throw new ConfigurationException(
                'SasaPay WAAS requires either waas_client_id and waas_client_secret, shared client_id and client_secret, or a custom token provider.'
            );
        }

        $waasConfig = $config;
        $waasConfig['client_id'] = $clientId;
        $waasConfig['client_secret'] = $clientSecret;
        $waasConfig['token_cache_skew_seconds'] = (int) ($config['waas_token_cache_skew_seconds']
            ?? $config['token_cache_skew_seconds']
            ?? 60);

        return ClientCredentialsTokenProvider::forConfig(
            httpFactory: $httpFactory,
            tokenUrl: rtrim($baseUrl, '/').self::TOKEN_PATH,
            config: $waasConfig,
            idKey: 'client_id',
            secretKey: 'client_secret',
            missingCredentialsMessage: 'SasaPay WAAS requires either waas_client_id and waas_client_secret, shared client_id and client_secret, or a custom token provider.',
            cacheFactory: $cacheFactory,
            cacheKey: self::tokenCacheKey('waas', $config, $baseUrl, (string) $clientId),
        );
    }

    private static function resolveEndpoints(array $config, string $key, array $defaults): array
    {
        $endpoints = $defaults;

        foreach ((array) ($config[$key] ?? []) as $name => $path) {
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

    private static function tokenCacheKey(string $variant, array $config, string $baseUrl, string $clientId): string
    {
        $env = (string) ($config['environment'] ?? 'sandbox');

        return 'payments:sasapay:'.$variant.':token:'.sha1($env.'|'.$baseUrl.'|'.$clientId);
    }
}
