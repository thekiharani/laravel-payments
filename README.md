# Laravel Payments

Laravel package for payment providers, starting with:

- M-PESA Daraja
- SasaPay v1 merchant APIs
- SasaPay Wallet as a Service (WAAS) v2 APIs

The package is a Laravel-native HTTP SDK. It registers container bindings, publishes config, obtains and caches OAuth tokens, sends authenticated requests, supports retries and hooks, verifies SasaPay callback signatures, and throws typed exceptions for HTTP and network failures.

It does not persist transactions, define your application callback controllers, reconcile settlements, or transform provider callback payloads. Your application owns those concerns.

## Requirements

- PHP 8.2+
- Laravel 11, 12, or 13

## Installation

```bash
composer require thekiharani/laravel-payments
```

The service provider is auto-discovered. Publish the config:

```bash
php artisan vendor:publish --tag="payments-config"
```

## Bindings

The package registers:

- `NoriaLabs\Payments\PaymentsManager`
- `NoriaLabs\Payments\MpesaClient`
- `NoriaLabs\Payments\SasaPayClient`
- `NoriaLabs\Payments\SasaPayCallbackVerifier`

It also registers the facade alias:

- `Payments`

## Config

Published config file: `config/payments.php`

Top-level sections:

- `http`
- `mpesa`
- `sasapay`

### Shared HTTP Config

| Key | Description |
| --- | --- |
| `timeout_seconds` | Default request timeout. |
| `default_headers` | Headers applied to every provider request. |
| `user_agent` | Optional `User-Agent` fallback applied when `default_headers` does not already include one. |
| `cache_store` | Optional Laravel cache store for provider OAuth tokens. Use `true` or `default` for the default store. Leave unset to use only per-client in-memory token caching. |
| `cache_ttl_seconds` | Optional OAuth-token cache TTL override. When omitted, token `expires_in` is used. |
| `retry.max_attempts` | Total attempts including the first request. |
| `retry.retry_methods` | Methods eligible for retry, for example `POST`. Empty means all methods. |
| `retry.retry_on_statuses` | HTTP statuses eligible for retry. |
| `retry.retry_on_network_error` | Whether connection failures/timeouts are retried. |
| `retry.base_delay_seconds` | Initial retry delay. |
| `retry.max_delay_seconds` | Maximum retry delay. |
| `retry.backoff_multiplier` | Retry delay multiplier. |
| `retry.jitter_seconds` | Maximum random jitter added to computed backoff delays. |
| `retry.respect_retry_after` | Whether retryable HTTP responses should honor a `Retry-After` header before using configured backoff. |

### M-PESA Config

| Key | Description |
| --- | --- |
| `environment` | `sandbox` or `production`. |
| `base_url` | Optional full base URL override. |
| `consumer_key` | Daraja consumer key. |
| `consumer_secret` | Daraja consumer secret. |
| `token_cache_skew_seconds` | Refresh token before expiry by this many seconds. |
| `b2c_version` | Default B2C payment API version. Defaults to `v1`; set `MPESA_B2C_VERSION=v3` only when your Daraja app is enabled for the v3 B2C path. |
| `cache_store` | Optional M-PESA-specific token cache store override. |
| `cache_ttl_seconds` | Optional M-PESA-specific token cache TTL override. |
| `endpoints` | Optional endpoint-path overrides keyed by `MpesaClient::ENDPOINTS`. Useful when Safaricom enables tenant-specific or newer product paths. |

### SasaPay Config

