<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Sso\Http\Controllers\AuthorizationController;
use Sso\Http\Controllers\CheckpointController;
use Sso\Http\Controllers\DirectSsoController;
use Sso\Http\Controllers\ProviderController;

Route::get('/providers', ProviderController::class)->name('providers');
Route::get('/checkpoint', CheckpointController::class)->name('checkpoint');
Route::match(['get', 'post'], '/direct', [DirectSsoController::class, 'webhook'])->name('direct.webhook');
Route::get('/direct/{token}', [DirectSsoController::class, 'handle'])->where('token', '[A-Za-z0-9_-]+')->name('direct.login');
Route::get('/{provider}/redirect', [AuthorizationController::class, 'login'])->where('provider', '[a-z0-9-]+')->name('redirect');
Route::get('/{provider}/callback', [AuthorizationController::class, 'callback'])->where('provider', '[a-z0-9-]+')->name('callback');
