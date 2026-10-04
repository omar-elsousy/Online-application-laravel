<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const TABLE = 'ONLINE_APP_USERS';
    private const OLD_CONSTRAINT = 'OAU_MOBILE_POS_UQ';
    private const NEW_CONSTRAINT = 'OAU_MOBILE_UQ';

    public function up(): void
    {
        $connection = DB::connection('oracle_sales');
        $duplicate = $connection->selectOne(
            'select MOBILE from ' . self::TABLE . ' group by MOBILE having count(*) > 1 fetch first 1 rows only',
        );

        if ($duplicate) {
            throw new RuntimeException(
                'Cannot enforce one app account per mobile: duplicate mobile accounts already exist. Resolve them before running this migration.',
            );
        }

        $oldConstraint = $connection->selectOne(
            'select constraint_name from user_constraints where table_name = ? and constraint_name = ?',
            [self::TABLE, self::OLD_CONSTRAINT],
        );
        if ($oldConstraint) {
            $connection->statement(
                'alter table ' . self::TABLE . ' drop constraint ' . self::OLD_CONSTRAINT,
            );
        }

        $newConstraint = $connection->selectOne(
            'select constraint_name from user_constraints where table_name = ? and constraint_name = ?',
            [self::TABLE, self::NEW_CONSTRAINT],
        );
        if (!$newConstraint) {
            $connection->statement(
                'alter table ' . self::TABLE . ' add constraint ' . self::NEW_CONSTRAINT . ' unique (MOBILE)',
            );
        }
    }

    public function down(): void
    {
        $connection = DB::connection('oracle_sales');
        $connection->statement(
            'alter table ' . self::TABLE . ' drop constraint ' . self::NEW_CONSTRAINT,
        );
        $connection->statement(
            'alter table ' . self::TABLE . ' add constraint ' . self::OLD_CONSTRAINT .
            ' unique (MOBILE, SELECTED_POS_CODE)',
        );
    }
};
