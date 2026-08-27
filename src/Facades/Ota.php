<?php

namespace Nativephp\MobileOta\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static int|string currentVersion()
 * @method static array status()
 * @method static array check()
 * @method static array downloadAndApply(bool $silent = false)
 * @method static array install(bool $silent = false, int|string|null $version = null)
 * @method static bool rollback()
 * @method static array prompt()
 * @method static void onLaunch()
 *
 * @see \Nativephp\MobileOta\Ota
 */
class Ota extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Nativephp\MobileOta\Ota::class;
    }
}
