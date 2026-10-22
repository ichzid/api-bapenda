<?php

namespace App\Reports\PajakDaerah\ValueObjects;

use App\Reports\PajakDaerah\Enums\CompatibilityMode;
use DateTimeImmutable;

final readonly class ReportCriteria
{
    public function __construct(
        public int $year,
        public ?int $month,
        public ?string $search,
        public ?string $sortBy,
        public string $sortDirection,
        public DateTimeImmutable $asOfDate,
        public int $page,
        public int $perPage,
        public CompatibilityMode $mode,
    ) {}

    public static function fromArray(array $input): self
    {
        return new self((int) $input['year'], isset($input['month']) ? (int) $input['month'] : null,
            isset($input['search']) ? trim($input['search']) : null, $input['sort_by'] ?? null,
            $input['sort_direction'] ?? 'asc', new DateTimeImmutable($input['as_of_date'] ?? 'now'),
            (int) ($input['page'] ?? 1), (int) ($input['per_page'] ?? 25), CompatibilityMode::fromInput($input['mode'] ?? null));
    }

    public function range(string $field): array
    {
        $start = new DateTimeImmutable(sprintf('%04d-%02d-01', $this->year, $this->month ?? 1));

        return [$field, $start->format('Y-m-d'), $start->modify($this->month ? '+1 month' : '+1 year')->format('Y-m-d')];
    }
}
