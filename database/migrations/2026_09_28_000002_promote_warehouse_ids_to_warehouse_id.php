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

        // Keep the newest complete assignment, falling back to any legacy single ID.
        DB::connection('oracle_sales')->statement(
            'UPDATE ONLINE_APP_USERS '
            . 'SET WAREHOUSE_IDS = TO_CHAR(WAREHOUSE_ID) '
            . 'WHERE WAREHOUSE_IDS IS NULL AND WAREHOUSE_ID IS NOT NULL'
        );

        // Oracle requires the existing values to be cleared before changing NUMBER to VARCHAR2.
        DB::connection('oracle_sales')->statement(
            'UPDATE ONLINE_APP_USERS SET WAREHOUSE_ID = NULL WHERE WAREHOUSE_ID IS NOT NULL'
        );
        DB::connection('oracle_sales')->statement(
            'ALTER TABLE ONLINE_APP_USERS MODIFY (WAREHOUSE_ID VARCHAR2(1000))'
        );
        DB::connection('oracle_sales')->statement(
            'UPDATE ONLINE_APP_USERS SET WAREHOUSE_ID = WAREHOUSE_IDS WHERE WAREHOUSE_IDS IS NOT NULL'
        );

        $schema->table('online_app_users', function (Blueprint $table) {
            $table->dropColumn('warehouse_ids');
        });
    }

    public function down()
    {
        $connection = DB::connection('oracle_sales');
        $multiWarehouseCount = $connection->selectOne(
            "SELECT COUNT(*) AS aggregate FROM ONLINE_APP_USERS "
            . "WHERE WAREHOUSE_ID IS NOT NULL AND REGEXP_LIKE(WAREHOUSE_ID, '[^0-9]')"
        )->aggregate;

        if ((int) $multiWarehouseCount > 0) {
            throw new RuntimeException('Cannot downgrade WAREHOUSE_ID to NUMBER while multi-warehouse values exist.');
        }

        $schema = Schema::connection('oracle_sales');
        if (!$schema->hasColumn('online_app_users', 'warehouse_id_num')) {
            $schema->table('online_app_users', function (Blueprint $table) {
                $table->bigInteger('warehouse_id_num')->nullable();
            });
        }

        $connection->statement(
            'UPDATE ONLINE_APP_USERS SET WAREHOUSE_ID_NUM = TO_NUMBER(WAREHOUSE_ID) WHERE WAREHOUSE_ID IS NOT NULL'
        );
        $connection->statement(
            'UPDATE ONLINE_APP_USERS SET WAREHOUSE_ID = NULL WHERE WAREHOUSE_ID IS NOT NULL'
        );
        $connection->statement(
            'ALTER TABLE ONLINE_APP_USERS MODIFY (WAREHOUSE_ID NUMBER)'
        );
        $connection->statement(
            'UPDATE ONLINE_APP_USERS SET WAREHOUSE_ID = WAREHOUSE_ID_NUM WHERE WAREHOUSE_ID_NUM IS NOT NULL'
        );

        $schema->table('online_app_users', function (Blueprint $table) {
            $table->dropColumn('warehouse_id_num');
        });
    }
};
