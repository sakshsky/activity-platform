<?php

namespace App\Jobs;

use App\Models\Screenshot;
use App\Services\Vault;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Str;

class GenerateThumbnail implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public string $screenshotId) {}

    public function backoff(): array
    {
        return [15, 60, 180];
    }

    public function handle(Vault $vault): void
    {
        $shot = Screenshot::find($this->screenshotId);
        if (! $shot || $shot->thumbnail_path) {
            return;
        }
        $source = $vault->get($shot->path, $shot->storage);
        $size = @getimagesizefromstring($source);
        // Keep large multi-monitor originals viewable without decoding them in GD.
        if (! $size || $size[0] * $size[1] > 16000000) {
            return;
        }
        $image = imagecreatefromstring($source);
        unset($source);
        if (! $image) {
            throw new \RuntimeException('Thumbnail image could not be decoded.');
        }
        $width = min(480, imagesx($image));
        $height = max(1, (int) round(imagesy($image) * $width / imagesx($image)));
        $thumbnail = imagecreatetruecolor($width, $height);
        imagecopyresampled($thumbnail, $image, 0, 0, 0, 0, $width, $height, imagesx($image), imagesy($image));
        ob_start();
        imagejpeg($thumbnail, null, 75);
        $bytes = ob_get_clean();
        imagedestroy($thumbnail);
        imagedestroy($image);
        // Each attempt owns its file; only the first conditional publication wins.
        $path = 'thumbnails/'.$shot->device_id.'/'.$shot->id.'-'.Str::uuid().'.jpg';
        $vault->put($path, $bytes, $shot->storage);
        if (! Screenshot::whereKey($shot->id)->whereNull('thumbnail_path')->update(['thumbnail_path' => $path])) {
            $vault->delete($path, $shot->storage);
        }
    }
}
