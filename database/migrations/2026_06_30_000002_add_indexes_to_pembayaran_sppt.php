<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Index pada tabel pembayaran_sppt di database epbb_db
        DB::connection('epbb_db')->statement(
            'CREATE INDEX idx_pbbsppt_tgl_bayar ON pembayaran_sppt(tgl_pembayaran_sppt)'
        );

        // Composite index untuk NOP (kolom pembentuk NOP)
        DB::connection('epbb_db')->statement(
            'CREATE INDEX idx_pbbsppt_nop ON pembayaran_sppt(kd_propinsi, kd_dati2, kd_kecamatan, kd_kelurahan, kd_blok, no_urut, kd_jns_op)'
        );
    }

    public function down(): void
    {
        DB::connection('epbb_db')->statement('DROP INDEX idx_pbbsppt_tgl_bayar ON pembayaran_sppt');
        DB::connection('epbb_db')->statement('DROP INDEX idx_pbbsppt_nop ON pembayaran_sppt');
    }
};
