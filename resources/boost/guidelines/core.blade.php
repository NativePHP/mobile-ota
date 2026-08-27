## nativephp/mobile-ota

A NativePHP Mobile plugin

### Installation

```bash
composer require nativephp/mobile-ota
```

### PHP Usage (Livewire/Blade)

Use the `Ota` facade:

@verbatim
<code-snippet name="Using Ota Facade" lang="php">
use Nativephp\MobileOta\Facades\Ota;

// Execute the plugin functionality
$result = Ota::execute(['option1' => 'value']);

// Get the current status
$status = Ota::getStatus();
</code-snippet>
@endverbatim

### Available Methods

- `Ota::execute()`: Execute the plugin functionality
- `Ota::getStatus()`: Get the current status

### Events

- `OtaCompleted`: Listen with `#[OnNative(OtaCompleted::class)]`

@verbatim
<code-snippet name="Listening for Ota Events" lang="php">
use Native\Mobile\Attributes\OnNative;
use Nativephp\MobileOta\Events\OtaCompleted;

#[OnNative(OtaCompleted::class)]
public function handleOtaCompleted($result, $id = null)
{
    // Handle the event
}
</code-snippet>
@endverbatim

### JavaScript Usage (Vue/React/Inertia)

@verbatim
<code-snippet name="Using Ota in JavaScript" lang="javascript">
import { ota } from '@nativephp/mobile-ota';

// Execute the plugin functionality
const result = await ota.execute({ option1: 'value' });

// Get the current status
const status = await ota.getStatus();
</code-snippet>
@endverbatim