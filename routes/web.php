<?php

use App\Http\Controllers\ActivityController;
use App\Http\Controllers\Auth\ForcedPasswordController;
use App\Http\Controllers\CashDocumentController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\GoldCalculationController;
use App\Http\Controllers\GoldDocumentController;
use App\Http\Controllers\LedgerController;
use App\Http\Controllers\LedgerSearchController;
use App\Http\Controllers\LedgerStatementController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\StockController;
use App\Http\Controllers\TransferController;
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

    // Purchases and sales of gold, and the live calculation behind their forms.
    foreach (['purchase' => 'purchases', 'sale' => 'sales'] as $type => $path) {
        Route::get("/$path/create", [GoldDocumentController::class, 'create'])->defaults('type', $type)->name("$path.create");
        Route::post("/$path", [GoldDocumentController::class, 'store'])->defaults('type', $type)->name("$path.store");
    }
    Route::post('/gold/calculate', GoldCalculationController::class)->name('gold.calculate');

    // Receipts and payments. The route says which, and DocumentPolicy decides who may record one.
    foreach (['receipt' => 'receipts', 'payment' => 'payments'] as $type => $path) {
        Route::get("/$path/create", [CashDocumentController::class, 'create'])->defaults('type', $type)->name("$path.create");
        Route::post("/$path", [CashDocumentController::class, 'store'])->defaults('type', $type)->name("$path.store");
    }

    // Sending gold to another branch, and the gold each branch holds.
    Route::get('/transfers/create', [TransferController::class, 'create'])->name('transfers.create');
    Route::post('/transfers/quote', [TransferController::class, 'quote'])->name('transfers.quote');
    // The receiving branch answers: accept the gold into its stock, or decline it.
    Route::get('/transfers/incoming', [TransferController::class, 'incoming'])->name('transfers.incoming');
    Route::post('/transfers/{transfer}/accept', [TransferController::class, 'accept'])->whereNumber('transfer')->name('transfers.accept');
    Route::post('/transfers/{transfer}/decline', [TransferController::class, 'decline'])->whereNumber('transfer')->name('transfers.decline');
    Route::post('/transfers', [TransferController::class, 'store'])->name('transfers.store');
    Route::get('/stock', StockController::class)->name('stock.index');

    // Reports for a period: purchases, sales, one person's supplies, and opening and closing balances.
    Route::prefix('reports')->name('reports.')->group(function () {
        Route::get('/', [ReportController::class, 'index'])->name('index');
        Route::get('/purchases', [ReportController::class, 'purchases'])->name('purchases');
        Route::get('/sales', [ReportController::class, 'sales'])->name('sales');
        Route::get('/supplier', [ReportController::class, 'supplier'])->name('supplier');
        Route::get('/ledger', [ReportController::class, 'ledger'])->name('ledger');
        Route::get('/summary', [ReportController::class, 'summary'])->name('summary');
    });

    // Finding, reading, printing and reversing transactions.
    Route::prefix('transactions')->name('documents.')->group(function () {
        Route::get('/', [DocumentController::class, 'index'])->name('index');
        Route::get('/{document}', [DocumentController::class, 'show'])->whereNumber('document')->name('show');
        Route::get('/{document}/print', [DocumentController::class, 'print'])->whereNumber('document')->name('print');
        Route::post('/{document}/reverse', [DocumentController::class, 'reverse'])->whereNumber('document')->name('reverse');
    });

    // The type-ahead for choosing a ledger, and a ledger's statement.
    Route::get('/ledger-search', LedgerSearchController::class)->name('ledgers.search');

    // Ledgers: customers, suppliers, banks, cash accounts and expenses. Rules live in LedgerPolicy.
    Route::get('/ledgers/{ledger}/statement', LedgerStatementController::class)->whereNumber('ledger')->name('ledgers.statement');
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
