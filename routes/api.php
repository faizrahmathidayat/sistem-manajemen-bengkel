<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/

// Called by Telegram itself; protected by the secret token header (see TelegramWebhookController).
Route::post('/telegram/webhook', \App\Http\Controllers\TelegramWebhookController::class)->name('telegram.webhook');

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});
