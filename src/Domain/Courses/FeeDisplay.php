<?php

declare(strict_types=1);

namespace Academy\Domain\Courses;

use InvalidArgumentException;

/**
 * DECIMAL-safe fee/GST display helpers.
 * Arithmetic is done in integer paise (1/100 of currency unit) to avoid float
 * and to keep PHPStan-friendly types. Display-only — persisted money remains
 * DECIMAL columns.
 */
final class FeeDisplay
{
    public static function effectiveBaseFee(Batch $batch, CourseVersion $version): string
    {
        return self::formatPaise(self::toPaise($batch->feeOverride ?? $version->standardFee));
    }

    public static function gstAmount(string $baseFee, string $gstRatePercent): string
    {
        $basePaise = self::toPaise($baseFee);
        $rateBasisPoints = self::toBasisPoints($gstRatePercent);
        // gst_paise = round_half_up(base_paise * rate_bp / 10000)
        $numerator = $basePaise * $rateBasisPoints;
        $gstPaise = intdiv($numerator + 5000 * ($numerator >= 0 ? 1 : -1), 10000);

        return self::formatPaise($gstPaise);
    }

    public static function inclusiveAmount(string $baseFee, string $gstRatePercent): string
    {
        $basePaise = self::toPaise($baseFee);
        $gstPaise = self::toPaise(self::gstAmount($baseFee, $gstRatePercent));

        return self::formatPaise($basePaise + $gstPaise);
    }

    /**
     * Locale-agnostic grouped display, e.g. "INR 1,180.00".
     */
    public static function formatted(string $amount, string $currency): string
    {
        $paise = self::toPaise($amount);
        $negative = $paise < 0;
        $abs = abs($paise);
        $whole = intdiv($abs, 100);
        $fraction = str_pad((string) ($abs % 100), 2, '0', STR_PAD_LEFT);

        $wholeStr = (string) $whole;
        $grouped = '';
        $len = strlen($wholeStr);
        for ($i = 0; $i < $len; $i++) {
            if ($i > 0 && ($len - $i) % 3 === 0) {
                $grouped .= ',';
            }
            $grouped .= $wholeStr[$i];
        }

        return $currency . ' ' . ($negative ? '-' : '') . $grouped . '.' . $fraction;
    }

    private static function toPaise(string $decimal): int
    {
        $trimmed = trim($decimal);
        if (preg_match('/^(-?)(\d+)(?:\.(\d{0,2}))?$/', $trimmed, $matches) !== 1) {
            throw new InvalidArgumentException('Fee values must be plain decimal strings with at most 2 fractional digits.');
        }

        $sign = $matches[1] === '-' ? -1 : 1;
        $whole = (int) $matches[2];
        $fraction = str_pad($matches[3] ?? '', 2, '0', STR_PAD_RIGHT);

        return $sign * ($whole * 100 + (int) $fraction);
    }

    /**
     * Convert a percent like "18.00" into basis points (1800 = 18.00%).
     */
    private static function toBasisPoints(string $percent): int
    {
        $trimmed = trim($percent);
        if (preg_match('/^(-?)(\d+)(?:\.(\d{0,2}))?$/', $trimmed, $matches) !== 1) {
            throw new InvalidArgumentException('GST rate must be a plain decimal percent string.');
        }

        $sign = $matches[1] === '-' ? -1 : 1;
        $whole = (int) $matches[2];
        $fraction = str_pad($matches[3] ?? '', 2, '0', STR_PAD_RIGHT);

        return $sign * ($whole * 100 + (int) $fraction);
    }

    private static function formatPaise(int $paise): string
    {
        $negative = $paise < 0;
        $abs = abs($paise);
        $whole = intdiv($abs, 100);
        $fraction = str_pad((string) ($abs % 100), 2, '0', STR_PAD_LEFT);

        return ($negative ? '-' : '') . $whole . '.' . $fraction;
    }
}
