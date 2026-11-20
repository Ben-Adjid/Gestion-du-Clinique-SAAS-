<?php

namespace App\Services;

use App\Models\Clinic;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Logo of a clinic, stored on the public disk as clinics/{clinic_id}/logo.png.
 *
 * Every upload is decoded and re-encoded as a PNG no larger than 300 px on its longest side. This
 * sanitises the file (no embedded script, no metadata, no hidden payload) and keeps the PDFs light.
 * SVG is refused: it is code, and decoding it with GD fails, which is the intended outcome.
 * Printed documents fall back to the clinic's name when there is no usable logo.
 */
final class ClinicLogo
{
    public const MAX_KILOBYTES = 1024;
    public const MAX_PIXELS = 300;
    public const EXTENSIONS = ['png', 'jpg', 'jpeg', 'webp'];
    /** Every file name a logo may have had, so that replacing or deleting leaves nothing behind. */
    private const ALL_FILES = ['png', 'jpg', 'jpeg', 'webp', 'svg'];

    public static function store(Clinic $clinic, UploadedFile $file): string
    {
        $png = self::toPng($file);
        self::delete($clinic);

        $path = "clinics/{$clinic->id}/logo.png";
        Storage::disk('public')->put($path, $png);
        $clinic->update(['settings' => array_merge($clinic->settings ?? [], ['logo_path' => $path])]);

        return $path;
    }

    public static function delete(Clinic $clinic): void
    {
        foreach (self::ALL_FILES as $extension) {
            Storage::disk('public')->delete("clinics/{$clinic->id}/logo.{$extension}");
        }
        $settings = $clinic->settings ?? [];
        unset($settings['logo_path']);
        $clinic->update(['settings' => $settings]);
    }

    /**
     * Decodes the upload and returns a PNG scaled to at most MAX_PIXELS.
     * Anything GD cannot decode as a raster image (SVG, PDF renamed to .png, corrupt files) is refused.
     */
    public static function toPng(UploadedFile $file): string
    {
        if (!function_exists('imagecreatefromstring')) {
            throw ValidationException::withMessages(['logo' => 'Le traitement d’image est indisponible sur ce serveur (extension GD absente).']);
        }

        $source = @imagecreatefromstring((string) file_get_contents($file->getRealPath()));
        if ($source === false) {
            throw ValidationException::withMessages(['logo' => 'Image illisible : le fichier est corrompu, ou ce n’est pas une image PNG, JPG ou WEBP (SVG refusé).']);
        }

        $width = imagesx($source);
        $height = imagesy($source);
        $scale = min(1, self::MAX_PIXELS / max($width, $height));
        $targetWidth = max(1, (int) round($width * $scale));
        $targetHeight = max(1, (int) round($height * $scale));

        $target = imagecreatetruecolor($targetWidth, $targetHeight);
        imagealphablending($target, false);
        imagesavealpha($target, true);
        imagecopyresampled($target, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);

        ob_start();
        imagepng($target, null, 6);
        $png = (string) ob_get_clean();

        return $png;
    }

    /** data: URI for the PDF documents, or null when there is no embeddable logo (the name is printed instead). */
    public static function pdfDataUri(Clinic $clinic): ?string
    {
        $path = $clinic->settings['logo_path'] ?? null;
        if (!$path || !Storage::disk('public')->exists($path)) {
            return null;
        }

        return 'data:image/png;base64,'.base64_encode(Storage::disk('public')->get($path));
    }
}
