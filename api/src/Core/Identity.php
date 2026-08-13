<?php

declare(strict_types=1);

namespace App\Core;

use DateTimeImmutable;

final class Identity
{
    public const NATIONAL_ID_LENGTH = 13;
    public const MINIMUM_DONOR_AGE = 16;

    public static function nationalId(string $value): string
    {
        return preg_replace('/\D+/', '', trim($value)) ?? '';
    }

    public static function validNationalId(string $value): bool
    {
        $nationalId = self::nationalId($value);

        return strlen($nationalId) === self::NATIONAL_ID_LENGTH
            && ctype_digit($nationalId)
            && self::birthDateFromNationalId($nationalId) instanceof DateTimeImmutable;
    }

    public static function birthDateFromNationalId(string $value): ?DateTimeImmutable
    {
        $nationalId = self::nationalId($value);
        if (strlen($nationalId) !== self::NATIONAL_ID_LENGTH || !ctype_digit($nationalId)) {
            return null;
        }

        $yearPart = (int) substr($nationalId, 0, 2);
        $month = (int) substr($nationalId, 2, 2);
        $day = (int) substr($nationalId, 4, 2);
        $currentTwoDigitYear = (int) date('y');
        $fullYear = $yearPart <= $currentTwoDigitYear ? 2000 + $yearPart : 1900 + $yearPart;

        if (!checkdate($month, $day, $fullYear)) {
            return null;
        }

        $date = DateTimeImmutable::createFromFormat(
            '!Y-m-d',
            sprintf('%04d-%02d-%02d', $fullYear, $month, $day)
        );

        if (!$date || $date > new DateTimeImmutable('today')) {
            return null;
        }

        return $date;
    }

    public static function ageFromBirthDate(DateTimeImmutable $birthDate, ?DateTimeImmutable $onDate = null): int
    {
        $referenceDate = $onDate ?? new DateTimeImmutable('today');
        return $birthDate->diff($referenceDate)->y;
    }

    public static function ageFromNationalId(string $value, ?DateTimeImmutable $onDate = null): ?int
    {
        $birthDate = self::birthDateFromNationalId($value);
        return $birthDate ? self::ageFromBirthDate($birthDate, $onDate) : null;
    }

    public static function donorRegistrationDate(string $value): ?DateTimeImmutable
    {
        return self::birthDateFromNationalId($value)?->modify('+' . self::MINIMUM_DONOR_AGE . ' years');
    }

    public static function isOldEnoughToRegister(string $value, ?DateTimeImmutable $onDate = null): bool
    {
        $eligibleOn = self::donorRegistrationDate($value);
        $referenceDate = $onDate ?? new DateTimeImmutable('today');

        return $eligibleOn !== null && $eligibleOn <= $referenceDate;
    }

    public static function humanDate(DateTimeImmutable $date): string
    {
        return $date->format('j F Y');
    }

    public static function phone(string $value): string
    {
        $digits = preg_replace('/\D+/', '', $value) ?? '';
        if (str_starts_with($digits, '268') && strlen($digits) === 11) {
            return '+' . $digits;
        }
        if (strlen($digits) === 8) {
            return '+268' . $digits;
        }
        return '+' . ltrim($digits, '+');
    }

    public static function validEswatiniPhone(string $value): bool
    {
        return preg_match('/^\+268(7[689]|24)[0-9]{6}$/', self::phone($value)) === 1;
    }
}
