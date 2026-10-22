<?php

namespace Tests\Feature\Reports\Esptpd;

use App\Reports\Esptpd\MonitoringMakanMinumReport;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class MonitoringMakanMinumTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Config::set('database.connections.sts', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        Config::set('database.connections.simpada', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        DB::purge('sts');
        DB::purge('simpada');
        Schema::connection('sts')->create('STS_History', function (Blueprint $table) {
            $table->string('No_NOP');
            $table->string('No_STS')->nullable();
            $table->string('Jn_Pajak')->nullable();
            $table->integer('Status_Bayar')->nullable();
            $table->dateTime('Tgl_Bayar')->nullable();
        });
        Schema::connection('simpada')->create('sptpd_resto', function (Blueprint $table) {
            $table->integer('transresto_id');
            $table->dateTime('tgl_trans');
            foreach (['no_npwpd', 'no_sptpd', 'nama_instansi', 'alamat_instansi', 'nama_usaha', 'alamat_usaha', 'masa_pajak', 'pajak_terhutangx', 'id_user'] as $column) {
                $table->string($column)->nullable();
            }
            $table->integer('thn_pajak');
        });
        DB::connection('simpada')->table('sptpd_resto')->insert([
            $this->row(['transresto_id' => 2, 'no_sptpd' => '002   ', 'no_npwpd' => '0002', 'nama_instansi' => 'OPD Dua', 'pajak_terhutangx' => '9.50', 'id_user' => 'OPD-B']),
            $this->row(['transresto_id' => 1, 'no_sptpd' => '001 ', 'no_npwpd' => '0001', 'nama_instansi' => '=OPD Satu', 'pajak_terhutangx' => '100.25', 'id_user' => 'OPD-A']),
            $this->row(['transresto_id' => 3, 'tgl_trans' => '2026-02-01 00:00:00', 'no_sptpd' => '003', 'nama_instansi' => 'OPD Tiga']),
            $this->row(['transresto_id' => 4, 'tgl_trans' => '2027-01-01 00:00:00', 'no_sptpd' => '004']),
        ]);
        DB::connection('sts')->table('STS_History')->insert([
            ['No_NOP' => '001', 'No_STS' => '000-FIRST', 'Jn_Pajak' => 'Lain', 'Status_Bayar' => 1, 'Tgl_Bayar' => '2026-01-20 10:11:12'],
            ['No_NOP' => '001', 'No_STS' => '999-SECOND', 'Jn_Pajak' => 'Pajak Restoran', 'Status_Bayar' => 0, 'Tgl_Bayar' => null],
        ]);
    }

    public function test_routes_validation_source_period_all_opd_transform_and_compatibility(): void
    {
        $this->getJson('/api/v1/reports/esptpd/monitoring-makan-minum')->assertUnprocessable();
        foreach (['month=1', 'year=1999', 'id_opd=1', 'id_user=1', 'akses=user'] as $invalid) {
            $query = str_contains($invalid, 'year=') ? $invalid : 'year=2026&'.$invalid;
            $this->getJson('/api/v1/reports/esptpd/monitoring-makan-minum?'.$query)->assertUnprocessable();
        }

        $response = $this->getJson('/api/v1/reports/esptpd/monitoring-makan-minum?year=2026&month=01&per_page=1&page=2&mode=parity')->assertOk();
        $response->assertJsonPath('meta.source', 'simpada')
            ->assertJsonPath('meta.report_type', 'monitoring-makan-minum')
            ->assertJsonPath('meta.compatibility_mode', 'compatibility')
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('data.0.no', 2)
            ->assertJsonPath('data.0.no_npwpd', '0001')
            ->assertJsonPath('data.0.no_sptpd', '001')
            ->assertJsonPath('data.0.status_bayar', 'Sudah Bayar');
        $first = $this->getJson('/api/v1/reports/esptpd/monitoring-makan-minum?year=2026&month=01')->assertOk();
        $first->assertJsonPath('data.1.tanggal_input', '15-01-2026')
            ->assertJsonPath('data.1.masa_pajak', 'Januari 2026')
            ->assertJsonPath('data.1.nilai_pajak', '100.25')
            ->assertJsonPath('data.1.status_bayar', 'Sudah Bayar')
            ->assertJsonPath('data.1.tanggal_bayar', '20-01-2026 10:11:12')
            ->assertJsonPath('data.1.nomor_sts', '000-FIRST');
        $this->assertSame(['0002', '0001'], array_column($first->json('data'), 'no_npwpd'));
        $this->assertSame(['002', '001'], array_column($first->json('data'), 'no_sptpd'));
        $this->assertSame(['OPD Dua', 'OPD Satu'], array_map(fn ($row) => ltrim($row['nama_instansi'], '='), $first->json('data')));
    }

    public function test_search_numeric_sort_tie_break_limit_and_batches(): void
    {
        $this->getJson('/api/v1/reports/esptpd/monitoring-makan-minum?year=2026&search=OPD%20Dua')->assertJsonPath('meta.total', 1);
        $this->getJson('/api/v1/reports/esptpd/monitoring-makan-minum?year=2026&month=01&sort_by=nilai_pajak&sort_direction=asc')->assertJsonPath('data.0.no_npwpd', '0002');
        Config::set('reports.pajak_daerah.max_rows', 1);
        $this->getJson('/api/v1/reports/esptpd/monitoring-makan-minum?year=2026')->assertUnprocessable();
        Config::set('reports.pajak_daerah.max_rows', 50000);
        Config::set('reports.pajak_daerah.batch_size', 1);
        $this->getJson('/api/v1/reports/esptpd/monitoring-makan-minum?year=2026')->assertOk()->assertJsonPath('meta.total', 3);
    }

    public function test_export_metadata_filename_all_rows_numeric_identifiers_and_cors(): void
    {
        Carbon::setTestNow('2026-09-14 10:30:00 Asia/Jakarta');
        $response = $this->withHeader('Origin', 'http://localhost:5173')->get('/api/v1/reports/esptpd/monitoring-makan-minum/export?year=2026&month=01&page=99&per_page=1')->assertOk();
        $response->assertHeader('Access-Control-Expose-Headers', 'Content-Disposition');
        $this->assertStringContainsString('filename=monitoring-makan-minum-opd-2026-01.xlsx', (string) $response->headers->get('Content-Disposition'));
        $path = tempnam(sys_get_temp_dir(), 'esptpd-test-');
        file_put_contents($path, $response->streamedContent());
        $sheet = IOFactory::load($path)->getActiveSheet();
        unlink($path);
        $this->assertSame('LAPORAN MONITORING PAJAK MAKANAN DAN MINUMAN OPD', $sheet->getCell('A1')->getValue());
        $this->assertSame('PERIODE JANUARI 2026', $sheet->getCell('A2')->getValue());
        $this->assertSame('Dicetak pada: 14 September 2026 10:30 WIB', $sheet->getCell('A3')->getValue());
        $this->assertSame(['No', 'Tanggal Input', 'NPWPD', 'No. SPTPD', 'Nama Instansi', 'Alamat Instansi', 'Nama Usaha', 'Alamat Usaha', 'Masa Pajak', 'Nilai Pajak', 'Status Bayar', 'Tanggal Bayar', 'Nomor STS'], $sheet->rangeToArray('A5:M5')[0]);
        $this->assertSame(7, $sheet->getHighestDataRow());
        $this->assertSame(['0001', '0002'], [$sheet->getCell('C6')->getValue(), $sheet->getCell('C7')->getValue()]);
        $this->assertSame(DataType::TYPE_STRING, $sheet->getCell('C6')->getDataType());
        $this->assertSame(['001', '002'], [$sheet->getCell('D6')->getValue(), $sheet->getCell('D7')->getValue()]);
        $this->assertSame(DataType::TYPE_STRING, $sheet->getCell('D6')->getDataType());
        $this->assertSame('=OPD Satu', $sheet->getCell('E6')->getValue());
        $this->assertSame(DataType::TYPE_STRING, $sheet->getCell('E6')->getDataType());
        $this->assertSame(DataType::TYPE_NUMERIC, $sheet->getCell('J6')->getDataType());
        $this->assertSame('0', $sheet->getStyle('J6')->getNumberFormat()->getFormatCode());

        $sorted = app(MonitoringMakanMinumReport::class)->build([
            'year' => 2026,
            'month' => '01',
            'sort_by' => 'nilai_pajak',
            'sort_direction' => 'desc',
        ], false);
        $this->assertSame(['0001', '0002'], array_column($sorted['data'], 'no_npwpd'));
        Carbon::setTestNow();
    }

    private function row(array $overrides = []): array
    {
        return array_merge([
            'transresto_id' => 1, 'tgl_trans' => '2026-01-15 08:30:00', 'no_npwpd' => '0001',
            'no_sptpd' => '001', 'nama_instansi' => 'OPD', 'alamat_instansi' => 'Alamat OPD',
            'nama_usaha' => 'Kantin', 'alamat_usaha' => 'Alamat Kantin', 'masa_pajak' => 'Januari',
            'thn_pajak' => 2026, 'pajak_terhutangx' => '100.25', 'id_user' => 'OPD',
        ], $overrides);
    }
}
