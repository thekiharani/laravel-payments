<?php

use Illuminate\Support\Facades\Http;
use NoriaLabs\Payments\Contracts\AccessTokenProvider;
use NoriaLabs\Payments\Exceptions\ConfigurationException;
use NoriaLabs\Payments\SasaPayClient;
use NoriaLabs\Payments\Support\HttpTransport;
use NoriaLabs\Payments\Support\RequestOptions;
use NoriaLabs\Payments\Support\RetryPolicy;

it('requires explicit production base url for sasapay', function (): void {
    SasaPayClient::make(Http::getFacadeRoot(), [
        'environment' => 'production',
        'client_id' => 'client-id',
        'client_secret' => 'client-secret',
    ]);
})->throws(ConfigurationException::class);

it('requests token and sends c2b payment', function (): void {
    Http::fake([
        'https://sandbox.sasapay.app/api/v1/auth/token/*' => Http::response([
            'status' => true,
            'access_token' => 'sasapay-token',
            'expires_in' => 3600,
        ], 200),
        'https://sandbox.sasapay.app/api/v1/payments/request-payment/' => Http::response([
            'status' => true,
            'ResponseCode' => '0',
        ], 200),
    ]);

    $client = SasaPayClient::make(Http::getFacadeRoot(), [
        'environment' => 'sandbox',
        'client_id' => 'client-id',
        'client_secret' => 'client-secret',
    ]);

    $response = $client->requestPayment([
        'MerchantCode' => '600980',
        'NetworkCode' => '63902',
        'Currency' => 'KES',
        'Amount' => 1,
        'PhoneNumber' => '254700000080',
        'AccountReference' => '12345678',
        'TransactionDesc' => 'Request Payment',
        'CallBackURL' => 'https://example.com/callback',
    ]);

    expect($response['ResponseCode'])->toBe('0');

    Http::assertSent(function ($request): bool {
        return $request->url() === 'https://sandbox.sasapay.app/api/v1/payments/request-payment/'
            && $request['Amount'] === '1'
            && $request->hasHeader('Authorization', 'Bearer sasapay-token');
    });
});

it('supports per request access token overrides', function (): void {
    Http::fake([
        'https://sandbox.sasapay.app/api/v1/payments/process-payment/' => Http::response([
            'status' => true,
            'detail' => 'Transaction is being processed',
        ], 200),
    ]);

    $tokenProvider = new class implements AccessTokenProvider
    {
        public function getAccessToken(bool $forceRefresh = false): string
        {
            throw new RuntimeException('token provider should not be called');
        }
    };

    $client = SasaPayClient::make(Http::getFacadeRoot(), [
        'environment' => 'sandbox',
    ], $tokenProvider);

    $client->processPayment([
        'MerchantCode' => '600980',
        'CheckoutRequestID' => 'checkout-123',
        'VerificationCode' => '123456',
    ], new RequestOptions(
        headers: ['X-Request-Id' => 'abc-123'],
        accessToken: 'manual-token',
    ));

    Http::assertSent(function ($request): bool {
        return $request->hasHeader('Authorization', 'Bearer manual-token')
            && $request->hasHeader('X-Request-Id', 'abc-123');
    });
});

it('retries post requests only when explicitly enabled', function (): void {
    Http::fakeSequence()
        ->push(['access_token' => 'sasapay-token', 'expires_in' => 3600], 200)
        ->push(['detail' => 'temporary failure'], 500)
        ->push(['status' => true, 'ResponseCode' => '0'], 200);

    $client = SasaPayClient::make(Http::getFacadeRoot(), [
        'environment' => 'sandbox',
        'client_id' => 'client-id',
        'client_secret' => 'client-secret',
    ]);

    $response = $client->requestPayment([
        'MerchantCode' => '600980',
        'NetworkCode' => '63902',
        'Currency' => 'KES',
        'Amount' => '1.00',
        'PhoneNumber' => '254700000080',
        'AccountReference' => '12345678',
        'TransactionDesc' => 'Request Payment',
        'CallBackURL' => 'https://example.com/callback',
    ], [
        'retry' => new RetryPolicy(
            maxAttempts: 2,
            retryMethods: ['POST'],
            retryOnStatuses: [500],
        ),
    ]);

    expect($response['ResponseCode'])->toBe('0');
});

