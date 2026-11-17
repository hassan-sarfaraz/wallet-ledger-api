<?php

use App\Http\Controllers\WalletController;
use Illuminate\Support\Facades\Route;

Route::post('/credit', [WalletController::class, 'credit'])->name('wallet.credit');
Route::post('/debit', [WalletController::class, 'debit'])->name('wallet.debit');
Route::get('/balance/{user_id}', [WalletController::class, 'balance'])->name('wallet.balance');
