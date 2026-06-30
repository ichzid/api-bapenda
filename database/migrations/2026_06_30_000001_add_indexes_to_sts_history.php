<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Index pada STS_History (koneksi default mysql)
        Schema::table('STS_History', function (Blueprint $table) {
            $table->index('Jn_Pajak', 'idx_sts_jn_pajak');
            $table->index('Status_Bayar', 'idx_sts_status_bayar');
            $table->index('Tgl_Bayar', 'idx_sts_tgl_bayar');
            // Composite index untuk query utama: Jn_Pajak + Status_Bayar + Tgl_Bayar
            $table->index(['Jn_Pajak', 'Status_Bayar', 'Tgl_Bayar'], 'idx_sts_jp_sb_tb');
        });
    }

    public function down(): void
    {
        Schema::table('STS_History', function (Blueprint $table) {
            $table->dropIndex('idx_sts_jn_pajak');
            $table->dropIndex('idx_sts_status_bayar');
            $table->dropIndex('idx_sts_tgl_bayar');
            $table->dropIndex('idx_sts_jp_sb_tb');
        });
    }
};