it('maps documented non waas sasapay endpoints', function (): void {
    Http::fake([
        'https://sandbox.sasapay.app/api/v1/*' => Http::response([
            'status' => true,
            'ResponseCode' => '0',
        ], 200),
    ]);

    $tokenProvider = new class implements AccessTokenProvider
    {
        public function getAccessToken(bool $forceRefresh = false): string
        {
            return 'token';
        }
    };

    $client = SasaPayClient::make(Http::getFacadeRoot(), [
        'environment' => 'sandbox',
    ], $tokenProvider);

    $client->cardPayment(['Amount' => 10]);
    $client->preApprovedPayment(['Amount' => 10]);
    $client->remittancePayment(['Amount' => 10]);
    $client->accountValidation(['merchant_code' => '600980']);
    $client->internalFundMovement(['merchantCode' => '600980', 'amount' => 10]);
    $client->transactionStatus(['MerchantCode' => '600980']);
    $client->merchantBalance('600980');
    $client->verifyTransaction(['merchantCode' => '600980']);
    $client->businessToBeneficiary(['Amount' => 10]);
    $client->registerIpnUrl(['MerchantCode' => '600980']);
    $client->lipaFare(['Amount' => 10]);
    $client->transactions(['merchant_code' => '600980']);
    $client->channelCodes();
    $client->utilityPayment(['merchantCode' => '600980']);
    $client->utilityBillQuery(['merchantCode' => '600980']);
    $client->bulkPayment(['merchant_code' => '600980']);
    $client->bulkPaymentStatus(['merchant_code' => '600980']);
    $client->dealerBusinessTypes();
    $client->dealerCountries();
    $client->dealerSubCounties(47);
    $client->dealerIndustries();
    $client->availableBillNumber();
    $client->merchantOnboarding(['business_type_id' => 1]);

    $requests = collect(Http::recorded())->map(fn (array $record): array => [
        'method' => $record[0]->method(),
        'url' => $record[0]->url(),
    ]);

    expect($requests->pluck('url')->all())->toContain(
        'https://sandbox.sasapay.app/api/v1/payments/card-payments/',
        'https://sandbox.sasapay.app/api/v1/payments/approved/',
        'https://sandbox.sasapay.app/api/v1/remittances/remittance-payments/',
        'https://sandbox.sasapay.app/api/v1/accounts/account-validation/',
        'https://sandbox.sasapay.app/api/v1/transactions/fund-movement/',
        'https://sandbox.sasapay.app/api/v1/transactions/status-query/',
        'https://sandbox.sasapay.app/api/v1/payments/check-balance/?MerchantCode=600980',
        'https://sandbox.sasapay.app/api/v1/transactions/verify/',
        'https://sandbox.sasapay.app/api/v1/payments/b2c/beneficiary/',
        'https://sandbox.sasapay.app/api/v1/payments/register-ipn-url/',
        'https://sandbox.sasapay.app/api/v1/payments/lipa-fare/',
        'https://sandbox.sasapay.app/api/v1/transactions/?merchant_code=600980',
        'https://sandbox.sasapay.app/api/v1/payments/channel-codes/',
        'https://sandbox.sasapay.app/api/v1/utilities/',
        'https://sandbox.sasapay.app/api/v1/utilities/bill-query',
        'https://sandbox.sasapay.app/api/v1/payments/bulk-payments/',
        'https://sandbox.sasapay.app/api/v1/payments/bulk-payments/status/',
        'https://sandbox.sasapay.app/api/v1/accounts/business-types/',
        'https://sandbox.sasapay.app/api/v1/accounts/countries/',
        'https://sandbox.sasapay.app/api/v1/accounts/sub-counties/?county_id=47',
        'https://sandbox.sasapay.app/api/v1/accounts/industries/',
        'https://sandbox.sasapay.app/api/v1/accounts/available-bill-number/',
        'https://sandbox.sasapay.app/api/v1/accounts/merchant-onboarding/',
    );

    expect($requests->where('method', 'GET')->pluck('url')->all())->toContain(
        'https://sandbox.sasapay.app/api/v1/payments/check-balance/?MerchantCode=600980',
        'https://sandbox.sasapay.app/api/v1/transactions/?merchant_code=600980',
        'https://sandbox.sasapay.app/api/v1/accounts/business-types/',
    );

    Http::assertSent(function ($request): bool {
        return $request->url() === 'https://sandbox.sasapay.app/api/v1/payments/card-payments/'
            && $request['Amount'] === '10';
    });
});

