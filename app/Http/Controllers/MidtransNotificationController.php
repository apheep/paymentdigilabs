<?php

namespace App\Http\Controllers;

use App\Services\Midtrans\MidtransWebhookDispatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use JsonException;

class MidtransNotificationController extends Controller
{
    public function __invoke(Request $request, MidtransWebhookDispatcher $dispatcher): JsonResponse
    {
        try {
            $payload = json_decode($request->getContent(), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return response()->json([
                'message' => 'Malformed JSON notification payload.',
                'code' => 'malformed_json',
            ], 400);
        }

        if (! is_array($payload) || array_is_list($payload)) {
            return response()->json([
                'message' => 'Midtrans notification payload must be a JSON object.',
                'code' => 'invalid_notification_payload',
            ], 422);
        }

        $result = $dispatcher->dispatch($payload);

        return response()->json($result['body'], $result['status']);
    }
}
