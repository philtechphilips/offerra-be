<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use Illuminate\Http\Request;

class SettingsController extends Controller
{
    protected function ensureBillingSetting(): void
    {
        Setting::firstOrCreate(
            ['key' => 'billing_enabled'],
            [
                'value' => '0',
                'display_name' => 'Enable Billing Checkout',
                'group' => 'billing',
                'type' => 'boolean',
            ]
        );
    }

    public function index()
    {
        $this->ensureBillingSetting();
        return response()->json(Setting::all());
    }

    public function update(Request $request)
    {
        $this->ensureBillingSetting();

        $request->validate([
            'settings' => 'required|array',
            'settings.*.key' => 'required|exists:settings,key',
            'settings.*.value' => 'required',
        ]);

        foreach ($request->settings as $item) {
            Setting::where('key', $item['key'])->update(['value' => $item['value']]);
        }

        return response()->json([
            'message' => 'Settings updated successfully',
            'settings' => Setting::all()
        ]);
    }

    public function getGroup($group)
    {
        return response()->json(Setting::where('group', $group)->get());
    }

    public function billingStatus()
    {
        $this->ensureBillingSetting();

        $enabled = Setting::getVal('billing_enabled', false);

        return response()->json([
            'billing_enabled' => (bool) $enabled,
        ]);
    }
}
