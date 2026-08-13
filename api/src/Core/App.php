<?php

declare(strict_types=1);

namespace App\Core;

final class App
{
    private static ?Auth $auth = null;
    private static ?Crypto $crypto = null;

    public static function auth(): Auth
    {
        return self::$auth ??= new Auth(Database::connection());
    }

    public static function crypto(): Crypto
    {
        return self::$crypto ??= new Crypto(
            (string) Env::get('NATIONAL_ID_ENCRYPTION_KEY', ''),
            (string) Env::get('NATIONAL_ID_HASH_KEY', '')
        );
    }
}
