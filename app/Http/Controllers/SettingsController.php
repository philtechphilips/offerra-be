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
        return $this->safeCall(function () {
            $this->ensureBillingSetting();
            return response()->json(Setting::all());
        }, 'SettingsController@index');
    }

    public function update(Request $request)
    {
        return $this->safeCall(function () use ($request) {
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
        }, 'SettingsController@update');
    }

    public function getGroup($group)
    {
        return $this->safeCall(function () use ($group) {
            return response()->json(Setting::where('group', $group)->get());
        }, 'SettingsController@getGroup');
    }

    public function billingStatus()
    {
        return $this->safeCall(function () {
            $this->ensureBillingSetting();

            $enabled = Setting::getVal('billing_enabled', false);

            return response()->json([
                'billing_enabled' => (bool) $enabled,
            ]);
        }, 'SettingsController@billingStatus');
    }
}
