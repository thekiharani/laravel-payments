# Laravel Payments

Laravel package for payment providers:

- M-PESA Daraja
- SasaPay v1 merchant APIs
- SasaPay Wallet as a Service (WAAS) v2 APIs
- KCB Buni APIs
- Paystack APIs

The package is a Laravel-native HTTP SDK. It registers container bindings, publishes config, obtains and caches OAuth tokens where providers require them, sends authenticated requests, supports retries and hooks, verifies SasaPay callbacks, KCB Buni IPNs, and Paystack webhooks, and throws typed exceptions for HTTP and network failures.

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
- `NoriaLabs\Payments\KcbBuniClient`
- `NoriaLabs\Payments\KcbBuniIpnVerifier`
- `NoriaLabs\Payments\PaystackClient`
- `NoriaLabs\Payments\PaystackWebhookVerifier`

It also registers the facade alias:

- `Payments`

## Config

Published config file: `config/payments.php`

Top-level sections:

- `http`
- `mpesa`
- `sasapay`
- `kcb_buni`
- `paystack`

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
| `amount_normalization` | M-PESA amount handling. Defaults to `string`; set to `none` to preserve raw numeric `Amount`/`amount` values. |
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
| `amount_normalization` | SasaPay amount handling. Defaults to `string`; set to `none` to preserve raw numeric `Amount`/`amount` values. |
| `payment_defaults` | Optional v1 defaults for `MerchantCode`, `Currency`, and `CallBackURL`. Defaults are added only when the payload omits the key. |
| `waas_payment_defaults` | Optional WAAS defaults for `merchantCode`, `currencyCode`, and `callbackUrl`. Defaults are added only when the payload omits the key. |
| `endpoints` | Optional SasaPay v1 endpoint-path overrides keyed by `SasaPayClient::ENDPOINTS`. |
| `waas_endpoints` | Optional SasaPay WAAS endpoint-path overrides keyed by `SasaPayClient::WAAS_ENDPOINTS`. |
| `callback_security.secret_key` | HMAC secret for inbound callbacks. Defaults to the SasaPay client ID, as documented by SasaPay. |
| `callback_security.trusted_ips` | SasaPay callback source IP allowlist. Defaults to the documented SasaPay list. Override in published config or with comma-separated `SASAPAY_CALLBACK_TRUSTED_IPS`. |
| `callback_security.enforce_ip_whitelist` | Reject callbacks from non-allowlisted IPs when using `verifyRequest()` or the middleware. Defaults to `false`; enable it after Laravel trusted proxy handling is configured for your deployment. |
| `callback_security.verify_signature` | Verify callback HMAC signatures when using `verifyRequest()` or the middleware. Defaults to `true`; set `SASAPAY_CALLBACK_VERIFY_SIGNATURE=false` only if you intentionally rely on a different callback-authentication control. |

SasaPay production hosts are not hard-coded. The reviewed SasaPay docs document sandbox hosts clearly, but production hosts are created through SasaPay production applications. Provide production `base_url` and `waas_base_url` explicitly.

### KCB Buni Config

| Key | Description |
| --- | --- |
| `environment` | Defaults to `uat`, matching the public Buni DevPortal endpoint URLs. |
| `base_url` | Optional full base URL override. Required for any non-`uat` environment because Buni production URLs are not published in the verified docs. |
| `token_url` | Optional full OAuth token URL override. |
| `token_path` | Token path used with `base_url` when `token_url` is unset. Defaults to `/token`. |
| `consumer_key` | Buni application consumer key. |
| `consumer_secret` | Buni application consumer secret. |
| `api_key` | Optional WSO2 `apikey` header value when your subscribed API requires it. The verified M-PESA Express Postman collection used bearer auth without an `apikey` header. |
| `token_cache_skew_seconds` | Refresh token before expiry by this many seconds. |
| `amount_normalization` | KCB Buni M-PESA Express amount handling. Defaults to `string`; set to `none` to preserve raw numeric `amount` values. |
| `cache_store` | Optional KCB Buni-specific token cache store override. |
| `cache_ttl_seconds` | Optional KCB Buni-specific token cache TTL override. |
| `endpoints` | Optional endpoint-path overrides keyed by `KcbBuniClient::ENDPOINTS`. |
| `mpesa_express.route_code` | Required `routeCode` header for `mpesaStkPush()` unless passed per call. Buni's M-PESA Express docs show `207` for M-PESA. |
| `mpesa_express.operation` | `operation` header for `mpesaStkPush()`. Defaults to the documented `STKPush`. |
| `ipn_security.public_key` | KCB public key used to verify inbound IPN `Signature` headers with SHA256withRSA. |
| `ipn_security.trusted_ips` | Optional KCB Buni IPN source IP allowlist. The verified public docs specify signature verification but do not publish a fixed IP list. |
| `ipn_security.enforce_ip_whitelist` | Reject IPNs from non-allowlisted IPs when using `verifyRequest()` or the middleware. Defaults to `false`. |
| `ipn_security.verify_signature` | Verify the IPN `Signature` header over the raw request body. Defaults to `true`. |

