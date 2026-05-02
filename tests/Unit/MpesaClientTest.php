<?php

use Illuminate\Support\Facades\Http;
use NoriaLabs\Payments\Contracts\AccessTokenProvider;
use NoriaLabs\Payments\Exceptions\ConfigurationException;
use NoriaLabs\Payments\MpesaClient;
use NoriaLabs\Payments\Support\Hooks;
use NoriaLabs\Payments\Support\RequestOptions;

function mpesaTokenProvider(string $token = 'mpesa-token'): AccessTokenProvider
{
    return new class($token) implements AccessTokenProvider
    {
        public function __construct(private readonly string $token) {}

        public function getAccessToken(bool $forceRefresh = false): string
        {
            return $forceRefresh ? $this->token.'-fresh' : $this->token;
        }
    };
}

it('authenticates and sends stk push requests', function (): void {
    Http::fake([
        'https://sandbox.safaricom.co.ke/oauth/custom*' => Http::response([
            'access_token' => 'token-123',
            'expires_in' => 3599,
        ], 200),
        'https://sandbox.safaricom.co.ke/mpesa/stkpush/v1/processrequest' => Http::response([
            'ResponseCode' => '0',
            'CheckoutRequestID' => 'ws_CO_123',
        ], 200),
    ]);

    $client = MpesaClient::make(Http::getFacadeRoot(), [
        'environment' => 'sandbox',
        'consumer_key' => 'consumer-key',
        'consumer_secret' => 'consumer-secret',
        'endpoints' => [
            'oauth_token' => '/oauth/custom',
        ],
    ]);

    $response = $client->stkPush([
        'BusinessShortCode' => '174379',
        'Password' => 'password',
        'Timestamp' => '20250102030405',
        'TransactionType' => 'CustomerPayBillOnline',
        'Amount' => 1,
        'PartyA' => '254700000000',
        'PartyB' => '174379',
        'PhoneNumber' => '254700000000',
        'CallBackURL' => 'https://example.com/callback',
        'AccountReference' => 'INV-001',
        'TransactionDesc' => 'Payment',
    ]);

    expect($response['ResponseCode'])->toBe('0');

    Http::assertSent(function ($request): bool {
        return $request->url() === 'https://sandbox.safaricom.co.ke/mpesa/stkpush/v1/processrequest'
            && $request['Amount'] === '1'
            && $request->hasHeader('Authorization', 'Bearer token-123');
    });
});

it('supports external token providers hooks and request headers', function (): void {
    Http::fake([
        'https://sandbox.safaricom.co.ke/mpesa/accountbalance/v1/query' => Http::response([
            'ResponseCode' => '0',
        ], 200),
    ]);

    $calls = 0;

    $tokenProvider = new class($calls) implements AccessTokenProvider
    {
        public int $calls = 0;

        public function __construct(int &$calls)
        {
            $this->calls = &$calls;
        }

        public function getAccessToken(bool $forceRefresh = false): string
        {
            $this->calls++;

            return 'external-token';
        }
    };

    $hooks = new Hooks(
        beforeRequest: function ($context): void {
            $context->headers['X-Hook-Header'] = 'hooked';
        },
    );

    $client = MpesaClient::make(Http::getFacadeRoot(), [
        'environment' => 'sandbox',
        'default_headers' => ['X-Client-Header' => 'client'],
    ], $tokenProvider, $hooks);

    $client->accountBalance([
        'Initiator' => 'apiuser',
        'SecurityCredential' => 'EncryptedPassword',
        'CommandID' => 'AccountBalance',
        'PartyA' => '600000',
        'IdentifierType' => '4',
        'ResultURL' => 'https://example.com/result',
        'QueueTimeOutURL' => 'https://example.com/timeout',
        'Remarks' => 'Account balance',
    ], [
        'headers' => ['X-Request-Header' => 'request'],
    ]);

    expect($calls)->toBe(1);

    Http::assertSent(function ($request): bool {
        return $request->hasHeader('Authorization', 'Bearer external-token')
            && $request->hasHeader('X-Client-Header', 'client')
            && $request->hasHeader('X-Request-Header', 'request')
            && $request->hasHeader('X-Hook-Header', 'hooked');
    });
});

