<?php

namespace App\Services;

use App\Models\Reservation;
use App\Models\ReservationPayment;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class PayMongoService
{
    public function createCheckoutSession(
        Reservation $reservation,
        array $customer = [],
        ?int $amount = null,
        string $purpose = ReservationPayment::PURPOSE_FULL,
    ): array
    {
        $amount ??= $reservation->balanceDueMinor();
        $methods = $this->paymentMethodTypes();
        $frontendUrl = rtrim((string) config('app.frontend_url', config('app.url')), '/');

        $payload = [
            'data' => [
                'attributes' => [
                    'line_items' => [[
                        'name' => $reservation->accommodation?->name ?? 'Reservation',
                        'description' => $this->checkoutDescription($reservation),
                        'amount' => $amount,
                        'currency' => 'PHP',
                        'quantity' => 1,
                    ]],
                    'payment_method_types' => $methods,
                    'success_url' => $this->buildRedirectUrl($frontendUrl, '/booking/payment/success', $reservation),
                    'cancel_url' => $this->buildRedirectUrl($frontendUrl, '/booking/payment/cancelled', $reservation),
                    'reference_number' => $reservation->booking_reference,
                    'send_email_receipt' => true,
                    'show_description' => true,
                    'show_line_items' => true,
                    'metadata' => [
                        'reservation_id' => (string) $reservation->id,
                        'booking_reference' => $reservation->booking_reference,
                        'payment_purpose' => $purpose,
                    ],
                ],
            ],
        ];

        if (! empty($customer['name']) || ! empty($customer['email'])) {
            $payload['data']['attributes']['billing'] = array_filter([
                'name' => $customer['name'] ?? null,
                'email' => $customer['email'] ?? null,
            ]);
        }

        try {
            $response = Http::withBasicAuth(config('services.paymongo.secret_key'), '')
                ->acceptJson()
                ->asJson()
                ->withHeaders([
                    'Idempotency-Key' => 'reservation-'.$reservation->id.'-'.$purpose.'-'.Str::uuid(),
                ])
                ->post('https://api.paymongo.com/v2/checkout_sessions', $payload)
                ->throw();
        } catch (RequestException $e) {
            throw new RuntimeException($this->normalizeError($e), previous: $e);
        }

        $data = $response->json('data') ?? [];
        $attributes = $data['attributes'] ?? [];

        return [
            'checkout_session_id' => $data['id'] ?? null,
            'checkout_url' => $attributes['checkout_url'] ?? null,
            'reference_number' => $attributes['reference_number'] ?? $reservation->booking_reference,
            'payment_method_types' => $attributes['payment_method_types'] ?? $methods,
            'raw' => $data,
        ];
    }

    public function retrieveCheckoutSession(string $checkoutSessionId): array
    {
        try {
            $response = Http::withBasicAuth(config('services.paymongo.secret_key'), '')
                ->acceptJson()
                ->get("https://api.paymongo.com/v1/checkout_sessions/{$checkoutSessionId}")
                ->throw();
        } catch (RequestException $e) {
            throw new RuntimeException($this->normalizeError($e), previous: $e);
        }

        $data = $response->json('data') ?? [];
        $attributes = $data['attributes'] ?? [];

        return [
            'checkout_session_id' => $data['id'] ?? $checkoutSessionId,
            'status' => $attributes['status'] ?? null,
            'reference_number' => $attributes['reference_number'] ?? null,
            'payments' => $attributes['payments'] ?? [],
            'metadata' => $attributes['metadata'] ?? [],
            'raw' => $data,
        ];
    }

    public function verifyWebhookSignature(string $rawBody, ?string $signatureHeader): bool
    {
        $secret = config('services.paymongo.webhook_secret');

        if (! $secret || ! $signatureHeader) {
            return false;
        }

        $parts = collect(explode(',', $signatureHeader))
            ->mapWithKeys(function (string $part) {
                [$key, $value] = array_pad(explode('=', trim($part), 2), 2, '');

                return [$key => $value];
            });

        $timestamp = $parts->get('t');
        $decodedPayload = json_decode($rawBody, true);
        $livemode = data_get($decodedPayload, 'data.attributes.livemode');
        $signatureKey = is_bool($livemode)
            ? ($livemode ? 'li' : 'te')
            : (app()->environment('local', 'testing') ? 'te' : 'li');
        $signature = $parts->get($signatureKey);

        if (! $timestamp || ! $signature) {
            return false;
        }

        if (! ctype_digit((string) $timestamp)) {
            return false;
        }

        $tolerance = max(0, (int) config('services.paymongo.webhook_signature_tolerance', 300));
        if (abs(now()->timestamp - (int) $timestamp) > $tolerance) {
            return false;
        }

        $expected = hash_hmac('sha256', $timestamp.'.'.$rawBody, $secret);

        return hash_equals($expected, $signature);
    }

    public function normalizeWebhookPayload(array $payload): array
    {
        $event = $payload['data'] ?? [];
        $eventAttributes = $event['attributes'] ?? [];
        $eventType = $eventAttributes['type'] ?? $event['type'] ?? $payload['type'] ?? null;
        $eventData = $eventAttributes['data'] ?? $event['data'] ?? [];
        $attributes = $eventData['attributes'] ?? [];
        $resourceId = $eventData['id'] ?? $attributes['reference_number'] ?? null;
        $payments = $attributes['payments'] ?? [];
        $payment = $payments[0] ?? [];

        return [
            'event_id' => $payload['id'] ?? $event['id'] ?? sha1(json_encode($payload)),
            'event_type' => $eventType,
            'resource_id' => $resourceId,
            'checkout_session_id' => $eventData['id'] ?? null,
            'reference_number' => $attributes['reference_number'] ?? null,
            'payment_id' => $payment['id'] ?? ($attributes['payment_intent']['id'] ?? null),
            'amount' => (int) ($payment['attributes']['amount'] ?? 0),
            'currency' => $payment['attributes']['currency'] ?? 'PHP',
            'payment_method' => $payment['attributes']['payment_method_type'] ?? null,
            'payment_purpose' => $attributes['metadata']['payment_purpose'] ?? null,
            'paid_at' => $payment['attributes']['paid_at'] ?? now()->toIso8601String(),
            'payload' => $payload,
            'status' => 'paid',
        ];
    }

    public function normalizeCheckoutSessionState(array $checkoutSession): array
    {
        $payments = $checkoutSession['payments'] ?? [];
        $payment = $payments[0] ?? [];
        $paymentAttributes = $payment['attributes'] ?? [];
        $paymentStatus = strtolower((string) ($paymentAttributes['status'] ?? $payment['status'] ?? 'pending'));
        $checkoutStatus = strtolower((string) ($checkoutSession['status'] ?? 'active'));

        return [
            'checkout_session_id' => $checkoutSession['checkout_session_id'] ?? null,
            'reference_number' => $checkoutSession['reference_number'] ?? null,
            'payment_id' => $payment['id'] ?? ($paymentAttributes['id'] ?? null),
            'amount' => (int) ($paymentAttributes['amount'] ?? 0),
            'currency' => $paymentAttributes['currency'] ?? 'PHP',
            'payment_method' => $paymentAttributes['payment_method_type'] ?? null,
            'payment_purpose' => $checkoutSession['metadata']['payment_purpose'] ?? null,
            'paid_at' => $paymentAttributes['paid_at'] ?? null,
            'payment_status' => in_array($paymentStatus, ['paid', 'succeeded', 'successful'], true)
                ? ReservationPayment::STATUS_PAID
                : (in_array($paymentStatus, ['failed', 'cancelled', 'canceled'], true)
                    ? ($paymentStatus === 'failed' ? ReservationPayment::STATUS_FAILED : ReservationPayment::STATUS_CANCELLED)
                    : ReservationPayment::STATUS_PENDING),
            'checkout_status' => $checkoutStatus,
            'raw' => $checkoutSession['raw'] ?? $checkoutSession,
        ];
    }

    private function paymentMethodTypes(): array
    {
        $methods = array_filter(array_map('trim', explode(',', (string) config('services.paymongo.payment_method_types', 'card'))));

        return $methods ?: ['card'];
    }

    private function checkoutDescription(Reservation $reservation): string
    {
        return sprintf(
            '%s reservation (%s to %s)',
            $reservation->accommodation?->name ?? 'Accommodation',
            $reservation->check_in?->format('Y-m-d'),
            $reservation->check_out?->format('Y-m-d'),
        );
    }

    private function buildRedirectUrl(string $baseUrl, string $path, Reservation $reservation): string
    {
        $separator = str_contains($baseUrl.$path, '?') ? '&' : '?';

        return rtrim($baseUrl, '/').$path.$separator.http_build_query([
            'reservation_id' => $reservation->id,
            'booking_reference' => $reservation->booking_reference,
        ]);
    }

    private function normalizeError(RequestException $exception): string
    {
        $message = $exception->response?->json('errors.0.detail')
            ?? $exception->response?->json('errors.0.title')
            ?? 'Unable to create PayMongo checkout session.';

        return $message;
    }
}