KCB Buni token acquisition is `POST /token` with HTTP Basic client credentials and `grant_type=client_credentials` as form data. This was verified against the UAT token endpoint: GET returns HTTP 405, while POST reaches OAuth client validation.

### Paystack Config

| Key | Description |
| --- | --- |
| `base_url` | Paystack API base URL. Defaults to `https://api.paystack.co`. Paystack uses your API key to determine test vs live mode. |
| `secret_key` | Paystack secret key used as the bearer token. |
| `endpoints` | Optional endpoint-path overrides keyed by `PaystackClient::ENDPOINTS`. |
| `webhook_security.secret_key` | HMAC secret for inbound webhooks. Defaults to `PAYSTACK_SECRET_KEY`. |
| `webhook_security.trusted_ips` | Paystack webhook source IP allowlist. Defaults to the documented Paystack list. Override in published config or with comma-separated `PAYSTACK_WEBHOOK_TRUSTED_IPS`. |
| `webhook_security.enforce_ip_whitelist` | Reject webhooks from non-allowlisted IPs when using `verifyRequest()` or the middleware. Defaults to `false`; enable it after Laravel trusted proxy handling is configured for your deployment. |
| `webhook_security.verify_signature` | Verify `x-paystack-signature` HMAC signatures when using `verifyRequest()` or the middleware. Defaults to `true`. |

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

### KCB Buni M-PESA Express

```php
use NoriaLabs\Payments\KcbBuniClient;

$buni = app(KcbBuniClient::class);

$response = $buni->mpesaStkPush([
    'phoneNumber' => '254722000000',
    'amount' => '10',
    'invoiceNumber' => '1234567-INV001',
    'sharedShortCode' => true,
    'orgShortCode' => '',
    'orgPassKey' => '',
    'callbackUrl' => 'https://example.com/kcb-buni/ipn',
    'transactionDescription' => 'school fees',
], messageId: '232323_KCBOrg_8875661561', routeCode: '207');
```

### KCB Buni Funds Transfer

```php
use NoriaLabs\Payments\KcbBuniClient;

$buni = app(KcbBuniClient::class);

$response = $buni->transferFunds([
    'companyCode' => 'KE0010001',
    'transactionType' => 'IF',
    'debitAccountNumber' => '37890012',
    'creditAccountNumber' => '909099090',
    'debitAmount' => 10,
    'paymentDetails' => 'fee payment',
    'transactionReference' => 'MHSGS7883',
    'currency' => 'KES',
    'beneficiaryDetails' => 'JOHN DOE',
]);
```

### Paystack Initialize Transaction

```php
use NoriaLabs\Payments\PaystackClient;

$paystack = app(PaystackClient::class);

$response = $paystack->initializeTransaction([
    'email' => 'customer@example.com',
    'amount' => 10000,
    'currency' => 'NGN',
    'reference' => 'INV-001',
    'callback_url' => 'https://example.com/paystack/callback',
]);
```

### Paystack Webhook Security

Paystack documents two webhook-origin controls:

- verify the `x-paystack-signature` header with HMAC-SHA512 over the raw request body
- verify the request source IP against the Paystack allowlist

Use the middleware on your webhook route:

