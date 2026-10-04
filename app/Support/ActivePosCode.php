<?php

namespace App\Support;

class ActivePosCode
{
    public static function forUser(object $user): ?string
    {
        $contextUser = method_exists($user, 'tokenContextValue') ? $user : auth()->user();
        $posCode = $contextUser && method_exists($contextUser, 'tokenContextValue')
            ? $contextUser->tokenContextValue('pos_code')
            : null;

        // Old access tokens may not have scoped abilities. SELECTED_POS_CODE is
        // only a compatibility fallback; new tokens always carry their own POS.
        $posCode ??= $user->selected_pos_code ?? null;
        $posCode = trim((string) $posCode);

        return preg_match('/^\d+_\d+$/', $posCode) ? $posCode : null;
    }

    public static function requireForUser(object $user): ?string
    {
        return self::forUser($user);
    }
}
