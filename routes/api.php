<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BranchesController;
use App\Http\Controllers\Api\CategoriesController;
use App\Http\Controllers\Api\CountersController;
use App\Http\Controllers\Api\CustomersController;
use App\Http\Controllers\Api\InventoriesController;
use App\Http\Controllers\Api\PaymentMethodController;
use App\Http\Controllers\Api\PermissionsController;
use App\Http\Controllers\Api\ProductsController;
use App\Http\Controllers\Api\RolesController;
use App\Http\Controllers\Api\SaleController;
use App\Http\Controllers\Api\UsersController;
use App\Http\Controllers\Api\StatusesController;
use App\Http\Controllers\Api\WarehousesController;
use App\Http\Controllers\Api\CustomerTransactionController;
use App\Http\Controllers\Api\PromotionsController;
use App\Http\Controllers\Api\SaleReturnController;
use App\Http\Controllers\Api\StockTransactionController;
use App\Models\Inventory;
// use App\Http\Controllers\Api\RolesController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::post('/login', [AuthController::class, 'login']);

Route::middleware(['auth:sanctum'])->group(function () {

    Route::get('/user', function (Request $request) {
        return $request->user();
    });

    Route::post('/logout', [AuthController::class, 'logout']);

    Route::get('/users', [UsersController::class, 'index']);
    Route::get('/users/{id}', [UsersController::class, 'show']);
    Route::post('/users', [UsersController::class, 'store']);
    
    Route::delete('/users/{id}', [UsersController::class, 'destroy']);

    Route::apiResource('/roles', RolesController::class);

    Route::apiResource('/permissions', PermissionsController::class);

    // User Role Management
    Route::post('/users/{user}/roles/{role}', [UsersController::class, 'assignRole']);
    Route::delete('/users/{user}/roles/{role}', [UsersController::class, 'removeRole']);

    // // Role Permission Management
    // Route::post('/roles/{role}/permissions/{permission}', [RolesController::class, 'assignPermission']);
    // Route::delete('/roles/{role}/permissions/{permission}', [RolesController::class, 'removePermission']);

    // Check Permission for User
    Route::get('/users/{user}/permissions/{permission}', [UsersController::class, 'hasPermission']);

    Route::apiResource('/branches', BranchesController::class);

    Route::apiResource('/counters', CountersController::class);

    Route::apiResource('/statuses', StatusesController::class);

    Route::apiResource('/categories', CategoriesController::class);

    Route::apiResource('/products', ProductsController::class);
    // Route::get('/products', [ProductsController::class, 'index']);
    // Route::get('/products/{id}', [ProductsController::class, 'show']);
    // Route::post('/products', [ProductsController::class, 'store']);
    // Route::put('/products/{id}', [ProductsController::class, 'update']);
    // Route::delete('/products/{id}', [ProductsController::class, 'destroy']);

    Route::get('/stocktransactions', [StockTransactionController::class, 'index']);

    Route::post('/inventories/adjust', [InventoriesController::class, 'adjust']);
    Route::apiResource('/inventories', InventoriesController::class);

    Route::apiResource('/warehouses', WarehousesController::class);

    Route::get('/customers/last-id', [CustomersController::class, 'getLastId']);
    Route::apiResource('/customers', CustomersController::class);

    Route::apiResource('/sales', SaleController::class);

    Route::apiResource('/sale_returns',SaleReturnController::class);

    Route::apiResource('/payment_methods', PaymentMethodController::class);

    Route::apiResource('/customers_transactions', CustomerTransactionController::class);

    Route::get('/promotions/checkprice/{id}', [PromotionsController::class, 'checkPrice']);
    Route::apiResource('/promotions', PromotionsController::class);

});

Route::put('/users/{id}', [UsersController::class, 'update']);