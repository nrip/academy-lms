<?php

declare(strict_types=1);

namespace Academy\Tests\Unit\Notifications;

use Academy\Domain\Exception\DomainRuleException;
use Academy\Domain\Notifications\DeliveryStatus;
use PHPUnit\Framework\TestCase;

final class DeliveryStatusTest extends TestCase
{
    public function testPendingToDeliveredOk(): void
    {
        DeliveryStatus::assertTransition(DeliveryStatus::PENDING, DeliveryStatus::DELIVERED);
        $this->addToAssertionCount(1);
    }

    public function testPendingToTerminalOk(): void
    {
        DeliveryStatus::assertTransition(DeliveryStatus::PENDING, DeliveryStatus::TERMINAL);
        $this->addToAssertionCount(1);
    }

    public function testDeliveredToAnythingThrows(): void
    {
        $this->expectException(DomainRuleException::class);
        DeliveryStatus::assertTransition(DeliveryStatus::DELIVERED, DeliveryStatus::TERMINAL);
    }

    public function testTerminalToAnythingThrows(): void
    {
        $this->expectException(DomainRuleException::class);
        DeliveryStatus::assertTransition(DeliveryStatus::TERMINAL, DeliveryStatus::DELIVERED);
    }

    public function testPendingToPendingThrows(): void
    {
        $this->expectException(DomainRuleException::class);
        DeliveryStatus::assertTransition(DeliveryStatus::PENDING, DeliveryStatus::PENDING);
    }
}
