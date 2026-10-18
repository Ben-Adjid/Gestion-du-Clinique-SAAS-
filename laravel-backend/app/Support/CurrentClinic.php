<?php

namespace App\Support;

/**
 * Clinic of the authenticated request. Null outside HTTP requests (console, seeders)
 * and for a super administrator who has not selected a clinic.
 */
final class CurrentClinic
{
    private static ?int $id = null;

    public static function set(?int $clinicId): void
    {
        self::$id = $clinicId;
    }

    public static function id(): ?int
    {
        return self::$id;
    }

    public static function clear(): void
    {
        self::$id = null;
    }
}
