<?php


namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;
use App\Models\OraclePersonalAccessToken;

class AppServiceProvider extends ServiceProvider
{
    public function register()
    {
        $this->app->bind(
            \App\Services\Auth\AuthService::class,
            \App\Services\Auth\Impl\AuthServiceImpl::class,
        );
        $this->app->bind(
            \App\Services\Product\CategoryService::class,
            \App\Services\Product\Impl\CategoryServiceImpl::class,
        );
        $this->app->bind(
            \App\Services\Product\ProductService::class,
            \App\Services\Product\Impl\ProductServiceImpl::class,
        );
        $this->app->bind(
            \App\Services\Section\SectionService::class,
            \App\Services\Section\Impl\SectionServiceImpl::class
        );
        $this->app->bind(
            \App\Services\Target\TargetService::class,
            \App\Services\Target\Impl\TargetServiceImpl::class
        );
        $this->app->bind(
            \App\Services\Cart\CartService::class,
            \App\Services\Cart\Impl\CartServiceImpl::class
        );  
        $this->app->bind(
            \App\Services\Order\OrderService::class,
            \App\Services\Order\Impl\OrderServiceImpl::class
        );
        $this->app->bind(
            \App\Services\Favourite\FavouriteService::class,
            \App\Services\Favourite\Impl\FavouriteServiceImpl::class
        );
        $this->app->bind(
            \App\Services\Notification\NotificationService::class,
            \App\Services\Notification\Impl\NotificationServiceImpl::class
        );
        $this->app->bind(
            \App\Services\Dashboard\Auth\AuthService::class,
            \App\Services\Dashboard\Auth\Impl\AuthServiceImpl::class
        );
        $this->app->bind(
            \App\Services\Dashboard\Home\HomeService::class,
            \App\Services\Dashboard\Home\Impl\HomeServiceImpl::class
        );
        $this->app->bind(
            \App\Services\Dashboard\Order\OrderService::class,
            \App\Services\Dashboard\Order\Impl\OrderServiceImpl::class
        );
        $this->app->bind(
            \App\Services\Dashboard\User\UserService::class,
            \App\Services\Dashboard\User\Impl\UserServiceImpl::class
        );
        $this->app->bind(
            \App\Services\Dashboard\Admin\AdminService::class,
            \App\Services\Dashboard\Admin\Impl\AdminServiceImpl::class
        );
        $this->app->bind(
            \App\Services\Dashboard\Image\ImageService::class,
            \App\Services\Dashboard\Image\Impl\ImageServiceImpl::class
        );
        $this->app->bind(
            \App\Services\Dashboard\Section\SectionService::class,
            \App\Services\Dashboard\Section\Impl\SectionServiceImpl::class
        );
        $this->app->bind(
            \App\Services\Dashboard\Notification\NotificationService::class,
            \App\Services\Dashboard\Notification\Impl\NotificationServiceImpl::class
        );
        $this->app->bind(
            \App\Services\Dashboard\Visibility\VisibilityService::class,
            \App\Services\Dashboard\Visibility\Impl\VisibilityServiceImpl::class
        );
        $this->app->bind(
            \App\Services\Dashboard\Stock\StockService::class,
            \App\Services\Dashboard\Stock\Impl\StockServiceImpl::class
        );
        $this->app->bind(
            \App\Services\Dashboard\LatestOffers\LatestOffersService::class,
            \App\Services\Dashboard\LatestOffers\Impl\LatestOffersServiceImpl::class
        );
        $this->app->bind(
            \App\Services\Dashboard\Points\PointsService::class,
            \App\Services\Dashboard\Points\Impl\PointsServiceImpl::class
        );
        $this->app->bind(
            \App\Services\Points\PointsService::class,
            \App\Services\Points\Impl\PointsServiceImpl::class
        );
    }

    public function boot()
    {
        Sanctum::usePersonalAccessTokenModel(OraclePersonalAccessToken::class);
    }   
}