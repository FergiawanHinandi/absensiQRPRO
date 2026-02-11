<?php

use App\Http\Controllers\Api\V1\WebhookController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Webhook Routes
|--------------------------------------------------------------------------
|
| Public webhook endpoints for payment gateway callbacks
| No authentication required (verified via signature)
|
*/

Route::post('/webhooks/payment', [WebhookController::class, 'handlePayment'])
    ->name('webhooks.payment');
