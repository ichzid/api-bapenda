<?php

namespace Tests\Feature\Reports\PajakDaerah;

use Carbon\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class ReportExportTest extends ReportEndpointsTest
{
    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-09-14 10:30:00 Asia/Jakarta');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_export_route_headers_columns_and_ignores_pagination(): void
    {
        DB::connection('simpada')->table('x_hotel')->insert([
            'hotel_id' => 20, 'sptpd' => '010', 'npwpd' => '0009', 'nama_objek' => 'Zulu',
            'alamat_instansi' => 'Jalan Z', 'masa_pajak' => 'Januari', 'thn_pajak' => 2026,
            'tgl_cetak' => '2026-01-11', 'jmlh_bayar' => '9.50', 'pendapatan' => '90',
        ]);
        $response = $this->get('/api/v1/reports/pajak-daerah/ketetapan/pbjt-hotel/export?year=2026&month=01&sort_by=principal_amount&sort_direction=asc&page=99&per_page=1');
        $response->assertOk();
        $response->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $this->assertStringContainsString('filename=ketetapan-pbjt-hotel-2026-01.xlsx', (string) $response->headers->get('content-disposition'));
        $sheet = $this->sheet($response->streamedContent());
        $this->assertSame('Laporan ketetapan PBJT Atas Jasa Perhotelan', $sheet->getCell('A1')->getValue());
        $this->assertSame('Periode: Januari 2026', $sheet->getCell('A2')->getValue());
        $this->assertSame('Dicetak pada: 14 September 2026 10:30 WIB', $sheet->getCell('A3')->getValue());
        $this->assertNull($sheet->getCell('A4')->getValue());
        $this->assertSame('center', $sheet->getStyle('A1')->getAlignment()->getHorizontal());
        $this->assertSame('center', $sheet->getStyle('A2')->getAlignment()->getHorizontal());
        $this->assertSame('center', $sheet->getStyle('A3')->getAlignment()->getHorizontal());
        $this->assertCount(3, $sheet->getMergeCells());
        $this->assertLessThanOrEqual(31, mb_strlen($sheet->getTitle()));
        $this->assertSame(['No.', 'Nomor Dokumen', 'NPWPD', 'Nama Wajib Pajak'], array_slice($sheet->rangeToArray('A5:D5')[0], 0, 4));
        $this->assertSame(2, $sheet->getHighestDataRow() - 5);
        $this->assertSame('0009', $sheet->getCell('C6')->getValue());
        $this->assertSame('0002', $sheet->getCell('C7')->getValue());
    }

    public function test_export_exposes_content_disposition_to_cross_origin_frontend(): void
    {
        $response = $this->withHeader('Origin', 'http://localhost:5173')
            ->get('/api/v1/reports/pajak-daerah/ketetapan/pbjt-hotel/export?year=2026&month=01');

        $response->assertOk();
        $response->assertHeader('Access-Control-Expose-Headers', 'Content-Disposition');
        $this->assertStringContainsString('filename=ketetapan-pbjt-hotel-2026-01.xlsx', (string) $response->headers->get('Content-Disposition'));
    }

    public function test_export_defaults_to_oldest_source_id_and_payment_date(): void
    {
        DB::connection('simpada')->table('x_hotel')->insert([
            'hotel_id' => 20, 'sptpd' => '020', 'npwpd' => '0020', 'nama_objek' => 'Newest Source',
            'alamat_instansi' => 'Jalan B', 'masa_pajak' => 'Januari', 'thn_pajak' => 2026,
            'tgl_cetak' => '2026-01-11', 'jmlh_bayar' => '90', 'pendapatan' => '900',
        ]);
        DB::connection('sts')->table('STS_History')->insert([
            'No_STS' => '020', 'No_NOP' => '001', 'Jn_Pajak' => 'Pajak Hotel',
            'Status_Bayar' => 1, 'Tgl_Bayar' => '2026-03-03',
        ]);

        foreach (['monitoring', 'ketetapan'] as $report) {
            $sheet = $this->sheet($this->get("/api/v1/reports/pajak-daerah/$report/pbjt-hotel/export?year=2026")->streamedContent());
            $this->assertSame('0002', $sheet->getCell('C6')->getValue());
        }
        $piutang = $this->sheet($this->get('/api/v1/reports/pajak-daerah/piutang/pbjt-hotel/export?year=2026')->streamedContent());
        $this->assertSame('0020', $piutang->getCell('C6')->getValue());

        $sheet = $this->sheet($this->get('/api/v1/reports/pajak-daerah/realisasi/pbjt-hotel/export?year=2026')->streamedContent());
        $this->assertSame('2026-02-02', $sheet->getCell('B6')->getValue());
    }

    public function test_export_uses_same_filter_search_and_sort_pipeline_as_all_json_pages(): void
    {
        DB::connection('simpada')->table('x_hotel')->insert([
            'hotel_id' => 20, 'sptpd' => '010', 'npwpd' => '0010', 'nama_objek' => 'Alpha Formula',
            'alamat_instansi' => 'Jalan B', 'masa_pajak' => 'Januari', 'thn_pajak' => 2026,
            'tgl_cetak' => '2026-01-11', 'jmlh_bayar' => '9.50', 'pendapatan' => '90',
        ]);
        $query = 'year=2026&search=Alpha&sort_by=taxpayer_name&sort_direction=desc&per_page=1';
        $json = $this->getJson('/api/v1/reports/pajak-daerah/ketetapan/pbjt-hotel?'.$query)->json('data');
        $sheet = $this->sheet($this->get('/api/v1/reports/pajak-daerah/ketetapan/pbjt-hotel/export?'.$query)->streamedContent());
        $this->assertSame(array_column($json, 'taxpayer_name'), [$sheet->getCell('D6')->getValue()]);
        $this->assertSame(6, $sheet->getHighestDataRow());
    }

    public function test_amounts_are_numeric_while_identifiers_and_other_strings_remain_literal(): void
    {
        DB::connection('simpada')->table('x_hotel')->where('hotel_id', 1)->update([
            'sptpd' => '0001',
            'npwpd' => '0002',
            'nama_objek' => '=1+1',
            'alamat_instansi' => '@SUM(A1:A2)',
            'jmlh_bayar' => '100.25',
        ]);
        DB::connection('sts')->table('STS_History')->where('No_NOP', '001')->update([
            'No_NOP' => '0001',
            'Nilai' => '100.25',
            'Kode_Pengesahan' => '00001',
        ]);

        $monitoring = $this->sheet($this->get('/api/v1/reports/pajak-daerah/monitoring/pbjt-hotel/export?year=2026')->streamedContent());
        foreach (['J6', 'K6', 'L6'] as $coordinate) {
            $this->assertSame(DataType::TYPE_NUMERIC, $monitoring->getCell($coordinate)->getDataType());
            $this->assertSame('0', $monitoring->getCell($coordinate)->getStyle()->getNumberFormat()->getFormatCode());
        }
        $this->assertSame(100.25, $monitoring->getCell('J6')->getValue());
        $this->assertSame(['100', '0', '100'], array_map(
            fn (string $coordinate): string => $monitoring->getCell($coordinate)->getFormattedValue(),
            ['J6', 'K6', 'L6'],
        ));
        $this->assertSame('0001', $monitoring->getCell('B6')->getValue());
        $this->assertSame(DataType::TYPE_STRING, $monitoring->getCell('B6')->getDataType());
        $this->assertSame('0002', $monitoring->getCell('C6')->getValue());
        $this->assertSame(DataType::TYPE_STRING, $monitoring->getCell('C6')->getDataType());
        $this->assertSame('=1+1', $monitoring->getCell('D6')->getValue());
        $this->assertSame(DataType::TYPE_STRING, $monitoring->getCell('D6')->getDataType());
        $this->assertSame('@SUM(A1:A2)', $monitoring->getCell('E6')->getValue());
        $this->assertSame(DataType::TYPE_STRING, $monitoring->getCell('E6')->getDataType());

        $realization = $this->sheet($this->get('/api/v1/reports/pajak-daerah/realisasi/pbjt-hotel/export?year=2026')->streamedContent());
        $this->assertSame('00001', $realization->getCell('K6')->getValue());
        $this->assertSame(DataType::TYPE_STRING, $realization->getCell('K6')->getDataType());
    }

    public function test_piutang_export_includes_payment_status(): void
    {
        DB::connection('sts')->table('STS_History')->where('No_NOP', '001')->delete();

        $sheet = $this->sheet($this->get('/api/v1/reports/pajak-daerah/piutang/pbjt-hotel/export?year=2026')->streamedContent());

        $this->assertSame('Status Pembayaran', $sheet->getCell('I5')->getValue());
        $this->assertSame('Belum Bayar', $sheet->getCell('I6')->getValue());
    }

    public function test_unsupported_limit_and_auth_behave_like_json_endpoint(): void
    {
        $this->get('/api/v1/reports/pajak-daerah/unknown/pbjt-hotel/export?year=2026')->assertNotFound();
        $this->get('/api/v1/reports/pajak-daerah/ketetapan/unknown/export?year=2026')->assertNotFound();
        Config::set('reports.pajak_daerah.max_rows', 0);
        $this->get('/api/v1/reports/pajak-daerah/ketetapan/pbjt-hotel/export?year=2026')->assertUnprocessable();
        Config::set('reports.pajak_daerah.max_rows', 50000);
        Config::set('reports.pajak_daerah.auth', true);
        $this->get('/api/v1/reports/pajak-daerah/ketetapan/pbjt-hotel/export?year=2026')->assertUnauthorized();
        Config::set('reports.pajak_daerah.auth', false);
        $this->get('/api/v1/reports/pajak-daerah/ketetapan/pbjt-hotel/export?year=2026')->assertOk();
    }

    public function test_rekap_export_has_annual_metadata_filename_numeric_months_and_default_order(): void
    {
        DB::connection('simpada')->table('x_hotel')->insert([
            'hotel_id' => 20, 'sptpd' => '020', 'npwpd' => '0001', 'nama_objek' => 'Zulu',
            'alamat_instansi' => 'Jalan Z', 'masa_pajak' => 'Januari', 'thn_pajak' => 2026,
            'tgl_cetak' => '2026-01-11', 'jmlh_bayar' => '9.50', 'pendapatan' => '90',
        ]);
        $response = $this->get('/api/v1/reports/pajak-daerah/rekap-ketetapan-per-wp/pbjt-hotel/export?year=2026&page=99&per_page=1')->assertOk();
        $this->assertStringContainsString('filename=rekap-ketetapan-per-wp-pbjt-hotel-2026.xlsx', (string) $response->headers->get('content-disposition'));
        $sheet = $this->sheet($response->streamedContent());

        $this->assertSame('Rekap Ketetapan per Wajib Pajak PBJT Atas Jasa Perhotelan', $sheet->getCell('A1')->getValue());
        $this->assertSame('Periode: 2026', $sheet->getCell('A2')->getValue());
        $this->assertSame(['No', 'NPWPD', 'Nama WP/Usaha', 'Alamat', 'Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des', 'Total'], $sheet->rangeToArray('A5:Q5')[0]);
        $this->assertSame('0002', $sheet->getCell('B6')->getValue());
        $this->assertSame('Hotel A', $sheet->getCell('C6')->getValue());
        $this->assertSame('0001', $sheet->getCell('B7')->getValue());
        $this->assertSame('Zulu', $sheet->getCell('C7')->getValue());
        foreach (range('E', 'Q') as $column) {
            $this->assertSame(DataType::TYPE_NUMERIC, $sheet->getCell($column.'6')->getDataType());
            $this->assertSame('0', $sheet->getStyle($column.'6')->getNumberFormat()->getFormatCode());
        }
        $this->assertSame(100.25, $sheet->getCell('E6')->getValue());
        $this->assertSame(100.25, $sheet->getCell('Q6')->getValue());
    }

    private function sheet(string $content): Worksheet
    {
        $path = tempnam(sys_get_temp_dir(), 'xlsx-test-');
        file_put_contents($path, $content);
        $spreadsheet = IOFactory::load($path);
        unlink($path);

        return $spreadsheet->getActiveSheet();
    }
}
