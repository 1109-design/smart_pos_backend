<?php

use App\Http\Controllers\ActivationCodeController;
use App\Http\Controllers\BackOffice\DashboardController as BackOfficeDashboard;
use App\Http\Controllers\BackOffice\ReportsController as BackOfficeReports;
use App\Http\Controllers\BackOffice\SessionController as BackOfficeSession;
use App\Http\Controllers\BackOffice\UsersController as BackOfficeUsers;
use App\Http\Controllers\BusinessController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DeviceController;
use App\Http\Controllers\DownloadController;
use App\Http\Controllers\PairController;
use App\Http\Controllers\PaymentSettingsController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\SubscriptionController;
use App\Http\Controllers\TierController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect()->route('dashboard');
});

// Public — no auth. Reached by scanning a QR code from a phone that isn't
// logged into the BackOffice at all.
Route::get('download', [DownloadController::class, 'show'])->name('download.show');
Route::get('download/apk', [DownloadController::class, 'apk'])->name('download.apk');
Route::get('pair/{tenant}', [PairController::class, 'show'])->name('pair.show');

Route::get('/dashboard', DashboardController::class)->middleware(['auth', 'verified', 'platform.admin'])->name('dashboard');

Route::middleware(['auth', 'verified', 'platform.admin'])->group(function () {
    // Business (Tenant) management
    Route::resource('businesses', BusinessController::class);

    // Users per business
    Route::get('businesses/{business}/users', [UserController::class, 'index'])->name('businesses.users.index');
    Route::post('businesses/{business}/users', [UserController::class, 'store'])->name('businesses.users.store');
    Route::put('businesses/{business}/users/{user}', [UserController::class, 'update'])->name('businesses.users.update');
    Route::put('businesses/{business}/users/{user}/password', [UserController::class, 'changePassword'])->name('businesses.users.change-password');
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

    // Payment settings (EcoCash / WhatsApp shown on the app's lock screen)
    Route::get('settings/payments', [PaymentSettingsController::class, 'edit'])->name('settings.payments.edit');
    Route::put('settings/payments', [PaymentSettingsController::class, 'update'])->name('settings.payments.update');

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

// ── Back-Office (Business Employee Portal) ─────────────────────────────────
Route::prefix('office')->name('office.')->group(function () {
    Route::get('login', [BackOfficeSession::class, 'create'])->name('login');
    Route::post('login', [BackOfficeSession::class, 'store'])->name('login.store');
    Route::post('logout', [BackOfficeSession::class, 'destroy'])->name('logout');

    Route::middleware('auth.backoffice')->group(function () {
        Route::get('dashboard', BackOfficeDashboard::class)->name('dashboard');
        Route::get('reports', BackOfficeReports::class)->name('reports');
        Route::get('users', [BackOfficeUsers::class, 'index'])->name('users.index');
        Route::put('users/{user}', [BackOfficeUsers::class, 'update'])->name('users.update');
        Route::put('users/{user}/password', [BackOfficeUsers::class, 'changePassword'])->name('users.change-password');
        Route::patch('users/{user}/toggle-active', [BackOfficeUsers::class, 'toggleActive'])->name('users.toggle-active');
    });
});

require __DIR__.'/auth.php';
