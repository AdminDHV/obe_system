<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\CohortStrategy;
use PHPUnit\Framework\TestCase;

class CohortStrategyTest extends TestCase
{
    public function test_from_start_year_boundaries(): void
    {
        $this->assertSame(CohortStrategy::Legacy, CohortStrategy::fromStartYear(2020));
        $this->assertSame(CohortStrategy::Legacy, CohortStrategy::fromStartYear(2024));
        $this->assertSame(CohortStrategy::Transition2025, CohortStrategy::fromStartYear(2025));
        $this->assertSame(CohortStrategy::Standard, CohortStrategy::fromStartYear(2026));
        $this->assertSame(CohortStrategy::Standard, CohortStrategy::fromStartYear(2030));
    }

    public function test_flags(): void
    {
        $this->assertTrue(CohortStrategy::Legacy->isLegacy());
        $this->assertFalse(CohortStrategy::Transition2025->isLegacy());
        $this->assertFalse(CohortStrategy::Standard->isLegacy());
    }

    public function test_code_and_label(): void
    {
        $this->assertSame(1, CohortStrategy::Legacy->code());
        $this->assertSame(2, CohortStrategy::Transition2025->code());
        $this->assertSame(3, CohortStrategy::Standard->code());

        $this->assertNotEmpty(CohortStrategy::Legacy->label());
        $this->assertNotEmpty(CohortStrategy::Standard->label());
    }
}
