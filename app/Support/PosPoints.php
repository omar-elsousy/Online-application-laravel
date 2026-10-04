<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

class PosPoints
{
    public static function balance(int $userId, string $posCode): int
    {
        self::ensure($userId, $posCode);

        return (int) DB::connection('oracle_sales')->table('online_app_pos_points')
            ->where('user_id', $userId)->where('pos_code', $posCode)->value('points');
    }

    public static function change(int $userId, string $posCode, int $amount): void
    {
        self::ensure($userId, $posCode);
        DB::connection('oracle_sales')->table('online_app_pos_points')
            ->where('user_id', $userId)->where('pos_code', $posCode)
            ->increment('points', $amount, ['updated_at' => now()]);
    }

    public static function deduct(int $userId, string $posCode, int $amount): bool
    {
        self::ensure($userId, $posCode);
        $affected = DB::connection('oracle_sales')->table('online_app_pos_points')
            ->where('user_id', $userId)->where('pos_code', $posCode)
            ->where('points', '>=', $amount)
            ->decrement('points', $amount, ['updated_at' => now()]);

        return $affected > 0;
    }

    private static function ensure(int $userId, string $posCode): void
    {
        $connection = DB::connection('oracle_sales');
        $connection->statement(
            'MERGE INTO ONLINE_APP_POS_POINTS target USING (SELECT ? USER_ID, ? POS_CODE FROM dual) source '
            . 'ON (target.USER_ID = source.USER_ID AND target.POS_CODE = source.POS_CODE) '
            . 'WHEN NOT MATCHED THEN INSERT (USER_ID, POS_CODE, POINTS, CREATED_AT, UPDATED_AT) '
            . 'VALUES (source.USER_ID, source.POS_CODE, 0, SYSTIMESTAMP, SYSTIMESTAMP)',
            [$userId, $posCode]
        );
    }
}
