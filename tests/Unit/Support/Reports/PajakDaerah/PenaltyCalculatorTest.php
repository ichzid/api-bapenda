<?php

namespace Tests\Unit\Support\Reports\PajakDaerah;

use App\Support\Reports\PajakDaerah\PenaltyCalculator;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PenaltyCalculatorTest extends TestCase
{
    #[DataProvider('unpaidCases')]
    public function test_unpaid_penalty_uses_legacy_month_diff(string $due, string $asOf, int $months): void
    {
        $result = (new PenaltyCalculator)->calculate('100000', (object) ['Status_Bayar' => 0, 'jatuh_tempo' => $due], new DateTimeImmutable($asOf));
        $this->assertSame($months, $result['penalty_months']);
        $this->assertSame((string) ($months * 1000), $result['penalty_amount']);
        $this->assertSame('Belum Bayar', $result['payment_status']);
    }

    public static function unpaidCases(): array
    {
        return [['2026-01-31', '2026-02-01', 1], ['2026-01-31', '2026-02-28', 1], ['2026-01-31', '2026-03-01', 1], ['2024-02-29', '2025-03-01', 12]];
    }

    public function test_paid_uses_sts_penalty_and_zero_months(): void
    {
        $result = (new PenaltyCalculator)->calculate('100000.99', (object) ['Status_Bayar' => '1', 'jatuh_tempo' => '2026-01-01', 'Tgl_Bayar' => '2026-03-02', 'Denda' => '123.99'], new DateTimeImmutable('2026-09-01'));
        $this->assertSame(0, $result['penalty_months']);
        $this->assertSame('123', $result['penalty_amount']);
        $this->assertSame('100123', $result['total_amount']);
        $this->assertSame('Lunas', $result['payment_status']);
        $this->assertSame('2026-03-02', $result['paid_at']);
    }

    public function test_paid_without_due_date_uses_sts_penalty_and_zero_months(): void
    {
        $result = (new PenaltyCalculator)->calculate('100000', (object) ['Status_Bayar' => 1, 'jatuh_tempo' => null, 'Denda' => '250.99'], new DateTimeImmutable('2026-09-01'));
        $this->assertSame(0, $result['penalty_months']);
        $this->assertSame('250', $result['penalty_amount']);
        $this->assertSame('100250', $result['total_amount']);
        $this->assertSame('Lunas', $result['payment_status']);
    }

    public function test_missing_due_date_has_no_penalty(): void
    {
        $result = (new PenaltyCalculator)->calculate('1250', null, new DateTimeImmutable('2026-09-01'));
        $this->assertNull($result['due_date']);
        $this->assertSame('-', $result['due_date_display']);
        $this->assertSame('0', $result['penalty_amount']);
    }

    public function test_half_rounds_like_php_round_half_up_for_positive_money(): void
    {
        $result = (new PenaltyCalculator)->calculate('50', (object) ['Status_Bayar' => 0, 'jatuh_tempo' => '2026-08-31'], new DateTimeImmutable('2026-09-01'));
        $this->assertSame('1', $result['penalty_amount']);
    }

    public function test_principal_keeps_more_than_two_decimals_before_penalty_formula(): void
    {
        $result = (new PenaltyCalculator)->calculate('100.999', (object) ['Status_Bayar' => 0, 'jatuh_tempo' => '2026-08-31'], new DateTimeImmutable('2026-09-01'));

        $this->assertSame('100.999', $result['tax_amount']);
        $this->assertSame('1', $result['penalty_amount']);
        $this->assertSame('101', $result['total_amount']);
    }

    public function test_penalty_rounds_half_up_using_full_precision_principal(): void
    {
        $result = (new PenaltyCalculator)->calculate('149.999', (object) ['Status_Bayar' => 0, 'jatuh_tempo' => '2026-06-30'], new DateTimeImmutable('2026-09-01'));

        $this->assertSame(3, $result['penalty_months']);
        $this->assertSame('4', $result['penalty_amount']);
    }
}
