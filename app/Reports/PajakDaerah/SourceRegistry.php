<?php

namespace App\Reports\PajakDaerah;

use InvalidArgumentException;

final class SourceRegistry
{
    private const SOURCES = [
        'pbjt-hotel' => ['id' => 1, 'name' => 'PBJT Atas Jasa Perhotelan', 'table' => 'x_hotel', 'pk' => 'hotel_id', 'number' => 'sptpd', 'npwpd' => 'npwpd', 'name_field' => 'nama_objek', 'address' => 'alamat_instansi', 'amount' => 'jmlh_bayar', 'sts' => 'Pajak Hotel'],
        'pbjt-makan-minum' => ['id' => 2, 'name' => 'PBJT Atas Makanan dan Minuman', 'table' => 'x_tbltrans_resto', 'pk' => 'transresto_id', 'number' => 'no_sptpd', 'npwpd' => 'no_npwpd', 'name_field' => 'instansi', 'address' => 'alamat_instansi', 'amount' => 'pajak_terhutangx', 'sts' => 'Pajak Restoran', 'join' => ['table' => 'x_regpribadi', 'left' => 'x_regpribadi.npwpd', 'right' => 'x_tbltrans_resto.no_npwpd', 'type' => 'inner', 'identity' => ['name' => 'instansi', 'address' => 'alamat_instansi']], 'join_reports' => ['monitoring', 'ketetapan', 'rekap-ketetapan-per-wp', 'piutang', 'realisasi']],
        'pbjt-hiburan' => ['id' => 3, 'name' => 'PBJT Atas Jasa Kesenian dan Hiburan', 'table' => 'x_hiburan', 'pk' => 'hiburan_id', 'number' => 'sptpd', 'npwpd' => 'npwpd', 'name_field' => 'nama_objek', 'address' => 'alamat_instansi', 'amount' => 'jmlh_bayar', 'sts' => 'Pajak Hiburan', 'join' => ['table' => 'x_jenishiburan', 'left' => 'x_jenishiburan.id_jenishiburan', 'right' => 'x_hiburan.jenis_id', 'type' => 'inner'], 'join_reports' => ['monitoring', 'ketetapan']],
        'pajak-reklame' => ['id' => 4, 'name' => 'Pajak Reklame', 'table' => 'x_tmpreklame', 'pk' => 'tmpreklame_id', 'number' => 'nop_reklame', 'npwpd' => 'nopx', 'name_field' => 'instansi', 'address' => 'alamat_instansi', 'amount' => 'nilai_pajak', 'sts' => 'Pajak Reklame', 'join' => ['table' => 'x_sewareklame', 'left' => 'x_sewareklame.jenis', 'right' => 'x_tmpreklame.reklame', 'type' => 'advertisement'], 'join_reports' => ['monitoring', 'ketetapan']],
        'pbjt-tenaga-listrik' => ['id' => 5, 'aliases' => [10], 'name' => 'PBJT Atas Tenaga Listrik', 'table' => 'x_penerangan_official', 'pk' => 'penerangan_id', 'number' => 'sptpd', 'npwpd' => 'npwpd', 'name_field' => 'nama_objek', 'address' => 'alamat_instansi', 'amount' => 'jmlh_bayar', 'sts' => 'Pajak Penerangan'],
        'pajak-mblb' => ['id' => 6, 'name' => 'Pajak Mineral Bukan Logam dan Batuan (MBLB)', 'table' => 'x_galian', 'pk' => 'galian_id', 'number' => 'no_sptpd', 'npwpd' => 'no_npwpd', 'name_field' => 'nama_usaha', 'address' => 'alamat_instansi', 'amount' => 'jmlh_bayar', 'sts' => 'Pajak Galian', 'join' => ['table' => 'objek_galian', 'left' => 'objek_galian.id_objek', 'right' => 'x_galian.objek_id', 'type' => 'left'], 'join_reports' => ['monitoring', 'ketetapan']],
        'pajak-air-tanah' => ['id' => 7, 'name' => 'Pajak Air Tanah (PAT)', 'table' => 'x_tbltransairtanah_new', 'pk' => 'transairtanah_id', 'number' => 'nop', 'npwpd' => 'no_npwpd', 'name_field' => 'instansi', 'address' => 'alamat_instansi', 'amount' => 'hasil_persen', 'sts' => 'Pajak Air Tanah', 'join' => ['table' => 'x_regpribadi', 'left' => 'x_regpribadi.npwpd', 'right' => 'x_tbltransairtanah_new.no_npwpd', 'type' => 'inner', 'identity' => ['name' => 'instansi', 'address' => 'alamat_instansi']], 'join_reports' => ['monitoring', 'ketetapan', 'rekap-ketetapan-per-wp', 'piutang', 'realisasi']],
        'pajak-sarang-burung-walet' => ['id' => 8, 'name' => 'Pajak Sarang Burung Walet', 'table' => 'x_walet', 'pk' => 'walet_id', 'number' => 'sptpd', 'npwpd' => 'npwpd', 'name_field' => 'nama_objek', 'address' => 'alamat_instansi', 'amount' => 'jmlh_bayar', 'sts' => 'Pajak Sarang Burung Walet'],
        'pbjt-parkir' => ['id' => 9, 'name' => 'PBJT Atas Jasa Parkir', 'table' => 'x_parkir', 'pk' => 'parkir_id', 'number' => 'sptpd', 'npwpd' => 'npwpd', 'name_field' => 'nama_objek', 'address' => 'alamat_instansi', 'amount' => 'jmlh_bayar', 'sts' => 'Pajak Parkir'],
    ];

    public function all(): array
    {
        return array_map(fn ($key, $source) => ['key' => $key, 'id' => $source['id'], 'aliases' => $source['aliases'] ?? [], 'name' => $source['name'], 'supported_reports' => ['monitoring', 'ketetapan', 'rekap-ketetapan-per-wp', ...($key === 'pbjt-tenaga-listrik' ? ['piutang_corrected'] : ['piutang']), 'realisasi']], array_keys(self::SOURCES), self::SOURCES);
    }

    public function get(string $key): array
    {
        if (! isset(self::SOURCES[$key])) {
            throw new InvalidArgumentException('Sumber laporan tidak didukung.');
        }

        return ['key' => $key, ...self::SOURCES[$key]];
    }

    public function keyForLegacyId(int $id): ?string
    {
        foreach (self::SOURCES as $key => $source) {
            if ($source['id'] === $id || in_array($id, $source['aliases'] ?? [], true)) {
                return $key;
            }
        }

        return null;
    }
}
