<?php

namespace Nativephp\MobileOta;

use Nativephp\MobileOta\Events\RolledBack;
use Nativephp\MobileOta\Events\UpdateApplied;
use Nativephp\MobileOta\Events\UpdateAvailable;
use Nativephp\MobileOta\Events\UpdateDownloaded;
use Nativephp\MobileOta\Events\UpdateFailed;

class Ota
{
    public function currentVersion(): int|string
    {
        $status = $this->status();

        $version = $status['current_version'] ?? $status['version'] ?? 0;

        if ($version === '' || $version === null) {
            return 0;
        }

        return $version;
    }

    public function status(): array
    {
        return $this->call('Ota.GetStatus') ?? [
            'version' => 0,
            'current_version' => '0',
            'hasPrevious' => false,
            'pending' => false,
            'queued' => false,
            'applyOnNextBoot' => false,
        ];
    }

    /**
     * The payload describes itself: a bundle that arrived over the air carries
     * ota.json, so after the first update the shell's own identity is whatever
     * it is running, not what it shipped with.
     *
     * @return array{release_uuid: ?string, project_uuid: ?string, arc: string, shell_fingerprint: ?string, fingerprint_algorithm: string}
     */
    public function identity(): array
    {
        $manifest = $this->manifest();

        // Configuration first: the builder stamps the shell's identity into the
        // app's environment, and a payload overwrites it with its own. The
        // manifest answers for payloads published before that, where ota.json
        // was the only thing describing them.
        return [
            'release_uuid' => config('nativephp-ota.release_uuid') ?: ($manifest['release_uuid'] ?? null),
            'project_uuid' => config('nativephp-ota.project_uuid') ?: ($manifest['app_id'] ?? null),
            'arc' => (string) (config('nativephp-ota.arc') ?: ($manifest['arc'] ?? 'staging')),
            'shell_fingerprint' => config('nativephp-ota.shell_fingerprint') ?: ($manifest['shell_fingerprint'] ?? null),
            'fingerprint_algorithm' => (string) (config('nativephp-ota.fingerprint_algorithm')
                ?: ($manifest['fingerprint_algorithm'] ?? 1)),
        ];
    }

    /**
     * What the payload says about itself. Present only once an update has been
     * applied — the bundled app ships without one.
     *
     * @return array<string, mixed>
     */
    public function manifest(): array
    {
        $path = base_path('ota.json');

        if (! is_file($path)) {
            return [];
        }

        return json_decode((string) file_get_contents($path), true) ?: [];
    }

    public function currentRelease(): ?string
    {
        return $this->identity()['release_uuid'];
    }

    public function check(): array
    {
        $identity = $this->identity();

        $result = $this->call('Ota.Check', [
            'endpoint' => config('nativephp-ota.endpoint'),
            'project' => $identity['project_uuid'],
            'token' => config('nativephp-ota.token'),
            'arc' => $identity['arc'],
            'fingerprint' => $identity['shell_fingerprint'],
            'algorithm' => $identity['fingerprint_algorithm'],
            'release' => $identity['release_uuid'],
            // What this shell shipped with: a release published before it is
            // already inside the app, and installing it would go backwards.
            'shell_built_at' => (string) config('nativephp-ota.shell_built_at'),
            'version' => $this->currentVersion(),
        ]);

        if (! $result) {
            UpdateFailed::dispatch('check', 'Native OTA check failed or is unavailable off-device.');

            return ['available' => false, 'version' => $this->currentVersion()];
        }

        if (! empty($result['available'])) {
            $remoteVersion = $result['current_version'] ?? $result['version'] ?? '0';
            UpdateAvailable::dispatch($remoteVersion, $result['download_url'] ?? $result['url'] ?? null);
        }

        return $result;
    }

