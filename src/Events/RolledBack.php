<?php

namespace Nativephp\MobileOta\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class RolledBack
{
    use Dispatchable, SerializesModels;

    public function __construct(public int|string $version) {}
}
