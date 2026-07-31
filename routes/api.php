<?php

use App\Http\Controllers\Api\AccountController;
use App\Http\Controllers\Api\AccountingIssuesController;
use App\Http\Controllers\Api\AccountingReportController;
use App\Http\Controllers\Api\AnalyticsController;
use App\Http\Controllers\Api\AddressController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BranchController;
use App\Http\Controllers\Api\CartController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\CommissionController;
use App\Http\Controllers\Api\ConversationController;
use App\Http\Controllers\Api\CustomerController;
use App\Http\Controllers\Api\EmployeeController;
use App\Http\Controllers\Api\InventoryController;
use App\Http\Controllers\Api\JournalEntryController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\PaymentProviderController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\PurchaseOrderController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\SettingsController;
use App\Http\Controllers\Api\ShippingController;
use App\Http\Controllers\Api\StockAlertController;
use App\Http\Controllers\Api\SupplierController;
use App\Http\Controllers\Api\SuperadminController;
use App\Http\Controllers\Api\SupportMessageController;
use App\Http\Controllers\Api\NotificationController;
use Illuminate\Support\Facades\Route;

// Public routes
Route::post('/auth/register', [AuthController::class, 'register']);
Route::post('/auth/login', [AuthController::class, 'login']);

Route::get('/products', [ProductController::class, 'index']);
Route::get('/products/featured', [ProductController::class, 'featured']);
Route::get('/products/{slug}', [ProductController::class, 'show']);

Route::get('/categories', [CategoryController::class, 'index']);
Route::get('/categories/{slug}', [CategoryController::class, 'show']);

// Payment providers (public — only enabled ones)
Route::get('/payment-providers', [PaymentProviderController::class, 'publicIndex']);

// Payment webhook (no auth)
Route::post('/payments/webhook', [PaymentController::class, 'webhook']);

// Shipping calculation (public)
Route::post('/shipping/calculate', [ShippingController::class, 'calculate']);

// Payment settings (public read)
Route::get('/settings/payment', [SettingsController::class, 'payment']);

// Public branding (returns the active owner's branding)
Route::get('/settings/branding', [SettingsController::class, 'branding']);

