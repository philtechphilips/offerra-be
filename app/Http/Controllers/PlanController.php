<?php

namespace App\Http\Controllers;

use App\Models\Plan;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PlanController extends Controller
{
    /**
     * Display a listing of active plans for users.
     */
    public function index()
    {
        return response()->json(Plan::where('is_active', true)->get());
    }

    /**
     * Admin: Display all plans.
     */
    public function adminIndex()
    {
        return response()->json(Plan::latest()->get());
    }

    /**
     * Admin: Store a new plan.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'price_usd' => 'required|numeric|min:0',
            'price_ngn' => 'required|numeric|min:0',
            'description' => 'nullable|string',
            'features' => 'nullable|array',
            'not_included' => 'nullable|array',
            'is_popular' => 'boolean',
            'btn_text' => 'nullable|string',
            'is_active' => 'boolean',
        ]);

        $validated['slug'] = Str::slug($validated['name']);

        $plan = Plan::create($validated);

        // Sync with external providers
        $this->syncWithExternalProviders($plan);

        return response()->json([
            'message' => 'Plan created and synced successfully',
            'plan' => $plan
        ]);
    }

    /**
     * Admin: Update an existing plan.
     */
    public function update(Request $request, $id)
    {
        $plan = Plan::findOrFail($id);

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'price_usd' => 'sometimes|required|numeric|min:0',
            'price_ngn' => 'sometimes|required|numeric|min:0',
            'description' => 'nullable|string',
            'features' => 'nullable|array',
            'not_included' => 'nullable|array',
            'is_popular' => 'boolean',
            'is_active' => 'boolean',
            'btn_text' => 'nullable|string',
        ]);

        if (isset($validated['name'])) {
            $validated['slug'] = Str::slug($validated['name']);
        }

        $plan->update($validated);

        // Sync updates with external providers
        $this->syncWithExternalProviders($plan);

        return response()->json([
            'message' => 'Plan updated and synced successfully',
            'plan' => $plan
        ]);
    }

    /**
     * Helper to sync plan with Polar and Paystack
     */
    protected function syncWithExternalProviders(Plan $plan)
    {
        // 1. Sync with Paystack (NGN)
        try {
            if ($plan->paystack_plan_id) {
                // Update
                Http::withToken(env('PAYSTACK_SECRET'))
                    ->put("https://api.paystack.co/plan/{$plan->paystack_plan_id}", [
                        'name' => $plan->name,
                        'amount' => $plan->price_ngn * 100, // kobo
                        'description' => $plan->description
                    ]);
            } else {
                // Create
                $response = Http::withToken(env('PAYSTACK_SECRET'))
                    ->post("https://api.paystack.co/plan", [
                        'name' => $plan->name,
                        'amount' => $plan->price_ngn * 100,
                        'interval' => 'monthly',
                        'description' => $plan->description
                    ]);

                if ($response->successful()) {
                    $plan->paystack_plan_id = $response->json()['data']['plan_code'];
                    $plan->save();
                }
            }
        } catch (\Exception $e) {
            Log::error("Paystack Sync Error: " . $e->getMessage());
        }

        // 2. Sync with Polar (USD)
        try {
            $polarUrl = env('POLAR_SERVER') === 'sandbox' ? "https://sandbox-api.polar.sh/v1/products" : "https://api.polar.sh/v1/products";
            
            $payload = [
                'name' => $plan->name,
                'description' => $plan->description,
                'organization_id' => env('POLAR_ORGANIZATION_ID'),
                'is_subscription' => true,
                'prices' => [
                    [
                        'amount_type' => 'fixed',
                        'price_amount' => $plan->price_usd * 100, // cents
                        'price_currency' => 'usd',
                        'recurring_interval' => 'month'
                    ]
                ]
            ];

            if ($plan->polar_product_id) {
                // Polar Update is PATCH /v1/products/:id
                Http::withToken(env('POLAR_ACCESS_TOKEN'))
                    ->patch("{$polarUrl}/{$plan->polar_product_id}", $payload);
            } else {
                // Create
                $response = Http::withToken(env('POLAR_ACCESS_TOKEN'))
                    ->post($polarUrl, $payload);

                if ($response->successful()) {
                    $plan->polar_product_id = $response->json()['id'];
                    $plan->save();
                }
            }
        } catch (\Exception $e) {
            Log::error("Polar Sync Error: " . $e->getMessage());
        }
    }

    /**
     * Admin: Delete a plan.
     */
    public function destroy($id)
    {
        $plan = Plan::findOrFail($id);
        $plan->delete();

        return response()->json(['message' => 'Plan deleted successfully']);
    }
}
