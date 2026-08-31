<?php

namespace Tests\Feature\Http\Controllers;

use App\Services\Midtrans\MidtransSignatureVerifier;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MidtransNotificationControllerTest extends TestCase
{
    private const SERVER_KEY = 'midtrans-server-key';

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();

        config([
            'services.midtrans.server_key' => self::SERVER_KEY,
            'services.midtrans.notification.connect_timeout' => 1,
            'services.midtrans.notification.timeout' => 2,
            'services.midtrans.notification.idempotency_ttl' => 3600,
            'services.midtrans.notification.processing_lock_seconds' => 10,
            'services.midtrans.notification.targets.kuotaumroh.url' => 'https://kuotaumroh.test/api/payment/midtrans/update',
            'services.midtrans.notification.targets.kuotaumroh.api_key' => 'kuotaumroh-secret',
            'services.midtrans.notification.targets.wargame.url' => 'https://wargame.test/api/payment/midtrans/update',
            'services.midtrans.notification.targets.wargame.api_key' => 'wargame-secret',
        ]);
    }

    public function test_forwards_valid_ku_notification_to_kuotaumroh_api(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://kuotaumroh.test/api/payment/midtrans/update' => Http::response(['success' => true]),
        ]);
        $payload = $this->validPayload('KU-1001');

        $response = $this->postJson('/api/payment/midtrans/notification', $payload);

        $response
            ->assertOk()
            ->assertJsonPath('message', 'Midtrans notification forwarded.')
            ->assertJsonPath('target', 'kuotaumroh')
            ->assertJsonPath('order_id', 'KU-1001');

        Http::assertSent(function (Request $request): bool {
            return $request->url() === 'https://kuotaumroh.test/api/payment/midtrans/update'
                && $request->hasHeader('X-API-KEY', 'kuotaumroh-secret')
                && $request['order_id'] === 'KU-1001'
                && $request['transaction_id'] === 'trx-1001'
                && $request['currency'] === 'IDR'
                && ! isset($request['signature_key'])
                && ! isset($request['status_code']);
        });
    }

    public function test_forwards_valid_game_notification_to_wargame_api(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://wargame.test/api/payment/midtrans/update' => Http::response(['success' => true]),
        ]);
        $payload = $this->validPayload('GAME-2001', ['transaction_id' => 'trx-2001']);

        $response = $this->postJson('/api/payment/midtrans/notification', $payload);

        $response
            ->assertOk()
            ->assertJsonPath('message', 'Midtrans notification forwarded.')
            ->assertJsonPath('target', 'wargame')
            ->assertJsonPath('order_id', 'GAME-2001');

        Http::assertSent(function (Request $request): bool {
            return $request->url() === 'https://wargame.test/api/payment/midtrans/update'
                && $request->hasHeader('X-API-KEY', 'wargame-secret')
                && $request['order_id'] === 'GAME-2001'
                && $request['transaction_id'] === 'trx-2001';
        });
    }

    public function test_returns_401_when_signature_is_invalid_before_evaluating_prefix(): void
    {
        Http::preventStrayRequests();
        $payload = $this->validPayload('UNKNOWN-1001');
        $payload['signature_key'] = str_repeat('0', 128);

        $response = $this->postJson('/api/payment/midtrans/notification', $payload);

        $response
            ->assertUnauthorized()
            ->assertJsonPath('code', 'invalid_signature');

        Http::assertNothingSent();
    }

    public function test_returns_422_when_order_prefix_is_unknown(): void
    {
        Http::preventStrayRequests();
        $payload = $this->validPayload('UNKNOWN-1001');

        $response = $this->postJson('/api/payment/midtrans/notification', $payload);

        $response
            ->assertUnprocessable()
            ->assertJsonPath('code', 'unknown_order_prefix');

        Http::assertNothingSent();
    }

    public function test_returns_400_when_notification_json_is_malformed(): void
    {
        Http::preventStrayRequests();

        $response = $this->call(
            'POST',
            '/api/payment/midtrans/notification',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: '{"order_id":',
        );

        $response
            ->assertBadRequest()
            ->assertJsonPath('code', 'malformed_json');

        Http::assertNothingSent();
    }

    public function test_returns_422_when_notification_payload_is_missing_required_fields(): void
    {
        Http::preventStrayRequests();
        $payload = $this->validPayload('KU-1001');
        unset($payload['transaction_id']);

        $response = $this->postJson('/api/payment/midtrans/notification', $payload);

        $response
            ->assertUnprocessable()
            ->assertJsonPath('code', 'invalid_notification_payload')
            ->assertJsonValidationErrors(['transaction_id']);

        Http::assertNothingSent();
    }

    public function test_returns_422_when_gross_amount_format_is_invalid_after_signature_passes(): void
    {
        Http::preventStrayRequests();
        $payload = $this->validPayload('KU-1001', ['gross_amount' => '100000']);

        $response = $this->postJson('/api/payment/midtrans/notification', $payload);

        $response
            ->assertUnprocessable()
            ->assertJsonPath('code', 'invalid_notification_payload')
            ->assertJsonValidationErrors(['gross_amount']);

        Http::assertNothingSent();
    }

    public function test_returns_502_when_target_rejects_the_api_key(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://kuotaumroh.test/api/payment/midtrans/update' => Http::response(['message' => 'Unauthenticated.'], 401),
        ]);
        config(['services.midtrans.notification.targets.kuotaumroh.api_key' => 'wrong-secret']);
        $payload = $this->validPayload('KU-1001');

        $response = $this->postJson('/api/payment/midtrans/notification', $payload);

        $response
            ->assertStatus(502)
            ->assertJsonPath('code', 'target_rejected_notification')
            ->assertJsonPath('target_status', 401);

        Http::assertSent(function (Request $request): bool {
            return $request->hasHeader('X-API-KEY', 'wrong-secret');
        });
    }

    public function test_returns_502_when_target_api_connection_fails(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://kuotaumroh.test/api/payment/midtrans/update' => Http::failedConnection(),
        ]);
        $payload = $this->validPayload('KU-1001');

        $response = $this->postJson('/api/payment/midtrans/notification', $payload);

        $response
            ->assertStatus(502)
            ->assertJsonPath('code', 'target_unavailable')
            ->assertJsonPath('target', 'kuotaumroh');
    }

    public function test_duplicate_notification_is_not_forwarded_twice_after_success(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://kuotaumroh.test/api/payment/midtrans/update' => Http::response(['success' => true]),
        ]);
        $payload = $this->validPayload('KU-1001');

        $firstResponse = $this->postJson('/api/payment/midtrans/notification', $payload);
        $secondResponse = $this->postJson('/api/payment/midtrans/notification', $payload);

        $firstResponse->assertOk();
        $secondResponse
            ->assertOk()
            ->assertJsonPath('message', 'Midtrans notification already processed.')
            ->assertJsonPath('duplicate', true);

        Http::assertSentCount(1);
    }

    /**
     * @param  array<string, string>  $overrides
     * @return array<string, string>
     */
    private function validPayload(string $orderId, array $overrides = []): array
    {
        $payload = array_merge([
            'transaction_time' => '2026-08-31 10:00:00',
            'transaction_status' => 'settlement',
            'transaction_id' => 'trx-1001',
            'status_message' => 'midtrans payment notification',
            'status_code' => '200',
            'payment_type' => 'bank_transfer',
            'order_id' => $orderId,
            'gross_amount' => '100000.00',
            'fraud_status' => 'accept',
            'currency' => 'IDR',
            'settlement_time' => '2026-08-31 10:05:00',
        ], $overrides);

        $payload['signature_key'] = (new MidtransSignatureVerifier)->signatureFor(
            $payload['order_id'],
            $payload['status_code'],
            $payload['gross_amount'],
            self::SERVER_KEY,
        );

        return $payload;
    }
}