```php
use NoriaLabs\Payments\Http\Middleware\VerifyPaystackWebhook;

Route::post('/paystack/webhook', PaystackWebhookController::class)
    ->middleware(VerifyPaystackWebhook::class);
```

Or verify manually:

```php
use Illuminate\Http\Request;
use NoriaLabs\Payments\PaystackWebhookVerifier;

public function __invoke(Request $request, PaystackWebhookVerifier $verifier)
{
    if (! $verifier->verifyRequest($request, enforceIpWhitelist: true, verifySignature: true)) {
        abort(403);
    }

    // Process the already-authenticated webhook payload.
}
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

### KCB Buni IPN Security

KCB Buni IPN docs specify a `Signature` header containing a SHA256withRSA signature of the raw request body, signed by KCB and verified with the KCB public key.

Use the middleware on your IPN route:

```php
use NoriaLabs\Payments\Http\Middleware\VerifyKcbBuniIpn;

Route::post('/kcb-buni/ipn', KcbBuniIpnController::class)
    ->middleware(VerifyKcbBuniIpn::class);
```

Or verify manually:

```php
use Illuminate\Http\Request;
use NoriaLabs\Payments\KcbBuniIpnVerifier;

public function __invoke(Request $request, KcbBuniIpnVerifier $verifier)
{
    if (! $verifier->verifyRequest($request, enforceIpWhitelist: true, verifySignature: true)) {
        abort(403);
    }

    // Process the already-authenticated IPN payload.
}
```

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

$paystack = $manager->paystack([
    'secret_key' => config('services.paystack.secret_key'),
]);

$buni = $manager->kcbBuni([
    'base_url' => 'https://your-confirmed-buni-production-host',
    'consumer_key' => config('services.kcb_buni.consumer_key'),
    'consumer_secret' => config('services.kcb_buni.consumer_secret'),
]);
```

## KCB Buni Coverage

The KCB Buni client keeps Buni field names exactly as documented. It does not translate `phoneNumber`, `callbackUrl`, `transactionReference`, or nested request payloads. The only automatic payload normalization is string-casting `amount` for `mpesaStkPush()` by default, matching the Buni M-PESA Express schema. Set `amount_normalization` to `none` when you need to preserve raw JSON number types.

The verified public DevPortal exposes UAT endpoint URLs. Production hosts are not hard-coded; configure `payments.kcb_buni.base_url` after KCB confirms your production endpoint.

### KCB Buni Auth and IPN

| API | Behavior |
| --- | --- |
| `KcbBuniClient::getAccessToken()` | Returns a Buni OAuth token from `POST /token` or a custom token-provider value. |
| `KcbBuniIpnVerifier::verify()` | Validates raw body/signature/IP checks according to configured or per-call toggles. |
| `KcbBuniIpnVerifier::verifyRequest()` | Extracts the raw body, `Signature` header, and IP from a Laravel request. |
| `KcbBuniIpnVerifier::isTrustedIp()` | Checks the configured KCB Buni IPN IP allowlist. |
| `KcbBuniIpnVerifier::verifiesSignature()` | Shows whether signature verification is enabled by default. |
| `VerifyKcbBuniIpn` middleware | Rejects invalid Laravel IPN requests with HTTP 403 according to IPN-security config. |

### KCB Buni Outbound APIs

| Method | Verified endpoint |
| --- | --- |
| `mpesaStkPush($payload, $messageId)` | `POST /mm/api/request/1.0.0/stkpush` |
| `transferFunds()` | `POST /fundstransfer/1.0.0/api/v1/transfer` |
| `queryCoreTransactionStatus()` | `POST /v1/core/t24/querytransaction/1.0.0/api/transactioninfo` |
| `queryTransactionDetails($identifier)` | `GET /kcb/transaction/query/1.0.0/api/v1/payment/query/{identifier}` |
| `vendingValidateRequest()` | `POST /kcb/vendingGateway/v1/1.0.0/api/validate-request` |
| `vendingVendorConfirmation()` | `POST /kcb/vendingGateway/v1/1.0.0/api/vendor-confirmation` |
| `vendingTransactionStatus()` | `POST /kcb/vendingGateway/v1/1.0.0/api/query/transaction-status` |

### KCB Buni Raw Authorized Helpers

