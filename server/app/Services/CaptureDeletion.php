<?php

namespace App\Services;

use App\Models\Screenshot;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class CaptureDeletion
{
    public const PLACEHOLDER = 'placeholders/deleted-capture.png';

    public function placeholder(): string
    {
        $disk = Storage::disk('local');
        if (! $disk->exists(self::PLACEHOLDER)) {
            $image = imagecreatetruecolor(640, 360);
            $bg = imagecolorallocate($image, 235, 242, 241);
            $fg = imagecolorallocate($image, 35, 65, 65);
            imagefill($image, 0, 0, $bg);
            imagestring($image, 5, 205, 150, 'Screenshot deleted', $fg);
            imagestring($image, 3, 130, 185, 'Original content is no longer available here.', $fg);
            ob_start();
            imagepng($image);
            $bytes = ob_get_clean();
            imagedestroy($image);
            if (! $disk->put(self::PLACEHOLDER, $bytes)) {
                throw new \RuntimeException('Cannot save deletion placeholder.');
            }
        }

        return $disk->get(self::PLACEHOLDER);
    }

    public function content(string $id, bool $thumbnail, Vault $vault): array
    {
        return DB::transaction(function () use ($id, $thumbnail, $vault) {
            $shot = Screenshot::whereKey($id)->lockForUpdate()->firstOrFail();
            if ($shot->deleted_at) {
                return [$this->placeholder(), 'image/png'];
            }
            $thumb = $thumbnail && $shot->thumbnail_path;

            return [$vault->get($thumb ? $shot->thumbnail_path : $shot->path, $shot->storage), $thumb ? 'image/jpeg' : 'image/png'];
        });
    }

    public function cleanup(string $id, Vault $vault): void
    {
        // Commit each confirmed removal separately; a later failure cannot restore visibility.
        foreach (['path', 'thumbnail_path'] as $field) {
            DB::transaction(function () use ($id, $field, $vault) {
                $shot = Screenshot::whereKey($id)->lockForUpdate()->first();
                if (! $shot || ! $shot->deleted_at) {
                    return;
                }
                if ($shot->$field) {
                    $vault->delete($shot->$field, $shot->storage);
                    $shot->update([$field => $field === 'path' ? '' : null]);
                }
            });
        }
        Screenshot::whereKey($id)->whereNotNull('deleted_at')->where('path','')->whereNull('thumbnail_path')->update(['deletion_cleanup_pending' => false]);
    }
}