| Key | Description |
| --- | --- |
| `environment` | `sandbox` or `production`. |
| `base_url` | SasaPay v1 base URL. Sandbox defaults to `https://sandbox.sasapay.app/api/v1`. Production must be supplied explicitly. |
| `waas_base_url` | SasaPay WAAS v2 base URL. Sandbox defaults to `https://sandbox.sasapay.app/api/v2/waas`. Production must be supplied explicitly before using WAAS methods. |
| `client_id` | SasaPay v1 client ID. Also used for WAAS unless WAAS-specific credentials are configured. |
| `client_secret` | SasaPay v1 client secret. Also used for WAAS unless WAAS-specific credentials are configured. |
| `waas_client_id` | Optional WAAS-specific client ID. |
| `waas_client_secret` | Optional WAAS-specific client secret. |
| `token_cache_skew_seconds` | v1 token cache skew. |
| `waas_token_cache_skew_seconds` | WAAS token cache skew. |
| `cache_store` | Optional SasaPay-specific token cache store override. |
| `cache_ttl_seconds` | Optional SasaPay-specific token cache TTL override. |
| `endpoints` | Optional SasaPay v1 endpoint-path overrides keyed by `SasaPayClient::ENDPOINTS`. |
| `waas_endpoints` | Optional SasaPay WAAS endpoint-path overrides keyed by `SasaPayClient::WAAS_ENDPOINTS`. |
| `callback_security.secret_key` | HMAC secret for inbound callbacks. Defaults to the SasaPay client ID, as documented by SasaPay. |
| `callback_security.trusted_ips` | SasaPay callback source IP allowlist. Defaults to the documented SasaPay list. Override in published config or with comma-separated `SASAPAY_CALLBACK_TRUSTED_IPS`. |
| `callback_security.enforce_ip_whitelist` | Reject callbacks from non-allowlisted IPs when using `verifyRequest()` or the middleware. Defaults to `false`; enable it after Laravel trusted proxy handling is configured for your deployment. |
| `callback_security.verify_signature` | Verify callback HMAC signatures when using `verifyRequest()` or the middleware. Defaults to `true`; set `SASAPAY_CALLBACK_VERIFY_SIGNATURE=false` only if you intentionally rely on a different callback-authentication control. |

SasaPay production hosts are not hard-coded. The reviewed SasaPay docs document sandbox hosts clearly, but production hosts are created through SasaPay production applications. Provide production `base_url` and `waas_base_url` explicitly.

## Usage

### M-PESA

```php
use NoriaLabs\Payments\MpesaClient;

$mpesa = app(MpesaClient::class);

$timestamp = MpesaClient::buildTimestamp();

$response = $mpesa->stkPush([
    'BusinessShortCode' => '174379',
    'Password' => MpesaClient::buildStkPassword('174379', config('services.mpesa.passkey'), $timestamp),
    'Timestamp' => $timestamp,
    'TransactionType' => 'CustomerPayBillOnline',
    'Amount' => 1,
    'PartyA' => '254700000000',
    'PartyB' => '174379',
    'PhoneNumber' => '254700000000',
    'CallBackURL' => 'https://example.com/mpesa/callback',
    'AccountReference' => 'INV-001',
    'TransactionDesc' => 'Payment',
]);
```

### SasaPay v1 C2B

```php
use NoriaLabs\Payments\SasaPayClient;

$sasapay = app(SasaPayClient::class);

$response = $sasapay->requestPayment([
    'MerchantCode' => '600980',
    'NetworkCode' => '63902',
    'Currency' => 'KES',
    'Amount' => '1.00',
    'PhoneNumber' => '254700000080',
    'AccountReference' => '12345678',
    'TransactionDesc' => 'Request Payment',
    'CallBackURL' => 'https://example.com/sasapay/callback',
]);
```

### SasaPay WAAS Request Payment

```php
use NoriaLabs\Payments\SasaPayClient;

$sasapay = app(SasaPayClient::class);

$response = $sasapay->waasRequestPayment([
    'merchantReference' => 'TOPUP-001',
    'merchantCode' => '600980',
    'networkCode' => '63902',
    'mobileNumber' => '254700000080',
    'receiverAccountNumber' => '600980-1',
    'amount' => '50',
    'transactionFee' => '0',
    'currencyCode' => 'KES',
    'transactionDesc' => 'Wallet topup',
    'callbackUrl' => 'https://example.com/sasapay/waas/callback',
]);
```

### SasaPay Callback Security

SasaPay documents two callback/IPN controls:

- verify the request source IP against the SasaPay allowlist
- verify the callback signature with HMAC-SHA512

The signed message format is:

```text
sasapay_transaction_code-merchant_code-account_number-payment_reference-amount
```

The HMAC secret is the Merchant API Client ID unless you override `payments.sasapay.callback_security.secret_key`. Signature verification and IP allowlisting are independent controls:

- `SASAPAY_CALLBACK_VERIFY_SIGNATURE=true|false`
- `SASAPAY_CALLBACK_ENFORCE_IP_WHITELIST=true|false`
- `SASAPAY_CALLBACK_TRUSTED_IPS=203.0.113.10,198.51.100.25`

Use the middleware on your callback route:

