<?php

namespace App\Reports\PajakDaerah\Contracts;

use App\Reports\PajakDaerah\ValueObjects\ReportCriteria;

interface ReportStrategy
{
    public function build(array $source, ReportCriteria $criteria): array;
}