it('authenticates against the documented waas token endpoint', function (): void {
    Http::fake([
        'https://sandbox.sasapay.app/api/v2/waas/auth/token/*' => Http::response([
            'status' => true,
            'access_token' => 'waas-token',
            'expires_in' => 3600,
            'token_type' => 'Bearer',
        ], 200),
        'https://sandbox.sasapay.app/api/v2/waas/payments/request-payment/' => Http::response([
            'status' => true,
            'responseCode' => '0',
        ], 200),
    ]);

    $client = SasaPayClient::make(Http::getFacadeRoot(), [
        'environment' => 'sandbox',
        'client_id' => 'client-id',
        'client_secret' => 'client-secret',
    ]);

    $response = $client->waasRequestPayment([
        'merchantReference' => 'REF-001',
        'merchantCode' => '600980',
        'networkCode' => '63902',
        'mobileNumber' => '254700000080',
        'receiverAccountNumber' => '600980-1',
        'amount' => '50',
        'currencyCode' => 'KES',
        'transactionDesc' => 'Wallet topup',
        'callbackUrl' => 'https://example.com/callback',
    ]);

    expect($response['responseCode'])->toBe('0');

    Http::assertSent(function ($request): bool {
        return $request->url() === 'https://sandbox.sasapay.app/api/v2/waas/auth/token/?grant_type=client_credentials'
            && $request->hasHeader('Authorization', 'Basic '.base64_encode('client-id:client-secret'));
    });

    Http::assertSent(function ($request): bool {
        return $request->url() === 'https://sandbox.sasapay.app/api/v2/waas/payments/request-payment/'
            && $request->hasHeader('Authorization', 'Bearer waas-token');
    });
});

