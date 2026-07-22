<?php

declare(strict_types=1);

namespace Academy\Domain\Courses;

/**
 * DECIMAL-safe fee/GST display helpers. Uses bcmath when available (never
 * float arithmetic on money); falls back to number_format-based rounding
 * only when the bcmath extension is unavailable. Display-only — persisted
 * money values remain DECIMAL columns computed server-side elsewhere.
 */
final class FeeDisplay
{
    public static function effectiveBaseFee(Batch $batch, CourseVersion $version): string
    {
        return $batch->feeOverride ?? $version->standardFee;
    }

    public static function gstAmount(string $baseFee, string $gstRatePercent): string
    {
        if (self::hasBcMath()) {
            $raw = bcdiv(bcmul($baseFee, $gstRatePercent, 6), '100', 6);

            return self::roundHalfUp($raw, 2);
        }

        return number_format(((float) $baseFee * (float) $gstRatePercent) / 100, 2, '.', '');
    }

    public static function inclusiveAmount(string $baseFee, string $gstRatePercent): string
    {
        $gst = self::gstAmount($baseFee, $gstRatePercent);

        if (self::hasBcMath()) {
            return bcadd($baseFee, $gst, 2);
        }

        return number_format((float) $baseFee + (float) $gst, 2, '.', '');
    }

    /**
     * Locale-agnostic grouped display, e.g. "INR 1,180.00".
     */
    public static function formatted(string $amount, string $currency): string
    {
        $negative = str_starts_with($amount, '-');
        $abs = $negative ? substr($amount, 1) : $amount;
        $parts = explode('.', $abs, 2);
        $whole = $parts[0] === '' ? '0' : $parts[0];
        $fraction = isset($parts[1]) ? substr($parts[1] . '00', 0, 2) : '00';

        $grouped = '';
        $len = strlen($whole);
        for ($i = 0; $i < $len; $i++) {
            if ($i > 0 && ($len - $i) % 3 === 0) {
                $grouped .= ',';
            }
            $grouped .= $whole[$i];
        }

        return $currency . ' ' . ($negative ? '-' : '') . $grouped . '.' . $fraction;
    }

    private static function roundHalfUp(string $value, int $scale): string
    {
        $negative = str_starts_with($value, '-');
        $unsigned = $negative ? substr($value, 1) : $value;
        $extended = bcadd($unsigned, '0.' . str_repeat('0', $scale) . '5', $scale + 1);
        $rounded = bcdiv($extended, '1', $scale);

        return ($negative && (float) $rounded !== 0.0 ? '-' : '') . $rounded;
    }

    private static function hasBcMath(): bool
    {
        return extension_loaded('bcmath');
    }
}
