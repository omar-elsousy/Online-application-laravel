<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Dashboard\LatestOffersController;
use App\Http\Controllers\Dashboard\AuthController;
use App\Http\Controllers\Dashboard\HomeController;
use App\Http\Controllers\Dashboard\OrderController;
use App\Http\Controllers\Dashboard\UserController;
use App\Http\Controllers\Dashboard\AdminController;
use App\Http\Controllers\Dashboard\ImageController;
use App\Http\Controllers\Dashboard\SectionController;
use App\Http\Controllers\Dashboard\NotificationController;
use App\Http\Controllers\Dashboard\VisibilityController;
use App\Http\Controllers\Dashboard\StockController;
use App\Http\Controllers\Dashboard\PointsController;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| contains the "web" middleware group. Now create something great!
|
*/

Route::prefix('dashboard')->group(function () {
    Route::get('/login', [AuthController::class, 'login']);
    Route::post('/loginPost', [AuthController::class, 'loginPost']);

    Route::middleware('admin.auth')->group(function () {
        Route::get('/home', [HomeController::class, 'home']);
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::get('/orders', [OrderController::class, 'orders']);
        Route::get('/orderDetails/{order_id}', [OrderController::class, 'orderDetails']);
        Route::post('/cancelOrder/{order_id}', [OrderController::class, 'cancelOrder']);
        Route::get('/users', [UserController::class, 'users']);
        Route::post('/deleteUser/{user_id}', [UserController::class, 'deleteUser']);
        Route::post('/blockUser/{user_id}', [UserController::class, 'blockUser']);
        Route::post('/unblockUser/{user_id}', [UserController::class, 'unblockUser']);
        Route::get('/images', [ImageController::class, 'images']);
        Route::post('/uploadProductImage', [ImageController::class, 'uploadProductImage']);
        Route::post('/uploadCategoryImage', [ImageController::class, 'uploadCategoryImage']);
        Route::post('/uploadSectionImage', [ImageController::class, 'uploadSectionImage']);
        Route::post('/uploadCompanyImage', [ImageController::class, 'uploadCompanyImage']);
        Route::post('/deleteImage/{image_id}', [ImageController::class, 'deleteImage']);
        Route::get('/sections', [SectionController::class, 'sections']);
        Route::post('/toggleSection/{image_id}', [SectionController::class, 'toggleSection']);
        Route::post('/updateSortOrder/{image_id}', [SectionController::class, 'updateSortOrder']);
        Route::post('/updateAction/{image_id}', [SectionController::class, 'updateAction']);
        Route::get('/notifications', [NotificationController::class, 'notifications']);
        Route::post('/sendToAll', [NotificationController::class, 'sendToAll']);
        Route::post('/sendToUser', [NotificationController::class, 'sendToUser']);
        Route::get('/visibility', [VisibilityController::class, 'visibility']);
        Route::post('/hideProduct/{product_id}', [VisibilityController::class, 'hideProduct']);
        Route::post('/showProduct/{product_id}', [VisibilityController::class, 'showProduct']);
        Route::post('/hideCategory/{family_id}', [VisibilityController::class, 'hideCategory']);
        Route::post('/showCategory/{family_id}', [VisibilityController::class, 'showCategory']);
        Route::post('/hideCompany/{company_id}', [VisibilityController::class, 'hideCompany']);
        Route::post('/showCompany/{company_id}', [VisibilityController::class, 'showCompany']);
        Route::get('/latestOffers', [LatestOffersController::class, 'latestOffers']);
        Route::post('/addLatestOffer', [LatestOffersController::class, 'addLatestOffer']);
        Route::post('/removeLatestOffer/{product_id}', [LatestOffersController::class, 'removeLatestOffer']);
        Route::get('/stock', [StockController::class, 'stock']);
        Route::post('/toggleStock/{product_id}/{warehouse_id}', [StockController::class, 'toggleStock']);
        Route::get('/points', [PointsController::class, 'points']);
        Route::post('/points/addRule', [PointsController::class, 'addRule']);
        Route::post('/points/toggleRule/{id}', [PointsController::class, 'toggleRule']);
        Route::post('/points/deleteRule/{id}', [PointsController::class, 'deleteRule']);
        Route::post('/points/updateSettings', [PointsController::class, 'updateSettings']);
        Route::post('/points/resetAllPoints', [PointsController::class, 'resetAllPoints']);
        Route::post('/points/addGift', [PointsController::class, 'addGift']);
        Route::post('/points/toggleGift/{id}', [PointsController::class, 'toggleGift']);
        Route::post('/points/deleteGift/{id}', [PointsController::class, 'deleteGift']);
        Route::post('/points/updateRedemptionStatus/{id}', [PointsController::class, 'updateRedemptionStatus']);
    });

    Route::middleware('super.admin')->group(function () {
        Route::get('/admins', [AdminController::class, 'admins']);
        Route::post('/addAdmin', [AdminController::class, 'addAdmin']);
        Route::post('/deleteAdmin/{admin_id}', [AdminController::class, 'deleteAdmin']);
    });
});
