<?php

namespace App\Services\Midtrans;

class MidtransSignatureVerifier
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function isValid(array $payload, string $serverKey): bool
    {
        if ($serverKey === '') {
            return false;
        }

        foreach (['order_id', 'status_code', 'gross_amount', 'signature_key'] as $field) {
            if (! isset($payload[$field]) || ! is_string($payload[$field])) {
                return false;
            }
        }

        return hash_equals(
            $this->signatureFor(
                $payload['order_id'],
                $payload['status_code'],
                $payload['gross_amount'],
                $serverKey,
            ),
            $payload['signature_key'],
        );
    }

    public function signatureFor(string $orderId, string $statusCode, string $grossAmount, string $serverKey): string
    {
        return hash('sha512', $orderId.$statusCode.$grossAmount.$serverKey);
    }
}
