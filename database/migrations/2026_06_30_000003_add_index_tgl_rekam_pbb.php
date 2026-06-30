<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $connection = DB::connection('epbb_db');

        $indexExists = function (string $indexName) use ($connection): bool {
            $rows = $connection->select("SHOW INDEX FROM pembayaran_sppt WHERE Key_name = ?", [$indexName]);
            return ! empty($rows);
        };

        // Index untuk tgl_rekam_byr_sppt — kolom yang dipakai semua query PBB
        if (! $indexExists('idx_pbbsppt_tgl_rekam')) {
            $connection->statement(
                'CREATE INDEX idx_pbbsppt_tgl_rekam ON pembayaran_sppt(tgl_rekam_byr_sppt)'
            );
        }
    }

    public function down(): void
    {
        DB::connection('epbb_db')->statement('DROP INDEX idx_pbbsppt_tgl_rekam ON pembayaran_sppt');
    }
};
