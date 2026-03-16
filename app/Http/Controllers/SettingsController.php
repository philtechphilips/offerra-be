<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use Illuminate\Http\Request;

class SettingsController extends Controller
{
    public function index()
    {
        return response()->json(Setting::all());
    }

    public function update(Request $request)
    {
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
}
