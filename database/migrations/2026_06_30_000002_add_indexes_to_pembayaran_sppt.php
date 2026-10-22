<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Aman dijalankan berulang kali — cek dulu sebelum CREATE INDEX
        // Tidak menyentuh data, tidak mengubah kolom.

        $connection = DB::connection('epbb_db');

        // Helper: cek apakah index sudah ada
        $indexExists = function (string $indexName) use ($connection): bool {
            $rows = $connection->select('SHOW INDEX FROM pembayaran_sppt WHERE Key_name = ?', [$indexName]);

            return ! empty($rows);
        };

        if (! $indexExists('idx_pbbsppt_tgl_bayar')) {
            $connection->statement(
                'CREATE INDEX idx_pbbsppt_tgl_bayar ON pembayaran_sppt(tgl_pembayaran_sppt)'
            );
        }

        if (! $indexExists('idx_pbbsppt_nop')) {
            $connection->statement(
                'CREATE INDEX idx_pbbsppt_nop ON pembayaran_sppt(kd_propinsi, kd_dati2, kd_kecamatan, kd_kelurahan, kd_blok, no_urut, kd_jns_op)'
            );
        }
    }

    public function down(): void
    {
        DB::connection('epbb_db')->statement('DROP INDEX idx_pbbsppt_tgl_bayar ON pembayaran_sppt');
        DB::connection('epbb_db')->statement('DROP INDEX idx_pbbsppt_nop ON pembayaran_sppt');
    }
};
