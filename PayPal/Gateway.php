<?php

namespace App\Gateways\PayPal;

use LaraPay\Framework\Interfaces\GatewayFoundation;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use LaraPay\Framework\Payment;
use Illuminate\Http\Request;
use Exception;

class Gateway extends GatewayFoundation
{
    /**
     * The unique gateway identifier.
     */
    protected string $identifier = 'paypal';

    /**
     * The gateway version.
     */
    protected string $version = '1.0.0';

    /**
     * (Optional) Specify the currencies supported by this gateway.
     */
    protected array $currencies = [];

    protected $gateway;

    /**
     * Define the fields required by this gateway, such as Client ID, Client Secret, etc.
     *
     * These values can later be accessed using:
     *    $payment->gateway->config('client_id'), etc.
     */
    public function config(): array
    {
        return [
            'mode' => [
                'label'       => 'PayPal Mode (Sandbox/Live)',
                'description' => 'Select sandbox for testing or live for production',
                'type'        => 'select',
                'options'     => ['sandbox' => 'Sandbox', 'live' => 'Live'],
                'rules'       => ['required'],
            ],
            'client_id' => [
                'label'       => 'PayPal Client ID',
                'description' => 'Your PayPal REST API Client ID',
                'type'        => 'text',
                'rules'       => ['required', 'string'],
            ],
            'client_secret' => [
                'label'       => 'PayPal Client Secret',
                'description' => 'Your PayPal REST API Client Secret',
                'type'        => 'text',
                'rules'       => ['required', 'string'],
            ],
        ];
    }

    /**
     * Main function to initiate a payment.
     * This method creates a PayPal order and redirects the user to PayPal.
     */
    public function pay($payment)
    {
        $this->gateway = $payment->gateway;

        $accessToken = $this->getAccessToken();

        $response = Http::withToken($accessToken)
            ->post($this->paypalApiUrl('checkout/orders'), [
            'intent' => 'CAPTURE',
            'purchase_units' => [
                [
                    'reference_id' => $payment->id,
                    'amount' => [
                        'currency_code' => $payment->currency,
                        'value' => $payment->total(),
                    ],
                ],
            ],
            'application_context' => [
                'cancel_url' => $payment->cancelUrl(),
                'return_url' => $payment->webhookUrl(),
                'shipping_preference'  => 'NO_SHIPPING',
            ],
        ]);

        if ($response->failed()) {
            throw new Exception('Failed to create PayPal order');
        }

        // store the transaction ID in the payment
        $payment->update([
            'transaction_id' => $response->json('id'),
            'data' => $response->json(),
        ]);

        $links = $response->json('links', []);

        // Find the approve link
        $approveLink = collect($links)->firstWhere('rel', 'approve')['href'] ?? null;

        if (!$approveLink) {
            throw new Exception("No approve link found in PayPal response.");
        }

        return redirect($approveLink);
    }

    private function getAccessToken()
    {
        return Cache::remember('larapay:paypal_access_token', now()->addMinutes(15), function () {

            $apiUrl = $this->isLive()
                ? 'https://api.paypal.com/v1/oauth2/token'
                : 'https://api.sandbox.paypal.com/v1/oauth2/token';

            $clientId = $this->gateway->config('client_id');
            $clientSecret = $this->gateway->config('client_secret');

            $response = Http::withBasicAuth($clientId, $clientSecret)
                ->asForm()
                ->post($apiUrl, [
                    'grant_type' => 'client_credentials',
                ]);

            if ($response->failed()) {
                throw new Exception('Failed to get PayPal access token');
            }

            return $response->json('access_token');
        });
    }

    private function isLive()
    {
        return $this->gateway->config('mode') === 'live';
    }

    public function paypalApiUrl($path)
    {
        return $this->isLive()
            ? "https://api.paypal.com/v2/{$path}"
            : "https://api.sandbox.paypal.com/v2/{$path}";
    }

    /**
     * This function handles the callback after PayPal redirects the user
     * or if PayPal sends a webhook notification (depending on your setup).
     */
    public function callback(Request $request)
    {
        $payment = Payment::where('transaction_id', $request->get('token'))->first();

        if (!$payment) {
            throw new \Exception("No matching Payment found for transaction_id={$request->get('token')}.");
        }

        if($payment->isPaid()) {
            return redirect($payment->successUrl());
        }

        $this->gateway = $payment->gateway;

        // PayPal sends back a "token" parameter which is actually the Order ID.
        // For v2/checkout/orders, it's typically named "token".
        // Double-check the actual parameter PayPal returns (some docs show 'token', others might show 'orderID').
        $orderId = $request->get('token');

        if (!$orderId) {
            throw new \Exception("No order 'token' (ID) provided in callback.");
        }

        // Retrieve (and possibly re-cache) the access token
        $accessToken = $this->getAccessToken();

        // Capture the order
        $captureResponse = Http::withToken($accessToken, 'Bearer')
            ->post($this->paypalApiUrl("checkout/orders/{$orderId}/capture"), ['success' => true]);

        if ($captureResponse->failed()) {
            throw new \Exception('Failed to capture PayPal order.');
        }

        $responseData = $captureResponse->json();

        // The status is often at the top-level: 'COMPLETED' or 'APPROVED'
        if (!isset($responseData['status'])) {
            throw new \Exception('Unexpected PayPal response structure — no status field found.');
        }

        // You may want to check for 'COMPLETED' or 'APPROVED'.
        // For an intent of CAPTURE, 'COMPLETED' means the funds are captured.
        if ($responseData['status'] !== 'COMPLETED') {
            // If it's 'APPROVED', you might still need to capture again or handle partial captures.
            // But typically, for CAPTURE, we want 'COMPLETED'.
            throw new \Exception("PayPal order not completed. Current status: {$responseData['status']}");
        }

        if (!$payment) {
            throw new \Exception("No matching Payment found for transaction_id={$orderId}.");
        }

        $payment->completed($orderId, $responseData);
        return redirect($payment->successUrl());
    }
}
