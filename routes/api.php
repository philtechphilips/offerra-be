<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\JobApplicationController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\VerifyEmailController;
use App\Http\Controllers\CVController;
use App\Http\Controllers\JobController;

Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);
Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth:sanctum');
Route::post('/forgot-password', [AuthController::class, 'forgotPassword']);
Route::post('/reset-password', [AuthController::class, 'resetPassword'])->name('password.reset');

Route::get('/email/verify/{id}/{hash}', [VerifyEmailController::class, 'verify'])
    ->middleware(['signed'])
    ->name('verification.verify');

Route::post('/email/verification-notification', [VerifyEmailController::class, 'resend'])
    ->middleware(['auth:sanctum', 'throttle:6,1'])
    ->name('verification.send');

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/user', function (Request $request) {
        return $request->user();
    });
    
    Route::get('/jobs', [JobApplicationController::class, 'index']);
    Route::post('/jobs', [JobApplicationController::class, 'store']);
    Route::put('/jobs/{id}', [JobApplicationController::class, 'update']);
    Route::delete('/jobs/{id}', [JobApplicationController::class, 'destroy']);
    Route::post('/jobs/detect', [JobApplicationController::class, 'detect']);

    // CV & Autofill
    Route::post('/cv/upload', [CVController::class, 'upload']);
    Route::get('/cv', [CVController::class, 'show']);
    Route::post('/cv/autofill', [CVController::class, 'autofill']);
    Route::post('/cv/match-score', [CVController::class, 'matchScore']);
    Route::post('/cv/generate-bios', [CVController::class, 'generateBios']);
    Route::post('/cv/refactor', [CVController::class, 'refactorResume']);
    Route::post('/cv/save-optimized', [CVController::class, 'storeRefactored']);
    Route::delete('/cv/{id}', [CVController::class, 'destroy']);
    Route::put('/cv/{id}/activate', [CVController::class, 'activate']);
});


// Admin Only Routes Example
Route::middleware(['auth:sanctum', 'role:admin'])->prefix('admin')->group(function () {
    Route::get('/stats', function () {
        return response()->json([
            'total_users' => \App\Models\User::count(),
            'total_jobs' => \App\Models\JobApplication::count(),
        ]);
    });
});