Use these when KCB exposes an endpoint before this package has a named high-level method. They use the same token providers, retries, timeout handling, hooks, and exception mapping as the named APIs and do not normalize or rewrite payloads.

| Method | Behavior |
| --- | --- |
| `authorizedPost($path, $payload = [])` | POST on the configured Buni base URL with bearer auth. |
| `authorizedGet($path, $query = [])` | GET on the configured Buni base URL with bearer auth. |

## Paystack Coverage

Paystack uses one API host for test and live mode: `https://api.paystack.co`. The secret key determines the environment. The client keeps Paystack field names and amount units exactly as Paystack documents them; pass amounts in provider subunits.

### Paystack Auth and Webhooks

| API | Behavior |
| --- | --- |
| `PaystackClient::getAccessToken()` | Returns the configured secret key or custom token-provider value. |
| `PaystackClient::authorizedPost()/authorizedGet()/authorizedPut()/authorizedDelete()` | Raw bearer-authenticated helpers for Paystack endpoints not yet represented by named methods. Payloads and query keys are preserved. |
| `PaystackWebhookVerifier::expectedSignature()` | Computes the HMAC-SHA512 hex digest over the raw request body. |
| `PaystackWebhookVerifier::verify()` | Validates raw body/signature/IP checks according to configured or per-call toggles. |
| `PaystackWebhookVerifier::verifyRequest()` | Extracts the raw body, `x-paystack-signature`, and IP from a Laravel request. |
| `PaystackWebhookVerifier::isTrustedIp()` | Checks the documented Paystack webhook IP allowlist. |
| `PaystackWebhookVerifier::trustedIps()` | Returns the active Paystack webhook IP allowlist. |
| `PaystackWebhookVerifier::verifiesSignature()` | Shows whether signature verification is enabled by default. |
| `VerifyPaystackWebhook` middleware | Rejects invalid Laravel webhook requests with HTTP 403 according to webhook-security config. |

### Paystack Transactions, Charge, Bulk Charge, Subaccounts, Splits

| Method | Endpoint |
| --- | --- |
| `initializeTransaction()` | `POST /transaction/initialize` |
| `chargeAuthorization()` | `POST /transaction/charge_authorization` |
| `partialDebit()` | `POST /transaction/partial_debit` |
| `verifyTransaction($reference)` | `GET /transaction/verify/{reference}` |
| `listTransactions()` | `GET /transaction` |
| `fetchTransaction($id)` | `GET /transaction/{id}` |
| `transactionTimeline($id)` | `GET /transaction/timeline/{id}` |
| `transactionTotals()` | `GET /transaction/totals` |
| `exportTransactions()` | `GET /transaction/export` |
| `createCharge()` | `POST /charge` |
| `submitChargePin()` | `POST /charge/submit_pin` |
| `submitChargeOtp()` | `POST /charge/submit_otp` |
| `submitChargePhone()` | `POST /charge/submit_phone` |
| `submitChargeBirthday()` | `POST /charge/submit_birthday` |
| `submitChargeAddress()` | `POST /charge/submit_address` |
| `checkPendingCharge($reference)` | `GET /charge/{reference}` |
| `initiateBulkCharge()` | `POST /bulkcharge` |
| `listBulkChargeBatches()` | `GET /bulkcharge` |
| `fetchBulkChargeBatch($code)` | `GET /bulkcharge/{code}` |
| `fetchBulkChargeBatchCharges($code)` | `GET /bulkcharge/{code}/charges` |
| `pauseBulkChargeBatch($code)` | `GET /bulkcharge/pause/{code}` |
| `resumeBulkChargeBatch($code)` | `GET /bulkcharge/resume/{code}` |
| `createSubaccount()` | `POST /subaccount` |
| `listSubaccounts()` | `GET /subaccount` |
| `fetchSubaccount($code)` | `GET /subaccount/{code}` |
| `updateSubaccount($code)` | `PUT /subaccount/{code}` |
| `createSplit()` | `POST /split` |
| `listSplits()` | `GET /split` |
| `fetchSplit($id)` | `GET /split/{id}` |
| `updateSplit($id)` | `PUT /split/{id}` |
| `addSubaccountToSplit($id)` | `POST /split/{id}/subaccount/add` |
| `removeSubaccountFromSplit($id)` | `POST /split/{id}/subaccount/remove` |

