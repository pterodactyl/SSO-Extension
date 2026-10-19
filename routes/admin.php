<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Sso\Http\Controllers\AccountController;

Route::get('/accounts', [AccountController::class, 'index'])->name('accounts.index');
Route::get('/accounts/{provider}/{id}', [AccountController::class, 'show'])->where(['provider' => '[a-z0-9-]+', 'id' => '[A-Za-z0-9_.-]{1,191}'])->name('accounts.show');
