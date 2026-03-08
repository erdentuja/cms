<?php
/**
 * Képfeldolgozó osztály
 * - Képek átméretezése
 * - WebP konverzió
 * - Thumbnail generálás
 */
class ImageProcessor {

    // Előre definiált méretek
    const SIZES = [
        'thumbnail' => ['width' => 150, 'height' => 150],
        'medium'    => ['width' => 640, 'height' => 640],
        'large'     => ['width' => 1200, 'height' => 1200],
    ];

    /**
     * Kép átméretezése és különböző méretek generálása
     * @param string $sourcePath Eredeti kép elérési útja
     * @param string $uploadDir Feltöltési könyvtár
     * @param string $baseFilename Alap fájlnév (extension nélkül)
     * @return array Generált fájlok információi
     */
    public static function generateSizes($sourcePath, $uploadDir, $baseFilename) {
        $result = [];

        // Eredeti kép információk
        $imageInfo = getimagesize($sourcePath);
        if (!$imageInfo) {
            return ['error' => 'Nem sikerült betölteni a képet'];
        }

        list($originalWidth, $originalHeight, $imageType) = $imageInfo;

        // GD resource létrehozása az eredeti képből
        $sourceImage = self::createImageResource($sourcePath, $imageType);
        if (!$sourceImage) {
            return ['error' => 'Nem támogatott képformátum'];
        }

        // Eredeti kép adatai
        $result['original'] = [
            'width' => $originalWidth,
            'height' => $originalHeight,
            'path' => basename($sourcePath)
        ];

        // Különböző méretek generálása
        foreach (self::SIZES as $sizeName => $dimensions) {
            $maxWidth = $dimensions['width'];
            $maxHeight = $dimensions['height'];

            // Ha az eredeti kép kisebb, mint a célméret, akkor nem kell átméretezni
            if ($originalWidth <= $maxWidth && $originalHeight <= $maxHeight) {
                continue;
            }

            // Arányos méretezés kiszámítása
            $ratio = min($maxWidth / $originalWidth, $maxHeight / $originalHeight);
            $newWidth = (int)($originalWidth * $ratio);
            $newHeight = (int)($originalHeight * $ratio);

            // Új kép létrehozása
            $resizedImage = imagecreatetruecolor($newWidth, $newHeight);

            // Átlátszóság megőrzése (PNG, GIF esetén)
            self::preserveTransparency($resizedImage, $imageType);

            // Átméretezés
            imagecopyresampled(
                $resizedImage, $sourceImage,
                0, 0, 0, 0,
                $newWidth, $newHeight,
                $originalWidth, $originalHeight
            );

            // Mentés
            $extension = image_type_to_extension($imageType, false);
            $filename = $baseFilename . '-' . $sizeName . '.' . $extension;
            $savePath = $uploadDir . '/' . $filename;

            self::saveImage($resizedImage, $savePath, $imageType);
            imagedestroy($resizedImage);

            $result[$sizeName] = [
                'width' => $newWidth,
                'height' => $newHeight,
                'path' => $filename
            ];
        }

        imagedestroy($sourceImage);
        return $result;
    }

    /**
     * WebP verzió generálása a képből
     * @param string $sourcePath Eredeti kép
     * @param string $outputPath WebP kimenet
     * @param int $quality Minőség (0-100)
     * @return bool Sikeres volt-e
     */
    public static function convertToWebP($sourcePath, $outputPath, $quality = 85) {
        // Ellenőrizzük, hogy a WebP támogatott-e
        if (!function_exists('imagewebp')) {
            return false;
        }

        $imageInfo = getimagesize($sourcePath);
        if (!$imageInfo) {
            return false;
        }

        list($width, $height, $imageType) = $imageInfo;

        $sourceImage = self::createImageResource($sourcePath, $imageType);
        if (!$sourceImage) {
            return false;
        }

        // WebP mentés
        $success = imagewebp($sourceImage, $outputPath, $quality);
        imagedestroy($sourceImage);

        return $success;
    }

