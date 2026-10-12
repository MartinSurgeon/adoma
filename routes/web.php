<?php

use App\Http\Controllers\ActivityController;
use App\Http\Controllers\Auth\ForcedPasswordController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\LedgerController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

// The app has no public home page: staff go straight to sign in, or to the dashboard.
Route::get('/', fn () => redirect()->route(auth()->check() ? 'dashboard' : 'login'));

Route::get('/dashboard', DashboardController::class)->middleware(['auth', 'verified'])->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::post('/branch/switch', \App\Http\Controllers\BranchSwitchController::class)->name('branch.switch');

    // Temporary passwords: people choose their own before they can do anything else.
    Route::get('/password/change', [ForcedPasswordController::class, 'edit'])->name('password.force.edit');
    Route::put('/password/change', [ForcedPasswordController::class, 'update'])->name('password.force.update');

    // Who did what. For owners and admins, read only.
    Route::get('/activity', [ActivityController::class, 'index'])->name('activity.index');

    // Ledgers: customers, suppliers, banks, cash accounts and expenses. Rules live in LedgerPolicy.
    Route::prefix('ledgers')->name('ledgers.')->group(function () {
        Route::get('/', [LedgerController::class, 'index'])->name('index');
        Route::get('/create', [LedgerController::class, 'create'])->name('create');
        Route::post('/', [LedgerController::class, 'store'])->name('store');
        Route::get('/{ledger}/edit', [LedgerController::class, 'edit'])->whereNumber('ledger')->name('edit');
        Route::put('/{ledger}', [LedgerController::class, 'update'])->whereNumber('ledger')->name('update');
        Route::patch('/{ledger}/status', [LedgerController::class, 'toggle'])->whereNumber('ledger')->name('toggle');
    });

    // Admin-created accounts. Rules live in UserPolicy.
    Route::prefix('settings/users')->name('users.')->group(function () {
        Route::get('/', [UserController::class, 'index'])->name('index');
        Route::get('/create', [UserController::class, 'create'])->name('create');
        Route::post('/', [UserController::class, 'store'])->name('store');
        Route::get('/{user}/edit', [UserController::class, 'edit'])->whereNumber('user')->name('edit');
        Route::put('/{user}', [UserController::class, 'update'])->whereNumber('user')->name('update');
        Route::patch('/{user}/status', [UserController::class, 'toggle'])->whereNumber('user')->name('toggle');
        Route::post('/{user}/reset-password', [UserController::class, 'resetPassword'])->whereNumber('user')->name('reset');
    });

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
});

require __DIR__.'/auth.php';