it('maps documented waas endpoints', function (): void {
    Http::fake([
        'https://sandbox.sasapay.app/api/v2/waas/*' => Http::response([
            'status' => true,
            'responseCode' => '0',
        ], 200),
        'https://sandbox.sasapay.app/api/v1/*' => Http::response([
            'status' => true,
            'ResponseCode' => '0',
        ], 200),
    ]);

    $tokenProvider = new class implements AccessTokenProvider
    {
        public function getAccessToken(bool $forceRefresh = false): string
        {
            return 'token';
        }
    };

    $client = SasaPayClient::make(Http::getFacadeRoot(), [
        'environment' => 'sandbox',
    ], $tokenProvider);

    $client->waasPersonalOnboarding(['merchantCode' => '600980']);
    $client->waasConfirmPersonalOnboarding(['merchantCode' => '600980']);
    $client->waasPersonalKyc(['merchantCode' => '600980']);
    $client->waasBusinessOnboarding(['merchantCode' => '600980']);
    $client->waasConfirmBusinessOnboarding(['merchantCode' => '600980']);
    $client->waasBusinessKyc(['merchantCode' => '600980']);
    $client->waasCustomers(['merchant_code' => '600980']);
    $client->waasCustomerDetails(['merchantCode' => '600980']);
    $client->waasUpdateCustomerDetails(['merchantCode' => '600980']);
    $client->waasRequestPayment(['merchantCode' => '600980']);
    $client->waasProcessPayment(['merchantCode' => '600980']);
    $client->waasMerchantTransfer(['merchantCode' => '600980']);
    $client->waasSendMoney(['merchantCode' => '600980']);
    $client->waasPayBill(['merchantCode' => '600980']);
    $client->waasCreateSubWallet(['merchantCode' => '600980']);
    $client->waasTransactions(['merchantCode' => '600980', 'accountNumber' => '600980-1']);
    $client->waasTransactionStatus(['merchantCode' => '600980']);
    $client->waasVerifyTransaction(['merchantCode' => '600980']);
    $client->waasMerchantBalance('600980');
    $client->waasChannelCodes();
    $client->waasCountries();
    $client->waasCountrySubRegions(254);
    $client->waasIndustries();
    $client->waasSubIndustries(62);
    $client->waasBusinessTypes();
    $client->waasProducts();
    $client->waasNearestAgents('36.8157532', '-1.2827683');
    $client->waasUtilityPayment(['merchantCode' => '600980']);
    $client->waasUtilityBillQuery(['merchantCode' => '600980']);

    $urls = collect(Http::recorded())->map(fn (array $record): string => $record[0]->url())->all();

    expect($urls)->toContain(
        'https://sandbox.sasapay.app/api/v2/waas/personal-onboarding/',
        'https://sandbox.sasapay.app/api/v2/waas/personal-onboarding/confirmation/',
        'https://sandbox.sasapay.app/api/v2/waas/personal-onboarding/kyc/',
        'https://sandbox.sasapay.app/api/v2/waas/business-onboarding/',
        'https://sandbox.sasapay.app/api/v2/waas/business-onboarding/confirmation/',
        'https://sandbox.sasapay.app/api/v2/waas/business-onboarding/kyc/',
        'https://sandbox.sasapay.app/api/v2/waas/customers/?merchant_code=600980',
        'https://sandbox.sasapay.app/api/v2/waas/customer-details/',
        'https://sandbox.sasapay.app/api/v2/waas/customer-details/update/',
        'https://sandbox.sasapay.app/api/v2/waas/payments/request-payment/',
        'https://sandbox.sasapay.app/api/v2/waas/payments/process-payment/',
        'https://sandbox.sasapay.app/api/v2/waas/payments/merchant-transfers/',
        'https://sandbox.sasapay.app/api/v2/waas/payments/send-money/',
        'https://sandbox.sasapay.app/api/v2/waas/payments/pay-bills/',
        'https://sandbox.sasapay.app/api/v2/waas/sub-wallets/',
        'https://sandbox.sasapay.app/api/v2/waas/transactions/?merchantCode=600980&accountNumber=600980-1',
        'https://sandbox.sasapay.app/api/v2/waas/transactions/status/',
        'https://sandbox.sasapay.app/api/v2/waas/transactions/verify/',
        'https://sandbox.sasapay.app/api/v2/waas/merchant-balances/?merchantCode=600980',
        'https://sandbox.sasapay.app/api/v2/waas/channel-codes/',
        'https://sandbox.sasapay.app/api/v2/waas/countries/',
        'https://sandbox.sasapay.app/api/v2/waas/countries/sub-regions/?callingCode=254',
        'https://sandbox.sasapay.app/api/v2/waas/industries/',
        'https://sandbox.sasapay.app/api/v2/waas/sub-industries/?industryId=62',
        'https://sandbox.sasapay.app/api/v2/waas/business-types/',
        'https://sandbox.sasapay.app/api/v2/waas/products/',
        'https://sandbox.sasapay.app/api/v2/waas/nearest-agent/?Longitude=36.8157532&Latitude=-1.2827683',
        'https://sandbox.sasapay.app/api/v2/waas/utilities/',
        'https://sandbox.sasapay.app/api/v1/utilities/bill-query',
    );
});

