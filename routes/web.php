<?php

use App\Http\Controllers\ActivationCodeController;
use App\Http\Controllers\BusinessController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DeviceController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\SubscriptionController;
use App\Http\Controllers\TierController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect()->route('dashboard');
});

Route::get('/dashboard', DashboardController::class)->middleware(['auth', 'verified'])->name('dashboard');

Route::middleware(['auth', 'verified'])->group(function () {
    // Business (Tenant) management
    Route::resource('businesses', BusinessController::class);

    // Users per business
    Route::get('businesses/{business}/users', [UserController::class, 'index'])->name('businesses.users.index');
    Route::post('businesses/{business}/users', [UserController::class, 'store'])->name('businesses.users.store');
    Route::put('businesses/{business}/users/{user}', [UserController::class, 'update'])->name('businesses.users.update');
    Route::delete('businesses/{business}/users/{user}', [UserController::class, 'destroy'])->name('businesses.users.destroy');

    // Devices per business
    Route::get('businesses/{business}/devices', [DeviceController::class, 'index'])->name('businesses.devices.index');
    Route::post('businesses/{business}/devices/{device}/revoke', [DeviceController::class, 'revoke'])->name('businesses.devices.revoke');
    Route::delete('businesses/{business}/devices/{device}', [DeviceController::class, 'destroy'])->name('businesses.devices.destroy');

    // Subscriptions
    Route::get('businesses/{business}/subscription', [SubscriptionController::class, 'show'])->name('businesses.subscription.show');
    Route::put('businesses/{business}/subscription', [SubscriptionController::class, 'update'])->name('businesses.subscription.update');

    // Activation Codes
    Route::get('businesses/{business}/activation-codes', [ActivationCodeController::class, 'index'])->name('businesses.activation-codes.index');
    Route::post('businesses/{business}/activation-codes', [ActivationCodeController::class, 'store'])->name('businesses.activation-codes.store');

    // Tiers
    Route::get('tiers', [TierController::class, 'index'])->name('tiers.index');
    Route::post('tiers', [TierController::class, 'store'])->name('tiers.store');
    Route::put('tiers/{tier}', [TierController::class, 'update'])->name('tiers.update');
    Route::delete('tiers/{tier}', [TierController::class, 'destroy'])->name('tiers.destroy');

    // Profile
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';
