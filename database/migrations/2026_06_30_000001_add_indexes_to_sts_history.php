<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Aman dijalankan berulang kali — cek dulu sebelum CREATE INDEX
        // Tidak menyentuh struktur kolom atau data.
        Schema::table('STS_History', function (Blueprint $table) {
            if (! $this->indexExists('STS_History', 'idx_sts_jn_pajak')) {
                $table->index('Jn_Pajak', 'idx_sts_jn_pajak');
            }
            if (! $this->indexExists('STS_History', 'idx_sts_status_bayar')) {
                $table->index('Status_Bayar', 'idx_sts_status_bayar');
            }
            if (! $this->indexExists('STS_History', 'idx_sts_tgl_bayar')) {
                $table->index('Tgl_Bayar', 'idx_sts_tgl_bayar');
            }
            if (! $this->indexExists('STS_History', 'idx_sts_jp_sb_tb')) {
                $table->index(['Jn_Pajak', 'Status_Bayar', 'Tgl_Bayar'], 'idx_sts_jp_sb_tb');
            }
        });
    }

    private function indexExists(string $table, string $indexName): bool
    {
        return collect(DB::select("SHOW INDEX FROM `{$table}` WHERE Key_name = ?", [$indexName]))->isNotEmpty();
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