```php
use NoriaLabs\Payments\Http\Middleware\VerifySasaPayCallback;

Route::post('/sasapay/callback', SasaPayCallbackController::class)
    ->middleware(VerifySasaPayCallback::class);
```

Or verify manually:

```php
use Illuminate\Http\Request;
use NoriaLabs\Payments\SasaPayCallbackVerifier;

public function __invoke(Request $request, SasaPayCallbackVerifier $verifier)
{
    if (! $verifier->verifyRequest($request, enforceIpWhitelist: true, verifySignature: true)) {
        abort(403);
    }

    // Process the already-authenticated callback payload.
}
```

The verifier accepts documented SasaPay callback field names only; it does not infer case variants or provider-specific names that are not in SasaPay's published callback examples. The documented signature field is `sasapay_signature`; if your application receives the signature through another transport, pass it explicitly to `verify($payload, signature: $value)`.

Canonical callback fields include documented aliases across C2B, IPN, checkout/card, B2C, B2B, remittance, utilities, WAAS, and bulk status payloads:

| Canonical field | Documented aliases |
| --- | --- |
| `sasapay_transaction_code` | `sasapay_transaction_code`, `TransactionCode`, `TransID`, `SasaPayTransactionCode` |
| `sasapay_transaction_id` | `SasaPayTransactionID` |
| `third_party_transaction_id` | `ThirdPartyTransID`, `ThirdPartyTransactionCode`, `third_party_transaction_code` |
| `merchant_code` | `merchant_code`, `merchantCode`, `MerchantCode`, `BusinessShortCode` |
| `account_number` | `account_number`, `accountNumber`, `AccountNumber`, `CustomerMobile`, `MSISDN`, `RecipientAccountNumber`, `BeneficiaryAccountNumber`, `SenderAccountNumber`, `ContactNumber`, `DestinationAccountNumber` |
| `checkout_request_id` | `CheckoutRequestID`, `CheckoutRequestId`, `checkoutRequestId` |
| `payment_reference` | `payment_reference`, `BillRefNumber`, `InvoiceNumber`, `MerchantReference`, `merchantReference`, `MerchantTransactionReference`, `TransactionReference`, `transactionReference`, `PaymentRequestID`, `MerchantRequestID`, `bulk_payment_reference` |
| `amount` | `amount`, `TransactionAmount`, `TransAmount`, `AmountPaid`, `PaidAmount`, `Amount`, `RequestedAmount` |

`third_party_transaction_id` and `sasapay_transaction_id` are intentionally not treated as SasaPay transaction-code aliases. Amount formatting is part of the signature input, so keep the exact provider value, for example `1500.00`.

## Manager Usage

Use the manager when you want custom runtime clients instead of the default container bindings:

```php
use NoriaLabs\Payments\PaymentsManager;

$manager = app(PaymentsManager::class);

$sasapay = $manager->sasapay([
    'environment' => 'production',
    'base_url' => 'https://your-confirmed-production-host/api/v1',
    'waas_base_url' => 'https://your-confirmed-production-host/api/v2/waas',
    'default_headers' => [
        'X-App-Name' => 'billing',
    ],
]);
```

## SasaPay Coverage

The SasaPay client intentionally keeps provider field names as documented. It accepts raw arrays and does not translate `MerchantCode` to `merchantCode`, `CallBackURL` to `callbackUrl`, or similar. Pass the exact payload expected by the specific SasaPay endpoint.

Methods return parsed JSON or text responses. HTTP 4xx/5xx responses throw `ApiException`. A SasaPay business failure returned with HTTP 200 is returned to you as the provider sent it, because SasaPay uses fields such as `status`, `responseCode`, `ResponseCode`, and `statusCode` differently across endpoints.

### SasaPay Callback Security

| API | Behavior |
| --- | --- |
| `SasaPayCallbackVerifier::message()` | Builds the documented `sasapay_transaction_code-merchant_code-account_number-payment_reference-amount` message. |
| `SasaPayCallbackVerifier::expectedSignature()` | Computes the HMAC-SHA512 hex digest. |
| `SasaPayCallbackVerifier::verify()` | Validates payload/signature/IP checks according to configured or per-call toggles. |
| `SasaPayCallbackVerifier::verifyRequest()` | Extracts payload, signature, and IP from a Laravel request, then applies the configured or per-call toggles. |
| `SasaPayCallbackVerifier::callbackValue()` | Reads a canonical callback field from any supported alias, for example `third_party_transaction_id`. |
| `SasaPayCallbackVerifier::fieldAliases()` | Returns supported aliases for a canonical callback field. |
| `SasaPayCallbackVerifier::isTrustedIp()` | Checks the documented SasaPay callback IP allowlist. |
| `SasaPayCallbackVerifier::verifiesSignature()` | Shows whether signature verification is enabled by default. |
| `VerifySasaPayCallback` middleware | Rejects invalid Laravel callback requests with HTTP 403 according to callback-security config. |

