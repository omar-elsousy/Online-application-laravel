<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'oracle_sales';

    public function up(): void
    {
        foreach ([
            'CART_ONLINE_APP',
            'ORDERS_ONLINE_APP',
            'FAVOURITES_ONLINE_APP',
            'ONLINE_APP_POINTS_HISTORY',
            'ONLINE_APP_POINTS_REDEMPTIONS',
        ] as $table) {
            $this->addColumnIfMissing($table, 'POS_CODE', 'VARCHAR2(80)');
        }

        // Existing point totals were account-wide and cannot safely be split by
        // POS. The owner explicitly approved starting all customer balances at 0.
        DB::connection($this->connection)->table('online_app_users')->update(['points' => 0]);

        if (!$this->tableExists('ONLINE_APP_POS_POINTS')) {
            DB::connection($this->connection)->statement(
                'CREATE TABLE ONLINE_APP_POS_POINTS ('
                . 'USER_ID NUMBER NOT NULL, POS_CODE VARCHAR2(80) NOT NULL, POINTS NUMBER(12,0) DEFAULT 0 NOT NULL, '
                . 'CREATED_AT TIMESTAMP NULL, UPDATED_AT TIMESTAMP NULL, '
                . 'CONSTRAINT PK_ONLINE_APP_POS_POINTS PRIMARY KEY (USER_ID, POS_CODE))'
            );
        }

        // Replace account-wide product uniqueness with customer-specific uniqueness.
        $this->dropConstraintIfExists('CART_ONLINE_APP', 'UNIQUE_USER_PRODUCT');
        $this->dropConstraintIfExists('FAVOURITES_ONLINE_APP', 'UNIQUE_USER_PRODUCT_FAV');
        $this->addUniqueConstraintIfMissing('CART_ONLINE_APP', 'UNIQUE_USER_POS_PRODUCT', '(USER_ID, POS_CODE, PRODUCT_ID)');
        $this->addUniqueConstraintIfMissing('FAVOURITES_ONLINE_APP', 'UNIQUE_USER_POS_PRODUCT_FAV', '(USER_ID, POS_CODE, PRODUCT_ID)');
    }

    public function down(): void
    {
        // Keep the new data and columns on rollback: deleting POS-scoped carts,
        // orders, or point balances would be destructive.
    }

    private function addColumnIfMissing(string $table, string $column, string $type): void
    {
        if (!$this->columnExists($table, $column)) {
            DB::connection($this->connection)->statement("ALTER TABLE {$table} ADD ({$column} {$type})");
        }
    }

    private function columnExists(string $table, string $column): bool
    {
        return DB::connection($this->connection)->table('user_tab_columns')
            ->whereRaw('table_name = ?', [strtoupper($table)])
            ->whereRaw('column_name = ?', [strtoupper($column)])
            ->exists();
    }

    private function tableExists(string $table): bool
    {
        return DB::connection($this->connection)->table('user_tables')
            ->whereRaw('table_name = ?', [strtoupper($table)])->exists();
    }

    private function dropConstraintIfExists(string $table, string $constraint): void
    {
        $exists = DB::connection($this->connection)->table('user_constraints')
            ->whereRaw('table_name = ?', [strtoupper($table)])
            ->whereRaw('constraint_name = ?', [strtoupper($constraint)])->exists();
        if ($exists) {
            DB::connection($this->connection)->statement("ALTER TABLE {$table} DROP CONSTRAINT {$constraint}");
        }
    }

    private function addUniqueConstraintIfMissing(string $table, string $constraint, string $columns): void
    {
        $exists = DB::connection($this->connection)->table('user_constraints')
            ->whereRaw('table_name = ?', [strtoupper($table)])
            ->whereRaw('constraint_name = ?', [strtoupper($constraint)])->exists();
        if (!$exists) {
            DB::connection($this->connection)->statement("ALTER TABLE {$table} ADD CONSTRAINT {$constraint} UNIQUE {$columns}");
        }
    }
};