it('honors per-provider endpoint overrides for both v1 and waas', function (): void {
    Http::fake([
        'https://sandbox.sasapay.app/api/v1/custom/request-payment' => Http::response(['ResponseCode' => '0'], 200),
        'https://sandbox.sasapay.app/api/v2/waas/custom/send-money' => Http::response(['ResponseCode' => '0'], 200),
    ]);

    $tokenProvider = new class implements AccessTokenProvider
    {
        public function getAccessToken(bool $forceRefresh = false): string
        {
            return 'token';
        }
    };

    $client = SasaPayClient::make(Http::getFacadeRoot(), [
        'environment' => 'sandbox',
        'endpoints' => [
            'request_payment' => '/custom/request-payment',
        ],
        'waas_endpoints' => [
            'send_money' => '/custom/send-money',
        ],
    ], $tokenProvider);

    $client->requestPayment(['Amount' => 1]);
    $client->waasSendMoney(['amount' => 1]);

    Http::assertSent(fn ($request): bool => $request->url() === 'https://sandbox.sasapay.app/api/v1/custom/request-payment');
    Http::assertSent(fn ($request): bool => $request->url() === 'https://sandbox.sasapay.app/api/v2/waas/custom/send-money');
});

it('uses configured user_agent when default_headers omits one', function (): void {
    Http::fake([
        'https://sandbox.sasapay.app/api/v1/payments/check-balance/*' => Http::response(['status' => true], 200),
    ]);

    $tokenProvider = new class implements AccessTokenProvider
    {
        public function getAccessToken(bool $forceRefresh = false): string
        {
            return 'token';
        }
    };

    $client = SasaPayClient::make(Http::getFacadeRoot(), [
        'environment' => 'sandbox',
        'user_agent' => 'laravel-payments/sasapay',
    ], $tokenProvider);

    $client->merchantBalance('600980');

    Http::assertSent(fn ($request): bool => $request->hasHeader('User-Agent', 'laravel-payments/sasapay'));
});

it('keeps a User-Agent supplied via default_headers and ignores the user_agent fallback', function (): void {
    Http::fake([
        'https://sandbox.sasapay.app/api/v1/payments/check-balance/*' => Http::response(['status' => true], 200),
    ]);

    $tokenProvider = new class implements AccessTokenProvider
    {
        public function getAccessToken(bool $forceRefresh = false): string
        {
            return 'token';
        }
    };

    $client = SasaPayClient::make(Http::getFacadeRoot(), [
        'environment' => 'sandbox',
        'default_headers' => ['User-Agent' => 'explicit-sasapay/1.0'],
        'user_agent' => 'should-be-ignored',
    ], $tokenProvider);

    $client->merchantBalance('600980');

    Http::assertSent(fn ($request): bool => $request->hasHeader('User-Agent', 'explicit-sasapay/1.0'));
});

it('exposes v1 and waas tokens via the client when a custom provider is supplied', function (): void {
    Http::fake([
        'https://custom.sasapay.test/*' => Http::response(['status' => true], 200),
    ]);

    $tokenProvider = new class implements AccessTokenProvider
    {
        public function getAccessToken(bool $forceRefresh = false): string
        {
            return $forceRefresh ? 'sasapay-token-fresh' : 'sasapay-token';
        }
    };

    $client = SasaPayClient::make(Http::getFacadeRoot(), [
        'base_url' => 'https://custom.sasapay.test/api/v1',
        'waas_base_url' => 'https://custom.sasapay.test/api/v2/waas',
        'environment' => 'production',
    ], $tokenProvider);

    expect($client->getAccessToken(forceRefresh: true))->toBe('sasapay-token-fresh')
        ->and($client->getWaasAccessToken())->toBe('sasapay-token');
});