// Authenticated routes
Route::middleware('auth:sanctum')->group(function () {
    // Auth
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/auth/profile', [AuthController::class, 'profile']);
    Route::put('/auth/profile', [AuthController::class, 'updateProfile']);
    Route::post('/auth/change-password', [AuthController::class, 'changePassword']);

    // Employee management (owner only)
    Route::middleware('owner')->group(function () {
        Route::get('/employees', [EmployeeController::class, 'index']);
        Route::post('/employees', [EmployeeController::class, 'store']);
        Route::put('/employees/{user}', [EmployeeController::class, 'update']);
        Route::patch('/employees/{user}/toggle-status', [EmployeeController::class, 'toggleStatus']);
        Route::patch('/employees/{user}/assign-branch', [EmployeeController::class, 'assignBranch']);
        Route::delete('/employees/{user}', [EmployeeController::class, 'destroy']);
        Route::put('/employees/{user}/profile', [EmployeeController::class, 'updateProfile']);
        Route::post('/employees/{user}/reset-password', [EmployeeController::class, 'resetPassword']);

        // Employee attachments (contracts, background check)
        Route::get('/employees/{user}/documents', [EmployeeController::class, 'indexDocuments']);
        Route::post('/employees/{user}/documents', [EmployeeController::class, 'storeDocuments']);
        Route::delete('/employees/{user}/documents/{document}', [EmployeeController::class, 'destroyDocument']);
        Route::get('/employees/{user}/documents/{document}/download', [EmployeeController::class, 'downloadDocument']);
    });

    // Branch management (owner only)
    Route::get('/branches', [BranchController::class, 'index']);
    Route::post('/branches', [BranchController::class, 'store']);
    Route::get('/branches/{branch}', [BranchController::class, 'show']);
    Route::put('/branches/{branch}', [BranchController::class, 'update']);
    Route::patch('/branches/{branch}/set-default', [BranchController::class, 'setDefault']);
    Route::delete('/branches/{branch}', [BranchController::class, 'destroy']);

    // Customer management (employee/owner)
    Route::get('/customers', [CustomerController::class, 'index']);
    Route::patch('/customers/{user}/toggle-status', [CustomerController::class, 'toggleStatus']);
    Route::delete('/customers/{user}', [CustomerController::class, 'destroy']);

    // Payment settings (owner only)
    Route::put('/settings/payment', [SettingsController::class, 'updatePayment']);

    // Payment provider management (owner only)
    Route::get('/payment-providers-manage', [PaymentProviderController::class, 'index']);
    Route::post('/payment-providers', [PaymentProviderController::class, 'store']);
    Route::put('/payment-providers/{id}', [PaymentProviderController::class, 'update']);
    Route::delete('/payment-providers/{id}', [PaymentProviderController::class, 'destroy']);

    // Product management (owner)
    Route::get('/products-manage', [ProductController::class, 'manage']);
    Route::post('/products', [ProductController::class, 'store']);
    Route::put('/products/{id}', [ProductController::class, 'update']);
    Route::delete('/products/{id}', [ProductController::class, 'destroy']);

    // Cart
    Route::get('/cart', [CartController::class, 'index']);
    Route::post('/cart', [CartController::class, 'add']);
    Route::put('/cart/{itemId}', [CartController::class, 'update']);
    Route::delete('/cart/{itemId}', [CartController::class, 'remove']);
    Route::delete('/cart', [CartController::class, 'clear']);

    // Orders
    Route::get('/orders', [OrderController::class, 'index']);
    Route::post('/orders', [OrderController::class, 'store']);
    Route::get('/orders/{orderId}', [OrderController::class, 'show']);

    // Order management (employee/owner)
    Route::get('/orders-manage', [OrderController::class, 'manage']);
    Route::patch('/orders/{orderId}/status', [OrderController::class, 'updateStatus']);
    Route::post('/orders/{orderId}/return', [OrderController::class, 'returnItems']);

    // Accounting issues (employee + owner - actionable, branch-scoped, no P&L/equity)
    Route::get('/accounting-issues', [AccountingIssuesController::class, 'index']);

    // Reports (employee/owner)
    Route::get('/reports/daily', [ReportController::class, 'daily']);
    Route::get('/reports/summary', [ReportController::class, 'summary']);

    // Payments
    Route::post('/payments/initiate', [PaymentController::class, 'initiate']);
    Route::get('/orders/{orderId}/payment-status', [PaymentController::class, 'status']);

    // Addresses
    Route::apiResource('addresses', AddressController::class);

    // Support messages
    Route::get('/support-messages', [SupportMessageController::class, 'index']);
    Route::post('/support-messages', [SupportMessageController::class, 'store']);
    Route::get('/support-messages/{id}', [SupportMessageController::class, 'show']);
    Route::patch('/support-messages/{id}/reply', [SupportMessageController::class, 'reply']);
    Route::patch('/support-messages/{id}/status', [SupportMessageController::class, 'updateStatus']);
    Route::get('/support/unread-count', [SupportMessageController::class, 'unreadCount']);

    // Delivery updates (employee/owner)
    Route::patch('/orders/{orderId}/delivery', [OrderController::class, 'updateDelivery']);

    // Shipping rules (owner only)
    Route::get('/shipping-rules', [ShippingController::class, 'index']);
    Route::post('/shipping-rules', [ShippingController::class, 'store']);
    Route::put('/shipping-rules/{id}', [ShippingController::class, 'update']);
    Route::delete('/shipping-rules/{id}', [ShippingController::class, 'destroy']);

    // Analytics (owner)
    Route::get('/analytics/sales', [AnalyticsController::class, 'sales']);
    Route::post('/analytics/ai-suggestions', [AnalyticsController::class, 'aiSuggestions']);

    // Conversations (superadmin + owner + customer)
    Route::get('/conversations', [ConversationController::class, 'index']);
    Route::post('/conversations', [ConversationController::class, 'store']);
    Route::get('/conversations/unread-count', [ConversationController::class, 'unreadCount']);
    Route::get('/conversations/{conversation}', [ConversationController::class, 'show']);
    Route::post('/conversations/{conversation}/messages', [ConversationController::class, 'sendMessage']);
    Route::patch('/conversations/{conversation}/status', [ConversationController::class, 'updateStatus']);
    Route::get('/conversations/{conversation}/owner-details', [ConversationController::class, 'ownerDetails']);
    Route::get('/conversations/{conversation}/customer-details', [ConversationController::class, 'customerDetails']);

    // Accounting (owner only - financial statements & journal management)
    Route::middleware('owner')->group(function () {
        Route::get('/accounts', [AccountController::class, 'index']);
        Route::get('/accounts/tree', [AccountController::class, 'tree']);
        Route::post('/accounts', [AccountController::class, 'store']);
        Route::put('/accounts/{id}', [AccountController::class, 'update']);
        Route::delete('/accounts/{id}', [AccountController::class, 'destroy']);

        Route::get('/journal-entries', [JournalEntryController::class, 'index']);
        Route::post('/journal-entries', [JournalEntryController::class, 'store']);
        Route::get('/journal-entries/{id}', [JournalEntryController::class, 'show']);
        Route::put('/journal-entries/{id}', [JournalEntryController::class, 'update']);
        Route::delete('/journal-entries/{id}', [JournalEntryController::class, 'destroy']);
        Route::post('/journal-entries/{id}/post', [JournalEntryController::class, 'post']);
        Route::post('/journal-entries/{id}/void', [JournalEntryController::class, 'void']);

        Route::get('/reports/trial-balance', [AccountingReportController::class, 'trialBalance']);
        Route::get('/reports/profit-loss', [AccountingReportController::class, 'profitLoss']);
        Route::get('/reports/balance-sheet', [AccountingReportController::class, 'balanceSheet']);
        Route::get('/reports/general-ledger', [AccountingReportController::class, 'generalLedger']);
        Route::post('/reports/generate-monthly', [AccountingReportController::class, 'generateMonthly']);
        Route::post('/reports/generate-yearly', [AccountingReportController::class, 'generateYearly']);
        Route::get('/reports/list', [AccountingReportController::class, 'listReports']);
        Route::get('/reports/{id}', [AccountingReportController::class, 'showReport']);
        Route::post('/reports/ai-suggestions', [AccountingReportController::class, 'aiSuggestions']);
    });

    // Commissions (owner + employee)
    Route::get('/commissions', [CommissionController::class, 'index']);
    Route::get('/commissions/summary', [CommissionController::class, 'summary']);
    Route::post('/commissions/{id}/pay', [CommissionController::class, 'pay']);
    Route::post('/commissions/pay-all', [CommissionController::class, 'payAll']);
    Route::get('/commissions/my-earnings', [CommissionController::class, 'employeeEarnings']);

    // Inventory (owner)
    Route::get('/inventory', [InventoryController::class, 'index']);
    Route::post('/inventory/adjust', [InventoryController::class, 'adjust']);
    Route::get('/inventory/transactions', [InventoryController::class, 'transactions']);
    Route::get('/inventory/low-stock', [InventoryController::class, 'lowStock']);
    Route::get('/inventory/dashboard', [InventoryController::class, 'dashboard']);

    // Purchase orders (owner)
    Route::get('/purchase-orders', [PurchaseOrderController::class, 'index']);
    Route::post('/purchase-orders', [PurchaseOrderController::class, 'store']);
    Route::get('/purchase-orders/{id}', [PurchaseOrderController::class, 'show']);
    Route::post('/purchase-orders/{id}/receive', [PurchaseOrderController::class, 'receive']);
    Route::delete('/purchase-orders/{id}', [PurchaseOrderController::class, 'destroy']);

    // Suppliers (owner)
    Route::get('/suppliers', [SupplierController::class, 'index']);
    Route::get('/suppliers/all', [SupplierController::class, 'all']);
    Route::post('/suppliers', [SupplierController::class, 'store']);
    Route::get('/suppliers/{id}', [SupplierController::class, 'show']);
    Route::put('/suppliers/{id}', [SupplierController::class, 'update']);
    Route::delete('/suppliers/{id}', [SupplierController::class, 'destroy']);

    // Stock alerts (owner)
    Route::get('/stock-alerts', [StockAlertController::class, 'index']);
    Route::get('/stock-alerts/count', [StockAlertController::class, 'count']);
    Route::post('/stock-alerts/{id}/acknowledge', [StockAlertController::class, 'acknowledge']);
    Route::post('/stock-alerts/{id}/resolve', [StockAlertController::class, 'resolve']);

    // Notifications
    Route::get('/notifications', [NotificationController::class, 'index']);
    Route::get('/notifications/count', [NotificationController::class, 'count']);
    Route::post('/notifications/{id}/read', [NotificationController::class, 'markRead']);
    Route::post('/notifications/read-all', [NotificationController::class, 'markAllRead']);

    // Supplier portal
    Route::middleware('supplier')->prefix('supplier-portal')->group(function () {
        Route::get('/profile', [SupplierController::class, 'supplierProfile']);
        Route::get('/purchase-orders', [PurchaseOrderController::class, 'supplierOrders']);
        Route::get('/purchase-orders/{id}', [PurchaseOrderController::class, 'supplierShow']);
        Route::post('/purchase-orders/{id}/update-status', [PurchaseOrderController::class, 'supplierUpdateStatus']);
    });

    // Superadmin routes
    Route::prefix('superadmin')->middleware('superadmin')->group(function () {
        Route::get('/stats', [SuperadminController::class, 'stats']);
        Route::get('/owners', [SuperadminController::class, 'index']);
        Route::post('/owners', [SuperadminController::class, 'store']);
        Route::get('/owners/{id}', [SuperadminController::class, 'show']);
        Route::patch('/owners/{id}/toggle-active', [SuperadminController::class, 'toggleActive']);
        Route::put('/owners/{id}/subscription', [SuperadminController::class, 'updateSubscription']);
        Route::put('/owners/{id}/limits', [SuperadminController::class, 'updateLimits']);
        Route::put('/owners/{id}/branding', [SuperadminController::class, 'updateBranding']);
        Route::post('/owners/{id}/branding-logo', [SuperadminController::class, 'updateBrandingLogo']);
        Route::delete('/owners/{id}', [SuperadminController::class, 'destroy']);

        // Owner password management
        Route::get('/passwords/status', [SuperadminController::class, 'allPasswordsStatus']);
        Route::get('/owners/{id}/password-status', [SuperadminController::class, 'getPasswordStatus']);
        Route::post('/owners/{id}/reset-password', [SuperadminController::class, 'resetPassword']);
        Route::post('/owners/{id}/set-password', [SuperadminController::class, 'setPassword']);
        Route::post('/owners/{id}/force-password-change', [SuperadminController::class, 'forcePasswordChange']);
        Route::post('/owners/{id}/unlock-account', [SuperadminController::class, 'unlockAccount']);
    });
});
