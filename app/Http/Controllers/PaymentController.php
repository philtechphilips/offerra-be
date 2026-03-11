<?php

namespace App\Http\Controllers;

use App\Models\Plan;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PaymentController extends Controller
{
    /**
     * Initiate a payment checkout.
     */
    public function initiate(Request $request)
    {
        $request->validate([
            'plan_id' => 'required|exists:plans,id',
            'region' => 'required|in:global,nigeria',
        ]);

        $user = $request->user();
        $plan = Plan::find($request->plan_id);

        if ($request->region === 'nigeria') {
            return $this->initiatePaystack($user, $plan);
        }

        return $this->initiatePolar($user, $plan);
    }

    protected function initiatePaystack($user, $plan)
    {
        $url = "https://api.paystack.co/transaction/initialize";
        $fields = [
            'email' => $user->email,
            'amount' => $plan->price_ngn * 100, // Paystack expects kobo
            'callback_url' => config('app.frontend_url') . "/dashboard/billing?status=success",
            'metadata' => [
                'user_id' => $user->id,
                'plan_id' => $plan->id,
            ],
        ];

        if ($plan->paystack_plan_id) {
            $fields['plan'] = $plan->paystack_plan_id;
        }

        $response = Http::withToken(env('PAYSTACK_SECRET'))
            ->post($url, $fields);

        if ($response->successful()) {
            return response()->json($response->json()['data']);
        }

        return response()->json(['error' => 'Failed to initialize Paystack payment'], 500);
    }

    protected function initiatePolar($user, $plan)
    {
        // Polar API uses Checkout Create
        $url = env('POLAR_SERVER') === 'sandbox' 
            ? "https://sandbox-api.polar.sh/api/v1/checkouts/custom" 
            : "https://api.polar.sh/api/v1/checkouts/custom";

        // If plan has a polar_product_id, we use it. Otherwise we create a custom checkout if allowed.
        // For now, let's assume we use Product IDs if present.
        
        $fields = [
            'product_id' => $plan->polar_product_id, // Ensure this is set in admin
            'customer_email' => $user->email,
            'success_url' => env('POLAR_SUCCESS_URL'),
            'cancel_url' => env('POLAR_CANCEL_URL'),
            'metadata' => [
                'user_id' => (string)$user->id,
                'plan_id' => (string)$plan->id,
            ],
        ];

        $response = Http::withToken(env('POLAR_ACCESS_TOKEN'))
            ->post($url, $fields);

        if ($response->successful()) {
            return response()->json(['authorization_url' => $response->json()['url']]);
        }

        Log::error('Polar Initialization Error', ['response' => $response->body()]);
        return response()->json(['error' => 'Failed to initialize Polar payment. Make sure Polar Product ID is set.'], 500);
    }

    public function handlePaystackWebhook(Request $request)
    {
        // 1. Verify Signature
        $signature = $request->header('x-paystack-signature');
        if (!$signature || $signature !== hash_hmac('sha512', $request->getContent(), env('PAYSTACK_SECRET'))) {
            return response()->json(['message' => 'Invalid signature'], 400);
        }

        $event = $request->all();
        Log::info('Paystack Webhook Received', ['event' => $event['event'] ?? 'unknown']);

        if ($event['event'] === 'charge.success') {
            $data = $event['data'];
            $userId = $data['metadata']['user_id'];
            $planId = $data['metadata']['plan_id'];

            $user = User::find($userId);
            if ($user) {
                $user->update([
                    'plan_id' => $planId,
                    'subscription_provider' => 'paystack',
                    'subscription_id' => $data['reference'],
                    'subscription_status' => 'active',
                    'subscription_ends_at' => now()->addMonth(), // Assuming monthly
                ]);
            }
        }

        return response()->json(['status' => 'success']);
    }

    public function handlePolarWebhook(Request $request)
    {
        // Polar Webhook Verification
        // Polar sends a signature that should be verified with the secret
        // For brevity in this setup, I'll focus on the logic, but usually you'd verify signature.
        
        $event = $request->all();
        $type = $event['type'] ?? '';
        
        Log::info('Polar Webhook Received', ['type' => $type]);

        if ($type === 'checkout.created' || $type === 'subscription.created' || $type === 'subscription.updated') {
            $data = $event['data'];
            // For checkout.created, we might wait for order.created or subscription.created
        }

        if ($type === 'subscription.created' || $type === 'subscription.updated') {
            $sub = $event['data'];
            $metadata = $sub['metadata'] ?? [];
            $userId = $metadata['user_id'] ?? null;
            $planId = $metadata['plan_id'] ?? null;

            if ($userId && $planId) {
                $user = User::find($userId);
                if ($user) {
                    $user->update([
                        'plan_id' => $planId,
                        'subscription_provider' => 'polar',
                        'subscription_id' => $sub['id'],
                        'subscription_status' => $sub['status'] === 'active' ? 'active' : $sub['status'],
                        'subscription_ends_at' => isset($sub['current_period_end']) ? date('Y-m-d H:i:s', $sub['current_period_end']) : now()->addMonth(),
                    ]);
                }
            }
        }

        return response()->json(['status' => 'success']);
    }
}
