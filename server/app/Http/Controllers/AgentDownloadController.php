<?php

namespace App\Http\Controllers;

use App\Services\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;

class AgentDownloadController extends Controller
{
    private function artifact(string $kind): array
    {
        abort_unless(in_array($kind, ['exe', 'zip'], true), 404);
        $directory = storage_path('app/agent-release');
        $manifest = is_file($directory.'/release.json') ? json_decode(file_get_contents($directory.'/release.json'), true) : null;
        $item = $manifest['files'][$kind] ?? null;
        abort_unless($item && preg_match('/^activity-agent[-a-zA-Z0-9.]*\.(exe|zip)$/', $item['name'] ?? ''), 404, 'Agent release has not been installed on this server.');
        $path = $directory.'/'.$item['name'];
        abort_unless(is_file($path) && hash_equals($item['sha256'] ?? '', hash_file('sha256', $path)), 503, 'Release verification failed. Contact the administrator.');

        return [$path, $item, $manifest['version']];
    }

    public function info(): JsonResponse
    {
        $files = [];
        foreach (['exe', 'zip'] as $kind) {
            try {
                [$path,$item,$version] = $this->artifact($kind);
                $files[$kind] = [...$item, 'bytes' => filesize($path), 'url' => route('agent.download', ['kind' => $kind])];
            } catch (HttpException $e) {
                if ($e->getStatusCode() !== 404) {
                    throw $e;
                }
            }
        }

        return response()->json(['available' => count($files) > 0, 'version' => $version ?? null, 'files' => $files]);
    }

    public function download(Request $request, string $kind): BinaryFileResponse
    {
        [$path,$item] = $this->artifact($kind);
        Audit::record($request, 'agent.downloaded', 'release', $item['name']);

        return response()->download($path, $item['name'], ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }
}
