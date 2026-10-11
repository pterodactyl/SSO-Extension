<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Sso\Http\Controllers\OidcProviderController;

Route::get('/oidc-providers', [OidcProviderController::class, 'index'])->name('oidc.index');
Route::post('/oidc-providers', [OidcProviderController::class, 'store'])->name('oidc.store');
Route::put('/oidc-providers/{oidcProvider}', [OidcProviderController::class, 'update'])->whereNumber('oidcProvider')->name('oidc.update');
Route::delete('/oidc-providers/{oidcProvider}', [OidcProviderController::class, 'destroy'])->whereNumber('oidcProvider')->name('oidc.destroy');