### Paystack Terminals

| Method | Endpoint |
| --- | --- |
| `sendTerminalEvent($id)` | `POST /terminal/{id}/event` |
| `fetchTerminalEventStatus($terminalId, $eventId)` | `GET /terminal/{terminal_id}/event/{event_id}` |
| `fetchTerminalStatus($terminalId)` | `GET /terminal/{terminal_id}/presence` |
| `listTerminals()` | `GET /terminal` |
| `fetchTerminal($terminalId)` | `GET /terminal/{terminal_id}` |
| `updateTerminal($terminalId)` | `PUT /terminal/{terminal_id}` |
| `commissionTerminal()` | `POST /terminal/commission_device` |
| `decommissionTerminal()` | `POST /terminal/decommission_device` |
| `createVirtualTerminal()` | `POST /virtual_terminal` |
| `listVirtualTerminals()` | `GET /virtual_terminal` |
| `fetchVirtualTerminal($code)` | `GET /virtual_terminal/{code}` |
| `updateVirtualTerminal($code)` | `PUT /virtual_terminal/{code}` |
| `deactivateVirtualTerminal($code)` | `PUT /virtual_terminal/{code}/deactivate` |
| `assignVirtualTerminalDestination($code)` | `POST /virtual_terminal/{code}/destination/assign` |
| `unassignVirtualTerminalDestination($code)` | `POST /virtual_terminal/{code}/destination/unassign` |
| `addVirtualTerminalSplitCode($code)` | `PUT /virtual_terminal/{code}/split_code` |
| `removeVirtualTerminalSplitCode($code)` | `DELETE /virtual_terminal/{code}/split_code` |

### Paystack Customers, Direct Debit, Dedicated Accounts, Apple Pay

| Method | Endpoint |
| --- | --- |
| `createCustomer()` | `POST /customer` |
| `listCustomers()` | `GET /customer` |
| `fetchCustomer($code)` | `GET /customer/{code}` |
| `updateCustomer($code)` | `PUT /customer/{code}` |
| `setCustomerRiskAction()` | `POST /customer/set_risk_action` |
| `validateCustomer($code)` | `POST /customer/{code}/identification` |
| `initializeAuthorization()` | `POST /customer/authorization/initialize` |
| `verifyAuthorization($reference)` | `GET /customer/authorization/verify/{reference}` |
| `deactivateAuthorization()` | `POST /customer/authorization/deactivate` |
| `initializeDirectDebit($id)` | `POST /customer/{id}/initialize-direct-debit` |
| `customerDirectDebitActivationCharge($id)` | `PUT /customer/{id}/directdebit-activation-charge` |
| `customerDirectDebitMandateAuthorizations($id)` | `GET /customer/{id}/directdebit-mandate-authorizations` |
| `triggerDirectDebitActivationCharge()` | `PUT /directdebit/activation-charge` |
| `listDirectDebitMandateAuthorizations()` | `GET /directdebit/mandate-authorizations` |
| `createDedicatedAccount()` | `POST /dedicated_account` |
| `listDedicatedAccounts()` | `GET /dedicated_account` |
| `assignDedicatedAccount()` | `POST /dedicated_account/assign` |
| `fetchDedicatedAccount($id)` | `GET /dedicated_account/{id}` |
| `deactivateDedicatedAccount($id)` | `DELETE /dedicated_account/{id}` |
| `requeryDedicatedAccount()` | `GET /dedicated_account/requery` |
| `splitDedicatedAccountTransaction()` | `POST /dedicated_account/split` |
| `removeSplitFromDedicatedAccount()` | `DELETE /dedicated_account/split` |
| `fetchDedicatedAccountProviders()` | `GET /dedicated_account/available_providers` |
| `registerApplePayDomain()` | `POST /apple-pay/domain` |
| `listApplePayDomains()` | `GET /apple-pay/domain` |
| `unregisterApplePayDomain()` | `DELETE /apple-pay/domain` |

### Paystack Plans, Subscriptions, Transfers

