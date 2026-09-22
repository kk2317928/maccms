<?php

namespace app\common\util;

use RuntimeException;

final class PublicIdGenerator
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
    private const LENGTH = 6;
    private const MAX_ATTEMPTS = 32;

    public static function generate(callable $exists): string
    {
        $lastIndex = strlen(self::ALPHABET) - 1;

        for ($attempt = 0; $attempt < self::MAX_ATTEMPTS; $attempt++) {
            $candidate = '';
            for ($position = 0; $position < self::LENGTH; $position++) {
                $candidate .= self::ALPHABET[random_int(0, $lastIndex)];
            }

            if (!$exists($candidate)) {
                return $candidate;
            }
        }

        throw new RuntimeException('Unable to allocate unique public ID.');
    }
}
