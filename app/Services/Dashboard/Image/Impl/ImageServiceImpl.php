<?php

namespace App\Services\Dashboard\Image\Impl;

use App\Services\Dashboard\Image\ImageService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ImageServiceImpl implements ImageService
{
    public function images(Request $request)
    {
        $products = DB::connection('oracle_lmidc')
            ->table('to_sfa_products_android')
            ->get();

        $categories = DB::connection('oracle_lmidc')
            ->table('prod_family')
            ->get();

        $productImages = DB::connection('oracle_sales')
            ->table('online_app_images')
            ->where('type', 'product')
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($image) {
                $product = DB::connection('oracle_lmidc')
                    ->table('to_sfa_products_android')
                    ->where('product_id', $image->ref_id)
                    ->first();
                $image->name = $product ? $product->product_ename : '-';
                return $image;
            });

        $categoryImages = DB::connection('oracle_sales')
            ->table('online_app_images')
            ->where('type', 'category')
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($image) {
                $category = DB::connection('oracle_lmidc')
                    ->table('prod_family')
                    ->where('family_id', $image->ref_id)
                    ->first();
                $image->name = $category ? $category->name : '-';
                return $image;
            });

        $sectionImages = DB::connection('oracle_sales')
            ->table('online_app_images')
            ->where('type', 'section')
            ->orderBy('created_at', 'desc')
            ->get();

        $companies = DB::connection('oracle_lmidc')
            ->table('product_company')
            ->select('company_id', 'company_name')
            ->orderBy('company_id')
            ->get();

        $companyImages = DB::connection('oracle_sales')
            ->table('online_app_images')
            ->where('type', 'company')
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($image) {
                $company = DB::connection('oracle_lmidc')
                    ->table('product_company')
                    ->where('company_id', $image->ref_id)
                    ->first();
                $image->name = $company ? $company->company_name : '-';
                return $image;
            });

        return view('dashboard.images', compact(
            'products',
            'categories',
            'productImages',
            'categoryImages',
            'sectionImages',
            'companies',
            'companyImages'
        ));
    }

    public function uploadProductImage(Request $request)
    {
        $request->validate([
            'product_id' => 'required',
            'image'      => 'required|image|mimes:jpeg,png,jpg',
        ]);

        $path = $request->file('image')->store('images/products', 'public');

        // امسح الصورة القديمة لو موجودة
        $old = DB::connection('oracle_sales')
            ->table('online_app_images')
            ->where('type', 'product')
            ->where('ref_id', $request->product_id)
            ->first();

        if ($old) {
            Storage::disk('public')->delete($old->image_path);
            DB::connection('oracle_sales')
                ->table('online_app_images')
                ->where('id', $old->id)
                ->delete();
        }

        DB::connection('oracle_sales')
            ->table('online_app_images')
            ->insert([
                'type'       => 'product',
                'ref_id'     => $request->product_id,
                'image_path' => $path,
                'created_at' => now(),
            ]);

        return redirect(asset('dashboard/images'));
    }

    public function uploadCategoryImage(Request $request)
    {
        $request->validate([
            'family_id' => 'required',
            'image'     => 'required|image|mimes:jpeg,png,jpg',
        ]);

        $path = $request->file('image')->store('images/categories', 'public');

        // امسح الصورة القديمة لو موجودة
        $old = DB::connection('oracle_sales')
            ->table('online_app_images')
            ->where('type', 'category')
            ->where('ref_id', $request->family_id)
            ->first();

        if ($old) {
            Storage::disk('public')->delete($old->image_path);
            DB::connection('oracle_sales')
                ->table('online_app_images')
                ->where('id', $old->id)
                ->delete();
        }

        DB::connection('oracle_sales')
            ->table('online_app_images')
            ->insert([
                'type'       => 'category',
                'ref_id'     => $request->family_id,
                'image_path' => $path,
                'created_at' => now(),
            ]);

        return redirect(asset('dashboard/images'));
    }

    public function uploadSectionImage(Request $request)
    {
        $request->validate([
            'image' => 'required|image|mimes:jpeg,png,jpg',
        ]);

        $path = $request->file('image')->store('images/sections', 'public');

        DB::connection('oracle_sales')
            ->table('online_app_images')
            ->insert([
                'type'              => 'section',
                'ref_id'            => null,
                'image_path'        => $path,
                'is_active_section' => 1,
                'sort_order'        => 0,
                'action_type'       => 'none',
                'created_at'        => now(),
            ]);

        return redirect(asset('dashboard/images'));
    }

    public function deleteImage(Request $request, $image_id)
    {
        $image = DB::connection('oracle_sales')
            ->table('online_app_images')
            ->where('id', $image_id)
            ->first();

        if ($image) {
            Storage::disk('public')->delete($image->image_path);
            DB::connection('oracle_sales')
                ->table('online_app_images')
                ->where('id', $image_id)
                ->delete();
        }

        return redirect(asset('dashboard/images'));
    }

    public function uploadCompanyImage(Request $request)
    {
        $request->validate([
            'company_id' => 'required',
            'image'      => 'required|image|mimes:jpeg,png,jpg',
        ]);

        $path = $request->file('image')->store('images/companies', 'public');

        $old = DB::connection('oracle_sales')
            ->table('online_app_images')
            ->where('type', 'company')
            ->where('ref_id', $request->company_id)
            ->first();

        if ($old) {
            Storage::disk('public')->delete($old->image_path);
            DB::connection('oracle_sales')
                ->table('online_app_images')
                ->where('id', $old->id)
                ->delete();
        }

        DB::connection('oracle_sales')
            ->table('online_app_images')
            ->insert([
                'type'       => 'company',
                'ref_id'     => $request->company_id,
                'image_path' => $path,
                'created_at' => now(),
            ]);

        return redirect(asset('dashboard/images'));
    }
}
