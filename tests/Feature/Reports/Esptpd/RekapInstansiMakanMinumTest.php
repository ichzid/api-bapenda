<?php

namespace Tests\Feature\Reports\Esptpd;

use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class RekapInstansiMakanMinumTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Config::set('database.connections.simpada', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        DB::purge('simpada');
        Schema::connection('simpada')->create('sptpd_resto', function (Blueprint $table) {
            $table->integer('transresto_id');
            $table->dateTime('tgl_trans');
            $table->string('no_npwpd')->nullable();
            $table->string('nama_instansi')->nullable();
            $table->string('alamat_instansi')->nullable();
            $table->string('masa_pajak')->nullable();
            $table->string('pajak_terhutangx')->nullable();
            $table->string('id_user')->nullable();
        });
        DB::connection('simpada')->table('sptpd_resto')->insert([
            $this->row(['transresto_id' => 1, 'no_npwpd' => '0002   ', 'nama_instansi' => 'Zulu Lama', 'alamat_instansi' => 'Lama', 'pajak_terhutangx' => '9007199254740993.10', 'id_user' => 'OPD-A']),
            $this->row(['transresto_id' => 5, 'tgl_trans' => '2026-02-15 08:30:00', 'no_npwpd' => '0002', 'nama_instansi' => 'Alpha', 'alamat_instansi' => 'Baru', 'masa_pajak' => 'Desember', 'pajak_terhutangx' => '0.20', 'id_user' => 'OPD-B']),
            $this->row(['transresto_id' => 6, 'no_npwpd' => '0002', 'nama_instansi' => 'Alpha', 'alamat_instansi' => 'Baru', 'masa_pajak' => 'Tidak Dikenal', 'pajak_terhutangx' => '0']),
            $this->row(['transresto_id' => 2, 'no_npwpd' => '0001', 'nama_instansi' => 'Alpha', 'alamat_instansi' => '=Alamat', 'pajak_terhutangx' => '2']),
            $this->row(['transresto_id' => 3, 'no_npwpd' => '   ', 'nama_instansi' => 'Kosong']),
            $this->row(['transresto_id' => 4, 'tgl_trans' => '2025-12-31 23:59:59', 'no_npwpd' => 'OLD']),
            $this->row(['transresto_id' => 7, 'tgl_trans' => '2027-01-01 00:00:00', 'no_npwpd' => 'NEXT']),
        ]);
    }

    public function test_validation_contract(): void
    {
        $this->getJson('/api/v1/reports/esptpd/rekap-instansi-makan-minum')->assertUnprocessable();
        foreach (['year=1999', 'year=9999', 'year=2026&month=01', 'year=2026&mode=x', 'year=2026&as_of_date=2026-01-01', 'year=2026&id_user=1', 'year=2026&id_opd=1', 'year=2026&akses=x', 'year=2026&per_page=1001', 'year=2026&sort_by=x'] as $query) {
            $this->getJson('/api/v1/reports/esptpd/rekap-instansi-makan-minum?'.$query)->assertUnprocessable();
        }
    }

    public function test_year_group_identity_month_decimal_default_sort_and_metadata(): void
    {
        $response = $this->getJson('/api/v1/reports/esptpd/rekap-instansi-makan-minum?year=2026')->assertOk();
        $response->assertJsonPath('meta.report_type', 'rekap-instansi-makan-minum')
            ->assertJsonPath('meta.source', 'simpada')->assertJsonPath('meta.year', 2026)
            ->assertJsonPath('meta.total', 2)->assertJsonPath('meta.row_count', 2)
            ->assertJsonPath('data.0.no_npwpd', '0001')->assertJsonPath('data.1.no_npwpd', '0002')
            ->assertJsonPath('data.1.nama_instansi', 'Alpha')->assertJsonPath('data.1.alamat_instansi', 'Baru')
            ->assertJsonPath('data.1.januari', '9007199254740993.1')->assertJsonPath('data.1.februari', '0.2')
            ->assertJsonPath('data.1.desember', '0')->assertJsonPath('data.1.total', '9007199254740993.3');
        $this->assertSame(['no', 'no_npwpd', 'nama_instansi', 'alamat_instansi', 'januari', 'februari', 'maret', 'april', 'mei', 'juni', 'juli', 'agustus', 'september', 'oktober', 'november', 'desember', 'total'], $response->json('meta.columns'));
    }

    public function test_year_filter_uses_inclusive_start_and_exclusive_end_of_tgl_trans(): void
    {
        DB::connection('simpada')->table('sptpd_resto')->insert([
            $this->row(['transresto_id' => 8, 'tgl_trans' => '2026-01-01 00:00:00', 'no_npwpd' => 'START']),
            $this->row(['transresto_id' => 9, 'tgl_trans' => '2026-12-31 23:59:59', 'no_npwpd' => 'END']),
        ]);

        $response = $this->getJson('/api/v1/reports/esptpd/rekap-instansi-makan-minum?year=2026')->assertOk();
        $response->assertJsonPath('meta.total', 4)
            ->assertJsonFragment(['no_npwpd' => 'START', 'januari' => '1'])
            ->assertJsonFragment(['no_npwpd' => 'END', 'desember' => '1'])
            ->assertJsonMissing(['no_npwpd' => 'OLD'])
            ->assertJsonMissing(['no_npwpd' => 'NEXT']);
    }

    public function test_search_numeric_sort_pagination_and_guard(): void
    {
        $this->getJson('/api/v1/reports/esptpd/rekap-instansi-makan-minum?year=2026&search=Baru')->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.no_npwpd', '0002');
        $this->getJson('/api/v1/reports/esptpd/rekap-instansi-makan-minum?year=2026&sort_by=total&sort_direction=desc')->assertJsonPath('data.0.no_npwpd', '0002');
        $this->getJson('/api/v1/reports/esptpd/rekap-instansi-makan-minum?year=2026&page=2&per_page=1')->assertJsonPath('data.0.no', 2)->assertJsonPath('meta.pagination.current_page', 2);
        Config::set('reports.pajak_daerah.max_rows', 1);
        $this->getJson('/api/v1/reports/esptpd/rekap-instansi-makan-minum?year=2026')->assertUnprocessable();
    }

    public function test_export_all_rows_metadata_cells_formula_safety_and_cors(): void
    {
        Carbon::setTestNow('2026-09-14 10:30:00 Asia/Jakarta');
        $response = $this->withHeader('Origin', 'http://localhost:5173')->get('/api/v1/reports/esptpd/rekap-instansi-makan-minum/export?year=2026&page=99&per_page=1')->assertOk();
        $response->assertHeader('Access-Control-Expose-Headers', 'Content-Disposition');
        $this->assertStringContainsString('filename=rekap-instansi-makan-minum-opd-2026.xlsx', (string) $response->headers->get('Content-Disposition'));
        $path = tempnam(sys_get_temp_dir(), 'rekap-test-');
        file_put_contents($path, $response->streamedContent());
        $sheet = IOFactory::load($path)->getActiveSheet();
        unlink($path);
        $this->assertSame('REKAP INSTANSI PAJAK MAKANAN DAN MINUMAN OPD', $sheet->getCell('A1')->getValue());
        $this->assertSame('TAHUN 2026', $sheet->getCell('A2')->getValue());
        $this->assertSame('Dicetak pada: 14 September 2026 10:30 WIB', $sheet->getCell('A3')->getValue());
        $this->assertSame(['No', 'NPWPD', 'Nama Instansi', 'Alamat Instansi', 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember', 'Total'], $sheet->rangeToArray('A5:Q5')[0]);
        $this->assertSame(7, $sheet->getHighestDataRow());
        $this->assertSame('0001', $sheet->getCell('B6')->getValue());
        $this->assertSame(DataType::TYPE_STRING, $sheet->getCell('B6')->getDataType());
        $this->assertSame('=Alamat', $sheet->getCell('D6')->getValue());
        $this->assertSame(DataType::TYPE_STRING, $sheet->getCell('D6')->getDataType());
        $this->assertSame(DataType::TYPE_NUMERIC, $sheet->getCell('E6')->getDataType());
        $this->assertSame('0', $sheet->getStyle('E6')->getNumberFormat()->getFormatCode());
        $this->assertSame('A6', $sheet->getFreezePane());
        Carbon::setTestNow();
    }

    private function row(array $overrides = []): array
    {
        return array_merge([
            'transresto_id' => 1, 'tgl_trans' => '2026-01-15 08:30:00', 'no_npwpd' => '0001',
            'nama_instansi' => 'Instansi', 'alamat_instansi' => 'Alamat', 'masa_pajak' => 'Januari',
            'pajak_terhutangx' => '1', 'id_user' => 'OPD',
        ], $overrides);
    }
}
