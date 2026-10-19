<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;

class EncryptedSignatureStorage
{
    public function store(User $user, UploadedFile $file): string
    {
        $image = imagecreatefromstring($file->get());
        throw_unless($image, new \RuntimeException('Image de signature illisible.'));

        imagealphablending($image, false);
        imagesavealpha($image, true);
        for ($y = 0; $y < imagesy($image); $y++) {
            for ($x = 0; $x < imagesx($image); $x++) {
                $rgba = imagecolorat($image, $x, $y);
                $r = ($rgba >> 16) & 0xFF;
                $g = ($rgba >> 8) & 0xFF;
                $b = $rgba & 0xFF;
                if ($r > 242 && $g > 242 && $b > 242) {
                    imagesetpixel($image, $x, $y, imagecolorallocatealpha($image, 255, 255, 255, 127));
                }
            }
        }

        ob_start();
        imagepng($image, null, 9);
        $png = ob_get_clean();
        imagedestroy($image);

        $path = "signatures/{$user->id}.enc";
        Storage::disk('local')->put($path, Crypt::encryptString(base64_encode($png)));

        return $path;
    }

    public function bytes(User $user): string
    {
        throw_unless($user->signature_path, new \RuntimeException('Aucune signature enregistrée.'));

        return base64_decode(Crypt::decryptString(Storage::disk('local')->get($user->signature_path)), true);
    }

    public function delete(User $user): void
    {
        if ($user->signature_path) {
            Storage::disk('local')->delete($user->signature_path);
        }
    }
}
