<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return response()->json([
        'message' => 'Welcome to Offerra API V1',
        'status' => 'online'
    ]);
});
