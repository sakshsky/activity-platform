<?php

namespace App\Services;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Process;

class Vault
{
    public function put(string $path, string $bytes, ?string $driver = null): void
    {
        $driver ??= config('tracker.storage');
        $this->validatePath($path);
        if ($driver === 'local') {
            if (! Storage::disk('local')->put('tracker/'.$path, $bytes)) {
                throw new RuntimeException('Private storage write failed.');
            }

            return;
        }
        $temporary = tempnam(sys_get_temp_dir(), 'tracker-upload-');
        try {
            if (file_put_contents($temporary, $bytes) === false) {
                throw new RuntimeException('Cannot stage storage upload.');
            }
            $this->ftp($path, ['--ftp-create-dirs', '--upload-file', $temporary]);
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }

    public function get(string $path, ?string $driver = null): string
    {
        $this->validatePath($path);
        if (($driver ?? config('tracker.storage')) === 'local') {
            $bytes = Storage::disk('local')->get('tracker/'.$path);
            if ($bytes === null) {
                throw new RuntimeException('Stored file is unavailable.');
            }

            return $bytes;
        }

        return $this->ftp($path, []);
    }

    public function delete(string $path, ?string $driver = null): void
    {
        $this->validatePath($path);
        if (($driver ?? config('tracker.storage')) === 'local') {
            if (! Storage::disk('local')->delete('tracker/'.$path)) {
                throw new RuntimeException('Storage delete failed.');
            }

            return;
        }
        $this->ftp(dirname($path).'/', ['--list-only', '--quote', '-DELE '.basename($path)]);
    }

    public function check(): void
    {
        $path = 'checks/'.Str::uuid().'.txt';
        $contents = 'activity-storage-check';
        $this->put($path, $contents);
        try {
            if ($this->get($path) !== $contents) {
                throw new RuntimeException('Storage verification failed.');
            }
        } finally {
            $this->delete($path);
        }
    }

    private function validatePath(string $path): void
    {
        if (! preg_match('~^[A-Za-z0-9_./-]+$~', $path) || str_contains($path, '..') || str_starts_with($path, '/')) {
            throw new RuntimeException('Invalid storage path.');
        }
    }

    private function ftp(string $path, array $extra): string
    {
        $base = config('tracker.ftps_url');
        $parts = parse_url($base ?? '');
        if (! $parts || ! in_array($parts['scheme'] ?? '', ['ftp', 'ftps'], true) || empty($parts['host']) || isset($parts['user']) || isset($parts['query']) || isset($parts['fragment'])) {
            throw new RuntimeException('Configure a valid FTPS folder URL on the server.');
        }
        $quote = static fn (string $value): string => '"'.str_replace(['\\', '"', "\n", "\r"], ['\\\\', '\"', '\n', '\r'], $value).'"';
        $config = 'url = '.$quote(rtrim($base, '/').'/'.$path)."\n".'user = '.$quote(config('tracker.ftps_username').':'.config('tracker.ftps_password'))."\n";
        $process = new Process([config('tracker.curl_binary'), '--disable', '--config', '-', '--silent', '--show-error', '--fail', '--globoff', '--ssl-reqd', '--connect-timeout', '10', '--max-time', '40', ...$extra], null, null, $config, 45);
        $process->run();
        if (! $process->isSuccessful()) {
            throw new RuntimeException('FTPS storage request failed. Check server credentials, certificates, folder permissions and connectivity.');
        }

        return $process->getOutput();
    }
}
