<?php

namespace App\Support\Reports\PajakDaerah;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use DateTimeImmutable;

class PenaltyCalculator
{
    public function calculate(string|int|float|null $taxAmount, object|array|null $sts, DateTimeImmutable $asOfDate): array
    {
        $sts = (object) ($sts ?? []);
        $paid = (int) ($sts->Status_Bayar ?? 0) === 1;
        $dueDate = $this->date($sts->jatuh_tempo ?? null);
        $paidAt = $paid ? $this->date($sts->Tgl_Bayar ?? null) : null;
        $principal = $this->decimal($taxAmount);

        if ($paid) {
            $penalty = $this->decimal($sts->Denda ?? 0)->toScale(0, RoundingMode::FLOOR);
            $months = 0;
        } elseif ($dueDate === null || $asOfDate <= $dueDate) {
            $penalty = BigDecimal::zero();
            $months = 0;
        } else {
            $interval = $dueDate->diff($asOfDate);
            $months = $interval->y * 12 + $interval->m + ($interval->d > 0 ? 1 : 0);
            $penalty = $principal->multipliedBy($months)->dividedBy(100, 0, RoundingMode::HalfUp);
        }

        $total = $principal->plus($penalty)->toScale(0, RoundingMode::Floor);

        return [
            'due_date' => $dueDate?->format('Y-m-d'),
            'due_date_display' => $dueDate?->format('Y-m-d') ?? '-',
            'penalty_months' => $months,
            'tax_amount' => (string) $principal,
            'penalty_amount' => (string) $penalty,
            'total_amount' => (string) $total,
            'payment_status_code' => $paid ? 'paid' : 'unpaid',
            'payment_status' => $paid ? 'Lunas' : 'Belum Bayar',
            'paid_at' => $paidAt?->format('Y-m-d'),
            'paid_at_display' => $paidAt?->format('Y-m-d') ?? '-',
        ];
    }

    private function date(mixed $value): ?DateTimeImmutable
    {
        $value = trim((string) $value);
        if ($value === '' || str_starts_with($value, '0000-00-00')) {
            return null;
        }

        return new DateTimeImmutable(substr($value, 0, 10));
    }

    private function decimal(mixed $value): BigDecimal
    {
        $value = trim((string) ($value ?? '0'));

        return preg_match('/^-?\d+(?:\.\d+)?$/', $value) ? BigDecimal::of($value) : BigDecimal::zero();
    }
}
