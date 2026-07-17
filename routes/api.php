<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\ContactController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\MidtransController;
use App\Http\Controllers\Api\WebhookController;

// --- PUBLIC ROUTES ---
Route::post('/login', [AuthController::class, 'login']);
Route::post('/webhook/midtrans', [MidtransController::class, 'webhook']);
Route::post('/webhook/waba', [WebhookController::class, 'handle']);

// --- PROTECTED ROUTES (SANCTUM) ---
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/topup', [MidtransController::class, 'createTransaction']);
    
    Route::get('/dashboard', [DashboardController::class, 'index']);
    Route::post('/contacts/import', [ContactController::class, 'import']);
});