### SasaPay v1 Auth

| Method | Endpoint |
| --- | --- |
| `getAccessToken()` | `GET /auth/token/?grant_type=client_credentials` |

### SasaPay v1 Payments

| Method | Endpoint |
| --- | --- |
| `requestPayment()` | `POST /payments/request-payment/` |
| `processPayment()` | `POST /payments/process-payment/` |
| `b2cPayment()` | `POST /payments/b2c/` |
| `b2bPayment()` | `POST /payments/b2b/` |
| `cardPayment()` | `POST /payments/card-payments/` |
| `preApprovedPayment()` | `POST /payments/approved/` |
| `remittancePayment()` | `POST /remittances/remittance-payments/` |
| `businessToBeneficiary()` | `POST /payments/b2c/beneficiary/` |
| `registerIpnUrl()` | `POST /payments/register-ipn-url/` |
| `lipaFare()` | `POST /payments/lipa-fare/` |
| `bulkPayment()` | `POST /payments/bulk-payments/` |
| `bulkPaymentStatus()` | `POST /payments/bulk-payments/status/` |

### SasaPay v1 Transactions, Balances, Validation, Utilities

| Method | Endpoint |
| --- | --- |
| `accountValidation()` | `POST /accounts/account-validation/` |
| `internalFundMovement()` | `POST /transactions/fund-movement/` |
| `transactionStatus()` | `POST /transactions/status-query/` |
| `merchantBalance($merchantCode)` | `GET /payments/check-balance/?MerchantCode=...` |
| `verifyTransaction()` | `POST /transactions/verify/` |
| `transactions()` | `GET /transactions/` |
| `channelCodes()` | `GET /payments/channel-codes/` |
| `utilityPayment()` | `POST /utilities/` |
| `utilityBillQuery()` | `POST /utilities/bill-query` |

### SasaPay v1 Dealer Onboarding

| Method | Endpoint |
| --- | --- |
| `dealerBusinessTypes()` | `GET /accounts/business-types/` |
| `dealerCountries()` | `GET /accounts/countries/` |
| `dealerSubCounties($countyId)` | `GET /accounts/sub-counties/?county_id=...` |
| `dealerIndustries()` | `GET /accounts/industries/` |
| `availableBillNumber()` | `GET /accounts/available-bill-number/` |
| `merchantOnboarding()` | `POST /accounts/merchant-onboarding/` |

### SasaPay WAAS Auth

| Method | Endpoint |
| --- | --- |
| `getWaasAccessToken()` | `GET /auth/token/?grant_type=client_credentials` on the WAAS base URL |

### SasaPay WAAS Onboarding and Customers

| Method | Endpoint |
| --- | --- |
| `waasPersonalOnboarding()` | `POST /personal-onboarding/` |
| `waasConfirmPersonalOnboarding()` | `POST /personal-onboarding/confirmation/` |
| `waasPersonalKyc()` | `POST /personal-onboarding/kyc/` |
| `waasBusinessOnboarding()` | `POST /business-onboarding/` |
| `waasConfirmBusinessOnboarding()` | `POST /business-onboarding/confirmation/` |
| `waasBusinessKyc()` | `POST /business-onboarding/kyc/` |
| `waasCustomers()` | `GET /customers/` |
| `waasCustomerDetails()` | `POST /customer-details/` |
| `waasUpdateCustomerDetails()` | `POST /customer-details/update/` |
| `waasCreateSubWallet()` | `POST /sub-wallets/` |

### SasaPay WAAS Payments

