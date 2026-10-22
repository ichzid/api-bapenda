<?php

namespace Tests\Feature\Reports\PajakDaerah;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ReportEndpointsTest extends TestCase
{
    private array $sources = ['pbjt-hotel', 'pbjt-makan-minum', 'pbjt-hiburan', 'pajak-reklame', 'pbjt-tenaga-listrik', 'pajak-mblb', 'pajak-air-tanah', 'pajak-sarang-burung-walet', 'pbjt-parkir'];

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('database.connections.simpada', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        Config::set('database.connections.sts', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        DB::purge('simpada');
        DB::purge('sts');
        $this->schema();
    }

    public function test_discovery_exposes_exact_canonical_sources_and_rejects_old_english_slugs(): void
    {
        $response = $this->getJson('/api/v1/reports/pajak-daerah/sources')->assertOk();

        $this->assertSame($this->sources, array_column($response->json('data'), 'key'));
        $response->assertJsonPath('data.4.name', 'PBJT Atas Tenaga Listrik');

        foreach (['hotel', 'restaurant', 'entertainment', 'parking', 'electricity', 'ground-water', 'advertisement', 'mineral', 'swiftlet', 'pbjt-listrik-official'] as $source) {
            $this->getJson("/api/v1/reports/pajak-daerah/ketetapan/$source?year=2026")->assertNotFound();
        }
    }

    public function test_canonical_four_reports_cover_every_supported_source(): void
    {
        foreach ($this->sources as $source) {
            foreach (['monitoring', 'ketetapan', 'realisasi'] as $report) {
                $this->getJson("/api/v1/reports/pajak-daerah/$report/$source?year=2026")->assertOk()->assertJsonPath('meta.source', $source);
            }
            if ($source !== 'pbjt-tenaga-listrik') {
                $this->getJson("/api/v1/reports/pajak-daerah/piutang/$source?year=2026")->assertOk()->assertJsonPath('meta.source', $source);
            }
        }
        $this->getJson('/api/v1/reports/pajak-daerah/piutang/pbjt-tenaga-listrik?year=2026')->assertUnprocessable();
        $this->getJson('/api/v1/reports/pajak-daerah/piutang/pbjt-tenaga-listrik?year=2026&mode=corrected')->assertOk()->assertJsonPath('meta.source', 'pbjt-tenaga-listrik');
    }

    public function test_join_details_pat_document_reklame_and_historical_tariff(): void
    {
        $this->getJson('/api/v1/reports/pajak-daerah/ketetapan/pbjt-makan-minum?year=2026')
            ->assertJsonPath('data.0.taxpayer_name', 'Join WP')
            ->assertJsonPath('data.0.address', 'Join Address');
        $this->getJson('/api/v1/reports/pajak-daerah/realisasi/pbjt-makan-minum?year=2026')
            ->assertJsonPath('data.0.taxpayer_name', 'Join WP')
            ->assertJsonPath('data.0.address', 'Join Address');
        $this->getJson('/api/v1/reports/pajak-daerah/ketetapan/pbjt-hiburan?year=2026')->assertJsonPath('data.0.details.entertainment_type', 'Bioskop')->assertJsonPath('data.0.details.rate', '15%');
        $this->getJson('/api/v1/reports/pajak-daerah/ketetapan/pbjt-hiburan?year=2023')->assertJsonPath('meta.total', 0);
        $this->getJson('/api/v1/reports/pajak-daerah/ketetapan/pajak-air-tanah?year=2026')
            ->assertJsonPath('data.0.document_number', ' PAT ')
            ->assertJsonMissingPath('data.0.kohir')
            ->assertJsonPath('data.0.taxpayer_name', 'Join WP')
            ->assertJsonPath('data.0.address', 'Join Address')
            ->assertJsonPath('data.0.details.location', 'Lokasi PAT');
        $this->getJson('/api/v1/reports/pajak-daerah/monitoring/pajak-reklame?year=2026')->assertJsonPath('data.0.details.strategic', 'Ya')->assertJsonPath('data.0.details.period_start', '2026-01-01')->assertJsonPath('data.0.details.period_end', null);
        $this->getJson('/api/v1/reports/pajak-daerah/piutang/pajak-reklame?year=2026')->assertJsonPath('meta.total', 0);
    }

    public function test_restaurant_reports_require_registration_match(): void
    {
        DB::connection('simpada')->table('x_tbltrans_resto')->insert($this->row(['transresto_id' => 2, 'no_sptpd' => 'DIRECT', 'no_npwpd' => 'UNMATCHED', 'instansi' => 'Direct WP']));
        DB::connection('sts')->table('STS_History')->insert(['No_STS' => '998', 'No_NOP' => 'DIRECT', 'Jn_Pajak' => 'Pajak Restoran', 'Status_Bayar' => 1, 'Tgl_Bayar' => '2026-03-01']);
        DB::connection('sts')->table('STS_History')->where('No_NOP', 'RESTO')->update(['Status_Bayar' => 0]);

        foreach (['monitoring', 'piutang', 'realisasi'] as $report) {
            $this->getJson("/api/v1/reports/pajak-daerah/$report/pbjt-makan-minum?year=2026")
                ->assertJsonMissing(['document_number' => 'DIRECT']);
        }

        $this->getJson('/api/v1/reports/pajak-daerah/piutang/pbjt-makan-minum?year=2026')
            ->assertJsonPath('data.0.taxpayer_name', 'Join WP')
            ->assertJsonPath('data.0.address', 'Join Address');
    }

    public function test_pat_reports_require_registration_match_and_use_registered_identity(): void
    {
        DB::connection('simpada')->table('x_tbltransairtanah_new')->insert($this->row([
            'transairtanah_id' => 2,
            'nop' => 'PAT-UNMATCHED',
            'no_sptpd' => 'PAT-UNMATCHED-SPTPD',
            'no_npwpd' => 'UNMATCHED',
            'npwpd' => 'PAT-UNMATCHED-LEGACY',
            'instansi' => 'Transaction WP',
            'alamat_instansi' => 'Transaction Address',
        ]));
        DB::connection('sts')->table('STS_History')->insert([
            'No_STS' => '998',
            'No_NOP' => 'PAT-UNMATCHED',
            'Jn_Pajak' => 'Pajak Air Tanah',
            'Status_Bayar' => 1,
            'Tgl_Bayar' => '2026-03-01',
        ]);

        foreach (['monitoring', 'ketetapan', 'piutang', 'realisasi'] as $report) {
            $response = $this->getJson("/api/v1/reports/pajak-daerah/$report/pajak-air-tanah?year=2026")->assertOk();
            $response->assertJsonMissing(['document_number' => 'PAT-UNMATCHED']);

            if ($report !== 'piutang') {
                $response->assertJsonPath('data.0.taxpayer_name', 'Join WP')
                    ->assertJsonPath('data.0.address', 'Join Address');
            }
        }

        DB::connection('sts')->table('STS_History')->where('No_NOP', 'PAT')->update(['Status_Bayar' => 0]);

        $this->getJson('/api/v1/reports/pajak-daerah/piutang/pajak-air-tanah?year=2026')
            ->assertJsonPath('data.0.taxpayer_number', '0002')
            ->assertJsonMissing(['taxpayer_number' => 'PAT-LEGACY'])
            ->assertJsonPath('data.0.taxpayer_name', 'Join WP')
            ->assertJsonPath('data.0.address', 'Join Address');
    }

    public function test_realization_only_contains_local_rows_with_matching_paid_sts(): void
    {
        DB::connection('sts')->table('STS_History')->insert([
            'No_STS' => '999',
            'No_NOP' => 'MISSING',
            'Jn_Pajak' => 'Pajak Hotel',
            'Status_Bayar' => 1,
            'Tgl_Bayar' => '2026-03-01',
            'Nama_Pemilik' => 'Forbidden STS WP',
            'Alamat_Pemilik' => 'Forbidden STS Address',
        ]);
        DB::connection('simpada')->table('x_hotel')->insert($this->row([
            'hotel_id' => 2,
            'sptpd' => 'UNPAID',
            'nama_objek' => 'Local Without Paid STS',
        ]));

        $response = $this->getJson('/api/v1/reports/pajak-daerah/realisasi/pbjt-hotel?year=2026')->assertOk();

        $response->assertJsonMissing(['document_number' => 'MISSING'])
            ->assertJsonMissing(['document_number' => 'UNPAID'])
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.source_match_status', 'found')
            ->assertJsonPath('data.0.payment_match_status', 'found');
    }

    public function test_realization_period_is_based_on_sts_payment_date_not_local_print_or_tax_year(): void
    {
        DB::connection('simpada')->table('x_hotel')->where('hotel_id', 1)->update([
            'tgl_cetak' => '2024-01-10',
            'thn_pajak' => 2024,
        ]);

        $this->getJson('/api/v1/reports/pajak-daerah/realisasi/pbjt-hotel?year=2026&month=02')
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.tax_period', 'Januari 2024')
            ->assertJsonPath('data.0.payment_date', '2026-02-02');
    }

    public function test_local_identity_and_source_match_status_are_used_consistently(): void
    {
        $this->getJson('/api/v1/reports/pajak-daerah/realisasi/pbjt-hotel?year=2026&search=001')
            ->assertJsonPath('data.0.taxpayer_name', 'Hotel A')
            ->assertJsonPath('data.0.address', 'Jalan A')
            ->assertJsonPath('data.0.source_match_status', 'found');

        $this->getJson('/api/v1/reports/pajak-daerah/realisasi/pbjt-makan-minum?year=2026')
            ->assertJsonPath('data.0.taxpayer_name', 'Join WP')
            ->assertJsonPath('data.0.address', 'Join Address')
            ->assertJsonPath('data.0.source_match_status', 'found');

        $this->getJson('/api/v1/reports/pajak-daerah/ketetapan/pbjt-hotel?year=2026')
            ->assertJsonPath('data.0.source_match_status', 'found');
    }

    public function test_pat_monitoring_uses_local_identity_instead_of_sts_identity(): void
    {
        DB::connection('sts')->table('STS_History')->where('No_NOP', 'PAT')->update([
            'Nama_Pemilik' => 'Forbidden STS PAT',
            'Alamat_Pemilik' => 'Forbidden STS PAT Address',
        ]);

        $this->getJson('/api/v1/reports/pajak-daerah/monitoring/pajak-air-tanah?year=2026')
            ->assertJsonPath('data.0.taxpayer_name', 'Join WP')
            ->assertJsonPath('data.0.address', 'Join Address')
            ->assertJsonPath('data.0.source_match_status', 'found');
    }

    public function test_report_implementation_does_not_reference_sts_identity_fields(): void
    {
        $implementation = file_get_contents(app_path('Reports/PajakDaerah/ReportEngine.php'));

        $this->assertStringNotContainsString('Nama_Pemilik', $implementation);
        $this->assertStringNotContainsString('Alamat_Pemilik', $implementation);
    }

    public function test_json_defaults_to_newest_source_id_and_payment_date_and_explicit_sort_wins(): void
    {
        DB::connection('simpada')->table('x_hotel')->insert($this->row([
            'hotel_id' => 20,
            'sptpd' => '020',
            'npwpd' => '0020',
            'nama_objek' => 'Newest Source',
        ]));
        DB::connection('sts')->table('STS_History')->insert([
            'No_STS' => '020',
            'No_NOP' => '001',
            'Jn_Pajak' => 'Pajak Hotel',
            'Status_Bayar' => 1,
            'Tgl_Bayar' => '2026-03-03',
        ]);

        foreach (['monitoring', 'ketetapan', 'piutang'] as $report) {
            $this->getJson("/api/v1/reports/pajak-daerah/$report/pbjt-hotel?year=2026")
                ->assertJsonPath('data.0.source_record_id', '20');
        }
        $this->getJson('/api/v1/reports/pajak-daerah/realisasi/pbjt-hotel?year=2026')
            ->assertJsonPath('data.0.payment_date', '2026-03-03');
        $this->getJson('/api/v1/reports/pajak-daerah/ketetapan/pbjt-hotel?year=2026&sort_by=taxpayer_name&sort_direction=asc')
            ->assertJsonPath('data.0.taxpayer_name', 'Hotel A');
        $this->getJson('/api/v1/reports/pajak-daerah/realisasi/pbjt-hotel?year=2026&sort_by=payment_date&sort_direction=asc')
            ->assertJsonPath('data.0.payment_date', '2026-02-02');
    }

    public function test_duplicate_modes_dates_pagination_sort_search_limit_auth_and_alias(): void
    {
        $this->getJson('/api/v1/reports/pajak-daerah/monitoring/pbjt-hotel?year=2026')->assertJsonPath('data.0.payment_status', 'Sudah Bayar');
        $this->getJson('/api/v1/reports/pajak-daerah/monitoring/pbjt-hotel?year=2026&mode=corrected')->assertJsonPath('data.0.payment_status', 'Belum Bayar');
        DB::connection('simpada')->table('x_hotel')->insert($this->row(['hotel_id' => 20, 'sptpd' => '010', 'nama_objek' => 'Zulu', 'jmlh_bayar' => '9.50']));
        $this->getJson('/api/v1/reports/pajak-daerah/ketetapan/pbjt-hotel?year=2026&sort_by=principal_amount&sort_direction=asc&per_page=1&page=2')->assertJsonPath('data.0.number', 2)->assertJsonPath('data.0.document_number', '001');
        $this->getJson('/api/v1/reports/pajak-daerah/ketetapan/pbjt-hotel?year=2026&search=001')->assertJsonPath('meta.total', 1);
        Config::set('reports.pajak_daerah.max_rows', 1);
        $this->getJson('/api/v1/reports/pajak-daerah/monitoring/pbjt-hotel?year=2026')->assertUnprocessable();
        Config::set('reports.pajak_daerah.max_rows', 50000);
        Config::set('reports.pajak_daerah.auth', true);
        $this->getJson('/api/v1/reports/pajak-daerah/sources')->assertUnauthorized();
        Config::set('reports.pajak_daerah.auth', false);
    }

    public function test_identifiers_are_consistent_across_reports_and_reklame_uses_nopx(): void
    {
        $expected = [
            'pbjt-hotel' => ['001', '0002'],
            'pbjt-makan-minum' => ['RESTO', '0002'],
            'pbjt-hiburan' => ['HIB', '0002'],
            'pajak-reklame' => ['AD', 'REKLAME-NOPX'],
            'pbjt-tenaga-listrik' => ['ELEC', '0002'],
            'pajak-mblb' => ['GAL', '0002'],
            'pajak-air-tanah' => [' PAT ', '0002'],
            'pajak-sarang-burung-walet' => ['WAL', '0002'],
            'pbjt-parkir' => ['PARK', '0002'],
        ];

        foreach ($expected as $source => [$documentNumber, $taxpayerNumber]) {
            foreach (['monitoring', 'ketetapan', 'realisasi'] as $report) {
                $this->getJson("/api/v1/reports/pajak-daerah/$report/$source?year=2026")
                    ->assertJsonPath('data.0.document_number', $documentNumber)
                    ->assertJsonPath('data.0.taxpayer_number', $taxpayerNumber)
                    ->assertJsonMissingPath('data.0.kohir');
            }
        }

        DB::connection('sts')->table('STS_History')->where('No_NOP', 'AD')->update(['Status_Bayar' => 0]);
        $this->getJson('/api/v1/reports/pajak-daerah/piutang/pajak-reklame?year=2026')
            ->assertJsonPath('data.0.document_number', 'AD')
            ->assertJsonPath('data.0.taxpayer_number', 'REKLAME-NOPX')
            ->assertJsonMissing(['taxpayer_number' => 'REKLAME-NPWPD']);
    }

    public function test_rekap_ketetapan_per_wp_aggregates_by_print_year_and_month(): void
    {
        DB::connection('simpada')->table('x_hotel')->insert([
            $this->row(['hotel_id' => 2, 'masa_pajak' => 'Desember', 'thn_pajak' => 2024, 'tgl_cetak' => '2026-02-10', 'jmlh_bayar' => '20.75', 'nama_objek' => 'Zulu']),
            $this->row(['hotel_id' => 3, 'masa_pajak' => 'Desember', 'tgl_cetak' => '2025-12-31', 'jmlh_bayar' => '999']),
            $this->row(['hotel_id' => 4, 'npwpd' => '0001', 'nama_objek' => 'Omega', 'jmlh_bayar' => '5']),
            $this->row(['hotel_id' => 5, 'npwpd' => '0003', 'nama_objek' => 'Zulu', 'jmlh_bayar' => '7']),
        ]);

        $response = $this->getJson('/api/v1/reports/pajak-daerah/rekap-ketetapan-per-wp/pbjt-hotel?year=2026')
            ->assertOk()
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('data.0.taxpayer_number', '0001')
            ->assertJsonPath('data.0.taxpayer_name', 'Omega')
            ->assertJsonPath('data.1.taxpayer_number', '0002')
            ->assertJsonPath('data.1.taxpayer_name', 'Zulu')
            ->assertJsonPath('data.1.january', '100.25')
            ->assertJsonPath('data.1.february', '20.75')
            ->assertJsonPath('data.1.december', '0')
            ->assertJsonPath('data.1.annual_total', '121')
            ->assertJsonPath('data.2.taxpayer_number', '0003')
            ->assertJsonPath('data.2.taxpayer_name', 'Zulu');

        $this->assertSame(['number', 'taxpayer_number', 'taxpayer_name', 'address', 'january', 'february', 'march', 'april', 'may', 'june', 'july', 'august', 'september', 'october', 'november', 'december', 'annual_total'], $response->json('meta.columns'));
        $this->getJson('/api/v1/reports/pajak-daerah/rekap-ketetapan-per-wp/pbjt-hotel?year=2026&month=01')->assertUnprocessable();
    }

    public function test_rekap_uses_print_month_and_print_year_boundaries_across_all_sources(): void
    {
        DB::connection('simpada')->table('x_regpribadi')->insert([
            'npwpd' => 'BOUNDARY',
            'instansi' => 'Boundary WP',
            'alamat_instansi' => 'Boundary Address',
        ]);
        $definitions = [
            'pbjt-hotel' => ['x_hotel', 'hotel_id', 'npwpd', 'nama_objek', 'jmlh_bayar'],
            'pbjt-makan-minum' => ['x_tbltrans_resto', 'transresto_id', 'no_npwpd', 'instansi', 'pajak_terhutangx'],
            'pbjt-hiburan' => ['x_hiburan', 'hiburan_id', 'npwpd', 'nama_objek', 'jmlh_bayar'],
            'pajak-reklame' => ['x_tmpreklame', 'tmpreklame_id', 'nopx', 'instansi', 'nilai_pajak'],
            'pbjt-tenaga-listrik' => ['x_penerangan_official', 'penerangan_id', 'npwpd', 'nama_objek', 'jmlh_bayar'],
            'pajak-mblb' => ['x_galian', 'galian_id', 'no_npwpd', 'nama_usaha', 'jmlh_bayar'],
            'pajak-air-tanah' => ['x_tbltransairtanah_new', 'transairtanah_id', 'no_npwpd', 'instansi', 'hasil_persen'],
            'pajak-sarang-burung-walet' => ['x_walet', 'walet_id', 'npwpd', 'nama_objek', 'jmlh_bayar'],
            'pbjt-parkir' => ['x_parkir', 'parkir_id', 'npwpd', 'nama_objek', 'jmlh_bayar'],
        ];

        foreach ($definitions as $source => [$table, $primaryKey, $taxpayerField, $nameField, $amountField]) {
            DB::connection('simpada')->table($table)->insert([
                $this->row([$primaryKey => 20, $taxpayerField => 'BOUNDARY', $nameField => 'Boundary WP', 'tgl_cetak' => '2026-01-01 00:00:00', 'masa_pajak' => 'Juni', $amountField => '10']),
                $this->row([$primaryKey => 21, $taxpayerField => 'BOUNDARY', $nameField => 'Boundary WP', 'tgl_cetak' => '2026-02-15 12:00:00', 'masa_pajak' => 'Desember', $amountField => '20.75']),
                $this->row([$primaryKey => 22, $taxpayerField => 'BOUNDARY', $nameField => 'Boundary WP', 'tgl_cetak' => '2026-12-31 23:59:59', 'masa_pajak' => 'Februari', $amountField => '30']),
                $this->row([$primaryKey => 23, $taxpayerField => 'BOUNDARY', $nameField => 'Boundary WP', 'tgl_cetak' => '2025-12-31 23:59:59', 'masa_pajak' => 'Februari', $amountField => '999']),
                $this->row([$primaryKey => 24, $taxpayerField => 'BOUNDARY', $nameField => 'Boundary WP', 'tgl_cetak' => '2027-01-01 00:00:00', 'masa_pajak' => 'Desember', $amountField => '999']),
            ]);

            $this->getJson("/api/v1/reports/pajak-daerah/rekap-ketetapan-per-wp/$source?year=2026&search=BOUNDARY")
                ->assertOk()
                ->assertJsonPath('meta.total', 1)
                ->assertJsonPath('data.0.taxpayer_number', 'BOUNDARY')
                ->assertJsonPath('data.0.january', '10')
                ->assertJsonPath('data.0.february', '20.75')
                ->assertJsonPath('data.0.june', '0')
                ->assertJsonPath('data.0.december', '30')
                ->assertJsonPath('data.0.annual_total', '60.75');
        }
    }

    public function test_rekap_join_rules_reklame_identity_filtering_and_group_limit(): void
    {
        DB::connection('simpada')->table('x_tbltrans_resto')->insert($this->row(['transresto_id' => 2, 'no_npwpd' => 'UNMATCHED']));
        DB::connection('simpada')->table('x_tbltransairtanah_new')->insert($this->row(['transairtanah_id' => 2, 'no_npwpd' => 'UNMATCHED']));

        $this->getJson('/api/v1/reports/pajak-daerah/rekap-ketetapan-per-wp/pbjt-makan-minum?year=2026')->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.taxpayer_name', 'Join WP');
        $this->getJson('/api/v1/reports/pajak-daerah/rekap-ketetapan-per-wp/pajak-air-tanah?year=2026')->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.taxpayer_name', 'Join WP');
        $this->getJson('/api/v1/reports/pajak-daerah/rekap-ketetapan-per-wp/pajak-reklame?year=2026')->assertJsonPath('data.0.taxpayer_number', 'REKLAME-NOPX')->assertJsonPath('data.0.taxpayer_name', 'Reklame Lokal')->assertJsonPath('data.0.address', 'Alamat Reklame');
        $this->getJson('/api/v1/reports/pajak-daerah/rekap-ketetapan-per-wp/pbjt-hotel?year=2026&search=Jalan&sort_by=annual_total&sort_direction=desc&per_page=1')->assertJsonPath('meta.pagination.per_page', 1);

        Config::set('reports.pajak_daerah.max_rows', 0);
        $this->getJson('/api/v1/reports/pajak-daerah/rekap-ketetapan-per-wp/pbjt-hotel?year=2026')->assertUnprocessable();
    }

    private function schema(): void
    {
        Schema::connection('simpada')->create('x_regpribadi', function (Blueprint $t) {
            $t->string('npwpd');
            $t->string('instansi')->nullable();
            $t->string('alamat_instansi')->nullable();
        });
        DB::connection('simpada')->table('x_regpribadi')->insert([
            'npwpd' => '0002',
            'instansi' => 'Join WP',
            'alamat_instansi' => 'Join Address',
        ]);
        Schema::connection('simpada')->create('x_jenishiburan', function (Blueprint $t) {
            $t->integer('id_jenishiburan');
            $t->string('nama');
        });
        DB::connection('simpada')->table('x_jenishiburan')->insert(['id_jenishiburan' => 1, 'nama' => 'Bioskop']);
        Schema::connection('simpada')->create('objek_galian', function (Blueprint $t) {
            $t->integer('id_objek');
            $t->string('jenis_galian');
        });
        DB::connection('simpada')->table('objek_galian')->insert(['id_objek' => 1, 'jenis_galian' => 'Pasir']);
        Schema::connection('simpada')->create('x_sewareklame', function (Blueprint $t) {
            $t->string('jenis');
            $t->decimal('min');
            $t->decimal('max');
            $t->decimal('harga');
        });
        DB::connection('simpada')->table('x_sewareklame')->insert(['jenis' => 'Papan', 'min' => 0, 'max' => 100, 'harga' => 1200]);
        $tables = ['x_hotel' => 'hotel_id', 'x_tbltrans_resto' => 'transresto_id', 'x_hiburan' => 'hiburan_id', 'x_tmpreklame' => 'tmpreklame_id', 'x_penerangan_official' => 'penerangan_id', 'x_galian' => 'galian_id', 'x_tbltransairtanah_new' => 'transairtanah_id', 'x_walet' => 'walet_id', 'x_parkir' => 'parkir_id'];
        foreach ($tables as $table => $pk) {
            Schema::connection('simpada')->create($table, function (Blueprint $t) use ($pk) {
                $t->integer($pk);
                foreach (['sptpd', 'no_sptpd', 'nop_reklame', 'nop', 'npwpd', 'no_npwpd', 'nopx', 'nama_objek', 'instansi', 'nama_usaha', 'alamat_instansi', 'masa_pajak', 'tgl_cetak', 'pendapatan', 'pend_makan_minum', 'persentase', 'jmlh_bayar', 'pajak_terhutangx', 'nilai_pajak', 'hasil_persen', 'judul', 'alamat_reklame', 'p', 'l', 's', 'jumlah', 'jlh_bulan', 'id_lokasi', 'reklame', 'tglmasa_pajak_ax', 'tglmasa_pajak_bx', 'jlh_daya', 'tarif', 'jlh_listrik', 'lokasi', 'nilai_jual', 'jml_pengambilan', 'sumber_air', 'gol', 'volume'] as $c) {
                    $t->string($c)->nullable();
                }$t->integer('thn_pajak')->nullable();
                $t->integer('jenis_id')->nullable();
                $t->integer('objek_id')->nullable();
            });
        }
        $base = $this->row();
        DB::connection('simpada')->table('x_hotel')->insert($base + ['hotel_id' => 1]);
        DB::connection('simpada')->table('x_tbltrans_resto')->insert($base + ['transresto_id' => 1, 'no_sptpd' => 'RESTO', 'no_npwpd' => '0002', 'instansi' => 'Join WP']);
        DB::connection('simpada')->table('x_hiburan')->insert(array_merge($base, ['hiburan_id' => 1, 'jenis_id' => 1, 'sptpd' => 'HIB', 'persentase' => '0.15']));
        DB::connection('simpada')->table('x_tmpreklame')->insert(array_merge($base, ['tmpreklame_id' => 1, 'nop_reklame' => 'AD', 'nopx' => 'REKLAME-NOPX', 'npwpd' => 'REKLAME-NPWPD', 'instansi' => 'Reklame Lokal', 'alamat_instansi' => 'Alamat Reklame', 'reklame' => 'Papan', 'p' => 2, 'l' => 2, 's' => 1, 'jumlah' => 1, 'jlh_bulan' => 12, 'id_lokasi' => 2, 'nilai_pajak' => 600, 'tglmasa_pajak_ax' => '2026-01-01', 'tglmasa_pajak_bx' => '2026-02-30']));
        DB::connection('simpada')->table('x_penerangan_official')->insert(array_merge($base, ['penerangan_id' => 1, 'sptpd' => 'ELEC']));
        DB::connection('simpada')->table('x_galian')->insert(array_merge($base, ['galian_id' => 1, 'no_sptpd' => 'GAL', 'no_npwpd' => '0002', 'objek_id' => 1]));
        DB::connection('simpada')->table('x_tbltransairtanah_new')->insert(array_merge($base, ['transairtanah_id' => 1, 'nop' => ' PAT ', 'no_sptpd' => 'PAT-SPTPD', 'no_npwpd' => '0002', 'npwpd' => 'PAT-LEGACY', 'instansi' => 'Join WP', 'lokasi' => 'Lokasi PAT', 'sumber_air' => 2]));
        DB::connection('simpada')->table('x_walet')->insert(array_merge($base, ['walet_id' => 1, 'sptpd' => 'WAL']));
        DB::connection('simpada')->table('x_parkir')->insert(array_merge($base, ['parkir_id' => 1, 'sptpd' => 'PARK']));
        Schema::connection('sts')->create('STS_History', function (Blueprint $t) {
            $t->string('No_STS');
            $t->string('No_NOP');
            $t->string('Jn_Pajak');
            $t->integer('Status_Bayar');
            $t->string('Tgl_Bayar')->nullable();
            $t->string('Tgl_STS')->nullable();
            $t->string('jatuh_tempo')->nullable();
            $t->string('Denda')->default('0');
            $t->string('Nilai')->default('100');
            $t->string('No_Pokok_WP')->default('STS-NPWPD');
            $t->string('Nama_Pemilik')->default('STS WP');
            $t->string('Alamat_Pemilik')->default('STS Address');
            $t->string('Kode_Pengesahan')->default('00001');
        });
        $tax = [['001', 'Pajak Hotel'], ['RESTO', 'Pajak Restoran'], ['HIB', 'Pajak Hiburan'], ['AD', 'Pajak Reklame'], ['ELEC', 'Pajak Penerangan'], ['GAL', 'Pajak Galian'], ['PAT', 'Pajak Air Tanah'], ['WAL', 'Pajak Sarang Burung Walet'], ['PARK', 'Pajak Parkir']];
        $i = 2;
        foreach ($tax as [$nop,$name]) {
            DB::connection('sts')->table('STS_History')->insert(['No_STS' => str_pad((string) $i++, 3, '0', STR_PAD_LEFT), 'No_NOP' => $nop, 'Jn_Pajak' => $name, 'Status_Bayar' => 1, 'Tgl_Bayar' => '2026-02-02', 'Tgl_STS' => '2026-02-01']);
        }
        DB::connection('sts')->table('STS_History')->insert(['No_STS' => '001', 'No_NOP' => '001', 'Jn_Pajak' => 'Pajak Hotel', 'Status_Bayar' => 0, 'Tgl_Bayar' => '2026-02-01']);
    }

    private function row(array $override = []): array
    {
        return array_merge(['sptpd' => '001', 'npwpd' => '0002', 'nama_objek' => 'Hotel A', 'alamat_instansi' => 'Jalan A', 'masa_pajak' => 'Januari', 'thn_pajak' => 2026, 'tgl_cetak' => '2026-01-10', 'jmlh_bayar' => '100.25', 'pendapatan' => '1000', 'pajak_terhutangx' => '100.25', 'hasil_persen' => '100.25'], $override);
    }
}