it('maps every supported daraja product endpoint without reshaping payloads', function (): void {
    Http::fake([
        'https://custom.safaricom.test/*' => Http::response(['ResponseCode' => '0'], 200),
    ]);

    $tokenProvider = new class implements AccessTokenProvider
    {
        public function getAccessToken(bool $forceRefresh = false): string
        {
            return 'mapped-token';
        }
    };

    $client = MpesaClient::make(Http::getFacadeRoot(), [
        'base_url' => 'https://custom.safaricom.test',
        'environment' => 'production',
        'b2c_version' => 'v3',
        'endpoints' => [
            'c2b_simulate' => '/custom/c2b/simulate',
            'pull_transactions' => null,
        ],
    ], $tokenProvider);

    $client->stkPush(['Amount' => 1, 'AccountReference' => 'INV-001']);
    $client->stkPushQuery(['CheckoutRequestID' => 'checkout']);
    $client->registerC2BUrls(['ShortCode' => '600000']);
    $client->registerC2BUrlsV1(['ShortCode' => '600000']);
    $client->c2bSimulate(['Amount' => 2, 'BillRefNumber' => 'INV-002']);
    $client->b2cPayment(['Amount' => 3]);
    $client->b2cPayment(['Amount' => 4], version: 'v1');
    $client->b2cPaymentV3(['Amount' => 5]);
    $client->b2bPayment(['Amount' => 7]);
    $client->b2cAccountTopUp(['Amount' => 8]);
    $client->businessPayBill(['Amount' => 9]);
    $client->businessBuyGoods(['Amount' => 10]);
    $client->businessBuyGoods(['Amount' => 11, 'CommandID' => 'CustomCommand']);
    $client->b2bExpressCheckout(['amount' => 12]);
    $client->reversal(['Amount' => 13]);
    $client->transactionStatus(['TransactionID' => 'ABC']);
    $client->accountBalance(['PartyA' => '600000']);
    $client->generateQrCode(['Amount' => 14]);
    $client->taxRemittance(['Amount' => 15]);
    $client->billManagerOptIn(['shortcode' => '600000']);
    $client->billManagerSingleInvoice(['amount' => 16]);
    $client->ratibaStandingOrder(['Amount' => 17]);
    $client->pullTransactions(['ShortCode' => '600000']);

    $urls = collect(Http::recorded())->map(fn (array $record): string => $record[0]->url())->all();

    expect($urls)->toContain(
        'https://custom.safaricom.test/mpesa/stkpush/v1/processrequest',
        'https://custom.safaricom.test/mpesa/stkpushquery/v1/query',
        'https://custom.safaricom.test/mpesa/c2b/v2/registerurl',
        'https://custom.safaricom.test/mpesa/c2b/v1/registerurl',
        'https://custom.safaricom.test/custom/c2b/simulate',
        'https://custom.safaricom.test/mpesa/b2c/v3/paymentrequest',
        'https://custom.safaricom.test/mpesa/b2c/v1/paymentrequest',
        'https://custom.safaricom.test/mpesa/b2b/v1/paymentrequest',
        'https://custom.safaricom.test/v1/ussdpush/get-msisdn',
        'https://custom.safaricom.test/mpesa/reversal/v1/request',
        'https://custom.safaricom.test/mpesa/transactionstatus/v1/query',
        'https://custom.safaricom.test/mpesa/accountbalance/v1/query',
        'https://custom.safaricom.test/mpesa/qrcode/v1/generate',
        'https://custom.safaricom.test/mpesa/b2b/v1/remittax',
        'https://custom.safaricom.test/v1/billmanager-invoice/optin',
        'https://custom.safaricom.test/v1/billmanager-invoice/single-invoicing',
        'https://custom.safaricom.test/standingorder/v1/createStandingOrderExternal',
        'https://custom.safaricom.test/pulltransactions/v1/query',
    );

    Http::assertSent(fn ($request): bool => ($request->data()['Amount'] ?? null) === '1');
    Http::assertSent(fn ($request): bool => ($request->data()['amount'] ?? null) === '12');
    Http::assertSent(fn ($request): bool => ($request->data()['CommandID'] ?? null) === 'BusinessPayToBulk');
    Http::assertSent(fn ($request): bool => ($request->data()['CommandID'] ?? null) === 'BusinessPayBill');
    Http::assertSent(fn ($request): bool => ($request->data()['CommandID'] ?? null) === 'BusinessBuyGoods');
    Http::assertSent(fn ($request): bool => ($request->data()['CommandID'] ?? null) === 'CustomCommand'
        && ($request->data()['Amount'] ?? null) === '11');
});

