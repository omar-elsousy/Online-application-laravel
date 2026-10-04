<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'oracle_sales';

    public function up(): void
    {
        $schema = Schema::connection('oracle_sales');
        if (!$schema->hasColumn('online_app_users', 'selected_pos_code')) {
            $schema->table('online_app_users', function (Blueprint $table) {
                $table->string('selected_pos_code', 80)->nullable();
            });
        }
    }

    public function down(): void
    {
        $schema = Schema::connection('oracle_sales');
        if ($schema->hasColumn('online_app_users', 'selected_pos_code')) {
            $schema->table('online_app_users', function (Blueprint $table) {
                $table->dropColumn('selected_pos_code');
            });
        }
    }
};
