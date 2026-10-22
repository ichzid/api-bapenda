<?php

return [
    'pajak_daerah' => [
        'auth' => env('REPORTS_PAJAK_DAERAH_AUTH', false),
        'max_rows' => (int) env('REPORTS_PAJAK_DAERAH_MAX_ROWS', 50000),
        'batch_size' => (int) env('REPORTS_PAJAK_DAERAH_BATCH_SIZE', 500),
        // Computed search/sort is materialized only after this source/STS count hard cap passes.
        'sts_order_columns' => ['No_STS', 'Tgl_STS', 'Tgl_Bayar', 'Kode_Pengesahan'],
    ],
];
