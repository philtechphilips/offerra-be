<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\JobApplicationController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\VerifyEmailController;
use App\Http\Controllers\CVController;
use App\Http\Controllers\JobController;
use App\Http\Controllers\GoogleAuthController;
use App\Http\Controllers\AdminController;
use App\Http\Controllers\PlanController;
use App\Http\Controllers\PaymentController;

Route::post('/webhooks/paystack', [PaymentController::class, 'handlePaystackWebhook']);
Route::post('/webhooks/polar', [PaymentController::class, 'handlePolarWebhook']);

Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);
Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth:sanctum');
Route::post('/forgot-password', [AuthController::class, 'forgotPassword']);
Route::post('/reset-password', [AuthController::class, 'resetPassword'])->name('password.reset');

Route::get('/auth/google/callback', [GoogleAuthController::class, 'callback'])->name('google.callback');

Route::get('/email/verify/{id}/{hash}', [VerifyEmailController::class, 'verify'])
    ->middleware(['signed'])
    ->name('verification.verify');

Route::post('/email/verification-notification', [VerifyEmailController::class, 'resend'])
    ->middleware(['auth:sanctum', 'throttle:6,1'])
    ->name('verification.send');

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/user', function (Request $request) {
        return $request->user()->load(['googleAccount', 'plan']);
    });

    Route::get('/auth/google/redirect', [GoogleAuthController::class, 'redirect']);
    Route::post('/auth/google/sync', [GoogleAuthController::class, 'sync']);
    
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
    Route::post('/cv/proposal', [CVController::class, 'generateProposal']);
    Route::post('/cv/interview-prep', [CVController::class, 'generateInterviewPrep']);
    Route::post('/cv/save-optimized', [CVController::class, 'storeRefactored']);
    Route::delete('/cv/{id}', [CVController::class, 'destroy']);
    Route::put('/cv/{id}/activate', [CVController::class, 'activate']);

    // Payments
    Route::post('/payments/initiate', [PaymentController::class, 'initiate']);
});


// Plan Public Access
Route::get('/plans', [PlanController::class, 'index']);

// Admin Dedicated Routes
Route::middleware(['auth:sanctum', 'role:admin'])->prefix('admin')->group(function () {
    Route::get('/stats', [AdminController::class, 'stats']);
    Route::get('/users', [AdminController::class, 'users']);
    Route::put('/users/{id}/role', [AdminController::class, 'updateUserRole']);
    Route::delete('/users/{id}', [AdminController::class, 'deleteUser']);

    // Admin Billing (Plan) Management
    Route::get('/plans', [PlanController::class, 'adminIndex']);
    Route::post('/plans', [PlanController::class, 'store']);
    Route::put('/plans/{id}', [PlanController::class, 'update']);
    Route::delete('/plans/{id}', [PlanController::class, 'destroy']);

    // System Settings management
    Route::get('/settings', [\App\Http\Controllers\SettingsController::class, 'index']);
    Route::put('/settings', [\App\Http\Controllers\SettingsController::class, 'update']);
});