it('can preserve raw mpesa amount values when normalization is disabled', function (): void {
    Http::fake([
        'https://custom.safaricom.test/*' => Http::response(['ResponseCode' => '0'], 200),
    ]);

    $rawByConfig = MpesaClient::make(Http::getFacadeRoot(), [
        'base_url' => 'https://custom.safaricom.test',
        'environment' => 'production',
        'amount_normalization' => 'none',
    ], mpesaTokenProvider('raw-token'));

    $rawByConfig->stkPush(['Amount' => 100]);

    $rawByRequest = MpesaClient::make(Http::getFacadeRoot(), [
        'base_url' => 'https://custom.safaricom.test',
        'environment' => 'production',
    ], mpesaTokenProvider('raw-token'));

    $rawByRequest->c2bSimulate(['amount' => 100.50], new RequestOptions(amountNormalization: 'none'));

    Http::assertSent(fn ($request): bool => $request->url() === 'https://custom.safaricom.test/mpesa/stkpush/v1/processrequest'
        && $request->data()['Amount'] === 100);
    Http::assertSent(fn ($request): bool => $request->url() === 'https://custom.safaricom.test/mpesa/c2b/v1/simulate'
        && $request->data()['amount'] === 100.50);
});

it('exposes raw authorized mpesa helpers without rewriting payloads', function (): void {
    Http::fake([
        'https://custom.safaricom.test/custom/raw' => Http::response(['status' => 'posted'], 200),
        'https://custom.safaricom.test/custom/raw-get*' => Http::response(['status' => 'fetched'], 200),
    ]);

    $client = MpesaClient::make(Http::getFacadeRoot(), [
        'base_url' => 'https://custom.safaricom.test',
        'environment' => 'production',
    ], mpesaTokenProvider('helper-token'));

    expect($client->authorizedPost('/custom/raw', ['Amount' => 100])['status'])->toBe('posted')
        ->and($client->authorizedGet('/custom/raw-get', ['Receipt' => 'ABC123'])['status'])->toBe('fetched');

    Http::assertSent(fn ($request): bool => $request->method() === 'POST'
        && $request->url() === 'https://custom.safaricom.test/custom/raw'
        && $request->data()['Amount'] === 100
        && $request->hasHeader('Authorization', 'Bearer helper-token'));
    Http::assertSent(fn ($request): bool => $request->method() === 'GET'
        && $request->url() === 'https://custom.safaricom.test/custom/raw-get?Receipt=ABC123'
        && $request->hasHeader('Authorization', 'Bearer helper-token'));
});

it('uses the configured user_agent when default_headers omits one', function (): void {
    Http::fake([
        'https://sandbox.safaricom.co.ke/mpesa/stkpushquery/v1/query' => Http::response(['ResponseCode' => '0'], 200),
    ]);

    $client = MpesaClient::make(Http::getFacadeRoot(), [
        'environment' => 'sandbox',
        'user_agent' => 'laravel-payments/test',
    ], mpesaTokenProvider('token'));

    $client->stkPushQuery(['CheckoutRequestID' => 'checkout']);

    Http::assertSent(fn ($request): bool => $request->hasHeader('User-Agent', 'laravel-payments/test'));
});

it('keeps a User-Agent supplied via default_headers and ignores user_agent fallback', function (): void {
    Http::fake([
        'https://sandbox.safaricom.co.ke/mpesa/stkpushquery/v1/query' => Http::response(['ResponseCode' => '0'], 200),
    ]);

    $client = MpesaClient::make(Http::getFacadeRoot(), [
        'environment' => 'sandbox',
        'default_headers' => ['User-Agent' => 'explicit/1.0'],
        'user_agent' => 'should-be-ignored',
    ], mpesaTokenProvider('token'));

    $client->stkPushQuery(['CheckoutRequestID' => 'checkout']);

    Http::assertSent(fn ($request): bool => $request->hasHeader('User-Agent', 'explicit/1.0'));
});

it('exposes deterministic helpers for stk timestamps and password hashing', function (): void {
    expect(MpesaClient::buildTimestamp(new DateTimeImmutable('2026-04-28 10:11:12')))
        ->toBe('20260428101112')
        ->and(strlen(MpesaClient::buildTimestamp()))->toBe(14)
        ->and(MpesaClient::buildStkPassword('174379', 'passkey', '20260428101112'))
        ->toBe(base64_encode('174379passkey20260428101112'));
});

it('exposes the access token from the configured provider', function (): void {
    Http::fake([
        'https://custom.safaricom.test/*' => Http::response(['ResponseCode' => '0'], 200),
    ]);

    $client = MpesaClient::make(Http::getFacadeRoot(), [
        'base_url' => 'https://custom.safaricom.test',
        'environment' => 'production',
    ], mpesaTokenProvider('mpesa-token'));

    expect($client->getAccessToken())->toBe('mpesa-token')
        ->and($client->getAccessToken(forceRefresh: true))->toBe('mpesa-token-fresh');
});

it('throws ConfigurationException when no credentials and no token provider are supplied', function (): void {
    expect(fn () => MpesaClient::make(Http::getFacadeRoot(), [
        'environment' => 'sandbox',
    ]))->toThrow(ConfigurationException::class);
});
