<?php

namespace Tests\Feature\Reports\Retribusi;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class MonitoringReportTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Config::set('database.connections.eretribusi_db', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        Config::set('database.connections.sts', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        DB::purge('eretribusi_db');
        DB::purge('sts');
        Schema::connection('eretribusi_db')->create('opd_retribusi', function (Blueprint $t) {
            $t->string('id_opd');
            $t->string('nama_opd');
        });
        Schema::connection('eretribusi_db')->create('jenis_retribusi', function (Blueprint $t) {
            $t->string('id_jenis');
            $t->string('nama_retribusi');
            $t->string('opd_id')->nullable();
            $t->string('status');
        });
        Schema::connection('eretribusi_db')->create('wajib_retribusi', function (Blueprint $t) {
            $t->string('npwrd');
            $t->string('nama');
        });
        Schema::connection('eretribusi_db')->create('lap_skrd', function (Blueprint $t) {
            $t->integer('id_laporan');
            $t->string('idretribusi');
            $t->string('masa_pajak');
            $t->integer('thn_pajak');
            $t->string('no_form');
            $t->string('tgl_transaksi');
            $t->string('nowp');
            $t->string('jlh_bayar');
        });
        Schema::connection('sts')->create('STS_History', function (Blueprint $t) {
            $t->string('No_NOP');
            $t->string('Status_Bayar')->nullable();
            $t->string('Nilai')->nullable();
            $t->string('Tgl_Bayar')->nullable();
        });
        DB::connection('eretribusi_db')->table('opd_retribusi')->insert([
            ['id_opd' => '3', 'nama_opd' => 'Dinas Pasar'],
            ['id_opd' => '9', 'nama_opd' => '=Dinas Wisata'],
        ]);
        DB::connection('eretribusi_db')->table('jenis_retribusi')->insert([
            ['id_jenis' => '2', 'nama_retribusi' => 'Wisata', 'opd_id' => '9', 'status' => '1'],
            ['id_jenis' => '1', 'nama_retribusi' => 'Pasar', 'opd_id' => '3', 'status' => 1],
            ['id_jenis' => '3', 'nama_retribusi' => 'Tanpa OPD', 'opd_id' => '404', 'status' => '1'],
            ['id_jenis' => '4', 'nama_retribusi' => 'Tidak Aktif', 'opd_id' => '3', 'status' => '0'],
        ]);
        DB::connection('eretribusi_db')->table('wajib_retribusi')->insert([
            ['npwrd' => '001', 'nama' => 'Alpha'],
            ['npwrd' => '002', 'nama' => '=Formula'],
        ]);
        DB::connection('eretribusi_db')->table('lap_skrd')->insert([
            ['id_laporan' => 1, 'idretribusi' => '1', 'masa_pajak' => ' Januari ', 'thn_pajak' => 2026, 'no_form' => 'SK1 ', 'tgl_transaksi' => '2025-01-02', 'nowp' => '001', 'jlh_bayar' => '9007199254740993.25'],
            ['id_laporan' => 2, 'idretribusi' => '2', 'masa_pajak' => 'Januari', 'thn_pajak' => 2026, 'no_form' => '=SK2', 'tgl_transaksi' => '2026-01-03', 'nowp' => '002', 'jlh_bayar' => '10'],
            ['id_laporan' => 3, 'idretribusi' => '1', 'masa_pajak' => 'Januari', 'thn_pajak' => 2026, 'no_form' => 'MISSING-WP', 'tgl_transaksi' => '2026-01-04', 'nowp' => 'missing', 'jlh_bayar' => '5'],
            ['id_laporan' => 4, 'idretribusi' => '3', 'masa_pajak' => 'Januari', 'thn_pajak' => 2026, 'no_form' => 'MISSING-OPD', 'tgl_transaksi' => '2026-01-05', 'nowp' => '001', 'jlh_bayar' => '7'],
            ['id_laporan' => 5, 'idretribusi' => '4', 'masa_pajak' => 'Januari', 'thn_pajak' => 2026, 'no_form' => 'INACTIVE', 'tgl_transaksi' => '2026-01-06', 'nowp' => '001', 'jlh_bayar' => '1000'],
        ]);
        DB::connection('sts')->table('STS_History')->insert([
            ['No_NOP' => 'SK1', 'Status_Bayar' => '1', 'Nilai' => '5.25', 'Tgl_Bayar' => '2026-02-03'],
            ['No_NOP' => '=SK2', 'Status_Bayar' => '0', 'Nilai' => '10', 'Tgl_Bayar' => '2026-02-04'],
        ]);
    }

    public function test_sources_and_monitoring_contract_filters_relations_and_statuses(): void
    {
        $this->getJson('/api/v1/reports/retribusi/sources')->assertOk()
            ->assertJsonPath('data.0.id', '1')
            ->assertJsonPath('data.2.opd_id', '9')
            ->assertJsonPath('meta.total', 3)
            ->assertJsonMissing(['id' => '4']);
        $response = $this->getJson('/api/v1/reports/retribusi/monitoring?year=2026&month=01')->assertOk();
        $response->assertJsonPath('meta.total', 2)
            ->assertJsonPath('data.0.source_record_id', '3')
            ->assertJsonPath('data.0.taxpayer_number', 'missing')
            ->assertJsonPath('data.0.opd_id', '3')
            ->assertJsonPath('data.0.opd_name', 'Dinas Pasar')
            ->assertJsonPath('data.1.status_code', 'unpaid')
            ->assertJsonPath('data.1.payment_amount', '0')
            ->assertJsonPath('meta.totals.assessment_amount', '15')
            ->assertJsonMissingPath('data.0.taxpayer_name');
        $this->assertSame(['number', 'source_record_id', 'transaction_date', 'taxpayer_number', 'document_number', 'opd_id', 'opd_name', 'tax_period', 'assessment_amount', 'payment_amount', 'payment_date', 'payment_status', 'status_code', 'payment_match_status', 'retribution_type_id', 'retribution_type_name'], $response->json('meta.columns'));
        $this->assertSame(['3', '2'], array_column($response->json('data'), 'source_record_id'));
        $this->getJson('/api/v1/reports/retribusi/monitoring?year=2026&source=3')->assertJsonPath('meta.total', 0);
    }

    public function test_year_and_month_use_transaction_date_ranges_not_tax_period(): void
    {
        DB::connection('eretribusi_db')->table('lap_skrd')->insert([
            ['id_laporan' => 10, 'idretribusi' => '1', 'masa_pajak' => 'Desember', 'thn_pajak' => 2025, 'no_form' => 'START', 'tgl_transaksi' => '2026-01-01 00:00:00', 'nowp' => '001', 'jlh_bayar' => '10'],
            ['id_laporan' => 11, 'idretribusi' => '1', 'masa_pajak' => 'September', 'thn_pajak' => 2026, 'no_form' => 'BEFORE', 'tgl_transaksi' => '2025-12-31 23:59:59', 'nowp' => '001', 'jlh_bayar' => '11'],
            ['id_laporan' => 12, 'idretribusi' => '1', 'masa_pajak' => 'Januari', 'thn_pajak' => 2024, 'no_form' => 'SEPTEMBER', 'tgl_transaksi' => '2026-09-15 12:00:00', 'nowp' => '001', 'jlh_bayar' => '12'],
            ['id_laporan' => 13, 'idretribusi' => '1', 'masa_pajak' => 'Februari', 'thn_pajak' => 2027, 'no_form' => 'DECEMBER', 'tgl_transaksi' => '2026-12-31 23:59:59', 'nowp' => '001', 'jlh_bayar' => '13'],
            ['id_laporan' => 14, 'idretribusi' => '1', 'masa_pajak' => 'Desember', 'thn_pajak' => 2026, 'no_form' => 'AFTER', 'tgl_transaksi' => '2027-01-01 00:00:00', 'nowp' => '001', 'jlh_bayar' => '14'],
        ]);

        $year = $this->getJson('/api/v1/reports/retribusi/monitoring?year=2026&source=1')->assertOk();
        $this->assertSame(['13', '12', '10', '3'], array_column($year->json('data'), 'source_record_id'));
        $year->assertJsonPath('data.0.tax_period', 'Februari 2027')->assertJsonPath('data.1.tax_period', 'Januari 2024')->assertJsonPath('data.2.tax_period', 'Desember 2025');
        $this->getJson('/api/v1/reports/retribusi/monitoring?year=2026&month=09&source=1')->assertJsonPath('data.0.source_record_id', '12')->assertJsonPath('data.0.tax_period', 'Januari 2024');
        $this->getJson('/api/v1/reports/retribusi/monitoring?year=2026&month=12&source=1')->assertJsonPath('data.0.source_record_id', '13');
    }

    public function test_search_and_sort_support_opd_fields_and_reject_taxpayer_name(): void
    {
        $this->getJson('/api/v1/reports/retribusi/monitoring?year=2026&search=Dinas%20Pasar')->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.source_record_id', '3');
        $this->getJson('/api/v1/reports/retribusi/monitoring?year=2026&search=9')->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.opd_id', '9');
        $this->getJson('/api/v1/reports/retribusi/monitoring?year=2026&sort_by=opd_name&sort_direction=asc')->assertJsonPath('data.0.opd_name', '=Dinas Wisata');
        $this->getJson('/api/v1/reports/retribusi/monitoring?year=2026&sort_by=opd_id&sort_direction=desc')->assertJsonPath('data.0.opd_id', '9');
        $this->getJson('/api/v1/reports/retribusi/monitoring?year=2026&sort_by=taxpayer_name')->assertUnprocessable();
    }

    public function test_export_uses_same_filter_opd_column_and_oldest_default_order(): void
    {
        DB::connection('eretribusi_db')->table('lap_skrd')->insert([
            ['id_laporan' => 20, 'idretribusi' => '1', 'masa_pajak' => 'Januari', 'thn_pajak' => 2024, 'no_form' => 'EXPORT-IN', 'tgl_transaksi' => '2026-09-01 00:00:00', 'nowp' => 'missing', 'jlh_bayar' => '20'],
            ['id_laporan' => 21, 'idretribusi' => '1', 'masa_pajak' => 'September', 'thn_pajak' => 2026, 'no_form' => 'EXPORT-OUT', 'tgl_transaksi' => '2026-10-01 00:00:00', 'nowp' => '001', 'jlh_bayar' => '21'],
        ]);

        $response = $this->get('/api/v1/reports/retribusi/monitoring/export?year=2026&month=09&source=1')->assertOk();
        $this->assertStringContainsString('filename=laporan-pasar-2026-09.xlsx', (string) $response->headers->get('content-disposition'));
        $path = tempnam(sys_get_temp_dir(), 'retribusi-export-');
        file_put_contents($path, $response->streamedContent());
        $sheet = IOFactory::load($path)->getActiveSheet();
        unlink($path);

        $this->assertSame('PERIODE SEPTEMBER 2026', $sheet->getCell('A2')->getValue());
        $this->assertSame(['No', 'Tanggal Transaksi', 'No. NPWRD', 'Nomor SKRD', 'Nama OPD', 'Masa Retribusi', 'Nilai Ketetapan', 'Jumlah Bayar', 'Tanggal Bayar', 'Keterangan'], $sheet->rangeToArray('A5:J5')[0]);
        $this->assertSame('EXPORT-IN', $sheet->getCell('D6')->getValue());
        $this->assertSame('Dinas Pasar', $sheet->getCell('E6')->getValue());
        $this->assertSame('Januari 2024', $sheet->getCell('F6')->getValue());
        $this->assertSame(6, $sheet->getHighestDataRow());

        $inactive = $this->get('/api/v1/reports/retribusi/monitoring/export?year=2026&search=INACTIVE')->assertOk();
        $path = tempnam(sys_get_temp_dir(), 'retribusi-inactive-export-');
        file_put_contents($path, $inactive->streamedContent());
        $sheet = IOFactory::load($path)->getActiveSheet();
        unlink($path);
        $this->assertSame(5, $sheet->getHighestDataRow());
    }

    public function test_validation_unknown_source_pagination_and_modes(): void
    {
        $this->getJson('/api/v1/reports/retribusi/monitoring?year=2026&source=404')
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Jenis retribusi tidak ditemukan atau tidak aktif.');
        $this->getJson('/api/v1/reports/retribusi/monitoring?year=2026&source=4')
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Jenis retribusi tidak ditemukan atau tidak aktif.');
        $this->getJson('/api/v1/reports/retribusi/monitoring?year=2026&opd_id=3')->assertUnprocessable();
        $this->getJson('/api/v1/reports/retribusi/monitoring?year=2026&month=Januari')->assertUnprocessable();
        $this->getJson('/api/v1/reports/retribusi/monitoring?year=2026&search=Wisata&per_page=1&page=1')->assertJsonPath('data.0.number', 1)->assertJsonPath('meta.total', 1);
        $this->getJson('/api/v1/reports/retribusi/monitoring?year=2026&mode=parity')->assertJsonPath('meta.compatibility_mode', 'compatibility');
        $this->getJson('/api/v1/reports/retribusi/monitoring?year=2026&mode=corrected')->assertJsonPath('data.1.payment_match_status', 'found');
    }
}