| Method | Endpoint |
| --- | --- |
| `createPlan()` | `POST /plan` |
| `listPlans()` | `GET /plan` |
| `fetchPlan($code)` | `GET /plan/{code}` |
| `updatePlan($code)` | `PUT /plan/{code}` |
| `createSubscription()` | `POST /subscription` |
| `listSubscriptions()` | `GET /subscription` |
| `fetchSubscription($code)` | `GET /subscription/{code}` |
| `disableSubscription()` | `POST /subscription/disable` |
| `enableSubscription()` | `POST /subscription/enable` |
| `subscriptionManagementLink($code)` | `GET /subscription/{code}/manage/link` |
| `sendSubscriptionManagementEmail($code)` | `POST /subscription/{code}/manage/email` |
| `createTransferRecipient()` | `POST /transferrecipient` |
| `listTransferRecipients()` | `GET /transferrecipient` |
| `bulkCreateTransferRecipients()` | `POST /transferrecipient/bulk` |
| `fetchTransferRecipient($code)` | `GET /transferrecipient/{code}` |
| `updateTransferRecipient($code)` | `PUT /transferrecipient/{code}` |
| `deleteTransferRecipient($code)` | `DELETE /transferrecipient/{code}` |
| `initiateTransfer()` | `POST /transfer` |
| `listTransfers()` | `GET /transfer` |
| `finalizeTransfer()` | `POST /transfer/finalize_transfer` |
| `initiateBulkTransfer()` | `POST /transfer/bulk` |
| `fetchTransfer($code)` | `GET /transfer/{code}` |
| `verifyTransfer($reference)` | `GET /transfer/verify/{reference}` |
| `exportTransfers()` | `GET /transfer/export` |
| `resendTransferOtp()` | `POST /transfer/resend_otp` |
| `disableTransferOtp()` | `POST /transfer/disable_otp` |
| `finalizeDisableTransferOtp()` | `POST /transfer/disable_otp_finalize` |
| `enableTransferOtp()` | `POST /transfer/enable_otp` |
| `balance()` | `GET /balance` |
| `balanceLedger()` | `GET /balance/ledger` |

### Paystack Payment Requests, Products, Storefronts, Orders, Pages

| Method | Endpoint |
| --- | --- |
| `createPaymentRequest()` | `POST /paymentrequest` |
| `listPaymentRequests()` | `GET /paymentrequest` |
| `fetchPaymentRequest($id)` | `GET /paymentrequest/{id}` |
| `updatePaymentRequest($id)` | `PUT /paymentrequest/{id}` |
| `verifyPaymentRequest($id)` | `GET /paymentrequest/verify/{id}` |
| `notifyPaymentRequest($id)` | `POST /paymentrequest/notify/{id}` |
| `paymentRequestTotals()` | `GET /paymentrequest/totals` |
| `finalizePaymentRequest($id)` | `POST /paymentrequest/finalize/{id}` |
| `archivePaymentRequest($id)` | `POST /paymentrequest/archive/{id}` |
| `createProduct()` | `POST /product` |
| `listProducts()` | `GET /product` |
| `fetchProduct($id)` | `GET /product/{id}` |
| `updateProduct($id)` | `PUT /product/{id}` |
| `deleteProduct($id)` | `DELETE /product/{id}` |
| `createStorefront()` | `POST /storefront` |
| `listStorefronts()` | `GET /storefront` |
| `fetchStorefront($id)` | `GET /storefront/{id}` |
| `updateStorefront($id)` | `PUT /storefront/{id}` |
| `deleteStorefront($id)` | `DELETE /storefront/{id}` |
| `verifyStorefront($slug)` | `GET /storefront/verify/{slug}` |
| `listStorefrontOrders($id)` | `GET /storefront/{id}/order` |
| `addStorefrontProducts($id)` | `POST /storefront/{id}/product` |
| `listStorefrontProducts($id)` | `GET /storefront/{id}/product` |
| `publishStorefront($id)` | `POST /storefront/{id}/publish` |
| `duplicateStorefront($id)` | `POST /storefront/{id}/duplicate` |
| `createOrder()` | `POST /order` |
| `listOrders()` | `GET /order` |
| `fetchOrder($id)` | `GET /order/{id}` |
| `listProductOrders($id)` | `GET /order/product/{id}` |
| `validateOrder($code)` | `GET /order/{code}/validate` |
| `createPage()` | `POST /page` |
| `listPages()` | `GET /page` |
| `fetchPage($id)` | `GET /page/{id}` |
| `updatePage($id)` | `PUT /page/{id}` |
| `checkSlugAvailability($slug)` | `GET /page/check_slug_availability/{slug}` |
| `addProductsToPage($id)` | `POST /page/{id}/product` |

