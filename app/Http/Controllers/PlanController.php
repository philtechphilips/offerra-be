<?php

namespace App\Http\Controllers;

use App\Models\Plan;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

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
        ]);

        $validated['slug'] = Str::slug($validated['name']);

        $plan = Plan::create($validated);

        return response()->json([
            'message' => 'Plan created successfully',
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

        return response()->json([
            'message' => 'Plan updated successfully',
            'plan' => $plan
        ]);
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
