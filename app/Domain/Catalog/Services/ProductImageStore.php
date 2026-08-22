<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * The only writer of product image files.
 *
 * Security posture (ARCHITECTURE-V1.md section 12, "Secure uploads"):
 *   - the stored filename is GENERATED, never taken from the client, so a
 *     caller cannot influence the path or plant a traversal sequence;
 *   - the extension is derived from the validated MIME type, not from the
 *     uploaded name;
 *   - SVG is refused entirely (it can carry <script>);
 *   - only a relative path is persisted, so no server filesystem layout is
 *     ever exposed in an API response.
 *
 * Local development uses the `public` disk. Cloud storage is deliberately out
 * of scope for this stage.
 */
final class ProductImageStore
{
    public const DISK = 'public';

    private const DIRECTORY = 'products';

    /**
     * Accepted MIME types mapped to the extension we will actually write.
     * SVG is intentionally absent.
     *
     * @var array<string, string>
     */
    private const ALLOWED_MIME_EXTENSIONS = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    /**
     * @return list<string>
     */
    public static function allowedMimeTypes(): array
    {
        return array_keys(self::ALLOWED_MIME_EXTENSIONS);
    }

    /**
     * Stores the upload and returns the relative path to persist.
     */
    public function store(UploadedFile $file): string
    {
        $extension = self::ALLOWED_MIME_EXTENSIONS[$file->getMimeType()] ?? 'jpg';

        // Generated name: the client's filename never reaches the filesystem.
        $filename = Str::ulid()->toBase32().'.'.$extension;

        Storage::disk(self::DISK)->putFileAs(self::DIRECTORY, $file, $filename);

        return self::DIRECTORY.'/'.$filename;
    }

    /**
     * Deletes a previously stored image.
     *
     * Guarded so it can only ever remove a file inside the managed directory,
     * even if a malformed path somehow reached the database.
     */
    public function delete(?string $path): void
    {
        if ($path === null || $path === '') {
            return;
        }

        if (! str_starts_with($path, self::DIRECTORY.'/') || str_contains($path, '..')) {
            return;
        }

        Storage::disk(self::DISK)->delete($path);
    }

    /**
     * Public URL for a stored path, or null when there is no image.
     */
    public function url(?string $path): ?string
    {
        if ($path === null || $path === '') {
            return null;
        }

        return Storage::disk(self::DISK)->url($path);
    }
}
