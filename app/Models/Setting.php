<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    protected $fillable = ['key', 'value', 'display_name', 'group', 'type'];

    public static function getVal($key, $default = null)
    {
        $setting = self::where('key', $key)->first();
        if (!$setting) return $default;

        if ($setting->type === 'number') {
            return (int) $setting->value;
        }

        return $setting->value;
    }
}
