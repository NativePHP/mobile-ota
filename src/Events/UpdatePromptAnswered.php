<?php

namespace Nativephp\MobileOta\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Nativephp\MobileOta\Events\Concerns\BroadcastsGlobally;

/**
 * A button was tapped on the update prompt. Core's native alert builds this
 * from the button's position and label and the alert's id, so the plugin can
 * answer its own dialog without catching every ButtonPressed in the app.
 */
class UpdatePromptAnswered implements BroadcastsGlobally
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public int $index,
        public string $label,
        public ?string $id = null
    ) {}
}
