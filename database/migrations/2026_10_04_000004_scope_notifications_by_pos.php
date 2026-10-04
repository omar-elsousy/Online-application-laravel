<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'oracle_sales';

    public function up(): void
    {
        $exists = DB::connection($this->connection)->table('user_tab_columns')
            ->where('table_name', 'ONLINE_APP_NOTIFICATIONS')
            ->where('column_name', 'POS_CODE')
            ->exists();
        if (!$exists) {
            DB::connection($this->connection)->statement('ALTER TABLE ONLINE_APP_NOTIFICATIONS ADD (POS_CODE VARCHAR2(80))');
        }
    }

    public function down(): void
    {
        // Keep customer-scoped notifications on rollback to avoid losing data.
    }
};
