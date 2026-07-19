<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\ContactController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\MidtransController;
use App\Http\Controllers\Api\WebhookController;

// --- PUBLIC ROUTES (Dilindungi Webhook & Login Shield) ---
Route::post('/request-otp', [AuthController::class, 'requestOtp'])->middleware('throttle:login');
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login');

// Tambah GET endpoint untuk verifikasi awal URL di Meta Developer Dashboard
Route::middleware('throttle:webhook')->group(function () {
    Route::post('/webhook/midtrans', [MidtransController::class, 'webhook']);
    Route::post('/webhook/waba', [WebhookController::class, 'handle']);

    // Cacat C Fix: GET endpoint wajib ada agar Meta bisa verifikasi URL saat pendaftaran webhook
    Route::get('/webhook/waba', function (\Illuminate\Http\Request $request) {
        if ($request->query('hub_mode') === 'subscribe'
            && $request->query('hub_verify_token') === config('services.waba.verify_token')) {
            return response($request->query('hub_challenge'), 200);
        }
        return response('Forbidden', 403);
    });
});

// --- PROTECTED ROUTES (Dilindungi Token-Based API Shield) ---
Route::middleware(['auth:sanctum', 'throttle:api'])->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/topup', [MidtransController::class, 'createTransaction']);
    
    Route::get('/dashboard', [DashboardController::class, 'index']);
    Route::post('/contacts/import', [ContactController::class, 'import']);
});