<?php

namespace App\Services\Midtrans;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class MidtransWebhookDispatcher
{
    private const FORWARDED_FIELDS = [
        'order_id',
        'transaction_id',
        'transaction_status',
        'payment_type',
        'gross_amount',
        'transaction_time',
        'settlement_time',
        'fraud_status',
        'currency',
    ];

    public function __construct(private MidtransSignatureVerifier $signatureVerifier)
    {
        //
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{status: int, body: array<string, mixed>}
     */
    public function dispatch(array $payload): array
    {
        $signatureValidator = Validator::make($payload, $this->signatureRules());

        if ($signatureValidator->fails()) {
            return $this->validationError($signatureValidator->errors()->toArray());
        }

        $serverKey = $this->serverKey();

        if ($serverKey === null) {
            Log::critical('Midtrans webhook server key is not configured.');

            return $this->error(500, 'midtrans_not_configured', 'Midtrans webhook is not configured.');
        }

        if (! $this->signatureVerifier->isValid($payload, $serverKey)) {
            return $this->error(401, 'invalid_signature', 'Invalid Midtrans signature.');
        }

        $validator = Validator::make($payload, $this->notificationRules());

        if ($validator->fails()) {
            return $this->validationError($validator->errors()->toArray());
        }

        /** @var array<string, string|null> $validated */
        $validated = $validator->validated();
        $target = $this->resolveTarget($validated['order_id']);

        if ($target === null) {
            return $this->error(422, 'unknown_order_prefix', 'Unknown order_id prefix.');
        }

        if (! $this->targetIsConfigured($target)) {
            Log::error('Midtrans webhook target is not configured.', [
                'target' => $target['name'],
                'order_id' => $validated['order_id'],
            ]);

            return $this->error(500, 'target_not_configured', 'Payment target is not configured.', [
                'target' => $target['name'],
            ]);
        }

        $forwardPayload = $this->forwardPayload($validated);
        $fingerprint = $this->notificationFingerprint($validated);
        $processedKey = $this->cacheKey('processed', $fingerprint);
        $processingKey = $this->cacheKey('processing', $fingerprint);

        if (Cache::has($processedKey)) {
            return $this->success('Midtrans notification already processed.', $target['name'], $validated['order_id'], [
                'duplicate' => true,
            ]);
        }

        if (! Cache::add($processingKey, true, $this->processingLockSeconds())) {
            return $this->accepted('Midtrans notification is already being processed.', $target['name'], $validated['order_id']);
        }

        try {
            $response = $this->sendToTarget($target, $forwardPayload);
        } catch (ConnectionException) {
            Log::warning('Midtrans webhook target connection failed.', [
                'target' => $target['name'],
                'order_id' => $validated['order_id'],
            ]);

            return $this->error(502, 'target_unavailable', 'Payment target is unavailable.', [
                'target' => $target['name'],
            ]);
        } finally {
            Cache::forget($processingKey);
        }

        if (! $response->successful()) {
            Log::warning('Midtrans webhook target rejected notification.', [
                'target' => $target['name'],
                'order_id' => $validated['order_id'],
                'target_status' => $response->status(),
            ]);

            return $this->error(502, 'target_rejected_notification', 'Payment target rejected the notification.', [
                'target' => $target['name'],
                'target_status' => $response->status(),
            ]);
        }

        Cache::put($processedKey, true, $this->idempotencyTtl());

        return $this->success('Midtrans notification forwarded.', $target['name'], $validated['order_id']);
    }

    /**
     * @return array<string, list<string>>
     */
    private function signatureRules(): array
    {
        return [
            'order_id' => ['required', 'string', 'max:255'],
            'status_code' => ['required', 'string', 'regex:/^\d{3}$/'],
            'gross_amount' => ['required', 'string', 'max:32'],
            'signature_key' => ['required', 'string', 'regex:/^[a-f0-9]{128}$/i'],
        ];
    }

    /**
     * @return array<string, list<string>>
     */
    private function notificationRules(): array
    {
        return array_merge($this->signatureRules(), [
            'transaction_id' => ['required', 'string', 'max:255'],
            'transaction_status' => ['required', 'string', 'max:50'],
            'payment_type' => ['required', 'string', 'max:100'],
            'gross_amount' => ['required', 'string', 'regex:/^\d+(?:\.\d{2})$/'],
            'transaction_time' => ['required', 'string', 'max:32'],
            'settlement_time' => ['nullable', 'string', 'max:32'],
            'fraud_status' => ['nullable', 'string', 'max:50'],
            'currency' => ['required', 'string', 'size:3'],
        ]);
    }

    private function serverKey(): ?string
    {
        $serverKey = config('services.midtrans.server_key');

        return is_string($serverKey) && $serverKey !== '' ? $serverKey : null;
    }

    /**
     * @return array{name: string, prefix: string, url: mixed, api_key: mixed}|null
     */
    private function resolveTarget(string $orderId): ?array
    {
        $targets = config('services.midtrans.notification.targets', []);

        if (! is_array($targets)) {
            return null;
        }

        foreach ($targets as $targetName => $target) {
            if (! is_array($target)) {
                continue;
            }

            $prefix = $target['prefix'] ?? null;

            if (is_string($prefix) && Str::startsWith($orderId, $prefix)) {
                return [
                    'name' => (string) $targetName,
                    'prefix' => $prefix,
                    'url' => $target['url'] ?? null,
                    'api_key' => $target['api_key'] ?? null,
                ];
            }
        }

        return null;
    }

    /**
     * @param  array{name: string, prefix: string, url: mixed, api_key: mixed}  $target
     */
    private function targetIsConfigured(array $target): bool
    {
        $url = $target['url'];
        $apiKey = $target['api_key'];

        return is_string($url)
            && filter_var($url, FILTER_VALIDATE_URL) !== false
            && Str::startsWith($url, ['http://', 'https://'])
            && is_string($apiKey)
            && $apiKey !== '';
    }

    /**
     * @param  array<string, string|null>  $validated
     * @return array<string, string|null>
     */
    private function forwardPayload(array $validated): array
    {
        return Arr::mapWithKeys(self::FORWARDED_FIELDS, function (string $field) use ($validated): array {
            return [$field => $validated[$field] ?? null];
        });
    }

    /**
     * @param  array<string, string|null>  $validated
     */
    private function notificationFingerprint(array $validated): string
    {
        return hash('sha256', implode('|', [
            $validated['order_id'],
            $validated['transaction_id'],
            $validated['transaction_status'],
            $validated['status_code'],
            $validated['gross_amount'],
        ]));
    }

    /**
     * @param  array{name: string, prefix: string, url: mixed, api_key: mixed}  $target
     * @param  array<string, string|null>  $payload
     */
    private function sendToTarget(array $target, array $payload): Response
    {
        /** @var string $url */
        $url = $target['url'];

        /** @var string $apiKey */
        $apiKey = $target['api_key'];

        return Http::acceptJson()
            ->asJson()
            ->withHeaders([
                'X-API-KEY' => $apiKey,
            ])
            ->connectTimeout($this->connectTimeout())
            ->timeout($this->timeout())
            ->post($url, $payload);
    }

    private function connectTimeout(): int
    {
        return max(1, (int) config('services.midtrans.notification.connect_timeout', 3));
    }

    private function timeout(): int
    {
        return max(1, (int) config('services.midtrans.notification.timeout', 5));
    }

    private function idempotencyTtl(): int
    {
        return max(60, (int) config('services.midtrans.notification.idempotency_ttl', 86400));
    }

    private function processingLockSeconds(): int
    {
        return max(1, (int) config('services.midtrans.notification.processing_lock_seconds', 10));
    }

    private function cacheKey(string $state, string $fingerprint): string
    {
        return "midtrans_webhook:{$state}:{$fingerprint}";
    }

    /**
     * @param  array<string, mixed>  $errors
     * @return array{status: int, body: array<string, mixed>}
     */
    private function validationError(array $errors): array
    {
        return $this->error(422, 'invalid_notification_payload', 'Invalid Midtrans notification payload.', [
            'errors' => $errors,
        ]);
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array{status: int, body: array<string, mixed>}
     */
    private function error(int $status, string $code, string $message, array $extra = []): array
    {
        return [
            'status' => $status,
            'body' => array_merge([
                'message' => $message,
                'code' => $code,
            ], $extra),
        ];
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array{status: int, body: array<string, mixed>}
     */
    private function success(string $message, string $target, string $orderId, array $extra = []): array
    {
        return [
            'status' => 200,
            'body' => array_merge([
                'message' => $message,
                'target' => $target,
                'order_id' => $orderId,
            ], $extra),
        ];
    }

    /**
     * @return array{status: int, body: array<string, mixed>}
     */
    private function accepted(string $message, string $target, string $orderId): array
    {
        return [
            'status' => 202,
            'body' => [
                'message' => $message,
                'target' => $target,
                'order_id' => $orderId,
                'duplicate' => true,
            ],
        ];
    }
}
