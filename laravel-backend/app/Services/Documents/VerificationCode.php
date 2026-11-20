<?php

namespace App\Services\Documents;

/**
 * Short verification code printed on every official document (receipt, prescription, operative report,
 * consent, hospital file). It is derived from the document's own identity and figures, so the code shown
 * on screen and the one printed on the PDF are the same by construction, and a document can be checked.
 */
final class VerificationCode
{
    public static function for(string $kind, int|string $id, string $number, string $figures = ''): string
    {
        $digest = strtoupper(substr(hash_hmac('sha256', implode('|', [$kind, $id, $number, $figures]), (string) config('app.key')), 0, 8));

        return substr($digest, 0, 4).'-'.substr($digest, 4, 4);
    }
}
