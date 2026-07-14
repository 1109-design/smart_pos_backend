<?php

namespace App\Services;

use Endroid\QrCode\Builder\Builder;

class QrCodeGenerator
{
    public static function pngDataUri(string $data, int $size = 300): string
    {
        $result = (new Builder(
            data: $data,
            size: $size,
            margin: 10,
        ))->build();

        return $result->getDataUri();
    }
}