| Method | Endpoint |
| --- | --- |
| `waasRequestPayment()` | `POST /payments/request-payment/` |
| `waasProcessPayment()` | `POST /payments/process-payment/` |
| `waasMerchantTransfer()` | `POST /payments/merchant-transfers/` |
| `waasSendMoney()` | `POST /payments/send-money/` |
| `waasPayBill()` | `POST /payments/pay-bills/` |
| `waasBulkPayment()` | Alias of v1 `bulkPayment()` because the current WAAS docs point to `/api/v1/payments/bulk-payments/`. |
| `waasBulkPaymentStatus()` | Alias of v1 `bulkPaymentStatus()` for the same reason. |

### SasaPay WAAS Transactions, Balances, Lookups, Utilities

| Method | Endpoint |
| --- | --- |
| `waasTransactions()` | `GET /transactions/` |
| `waasTransactionStatus()` | `POST /transactions/status/` |
| `waasVerifyTransaction()` | `POST /transactions/verify/` |
| `waasMerchantBalance($merchantCode)` | `GET /merchant-balances/?merchantCode=...` |
| `waasChannelCodes()` | `GET /channel-codes/` |
| `waasCountries()` | `GET /countries/` |
| `waasCountrySubRegions($callingCode)` | `GET /countries/sub-regions/?callingCode=...` |
| `waasIndustries()` | `GET /industries/` |
| `waasSubIndustries($industryId)` | `GET /sub-industries/?industryId=...` |
| `waasBusinessTypes()` | `GET /business-types/` |
| `waasProducts()` | `GET /products/` |
| `waasNearestAgents($longitude, $latitude)` | `GET /nearest-agent/?Longitude=...&Latitude=...` |
| `waasUtilityPayment()` | `POST /utilities/` |
| `waasUtilityBillQuery()` | Alias of v1 `utilityBillQuery()` because the current WAAS utilities docs point bill query to `/api/v1/utilities/bill-query`. |

The SasaPay docs also contain status-code pages. Those pages document static values, not API endpoints, so they are not represented as HTTP methods.

## M-PESA Coverage

| Method | Endpoint |
| --- | --- |
| `getAccessToken()` | `GET /oauth/v1/generate?grant_type=client_credentials` |
| `stkPush()` | `POST /mpesa/stkpush/v1/processrequest` |
| `stkPushQuery()` | `POST /mpesa/stkpushquery/v1/query` |
| `registerC2BUrls()` | `POST /mpesa/c2b/{version}/registerurl` |
| `c2bSimulate()` | `POST /mpesa/c2b/v1/simulate` |
| `b2cPayment()` | `POST /mpesa/b2c/{version}/paymentrequest` |
| `b2cPaymentV3()` | `POST /mpesa/b2c/v3/paymentrequest` |
| `b2bPayment()` | `POST /mpesa/b2b/v1/paymentrequest` |
| `b2cAccountTopUp()` | `POST /mpesa/b2b/v1/paymentrequest` with `CommandID=BusinessPayToBulk` unless already supplied. |
| `businessPayBill()` | `POST /mpesa/b2b/v1/paymentrequest` with `CommandID=BusinessPayBill` unless already supplied. |
| `businessBuyGoods()` | `POST /mpesa/b2b/v1/paymentrequest` with `CommandID=BusinessBuyGoods` unless already supplied. |
| `b2bExpressCheckout()` | `POST /v1/ussdpush/get-msisdn` |
| `reversal()` | `POST /mpesa/reversal/v1/request` |
| `transactionStatus()` | `POST /mpesa/transactionstatus/v1/query` |
| `accountBalance()` | `POST /mpesa/accountbalance/v1/query` |
| `generateQrCode()` | `POST /mpesa/qrcode/v1/generate` |
| `taxRemittance()` | `POST /mpesa/b2b/v1/remittax` |
| `billManagerOptIn()` | `POST /v1/billmanager-invoice/optin` |
| `billManagerSingleInvoice()` | `POST /v1/billmanager-invoice/single-invoicing` |
| `ratibaStandingOrder()` | `POST /standingorder/v1/createStandingOrderExternal` |
| `pullTransactions()` | `POST /pulltransactions/v1/query` |

M-PESA methods intentionally preserve Daraja field names. The client only string-casts `Amount` or `amount` when present and, for the named B2B product helpers, adds the documented `CommandID` only when the caller has not supplied one.

Endpoint overrides are available when a Daraja tenant is provisioned with a different path:

```php
use NoriaLabs\Payments\Facades\Payments;

$mpesa = Payments::mpesa([
    'endpoints' => [
        'b2c_payment' => '/mpesa/b2c/v3/paymentrequest',
    ],
]);
```

