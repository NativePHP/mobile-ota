<?php

namespace Nativephp\MobileOta\Events\Concerns;

/**
 * Marks an event the native side sends. On an EDGE screen core only hands a
 * native event to Laravel's dispatcher when it carries core's
 * BroadcastsGlobally marker; otherwise it goes to the screen's own handlers
 * and nowhere else, so an Event::listen in the app never hears it.
 *
 * Cores from before that marker have no such interface, so there this is an
 * empty one and changes nothing.
 */
if (interface_exists(\Native\Mobile\Events\Concerns\BroadcastsGlobally::class)) {
    interface BroadcastsGlobally extends \Native\Mobile\Events\Concerns\BroadcastsGlobally {}
} else {
    interface BroadcastsGlobally {}
}
