<?php

namespace Nativephp\MobileOta\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class UpdateFailed
{
    use Dispatchable, SerializesModels;

    public function __construct(public string $stage, public string $message) {}
}
