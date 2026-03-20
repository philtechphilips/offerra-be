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
    public function index(Request $request)
    {
        $user = $request->user();
        $query = Plan::where('is_active', true);

        // If user already has a plan assigned, hide the free ones
        if ($user && $user->plan_id) {
            $query->where('price_usd', '>', 0);
        }
        
        $plans = $query->orderBy('price_usd', 'asc')->get();

        return response()->json($plans);
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
            'credits' => 'required|integer|min:0',
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
            'credits' => 'sometimes|required|integer|min:0',
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
        // Skip sync for free plans
        if ($plan->price_usd <= 0 && $plan->price_ngn <= 0) {
            Log::info("Skipping external sync for free plan: {$plan->name}");
            return;
        }

        // 1. Sync with Paystack (NGN)
        if ($plan->price_ngn > 0) {
            try {
                if ($plan->paystack_plan_id) {
                    $response = Http::withToken(env('PAYSTACK_SECRET'))
                        ->put("https://api.paystack.co/plan/{$plan->paystack_plan_id}", [
                            'name' => $plan->name,
                            'amount' => (int)($plan->price_ngn * 100), // kobo
                            'description' => $plan->description
                        ]);
                    Log::info("Paystack Update Response for {$plan->name}: " . $response->body());
                } else {
                    $response = Http::withToken(env('PAYSTACK_SECRET'))
                        ->post("https://api.paystack.co/plan", [
                            'name' => $plan->name,
                            'amount' => (int)($plan->price_ngn * 100),
                            'description' => $plan->description
                        ]);

                    Log::info("Paystack Create Response for {$plan->name}: " . $response->body());

                    if ($response->successful()) {
                        $plan->paystack_plan_id = $response->json()['data']['plan_code'];
                        $plan->save();
                    }
                }
            } catch (\Exception $e) {
                Log::error("Paystack Sync Error: " . $e->getMessage());
            }
        }

        // 2. Sync with Polar (USD)
        if ($plan->price_usd > 0) {
            try {
                $polarUrl = env('POLAR_SERVER') === 'sandbox' ? "https://sandbox-api.polar.sh/v1/products" : "https://api.polar.sh/v1/products";
                
                $payload = [
                    'name' => $plan->name,
                    'description' => $plan->description,
                ];

                if (!$plan->polar_product_id) {
                    $payload['is_subscription'] = false;
                    $payload['prices'] = [
                        [
                            'amount_type' => 'fixed',
                            'price_amount' => (int)($plan->price_usd * 100), // cents
                            'price_currency' => 'usd',
                        ]
                    ];

                    $response = Http::withToken(env('POLAR_ACCESS_TOKEN'))
                        ->post($polarUrl, $payload);

                    Log::info("Polar Create Response for {$plan->name}: " . $response->body());

                    if ($response->successful()) {
                        $plan->polar_product_id = $response->json()['id'];
                        $plan->save();
                    } else {
                        Log::error("Polar Create Failed for {$plan->name}: " . $response->body());
                    }
                } else {
                    $response = Http::withToken(env('POLAR_ACCESS_TOKEN'))
                        ->patch("{$polarUrl}/{$plan->polar_product_id}", $payload);
                    
                    Log::info("Polar Update Response for {$plan->name}: " . $response->body());
                }
            } catch (\Exception $e) {
                Log::error("Polar Sync Error: " . $e->getMessage());
            }
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
