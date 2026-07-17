<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\ContactController;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

// Webhook Publik (Tanpa Sanctum Auth)
Route::post('/webhook/waba', [\App\Http\Controllers\Api\WebhookController::class, 'handle']);

// Menggunakan Sanctum middleware untuk otentikasi API
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/contacts/import', [ContactController::class, 'import']);
});
