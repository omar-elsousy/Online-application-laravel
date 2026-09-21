<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        $schema = Schema::connection('oracle_sales');

        // 1. قواعد احتساب النقاط
        if (!$schema->hasTable('online_app_points_rules')) {
            $schema->create('online_app_points_rules', function (Blueprint $table) {
                $table->increments('id');
                $table->integer('family_id')->nullable();
                $table->string('rule_type', 20)->default('quantity'); // quantity or amount
                $table->double('threshold', 12, 2)->default(1);
                $table->integer('points')->default(10);
                $table->smallInteger('is_active')->default(1);
                $table->timestamps();
            });
        }

        // 2. إعدادات النقاط والتصفير
        if (!$schema->hasTable('online_app_points_settings')) {
            $schema->create('online_app_points_settings', function (Blueprint $table) {
                $table->increments('id');
                $table->string('key', 50)->unique();
                $table->text('value')->nullable();
                $table->timestamps();
            });

            // إدخال الإعدادات الافتراضية
            DB::connection('oracle_sales')->table('online_app_points_settings')->insert([
                [
                    'key' => 'reset_months',
                    'value' => '6',
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
                [
                    'key' => 'next_reset_date',
                    'value' => now()->addMonths(6)->toDateString(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
                [
                    'key' => 'policy_text_ar',
                    'value' => 'يتم تصفير النقاط تلقائياً كل 6 أشهر من تاريخ بدء الدورة. يمكنك استبدال نقاطك بأي من الهدايا والمكافآت المتاحة قبل انتهاء المهلة.',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            ]);
        }

        // 3. الهدايا المتاحة للاستبدال
        if (!$schema->hasTable('online_app_points_gifts')) {
            $schema->create('online_app_points_gifts', function (Blueprint $table) {
                $table->increments('id');
                $table->string('title', 200);
                $table->text('description')->nullable();
                $table->integer('points_required');
                $table->string('image', 255)->nullable();
                $table->smallInteger('is_active')->default(1);
                $table->timestamps();
            });
        }

        // 4. سجل حركات النقاط
        if (!$schema->hasTable('online_app_points_history')) {
            $schema->create('online_app_points_history', function (Blueprint $table) {
                $table->increments('id');
                $table->integer('user_id');
                $table->integer('order_id')->nullable();
                $table->integer('gift_id')->nullable();
                $table->integer('points'); // موجب أو سالب
                $table->string('type', 30); // earned_order, redeemed_gift, expired, admin_adjustment
                $table->string('description', 255)->nullable();
                $table->timestamp('created_at')->useCurrent();
            });
        }

        // 5. طلبات استبدال الهدايا
        if (!$schema->hasTable('online_app_points_redemptions')) {
            $schema->create('online_app_points_redemptions', function (Blueprint $table) {
                $table->increments('id');
                $table->integer('user_id');
                $table->integer('gift_id');
                $table->integer('points_spent');
                $table->string('status', 20)->default('pending'); // pending, approved, rejected
                $table->string('admin_notes', 500)->nullable();
                $table->timestamps();
            });
        }

        // 6. إضافة عمود النقاط لجدول online_app_users إن لم يكن موجوداً
        if (!$schema->hasColumn('online_app_users', 'points')) {
            $schema->table('online_app_users', function (Blueprint $table) {
                $table->integer('points')->default(0);
            });
        }
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        $schema = Schema::connection('oracle_sales');
        $schema->dropIfExists('online_app_points_redemptions');
        $schema->dropIfExists('online_app_points_history');
        $schema->dropIfExists('online_app_points_gifts');
        $schema->dropIfExists('online_app_points_settings');
        $schema->dropIfExists('online_app_points_rules');

        if ($schema->hasColumn('online_app_users', 'points')) {
            $schema->table('online_app_users', function (Blueprint $table) {
                $table->dropColumn('points');
            });
        }
    }
};
