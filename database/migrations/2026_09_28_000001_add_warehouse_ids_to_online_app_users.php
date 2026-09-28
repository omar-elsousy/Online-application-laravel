<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'oracle_sales';

    public function up()
    {
        $schema = Schema::connection('oracle_sales');

        if (!$schema->hasColumn('online_app_users', 'warehouse_ids')) {
            $schema->table('online_app_users', function (Blueprint $table) {
                $table->string('warehouse_ids', 1000)->nullable();
            });
        }

        // Preserve existing single-warehouse assignments in the new list column.
        DB::connection('oracle_sales')->statement(
            'UPDATE ONLINE_APP_USERS '
            . 'SET WAREHOUSE_IDS = TO_CHAR(WAREHOUSE_ID) '
            . 'WHERE WAREHOUSE_IDS IS NULL AND WAREHOUSE_ID IS NOT NULL'
        );
    }

    public function down()
    {
        $schema = Schema::connection('oracle_sales');

        if ($schema->hasColumn('online_app_users', 'warehouse_ids')) {
            $schema->table('online_app_users', function (Blueprint $table) {
                $table->dropColumn('warehouse_ids');
            });
        }
    }
};