    /**
     * Több WebP verzió generálása (eredeti + átméretezett)
     * @param string $sourcePath Eredeti kép
     * @param array $sizes Már generált méretek
     * @param string $uploadDir Feltöltési könyvtár
     * @param string $baseFilename Alap fájlnév
     * @return array WebP fájlok információi
     */
    public static function generateWebPVersions($sourcePath, $sizes, $uploadDir, $baseFilename) {
        $webpVersions = [];

        // Eredeti WebP verzió
        $webpOriginal = $baseFilename . '.webp';
        if (self::convertToWebP($sourcePath, $uploadDir . '/' . $webpOriginal)) {
            $webpVersions['original'] = $webpOriginal;
        }

        // Átméretezett verziók WebP-be
        foreach ($sizes as $sizeName => $info) {
            if ($sizeName === 'original' || !isset($info['path'])) {
                continue;
            }

            $sizeImagePath = $uploadDir . '/' . $info['path'];
            if (!file_exists($sizeImagePath)) {
                continue;
            }

            $webpFilename = $baseFilename . '-' . $sizeName . '.webp';
            if (self::convertToWebP($sizeImagePath, $uploadDir . '/' . $webpFilename)) {
                $webpVersions[$sizeName] = $webpFilename;
            }
        }

        return $webpVersions;
    }

    /**
     * Kép resource létrehozása fájltípus alapján
     */
    private static function createImageResource($path, $imageType) {
        switch ($imageType) {
            case IMAGETYPE_JPEG:
                return imagecreatefromjpeg($path);
            case IMAGETYPE_PNG:
                return imagecreatefrompng($path);
            case IMAGETYPE_GIF:
                return imagecreatefromgif($path);
            case IMAGETYPE_WEBP:
                return imagecreatefromwebp($path);
            default:
                return false;
        }
    }

    /**
     * Kép mentése fájltípus alapján
     */
    private static function saveImage($image, $path, $imageType, $quality = 90) {
        switch ($imageType) {
            case IMAGETYPE_JPEG:
                return imagejpeg($image, $path, $quality);
            case IMAGETYPE_PNG:
                // PNG esetén a quality 0-9 közötti (compression level)
                $pngQuality = 9 - (int)(($quality / 100) * 9);
                return imagepng($image, $path, $pngQuality);
            case IMAGETYPE_GIF:
                return imagegif($image, $path);
            case IMAGETYPE_WEBP:
                return imagewebp($image, $path, $quality);
            default:
                return false;
        }
    }

    /**
     * Átlátszóság megőrzése PNG és GIF esetén
     */
    private static function preserveTransparency($image, $imageType) {
        if ($imageType === IMAGETYPE_PNG || $imageType === IMAGETYPE_WEBP) {
            imagealphablending($image, false);
            imagesavealpha($image, true);
            $transparent = imagecolorallocatealpha($image, 0, 0, 0, 127);
            imagefilledrectangle($image, 0, 0, imagesx($image), imagesy($image), $transparent);
        } elseif ($imageType === IMAGETYPE_GIF) {
            $transparentIndex = imagecolortransparent($image);
            if ($transparentIndex >= 0) {
                $transparentColor = imagecolorsforindex($image, $transparentIndex);
                $transparentNew = imagecolorallocate(
                    $image,
                    $transparentColor['red'],
                    $transparentColor['green'],
                    $transparentColor['blue']
                );
                imagefill($image, 0, 0, $transparentNew);
                imagecolortransparent($image, $transparentNew);
            }
        }
    }

    /**
     * Kép információk kinyerése
     * @param string $path Kép elérési útja
     * @return array|false Kép adatai vagy false
     */
    public static function getImageInfo($path) {
        $imageInfo = getimagesize($path);
        if (!$imageInfo) {
            return false;
        }

        return [
            'width' => $imageInfo[0],
            'height' => $imageInfo[1],
            'type' => $imageInfo[2],
            'mime' => $imageInfo['mime']
        ];
    }
}
