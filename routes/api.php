<?php

use App\Http\Controllers\MidtransNotificationController;
use Illuminate\Support\Facades\Route;

Route::post('/payment/midtrans/notification', MidtransNotificationController::class)
    ->name('payment.midtrans.notification');
