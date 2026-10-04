<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const TABLE = 'ONLINE_APP_USERS';
    private const COMPOSITE_CONSTRAINT = 'OAU_MOBILE_POS_UQ';

    public function up(): void
    {
        $connection = DB::connection('oracle_sales');
        $singleMobileConstraints = $connection->select(
            "select c.constraint_name
             from user_constraints c
             join user_cons_columns cc on cc.constraint_name = c.constraint_name
             where c.table_name = ? and c.constraint_type = 'U'
             group by c.constraint_name
             having count(*) = 1 and max(cc.column_name) = 'MOBILE'",
            [self::TABLE],
        );

        foreach ($singleMobileConstraints as $constraint) {
            $name = strtoupper((string) $constraint->constraint_name);
            if (!preg_match('/^[A-Z][A-Z0-9_$#]*$/', $name)) {
                throw new RuntimeException('Unexpected Oracle constraint name.');
            }
            $connection->statement('alter table ' . self::TABLE . ' drop constraint ' . $name);
        }

        $exists = $connection->selectOne(
            "select constraint_name from user_constraints where table_name = ? and constraint_name = ?",
            [self::TABLE, self::COMPOSITE_CONSTRAINT],
        );

        if (!$exists) {
            $connection->statement(
                'alter table ' . self::TABLE . ' add constraint ' . self::COMPOSITE_CONSTRAINT .
                ' unique (MOBILE, SELECTED_POS_CODE)',
            );
        }
    }

    public function down(): void
    {
        $connection = DB::connection('oracle_sales');
        $duplicate = $connection->selectOne(
            'select MOBILE from ' . self::TABLE . ' group by MOBILE having count(*) > 1 fetch first 1 rows only',
        );
        if ($duplicate) {
            throw new RuntimeException(
                'Cannot restore unique MOBILE constraint while multiple POS accounts share a mobile number.',
            );
        }

        $connection->statement(
            'alter table ' . self::TABLE . ' drop constraint ' . self::COMPOSITE_CONSTRAINT,
        );
        $connection->statement(
            'alter table ' . self::TABLE . ' add constraint OAU_MOBILE_UQ unique (MOBILE)',
        );
    }
};
