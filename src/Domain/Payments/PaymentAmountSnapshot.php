<?php

declare(strict_types=1);

namespace Academy\Domain\Payments;

use Academy\Domain\Courses\Batch;
use Academy\Domain\Courses\CourseVersion;
use Academy\Domain\Courses\FeeDisplay;
use Academy\Domain\Exception\DomainRuleException;
use InvalidArgumentException;

/**
 * Immutable payable snapshot in integer minor units (paise for INR).
 * Derived once at attempt creation from locked Application commercial data.
 */
final class PaymentAmountSnapshot
{
    public function __construct(
        public readonly int $baseFeeMinor,
        public readonly int $gstMinor,
        public readonly int $totalPayableMinor,
        public readonly string $currency,
        public readonly string $gstRatePercent,
        public readonly int $courseVersionId,
        public readonly int $batchId,
        public readonly ?string $feeOverrideApplied,
    ) {
        if ($this->baseFeeMinor < 0 || $this->gstMinor < 0 || $this->totalPayableMinor < 0) {
            throw new DomainRuleException('Payment amounts cannot be negative.');
        }
        if ($this->totalPayableMinor !== $this->baseFeeMinor + $this->gstMinor) {
            throw new DomainRuleException('Payment total must equal base fee plus GST.');
        }
        if ($this->totalPayableMinor < 1) {
            throw new DomainRuleException('Payable total must be at least 1 minor unit.');
        }
        if (!preg_match('/^[A-Z]{3}$/', $this->currency)) {
            throw new DomainRuleException('Currency must be a 3-letter ISO code.');
        }
    }

    public static function fromBatchAndVersion(Batch $batch, CourseVersion $version): self
    {
        if ($batch->courseVersionId !== $version->versionId) {
            throw new DomainRuleException('Batch does not belong to the Application course version.');
        }

        $baseDecimal = $batch->effectiveFee($version);
        $gstRate = $version->gstRate;
        $currency = strtoupper($version->currency);

        try {
            $baseMinor = self::decimalToMinor($baseDecimal);
            $gstDecimal = FeeDisplay::gstAmount($baseDecimal, $gstRate);
            $gstMinor = self::decimalToMinor($gstDecimal);
            $totalMinor = $baseMinor + $gstMinor;
        } catch (InvalidArgumentException $e) {
            throw new DomainRuleException('Commercial fee data is invalid: ' . $e->getMessage());
        }

        return new self(
            baseFeeMinor: $baseMinor,
            gstMinor: $gstMinor,
            totalPayableMinor: $totalMinor,
            currency: $currency,
            gstRatePercent: $gstRate,
            courseVersionId: $version->versionId,
            batchId: $batch->batchId,
            feeOverrideApplied: $batch->feeOverride,
        );
    }

    public static function decimalToMinor(string $decimal): int
    {
        $trimmed = trim($decimal);
        if (preg_match('/^(-?)(\d+)(?:\.(\d{0,2}))?$/', $trimmed, $matches) !== 1) {
            throw new InvalidArgumentException('Amount must be a plain decimal string with at most 2 fractional digits.');
        }

        $sign = $matches[1] === '-' ? -1 : 1;
        $whole = (int) $matches[2];
        $fraction = str_pad($matches[3] ?? '', 2, '0', STR_PAD_RIGHT);

        return $sign * ($whole * 100 + (int) $fraction);
    }

    public static function minorToDecimal(int $minor): string
    {
        $negative = $minor < 0;
        $abs = abs($minor);
        $whole = intdiv($abs, 100);
        $fraction = str_pad((string) ($abs % 100), 2, '0', STR_PAD_LEFT);

        return ($negative ? '-' : '') . $whole . '.' . $fraction;
    }
}
