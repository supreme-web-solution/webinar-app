<?php

namespace App\Http\Controllers;

use App\Services\PostmarkDeliveryTrackingService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class PostmarkWebhookController extends Controller
{
    public function __invoke(Request $request, string $token, PostmarkDeliveryTrackingService $trackingService): Response
    {
        $expected = (string) config('services.postmark.webhook_token', '');

        if ($expected === '' || ! hash_equals($expected, $token)) {
            abort(403);
        }

        $trackingService->handleWebhook($request->all());

        return response()->noContent();
    }
}
