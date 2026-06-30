<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Aman dijalankan berulang kali — hanya CREATE INDEX IF NOT EXISTS
        // Tidak menyentuh data, tidak mengubah kolom.
        DB::connection('epbb_db')->statement(
            'CREATE INDEX IF NOT EXISTS idx_pbbsppt_tgl_bayar ON pembayaran_sppt(tgl_pembayaran_sppt)'
        );

        DB::connection('epbb_db')->statement(
            'CREATE INDEX IF NOT EXISTS idx_pbbsppt_nop ON pembayaran_sppt(kd_propinsi, kd_dati2, kd_kecamatan, kd_kelurahan, kd_blok, no_urut, kd_jns_op)'
        );
    }

    public function down(): void
    {
        DB::connection('epbb_db')->statement('DROP INDEX idx_pbbsppt_tgl_bayar ON pembayaran_sppt');
        DB::connection('epbb_db')->statement('DROP INDEX idx_pbbsppt_nop ON pembayaran_sppt');
    }
};
