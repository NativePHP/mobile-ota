<?php

namespace Nativephp\MobileOta\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Nativephp\MobileOta\Events\Concerns\BroadcastsGlobally;

class UpdateDownloaded implements BroadcastsGlobally
{
    use Dispatchable, SerializesModels;

    public function __construct(public int|string $version) {}
}