### Paystack Settlements, Integration, Refunds, Disputes, Verification

| Method | Endpoint |
| --- | --- |
| `listSettlements()` | `GET /settlement` |
| `listSettlementTransactions($id)` | `GET /settlement/{id}/transactions` |
| `fetchPaymentSessionTimeout()` | `GET /integration/payment_session_timeout` |
| `updatePaymentSessionTimeout()` | `PUT /integration/payment_session_timeout` |
| `createRefund()` | `POST /refund` |
| `listRefunds()` | `GET /refund` |
| `retryRefundWithCustomerDetails($id)` | `POST /refund/retry_with_customer_details/{id}` |
| `fetchRefund($id)` | `GET /refund/{id}` |
| `listDisputes()` | `GET /dispute` |
| `fetchDispute($id)` | `GET /dispute/{id}` |
| `updateDispute($id)` | `PUT /dispute/{id}` |
| `disputeUploadUrl($id)` | `GET /dispute/{id}/upload_url` |
| `exportDisputes()` | `GET /dispute/export` |
| `transactionDisputes($id)` | `GET /dispute/transaction/{id}` |
| `resolveDispute($id)` | `PUT /dispute/{id}/resolve` |
| `addDisputeEvidence($id)` | `POST /dispute/{id}/evidence` |
| `listBanks()` | `GET /bank` |
| `resolveBankAccount()` | `GET /bank/resolve` |
| `validateBankAccount()` | `POST /bank/validate` |
| `resolveCardBin($bin)` | `GET /decision/bin/{bin}` |
| `listCountries()` | `GET /country` |
| `listAddressVerificationStates()` | `GET /address_verification/states` |


## SasaPay Coverage

The SasaPay client intentionally keeps provider field names as documented. It accepts raw arrays and does not translate `MerchantCode` to `merchantCode`, `CallBackURL` to `callbackUrl`, or similar. Pass the exact payload expected by the specific SasaPay endpoint.

Methods return parsed JSON or text responses. HTTP 4xx/5xx responses throw `ApiException`. A SasaPay business failure returned with HTTP 200 is returned to you as the provider sent it, because SasaPay uses fields such as `status`, `responseCode`, `ResponseCode`, and `statusCode` differently across endpoints.

SasaPay amount fields are string-cast by default for backward compatibility. Pass `new RequestOptions(amountNormalization: 'none')` or set `sasapay.amount_normalization` to `none` when an endpoint requires numeric JSON values.

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
| `transactionStatus()` | `POST /transactions/status/` |
| `transactionStatusQuery()` | `POST /transactions/status-query/` |
| `transactionStatusExact()` | `POST /transactions/status/` |
| `requestPaymentStatus()` | `POST /payments/request-payment/status/` |
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
| `waasPersonalKyc()` | `POST /personal-onboarding/kyc/`; sends multipart when files are provided. |
| `waasBusinessOnboarding()` | `POST /business-onboarding/` |
| `waasConfirmBusinessOnboarding()` | `POST /business-onboarding/confirmation/` |
| `waasBusinessKyc()` | `POST /business-onboarding/kyc/`; sends multipart when files are provided. |
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

### SasaPay Raw Authorized Helpers

Use these when SasaPay exposes an endpoint before this package has a named high-level method. They use the same token providers, retries, timeout handling, hooks, and exception mapping as the named APIs and do not normalize or rewrite payloads.