it('throws when v1 credentials are missing and no custom token provider is supplied', function (): void {
    expect(fn () => SasaPayClient::make(Http::getFacadeRoot(), [
        'environment' => 'sandbox',
    ]))->toThrow(ConfigurationException::class, 'SasaPayClient requires either client_id and client_secret');
});

it('throws when shared and waas credentials are both blank', function (): void {
    expect(fn () => SasaPayClient::make(Http::getFacadeRoot(), [
        'environment' => 'sandbox',
        'client_id' => 'client',
        'client_secret' => 'secret',
        'waas_client_id' => '',
        'waas_client_secret' => '',
    ]))->toThrow(ConfigurationException::class, 'SasaPay WAAS requires either waas_client_id');
});

it('throws ConfigurationException when waas methods are called without a waas base url', function (): void {
    $tokenProvider = new class implements AccessTokenProvider
    {
        public function getAccessToken(bool $forceRefresh = false): string
        {
            return 'token';
        }
    };

    $client = SasaPayClient::make(Http::getFacadeRoot(), [
        'environment' => 'production',
        'base_url' => 'https://custom.sasapay.test/api/v1',
    ], $tokenProvider);

    expect(fn () => $client->waasChannelCodes())
        ->toThrow(ConfigurationException::class, 'SasaPay WAAS production waas_base_url must be provided explicitly.');
});

it('throws ConfigurationException when waas tokens are absent on direct construction', function (): void {
    $transport = new HttpTransport(Http::getFacadeRoot(), 'https://custom.sasapay.test/api/v1');
    $tokenProvider = new class implements AccessTokenProvider
    {
        public function getAccessToken(bool $forceRefresh = false): string
        {
            return 'token';
        }
    };

    $client = new SasaPayClient(
        http: $transport,
        tokens: $tokenProvider,
        waasHttp: $transport,
    );

    expect(fn () => $client->getWaasAccessToken())
        ->toThrow(ConfigurationException::class, 'SasaPay WAAS requires either WAAS credentials');
});

it('exposes b2c, b2b, register_ipn_url, lipa_fare and bulk-payment paths against custom hosts', function (): void {
    Http::fake([
        'https://custom.sasapay.test/api/v1/*' => Http::response(['status' => true], 200),
        'https://custom.sasapay.test/api/v2/waas/*' => Http::response(['status' => true], 200),
    ]);

    $tokenProvider = new class implements AccessTokenProvider
    {
        public function getAccessToken(bool $forceRefresh = false): string
        {
            return 'token';
        }
    };

    $client = SasaPayClient::make(Http::getFacadeRoot(), [
        'base_url' => 'https://custom.sasapay.test/api/v1',
        'waas_base_url' => 'https://custom.sasapay.test/api/v2/waas',
        'environment' => 'production',
    ], $tokenProvider);

    $client->b2cPayment(['Amount' => 10]);
    $client->b2bPayment(['Amount' => 20]);
    $client->registerIpnUrl(['MerchantCode' => '600980']);
    $client->waasBulkPayment(['merchantCode' => '600980']);
    $client->waasBulkPaymentStatus(['merchantCode' => '600980']);
    $client->waasUtilityBillQuery(['merchantCode' => '600980']);

    $urls = collect(Http::recorded())->map(fn (array $record): string => $record[0]->url())->all();

    expect($urls)->toContain(
        'https://custom.sasapay.test/api/v1/payments/b2c/',
        'https://custom.sasapay.test/api/v1/payments/b2b/',
        'https://custom.sasapay.test/api/v1/payments/register-ipn-url/',
        'https://custom.sasapay.test/api/v1/payments/bulk-payments/',
        'https://custom.sasapay.test/api/v1/payments/bulk-payments/status/',
        'https://custom.sasapay.test/api/v1/utilities/bill-query',
    );
});
