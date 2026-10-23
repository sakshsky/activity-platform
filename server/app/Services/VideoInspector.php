<?php

namespace App\Services;

use Symfony\Component\Process\Process;

class VideoInspector
{
    public function inspect(string $path): array
    {
        $process = new Process([config('tracker.ffprobe_binary', 'ffprobe'), '-v', 'error', '-protocol_whitelist', 'file,pipe', '-f', 'mov', '-show_entries', 'stream=codec_type,codec_name,width,height:format=duration,format_name', '-of', 'json', $path], null, null, null, 15);
        try {
            $process->run();
        } catch (\Throwable) {
            abort(503, 'Video validation is unavailable. Install FFprobe on the server.');
        }
        abort_unless($process->isSuccessful(), 422, 'Invalid or unsupported MP4 video.');

        return $this->validate(json_decode($process->getOutput(), true) ?? []);
    }

    public function validate(array $info): array
    {
        $streams = $info['streams'] ?? [];
        $video = $streams[0] ?? [];
        $duration = (float) ($info['format']['duration'] ?? 0);
        abort_unless(count($streams) === 1 && ($video['codec_type'] ?? '') === 'video' && ($video['codec_name'] ?? '') === 'h264' && ($video['width'] ?? 0) > 0 && ($video['height'] ?? 0) > 0 && $video['width'] <= 1280 && $video['height'] <= 720 && is_finite($duration) && $duration > 0 && $duration <= 31, 422, 'Use a silent H.264 MP4 clip, at most 1280×720 and 30 seconds.');

        return ['width' => $video['width'], 'height' => $video['height'], 'duration_seconds' => round($duration, 2)];
    }
}
