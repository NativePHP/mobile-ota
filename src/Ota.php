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

    public function check(): array
    {
        $result = $this->call('Ota.Check', [
            'endpoint' => config('nativephp-ota.endpoint'),
            'project' => config('nativephp-ota.project_uuid'),
            'token' => config('nativephp-ota.token'),
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

        $version = $check['current_version'] ?? $check['version'] ?? '0';

        $downloaded = $this->call('Ota.Download', [
            'url' => $url,
            'version' => $version,
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
        $result = $this->call('Ota.Prompt', [
            'endpoint' => config('nativephp-ota.endpoint'),
            'project' => config('nativephp-ota.project_uuid'),
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
