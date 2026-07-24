<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\ContactController;
use App\Http\Controllers\Api\ContactGroupController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\MidtransController;
use App\Http\Controllers\Api\WebhookController;
use App\Http\Controllers\Api\MessageController;
use App\Http\Controllers\Api\CampaignController;
use App\Http\Controllers\Api\TemplateController;
use App\Http\Controllers\Api\OnboardingController;
use App\Http\Controllers\Api\DemoAuthController;

// --- PUBLIC ROUTES (Dilindungi Webhook & Login Shield) ---
Route::post('/request-otp', [AuthController::class, 'requestOtp'])->middleware('throttle:login');
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login');

// Demo dashboard — realm terpisah, sesi BUKAN Sanctum (tidak bisa akses /api/* asli).
// Lihat AGENTS.md §DEMO DASHBOARD.
Route::middleware('throttle:login')->group(function () {
    Route::post('/demo/login', [DemoAuthController::class, 'login']);
    Route::post('/demo/logout', [DemoAuthController::class, 'logout']);
    Route::get('/demo/session', [DemoAuthController::class, 'session']);
});

// Webhook api.co.id — didaftarkan lewat dashboard api.co.id, TIDAK butuh
// GET verification handshake (hub_challenge) seperti Meta native. Lihat
// documentation.md §7.
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

    // --- ONBOARDING ROUTES ---
    Route::get('/onboarding/request-number', [OnboardingController::class, 'show']);
    Route::post('/onboarding/request-number', [OnboardingController::class, 'store']);

    Route::post('/contacts/import', [ContactController::class, 'import']);
    Route::get('/contacts/import/{contactImport}', [ContactController::class, 'importStatus']);
    Route::delete('/contacts/import/{contactImport}', [ContactController::class, 'destroyImport']);

    // --- CONTACT GROUP ROUTES ---
    Route::get('/contact-groups', [ContactGroupController::class, 'index']);
    Route::post('/contact-groups', [ContactGroupController::class, 'store']);

    // --- TEMPLATE ROUTES ---
    Route::get('/templates', [TemplateController::class, 'index']);
    Route::post('/templates', [TemplateController::class, 'store']);
    Route::get('/templates/{template}/refresh', [TemplateController::class, 'refreshStatus']);

    // --- CAMPAIGN ROUTES ---
    Route::get('/campaigns',        [CampaignController::class, 'index']);
    Route::post('/campaigns',       [CampaignController::class, 'store']);
    Route::get('/campaigns/{campaign}', [CampaignController::class, 'show']);

    // --- CHAT ROUTES ---
    Route::get('/chats',                    [MessageController::class, 'index']);
    Route::get('/chats/{phone}',            [MessageController::class, 'show']);
    Route::post('/chats/{phone}/send',      [MessageController::class, 'send']);
});