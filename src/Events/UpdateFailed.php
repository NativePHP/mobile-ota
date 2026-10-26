<?php

namespace Nativephp\MobileOta\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Native\Mobile\Events\Contracts\DispatchableFromNative;
use Nativephp\MobileOta\Events\Concerns\BroadcastsGlobally;

class UpdateFailed implements BroadcastsGlobally, DispatchableFromNative
{
    use Dispatchable, SerializesModels;

    public function __construct(public string $stage, public string $message) {}
}
