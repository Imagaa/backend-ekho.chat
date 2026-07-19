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

Route::middleware('throttle:webhook')->group(function () {
    Route::post('/webhook/midtrans', [MidtransController::class, 'webhook']);
    Route::post('/webhook/waba', [WebhookController::class, 'handle']);
});

// --- PROTECTED ROUTES (Dilindungi Token-Based API Shield) ---
Route::middleware(['auth:sanctum', 'throttle:api'])->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/topup', [MidtransController::class, 'createTransaction']);
    
    Route::get('/dashboard', [DashboardController::class, 'index']);
    Route::post('/contacts/import', [ContactController::class, 'import']);
});