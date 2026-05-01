<?php

use NoriaLabs\Payments\Contracts\AccessTokenProvider;
use NoriaLabs\Payments\Facades\Payments;
use NoriaLabs\Payments\KcbBuniClient;
use NoriaLabs\Payments\KcbBuniIpnVerifier;
use NoriaLabs\Payments\MpesaClient;
use NoriaLabs\Payments\PaymentsManager;
use NoriaLabs\Payments\PaystackClient;
use NoriaLabs\Payments\PaystackWebhookVerifier;
use NoriaLabs\Payments\SasaPayCallbackVerifier;
use NoriaLabs\Payments\SasaPayClient;

function managerTokenProvider(string $token = 'token'): AccessTokenProvider
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

it('builds provider clients and verifiers via the manager and the container', function (): void {
    config()->set('payments.mpesa.consumer_key', 'consumer');
    config()->set('payments.mpesa.consumer_secret', 'secret');
    config()->set('payments.sasapay.client_id', 'client');
    config()->set('payments.sasapay.client_secret', 'secret');
    config()->set('payments.kcb_buni.consumer_key', 'consumer');
    config()->set('payments.kcb_buni.consumer_secret', 'secret');
    config()->set('payments.paystack.secret_key', 'sk_test_secret');

    $manager = app(PaymentsManager::class);

    expect($manager->mpesa(tokenProvider: managerTokenProvider()))->toBeInstanceOf(MpesaClient::class)
        ->and($manager->sasapay(tokenProvider: managerTokenProvider()))->toBeInstanceOf(SasaPayClient::class)
        ->and($manager->sasapayCallbackVerifier([
            'callback_security' => ['verify_signature' => false],
        ]))->toBeInstanceOf(SasaPayCallbackVerifier::class)
        ->and($manager->kcbBuni(tokenProvider: managerTokenProvider()))->toBeInstanceOf(KcbBuniClient::class)
        ->and($manager->kcbBuniIpnVerifier([
            'ipn_security' => ['verify_signature' => false],
        ]))->toBeInstanceOf(KcbBuniIpnVerifier::class)
        ->and($manager->paystack(tokenProvider: managerTokenProvider()))->toBeInstanceOf(PaystackClient::class)
        ->and($manager->paystackWebhookVerifier([
            'webhook_security' => ['verify_signature' => false],
        ]))->toBeInstanceOf(PaystackWebhookVerifier::class)
        ->and(app(MpesaClient::class))->toBeInstanceOf(MpesaClient::class)
        ->and(app(SasaPayClient::class))->toBeInstanceOf(SasaPayClient::class)
        ->and(app(SasaPayCallbackVerifier::class))->toBeInstanceOf(SasaPayCallbackVerifier::class)
        ->and(app(KcbBuniClient::class))->toBeInstanceOf(KcbBuniClient::class)
        ->and(app(KcbBuniIpnVerifier::class))->toBeInstanceOf(KcbBuniIpnVerifier::class)
        ->and(app(PaystackClient::class))->toBeInstanceOf(PaystackClient::class)
        ->and(app(PaystackWebhookVerifier::class))->toBeInstanceOf(PaystackWebhookVerifier::class)
        ->and(Payments::sasapayCallbackVerifier([
            'callback_security' => ['verify_signature' => false],
        ]))->toBeInstanceOf(SasaPayCallbackVerifier::class)
        ->and(Payments::kcbBuniIpnVerifier([
            'ipn_security' => ['verify_signature' => false],
        ]))->toBeInstanceOf(KcbBuniIpnVerifier::class)
        ->and(Payments::paystackWebhookVerifier([
            'webhook_security' => ['verify_signature' => false],
        ]))->toBeInstanceOf(PaystackWebhookVerifier::class);
});