    public function downloadAndApply(bool $silent = false): array
    {
        $check = $this->check();

        $url = $check['url'] ?? $check['download_url'] ?? null;

        if (empty($check['available']) || empty($url)) {
            return $check;
        }

        // Belt and braces for the same rule the server applies: never install a
        // release older than the code this shell was built with.
        if ($this->predatesShell($check['published_at'] ?? null)) {
            UpdateFailed::dispatch('download', 'That release is older than the installed app.');

            return [...$check, 'available' => false, 'reason' => 'older than the installed app'];
        }

        $version = $check['current_version'] ?? $check['version'] ?? '0';

        $downloaded = $this->call('Ota.Download', [
            'url' => $url,
            'version' => $version,
            // Checked against the bytes before they are queued for extraction,
            // then recorded beside them so the applied payload carries what the
            // server said about the release it is.
            'release' => $check['release'] ?? null,
            'sha256' => $check['sha256'] ?? null,
            'size' => $check['size'] ?? null,
            'commit' => $check['commit'] ?? null,
            'published_at' => $check['published_at'] ?? null,
        ]);

        if (! $downloaded || empty($downloaded['success'])) {
            UpdateFailed::dispatch('download', $downloaded['error'] ?? $downloaded['message'] ?? 'Download failed.');

            return [
                'available' => true,
                'success' => false,
                'stage' => 'download',
                'error' => $downloaded['error'] ?? $downloaded['message'] ?? 'Download failed.',
            ];
        }

        UpdateDownloaded::dispatch($version);

        // Apply queues the zip for core to extract on the next boot.
        return $this->install($silent, $version);
    }

    public function install(bool $silent = false, int|string|null $version = null): array
    {
        $params = ['silent' => $silent];
        if ($version !== null && $version !== '') {
            $params['version'] = $version;
        }

        $applied = $this->call('Ota.Apply', $params);

        if (! $applied || empty($applied['success'])) {
            UpdateFailed::dispatch('apply', $applied['error'] ?? 'Apply failed.');

            return ['success' => false, 'stage' => 'apply'];
        }

        UpdateApplied::dispatch($applied['version'] ?? $version ?? '0');

        return $applied;
    }

    public function rollback(): bool
    {
        $result = $this->call('Ota.Rollback');

        if (! $result || empty($result['success'])) {
            UpdateFailed::dispatch('rollback', $result['error'] ?? 'Rollback failed.');

            return false;
        }

        RolledBack::dispatch($result['version'] ?? $result['current_version'] ?? '0');

        return true;
    }

    public function prompt(): array
    {
        $identity = $this->identity();

        $result = $this->call('Ota.Prompt', [
            'endpoint' => config('nativephp-ota.endpoint'),
            'project' => $identity['project_uuid'],
            'arc' => $identity['arc'],
            'fingerprint' => $identity['shell_fingerprint'],
            'algorithm' => $identity['fingerprint_algorithm'],
            'release' => $identity['release_uuid'],
            'token' => config('nativephp-ota.token'),
            'version' => $this->currentVersion(),
        ]);

        return $result ?? ['available' => false, 'accepted' => false];
    }

    public function onLaunch(): void
    {
        $mode = config('nativephp-ota.mode', 'manual');

        if ($mode === 'prompt') {
            $this->prompt();
        } elseif ($mode === 'silent') {
            $this->downloadAndApply(silent: true);
        }
    }



    /**
     * A release published before this shell was built is already inside it, so
     * installing it would go backwards. The server applies the same rule; this
     * is the client refusing to be walked back regardless.
     */
    private function predatesShell(?string $publishedAt): bool
    {
        $builtAt = config('nativephp-ota.shell_built_at');

        if (! $builtAt || ! $publishedAt) {
            return false;
        }

        $published = strtotime($publishedAt);
        $built = strtotime((string) $builtAt);

        return $published !== false && $built !== false && $published <= $built;
    }

    protected function call(string $method, array $params = []): ?array
    {
        if (! function_exists('nativephp_call')) {
            return null;
        }

        $raw = nativephp_call($method, json_encode($params));

        if (! $raw) {
            return null;
        }

        $decoded = json_decode($raw, true);

        if (! is_array($decoded)) {
            return null;
        }

        if (array_key_exists('data', $decoded) && is_array($decoded['data'])) {
            return $decoded['data'];
        }

        return $decoded;
    }
}