| Method | Behavior |
| --- | --- |
| `authorizedPost($path, $payload = [])` | POST on the v1 base URL with bearer auth. |
| `authorizedGet($path, $query = [])` | GET on the v1 base URL with bearer auth. |
| `authorizedMultipartPost($path, $fields = [], $files = [])` | Multipart POST on the v1 base URL with bearer auth. |
| `waasAuthorizedPost($path, $payload = [])` | POST on the WAAS base URL with bearer auth. |
| `waasAuthorizedGet($path, $query = [])` | GET on the WAAS base URL with bearer auth. |
| `waasAuthorizedMultipartPost($path, $fields = [], $files = [])` | Multipart POST on the WAAS base URL with bearer auth. |

The SasaPay docs also contain status-code pages. Those pages document static values, not API endpoints, so they are not represented as HTTP methods.

## M-PESA Coverage

| Method | Endpoint |
| --- | --- |
| `getAccessToken()` | `GET /oauth/v1/generate?grant_type=client_credentials` |
| `stkPush()` | `POST /mpesa/stkpush/v1/processrequest` |
| `stkPushQuery()` | `POST /mpesa/stkpushquery/v1/query` |
| `registerC2BUrls()` | `POST /mpesa/c2b/v2/registerurl` by default for backward compatibility; pass `version: 'v1'` for the current documented C2B Register URL path. |
| `registerC2BUrlsV1()` | `POST /mpesa/c2b/v1/registerurl` |
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

M-PESA methods intentionally preserve Daraja field names. The client only string-casts `Amount` or `amount` when present by default and, for the named B2B product helpers, adds the documented `CommandID` only when the caller has not supplied one. Set `amount_normalization` to `none` globally or per request when you need to preserve raw JSON number types.

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
- `MpesaClient::authorizedPost(string $path, array $payload = [], array|RequestOptions|null $options = null): mixed`
- `MpesaClient::authorizedGet(string $path, array $query = [], array|RequestOptions|null $options = null): mixed`

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
| `amount_normalization` | Per-request override for providers that normalize amount fields by default. Use `none` to preserve raw numeric `Amount` and `amount` values for SasaPay, M-PESA, and KCB Buni M-PESA Express calls. |

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
$client = app(\NoriaLabs\Payments\PaymentsManager::class)->paystack(
    overrides: [],
    tokenProvider: new MyTokenProvider(),
);
```

When you supply a custom token provider:

- the package does not call a provider OAuth token endpoint or use a configured static secret for that client
- provider credentials become optional for that runtime client
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

For all supported providers, most important operations are asynchronous.

Treat the immediate response as accepted, queued, or processing unless the provider explicitly says otherwise. Final status usually arrives by callback, IPN, transaction-status query, or verification endpoint.

For SasaPay callbacks, KCB Buni IPNs, and Paystack webhooks, verify the provider signature before mutating local order, wallet, or ledger state.

## Development Quality

Run the same quality gates locally that CI enforces:

```bash
composer quality

# Or run each gate separately:
composer format:test
composer analyse
composer test:coverage
```

Use `composer format` to apply Laravel Pint formatting.

## M-PESA Documentation References

The M-PESA Daraja endpoint matrix was aligned with Safaricom's public Daraja portal and official Safaricom SDK references:

- https://developer.safaricom.co.ke/
- https://developer.safaricom.co.ke/apis
- https://developer.safaricom.co.ke/c2b/apis/post/registerurl
- https://developer.safaricom.co.ke/lipa-na-m-pesa-online/apis/post/stkpush/v1/processrequest
- https://github.com/safaricom/mpesa-node-library

## KCB Buni Documentation References

The KCB Buni endpoint matrix was aligned with the public Buni DevPortal API metadata, OpenAPI documents, and M-PESA Express Postman/PDF artifacts available on May 3, 2026:

- https://buni.kcbgroup.com/discover-apis
- https://sandbox.buni.kcbgroup.com/api/am/devportal/v3/apis
- https://sandbox.buni.kcbgroup.com/api/am/devportal/v3/apis/6396efd5-de10-4b04-adec-128f54349614
- https://sandbox.buni.kcbgroup.com/api/am/devportal/v3/apis/6396efd5-de10-4b04-adec-128f54349614/swagger

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

## Paystack Documentation References

The Paystack endpoint matrix and webhook security behavior were aligned with Paystack's public developer docs and official OpenAPI repository:

- https://paystack.com/docs/api
- https://paystack.com/docs/payments/webhooks/
- https://github.com/PaystackOSS/openapi
