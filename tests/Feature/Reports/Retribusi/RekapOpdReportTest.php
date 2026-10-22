<?php

namespace Tests\Feature\Reports\Retribusi;

use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class RekapOpdReportTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Config::set('database.connections.eretribusi_db', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        DB::purge('eretribusi_db');
        Schema::connection('eretribusi_db')->create('opd_retribusi', function (Blueprint $table) {
            $table->string('id_opd');
            $table->string('nama_opd');
        });
        Schema::connection('eretribusi_db')->create('jenis_retribusi', function (Blueprint $table) {
            $table->string('id_jenis');
            $table->string('nama_retribusi');
            $table->string('opd_id');
            $table->string('status');
        });
        Schema::connection('eretribusi_db')->create('lap_skrd', function (Blueprint $table) {
            $table->integer('id_laporan');
            $table->string('idretribusi');
            $table->string('tgl_transaksi');
            $table->string('masa_pajak')->nullable();
            $table->integer('thn_pajak')->nullable();
            $table->string('nowp')->nullable();
            $table->string('jlh_bayar');
        });
        Schema::connection('eretribusi_db')->create('wajib_retribusi', function (Blueprint $table) {
            $table->string('npwrd');
        });
        DB::connection('eretribusi_db')->table('opd_retribusi')->insert([
            ['id_opd' => '002', 'nama_opd' => 'Alpha'],
            ['id_opd' => '=001', 'nama_opd' => '=Formula'],
            ['id_opd' => '003', 'nama_opd' => 'Alpha'],
        ]);
        DB::connection('eretribusi_db')->table('jenis_retribusi')->insert([
            ['id_jenis' => 'A', 'nama_retribusi' => 'Pasar', 'opd_id' => '002', 'status' => 1],
            ['id_jenis' => 'B', 'nama_retribusi' => 'Wisata', 'opd_id' => '002', 'status' => '1'],
            ['id_jenis' => 'C', 'nama_retribusi' => 'Parkir', 'opd_id' => '=001', 'status' => 1],
            ['id_jenis' => 'D', 'nama_retribusi' => 'Sampah', 'opd_id' => '003', 'status' => '1'],
            ['id_jenis' => 'ORPHAN', 'nama_retribusi' => 'Tanpa OPD', 'opd_id' => '404', 'status' => '1'],
            ['id_jenis' => 'INACTIVE', 'nama_retribusi' => 'Tidak Aktif', 'opd_id' => '002', 'status' => '0'],
        ]);
        DB::connection('eretribusi_db')->table('lap_skrd')->insert([
            $this->row(1, 'A', '2026-01-01 00:00:00', '9007199254740993.10', 'Desember', 2025, 'MISSING-WP'),
            $this->row(2, 'B', '2026-02-10 10:00:00', '0.20', 'Januari', 2024),
            $this->row(3, 'C', '2026-12-31 23:59:59', '5'),
            $this->row(4, 'D', '2026-01-20 00:00:00', '2'),
            $this->row(5, 'A', '2025-12-31 23:59:59', '100'),
            $this->row(6, 'A', '2027-01-01 00:00:00', '100'),
            $this->row(7, 'ORPHAN', '2026-03-01 00:00:00', '9'),
            $this->row(8, 'A', 'malformed', '8'),
            $this->row(9, 'INACTIVE', '2026-01-15 00:00:00', '1000'),
        ]);
    }

    public function test_contract_grouping_dates_missing_wp_decimal_totals_and_default_sort(): void
    {
        $response = $this->getJson('/api/v1/reports/retribusi/rekap-opd?year=2026')->assertOk();
        $response->assertJsonPath('meta.report_type', 'rekap-opd')->assertJsonPath('meta.source', 'eretribusi')
            ->assertJsonPath('meta.total', 3)->assertJsonPath('meta.row_count', 3)
            ->assertJsonPath('data.0.opd_id', '=001')->assertJsonPath('data.1.opd_id', '002')->assertJsonPath('data.2.opd_id', '003')
            ->assertJsonPath('data.1.january', '9007199254740993.1')->assertJsonPath('data.1.february', '0.2')
            ->assertJsonPath('data.1.annual_total', '9007199254740993.3')->assertJsonPath('meta.totals.annual_total', '9007199254741000.3');
        $this->assertSame(['number', 'opd_id', 'opd_name', 'january', 'february', 'march', 'april', 'may', 'june', 'july', 'august', 'september', 'october', 'november', 'december', 'annual_total'], $response->json('meta.columns'));
        $this->assertDatabaseMissing('wajib_retribusi', ['npwrd' => 'MISSING-WP'], 'eretribusi_db');
    }

    public function test_validation_search_numeric_sort_pagination_and_group_limit(): void
    {
        foreach (['', 'year=1999', 'year=9999', 'year=2026&month=01', 'year=2026&opd_id=2', 'year=2026&id_opd=2', 'year=2026&id_user=2', 'year=2026&akses=x', 'year=2026&sort_by=x'] as $query) {
            $this->getJson('/api/v1/reports/retribusi/rekap-opd'.($query === '' ? '' : '?'.$query))->assertUnprocessable();
        }
        $this->getJson('/api/v1/reports/retribusi/rekap-opd?year=2026&search=002')->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.opd_id', '002');
        $this->getJson('/api/v1/reports/retribusi/rekap-opd?year=2026&sort_by=annual_total&sort_direction=desc')->assertJsonPath('data.0.opd_id', '002');
        $this->getJson('/api/v1/reports/retribusi/rekap-opd?year=2026&page=2&per_page=1')->assertJsonPath('data.0.number', 2);
        Config::set('reports.pajak_daerah.max_rows', 2);
        $this->getJson('/api/v1/reports/retribusi/rekap-opd?year=2026')->assertUnprocessable();
    }

    public function test_export_has_all_rows_sort_metadata_numeric_and_safe_text(): void
    {
        Carbon::setTestNow('2026-09-14 10:30:00 Asia/Jakarta');
        $response = $this->withHeader('Origin', 'http://localhost:5173')->get('/api/v1/reports/retribusi/rekap-opd/export?year=2026&sort_by=annual_total&sort_direction=desc&page=99&per_page=1')->assertOk();
        $response->assertHeader('Access-Control-Expose-Headers', 'Content-Disposition');
        $this->assertStringContainsString('filename=rekap-ketetapan-retribusi-per-opd-2026.xlsx', (string) $response->headers->get('Content-Disposition'));
        $path = tempnam(sys_get_temp_dir(), 'rekap-opd-');
        file_put_contents($path, $response->streamedContent());
        $sheet = IOFactory::load($path)->getActiveSheet();
        unlink($path);
        $this->assertSame('REKAP KETETAPAN RETRIBUSI PER OPD', $sheet->getCell('A1')->getValue());
        $this->assertSame('TAHUN 2026', $sheet->getCell('A2')->getValue());
        $this->assertSame('Dicetak pada: 14 September 2026 10:30 WIB', $sheet->getCell('A3')->getValue());
        $this->assertSame(['No', 'ID OPD', 'Nama OPD', 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember', 'Total'], $sheet->rangeToArray('A5:P5')[0]);
        $this->assertSame(8, $sheet->getHighestDataRow());
        $this->assertSame('002', $sheet->getCell('B6')->getValue());
        $this->assertSame('=001', $sheet->getCell('B7')->getValue());
        $this->assertSame(DataType::TYPE_STRING, $sheet->getCell('B7')->getDataType());
        $this->assertSame('=Formula', $sheet->getCell('C7')->getValue());
        $this->assertSame(DataType::TYPE_STRING, $sheet->getCell('C7')->getDataType());
        $this->assertSame(DataType::TYPE_NUMERIC, $sheet->getCell('D6')->getDataType());
        $this->assertSame('0', $sheet->getStyle('D6')->getNumberFormat()->getFormatCode());
        $this->assertSame(2, $sheet->getCell('D8')->getValue());
        $this->assertSame('A6', $sheet->getFreezePane());
        Carbon::setTestNow();
    }

    public function test_empty_export_keeps_header(): void
    {
        $response = $this->get('/api/v1/reports/retribusi/rekap-opd/export?year=2024')->assertOk();
        $path = tempnam(sys_get_temp_dir(), 'rekap-opd-empty-');
        file_put_contents($path, $response->streamedContent());
        $sheet = IOFactory::load($path)->getActiveSheet();
        unlink($path);
        $this->assertSame(5, $sheet->getHighestDataRow());
        $this->assertSame('No', $sheet->getCell('A5')->getValue());
    }

    private function row(int $id, string $type, string $date, string $amount, string $period = 'Januari', int $taxYear = 2026, string $nowp = 'WP'): array
    {
        return ['id_laporan' => $id, 'idretribusi' => $type, 'tgl_transaksi' => $date, 'masa_pajak' => $period, 'thn_pajak' => $taxYear, 'nowp' => $nowp, 'jlh_bayar' => $amount];
    }
}
