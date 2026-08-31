<?php

namespace Tests\Unit;

use App\Services\Midtrans\MidtransSignatureVerifier;
use Tests\TestCase;

class MidtransSignatureVerifierTest extends TestCase
{
    public function test_accepts_notification_signed_with_the_midtrans_server_key(): void
    {
        $verifier = new MidtransSignatureVerifier;
        $payload = [
            'order_id' => 'KU-1001',
            'status_code' => '200',
            'gross_amount' => '100000.00',
            'signature_key' => $verifier->signatureFor('KU-1001', '200', '100000.00', 'server-key'),
        ];

        $this->assertTrue($verifier->isValid($payload, 'server-key'));
    }

    public function test_rejects_notification_signed_with_a_different_server_key(): void
    {
        $verifier = new MidtransSignatureVerifier;
        $payload = [
            'order_id' => 'KU-1001',
            'status_code' => '200',
            'gross_amount' => '100000.00',
            'signature_key' => $verifier->signatureFor('KU-1001', '200', '100000.00', 'server-key'),
        ];

        $this->assertFalse($verifier->isValid($payload, 'other-server-key'));
    }

    public function test_rejects_notification_when_required_signature_fields_are_missing(): void
    {
        $verifier = new MidtransSignatureVerifier;

        $this->assertFalse($verifier->isValid([
            'order_id' => 'KU-1001',
            'status_code' => '200',
            'signature_key' => str_repeat('0', 128),
        ], 'server-key'));
    }
}
