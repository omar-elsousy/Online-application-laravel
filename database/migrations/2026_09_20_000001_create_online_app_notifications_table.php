<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        $schema = Schema::connection('oracle_sales');

        if (!$schema->hasTable('online_app_notifications')) {
            $schema->create('online_app_notifications', function (Blueprint $table) {
                $table->increments('id');
                $table->integer('user_id')->index();
                $table->string('title', 200);
                $table->text('body')->nullable();
                $table->smallInteger('is_read')->default(0);
                $table->timestamp('read_at')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down()
    {
        Schema::connection('oracle_sales')->dropIfExists('online_app_notifications');
    }
};
