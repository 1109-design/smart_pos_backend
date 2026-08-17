<?php

use App\Http\Controllers\ActivationCodeController;
use App\Http\Controllers\BackOffice\BundlesController as BackOfficeBundles;
use App\Http\Controllers\BackOffice\CategoriesController as BackOfficeCategories;
use App\Http\Controllers\BackOffice\DashboardController as BackOfficeDashboard;
use App\Http\Controllers\BackOffice\LocationsController as BackOfficeLocations;
use App\Http\Controllers\BackOffice\ProductsController as BackOfficeProducts;
use App\Http\Controllers\BackOffice\ReportsController as BackOfficeReports;
use App\Http\Controllers\BackOffice\SessionController as BackOfficeSession;
use App\Http\Controllers\BackOffice\SettingsController as BackOfficeSettings;
use App\Http\Controllers\BackOffice\ShiftsController as BackOfficeShifts;
use App\Http\Controllers\BackOffice\TransactionsController as BackOfficeTransactions;
use App\Http\Controllers\BackOffice\TransfersController as BackOfficeTransfers;
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

// The BackOffice (business portal) is the default destination. Platform
// admins reach their console directly via /dashboard.
Route::get('/', function () {
    return session()->has('backoffice')
        ? redirect()->route('office.dashboard')
        : redirect()->route('office.login');
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
    Route::put('businesses/{business}/devices/{device}/name', [DeviceController::class, 'rename'])->name('businesses.devices.rename');
    Route::put('businesses/{business}/devices/{device}/location', [DeviceController::class, 'assignLocation'])->name('businesses.devices.assign-location');
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
    Route::post('login', [BackOfficeSession::class, 'store'])
        ->middleware('throttle:10,1')
        ->name('login.store');
    Route::post('logout', [BackOfficeSession::class, 'destroy'])->name('logout');

    Route::middleware('auth.backoffice')->group(function () {
        Route::get('dashboard', BackOfficeDashboard::class)->name('dashboard');
        Route::get('reports', BackOfficeReports::class)->name('reports');
        Route::get('transactions', BackOfficeTransactions::class)->name('transactions');
        Route::get('shifts', BackOfficeShifts::class)->name('shifts');
        Route::get('products', [BackOfficeProducts::class, 'index'])->name('products.index');
        Route::post('products', [BackOfficeProducts::class, 'store'])->name('products.store');
        Route::put('products/{product}', [BackOfficeProducts::class, 'update'])->name('products.update');
        Route::patch('products/{product}/toggle-active', [BackOfficeProducts::class, 'toggleActive'])->name('products.toggle-active');
        Route::get('products/import/template', [BackOfficeProducts::class, 'template'])->name('products.import.template');
        Route::post('products/import', [BackOfficeProducts::class, 'import'])->name('products.import');
        Route::post('categories', [BackOfficeCategories::class, 'store'])->name('categories.store');
        Route::put('categories/{category}', [BackOfficeCategories::class, 'update'])->name('categories.update');
        Route::patch('categories/{category}/toggle-active', [BackOfficeCategories::class, 'toggleActive'])->name('categories.toggle-active');
        Route::get('combos', [BackOfficeBundles::class, 'index'])->name('combos.index');
        Route::post('combos', [BackOfficeBundles::class, 'store'])->name('combos.store');
        Route::put('combos/{bundle}', [BackOfficeBundles::class, 'update'])->name('combos.update');
        Route::patch('combos/{bundle}/toggle-active', [BackOfficeBundles::class, 'toggleActive'])->name('combos.toggle-active');
        Route::get('users', [BackOfficeUsers::class, 'index'])->name('users.index');
        Route::post('users', [BackOfficeUsers::class, 'store'])->name('users.store');
        Route::put('users/{user}', [BackOfficeUsers::class, 'update'])->name('users.update');
        Route::put('users/{user}/password', [BackOfficeUsers::class, 'changePassword'])->name('users.change-password');
        Route::patch('users/{user}/toggle-active', [BackOfficeUsers::class, 'toggleActive'])->name('users.toggle-active');
        Route::get('locations', [BackOfficeLocations::class, 'index'])->name('locations.index');
        Route::post('locations', [BackOfficeLocations::class, 'store'])->name('locations.store');
        Route::put('locations/{location}', [BackOfficeLocations::class, 'update'])->name('locations.update');
        Route::patch('locations/{location}/toggle-active', [BackOfficeLocations::class, 'toggleActive'])->name('locations.toggle-active');
        Route::get('transfers', [BackOfficeTransfers::class, 'index'])->name('transfers.index');
        Route::post('transfers', [BackOfficeTransfers::class, 'store'])->name('transfers.store');
        Route::post('transfers/{transfer}/approve', [BackOfficeTransfers::class, 'approve'])->name('transfers.approve');
        Route::post('transfers/{transfer}/dispatch', [BackOfficeTransfers::class, 'dispatch'])->name('transfers.dispatch');
        Route::post('transfers/{transfer}/receive', [BackOfficeTransfers::class, 'receive'])->name('transfers.receive');
        Route::post('transfers/{transfer}/cancel', [BackOfficeTransfers::class, 'cancel'])->name('transfers.cancel');
        Route::get('settings', [BackOfficeSettings::class, 'edit'])->name('settings.edit');
        Route::post('settings/reset-stock', [BackOfficeSettings::class, 'resetStock'])->name('settings.reset-stock');
    });
});

require __DIR__.'/auth.php';
