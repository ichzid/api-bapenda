<?php

namespace App\Reports\PajakDaerah\Enums;

enum ReportType: string
{
    case Monitoring = 'monitoring';
    case Ketetapan = 'ketetapan';
    case RekapKetetapanPerWp = 'rekap-ketetapan-per-wp';
    case Piutang = 'piutang';
    case Realisasi = 'realisasi';
}
