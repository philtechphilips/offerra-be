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
            'polar_product_id' => 'nullable|string',
            'paystack_plan_id' => 'nullable|string',
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
     * Sync plan with Polar and Paystack.
     * Creates the product/plan if it doesn't exist yet, updates it if it does.
     * Always writes the returned IDs back to the DB.
     */
    protected function syncWithExternalProviders(Plan $plan)
    {
        if ($plan->price_usd <= 0 && $plan->price_ngn <= 0) {
            Log::info("Skipping external sync for free plan: {$plan->name}");
            return;
        }

        $this->syncWithPolar($plan);
        $this->syncWithPaystack($plan);
    }

    protected function syncWithPolar(Plan $plan)
    {
        if ($plan->price_usd <= 0) return;

        $baseUrl = config('services.polar.server') === 'sandbox'
            ? 'https://sandbox-api.polar.sh/v1/products'
            : 'https://api.polar.sh/v1/products';

        $token = config('services.polar.access_token');

        try {
            if (!$plan->polar_product_id) {
                // Product doesn't exist — create it
                $response = Http::withToken($token)->post($baseUrl, [
                    'name'            => $plan->name,
                    'description'     => $plan->description,
                    'is_subscription' => false,
                    'prices'          => [[
                        'amount_type'    => 'fixed',
                        'price_amount'   => (int)($plan->price_usd * 100),
                        'price_currency' => 'usd',
                    ]],
                ]);

                Log::info("Polar Create [{$plan->name}]: " . $response->body());

                if ($response->successful()) {
                    $plan->polar_product_id = $response->json()['id'];
                    $plan->save();
                } else {
                    Log::error("Polar Create Failed [{$plan->name}]: " . $response->body());
                }
            } else {
                // Product exists — fetch current prices so we can archive them and set a new one
                $getResponse = Http::withToken($token)->get("{$baseUrl}/{$plan->polar_product_id}");

                $prices = [];

                if ($getResponse->successful()) {
                    foreach ($getResponse->json()['prices'] ?? [] as $price) {
                        if (!($price['is_archived'] ?? false)) {
                            $prices[] = ['id' => $price['id'], 'archived' => true];
                        }
                    }
                }

                // Add the new price
                $prices[] = [
                    'amount_type'    => 'fixed',
                    'price_amount'   => (int)($plan->price_usd * 100),
                    'price_currency' => 'usd',
                ];

                $response = Http::withToken($token)->patch("{$baseUrl}/{$plan->polar_product_id}", [
                    'name'        => $plan->name,
                    'description' => $plan->description,
                    'prices'      => $prices,
                ]);

                Log::info("Polar Update [{$plan->name}]: " . $response->body());

                if (!$response->successful()) {
                    Log::error("Polar Update Failed [{$plan->name}]: " . $response->body());
                }
            }
        } catch (\Exception $e) {
            Log::error("Polar Sync Error [{$plan->name}]: " . $e->getMessage());
        }
    }

    protected function syncWithPaystack(Plan $plan)
    {
        if ($plan->price_ngn <= 0) return;

        $token = config('services.paystack.secret');

        try {
            if (!$plan->paystack_plan_id) {
                // Plan doesn't exist — create it
                $response = Http::withToken($token)->post('https://api.paystack.co/plan', [
                    'name'        => $plan->name,
                    'amount'      => (int)($plan->price_ngn * 100), // kobo
                    'description' => $plan->description,
                ]);

                Log::info("Paystack Create [{$plan->name}]: " . $response->body());

                if ($response->successful()) {
                    $plan->paystack_plan_id = $response->json()['data']['plan_code'];
                    $plan->save();
                } else {
                    Log::error("Paystack Create Failed [{$plan->name}]: " . $response->body());
                }
            } else {
                // Plan exists — update name and amount
                $response = Http::withToken($token)->put("https://api.paystack.co/plan/{$plan->paystack_plan_id}", [
                    'name'        => $plan->name,
                    'amount'      => (int)($plan->price_ngn * 100),
                    'description' => $plan->description,
                ]);

                Log::info("Paystack Update [{$plan->name}]: " . $response->body());

                if (!$response->successful()) {
                    Log::error("Paystack Update Failed [{$plan->name}]: " . $response->body());
                }
            }
        } catch (\Exception $e) {
            Log::error("Paystack Sync Error [{$plan->name}]: " . $e->getMessage());
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