Helper methods:

- `MpesaClient::buildTimestamp(?DateTimeInterface $dateTime = null): string`
- `MpesaClient::buildStkPassword(string $businessShortCode, string $passkey, string $timestamp): string`

## Request Options

Every provider method accepts either:

- `null`
- `array`
- `NoriaLabs\Payments\Support\RequestOptions`

Fields:

| Field | Description |
| --- | --- |
| `headers` | Request-specific headers. |
| `timeout_seconds` | Request-specific timeout. |
| `retry` | Request-specific retry policy or `false` to disable retries. |
| `access_token` | Explicit bearer token override. |
| `force_token_refresh` | Forces the next token lookup to refresh. |

Example:

```php
use NoriaLabs\Payments\Support\RequestOptions;
use NoriaLabs\Payments\Support\RetryPolicy;

$response = $sasapay->requestPayment($payload, new RequestOptions(
    headers: ['X-Request-Id' => 'abc-123'],
    timeoutSeconds: 15.0,
    retry: new RetryPolicy(
        maxAttempts: 2,
        retryMethods: ['POST'],
        retryOnStatuses: [500, 502, 503, 504],
        baseDelaySeconds: 0.25,
    ),
));
```

## Custom Token Providers

Implement `NoriaLabs\Payments\Contracts\AccessTokenProvider`:

```php
use NoriaLabs\Payments\Contracts\AccessTokenProvider;

class MyTokenProvider implements AccessTokenProvider
{
    public function getAccessToken(bool $forceRefresh = false): string
    {
        return 'my-token';
    }
}
```

Inject via the manager:

```php
$sasapay = app(\NoriaLabs\Payments\PaymentsManager::class)->sasapay(
    overrides: [],
    tokenProvider: new MyTokenProvider(),
);
```

When you supply a custom token provider:

- the package does not call the provider OAuth token endpoint for that client
- provider credentials become optional
- you own token freshness

## Request Hooks

Use `NoriaLabs\Payments\Support\Hooks` to observe and mutate transport behavior:

```php
use NoriaLabs\Payments\Support\Hooks;

$hooks = new Hooks(
    beforeRequest: function ($context): void {
        $context->headers['X-Correlation-Id'] = 'corr-123';
    },
    afterResponse: function ($context): void {
        logger()->info('payment response', [
            'url' => $context->url,
            'status' => $context->response->status(),
        ]);
    },
    onError: function ($context): void {
        logger()->error('payment error', [
            'url' => $context->url,
            'error' => $context->error->getMessage(),
        ]);
    },
);
```

Hook contexts expose:

- `BeforeRequestContext`: `url`, `path`, `method`, `headers`, `body`, `attempt`
- `AfterResponseContext`: plus `response`, `responseBody`
- `ErrorContext`: plus `error`, optional `response`, optional `responseBody`

## Error Classes

The package throws:

- `NoriaLabs\Payments\Exceptions\ConfigurationException`
- `NoriaLabs\Payments\Exceptions\AuthenticationException`
- `NoriaLabs\Payments\Exceptions\TimeoutException`
- `NoriaLabs\Payments\Exceptions\NetworkException`
- `NoriaLabs\Payments\Exceptions\ApiException`

`ApiException` includes:

- `statusCode`
- `responseBody`
- `details`

## Async Settlement

For both providers, most important operations are asynchronous.

Treat the immediate response as accepted, queued, or processing unless the provider explicitly says otherwise. Final status usually arrives by callback, IPN, transaction-status query, or verification endpoint.

For SasaPay callbacks, verify the callback signature before mutating local order, wallet, or ledger state.

## SasaPay Documentation References

The SasaPay endpoint matrix was aligned with the public SasaPay docs:

- https://docs.sasapay.app/docs/introduction/
- https://docs.sasapay.app/docs/authentication/
- https://docs.sasapay.app/docs/customerTobusiness/
- https://docs.sasapay.app/docs/b2c/
- https://docs.sasapay.app/docs/b2b/
- https://docs.sasapay.app/docs/channel-codes/
- https://docs.sasapay.app/docs/waas/introduction/
- https://docs.sasapay.app/docs/waas/auth/
- https://docs.sasapay.app/docs/waas/getchannelcodes/
- https://developer.sasapay.app/docs/apis/callback-security?country=ke
