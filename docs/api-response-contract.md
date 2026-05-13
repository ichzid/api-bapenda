# API Response Contract

Dokumen ini dibuat untuk frontend developer agar struktur response API mudah dipahami tanpa membaca source code backend.

## Aturan Umum

- semua response sukses memiliki `success: true`
- semua response gagal memiliki `success: false`
- field numerik seperti `target`, `realisasi`, `nilai`, `denda`, dan `total_bayar` dikirim sebagai number
- field detail jenis pajak menggunakan slug pada URL, misalnya `pajak-restoran`

## Endpoint Ringkasan

### `GET /api/realisasi-pajak`

Query parameter:

| Field | Type | Required | Keterangan |
| --- | --- | --- | --- |
| `tahun` | number | tidak | Tahun data yang ingin diambil. Default tahun berjalan. |

Response structure:

| Field | Type | Keterangan |
| --- | --- | --- |
| `success` | boolean | Status request |
| `message` | string | Pesan response |
| `tahun` | number | Tahun data yang dipakai |
| `ringkasan` | object | Ringkasan seluruh jenis pajak |
| `rincian` | array | Daftar per jenis pajak |

`ringkasan` fields:

| Field | Type | Keterangan |
| --- | --- | --- |
| `total_target` | number | Total target semua jenis pajak |
| `total_realisasi` | number | Total realisasi semua jenis pajak |
| `persentase_capaian` | number | Persentase capaian total |
| `selisih_anggaran` | number | `total_realisasi - total_target` |

Item `rincian[]` fields:

| Field | Type | Keterangan |
| --- | --- | --- |
| `jenis_pajak` | string | Nama tampilan untuk dashboard |
| `kode_jenis_pajak` | string | Nama asli jenis pajak dari backend |
| `slug_jenis_pajak` | string | Slug yang dipakai untuk endpoint detail |
| `sumber_data` | string | `sts_history`, `epbb_db`, atau `simpada` |
| `detail_tersedia` | boolean | `true` jika endpoint detail sudah tersedia |
| `detail_endpoint` | string \| null | URL detail jika tersedia |
| `target` | number | Target anggaran |
| `realisasi` | number | Nilai realisasi |
| `persentase` | number | Persentase capaian |
| `selisih` | number | `realisasi - target` |

Contoh penggunaan frontend:

- gunakan `rincian` untuk tabel utama
- jika `detail_tersedia` bernilai `true`, baris boleh dibuat clickable
- ketika diklik, gunakan `detail_endpoint` atau `slug_jenis_pajak` untuk memanggil endpoint detail

## Endpoint Detail

### `GET /api/realisasi-pajak/{jenisPajak}/detail`

Endpoint ini saat ini hanya mendukung jenis pajak yang sumbernya dari `STS_History`.

Belum didukung:
- `PBB`
- `Opsen PKB`
- `Opsen BBNKB`

Path parameter:

| Field | Type | Required | Keterangan |
| --- | --- | --- | --- |
| `jenisPajak` | string | ya | Slug jenis pajak, misalnya `bphtb`, `pajak-restoran`, `pajak-reklame` |

Query parameter:

| Field | Type | Required | Keterangan |
| --- | --- | --- | --- |
| `tahun` | number | tidak | Tahun data. Default tahun berjalan. |
| `page` | number | tidak | Halaman data pembayaran. Default `1`. |
| `per_page` | number | tidak | Jumlah item per halaman. Default `10`, maksimum `100`. |
| `limit` | number | tidak | Alias lama dari `per_page`. Sebaiknya frontend baru gunakan `per_page`. |

Response structure:

| Field | Type | Keterangan |
| --- | --- | --- |
| `success` | boolean | Status request |
| `message` | string | Pesan response |
| `data` | object | Isi utama response |
| `filters` | object | Filter aktif yang dipakai backend |

`data.jenis_pajak` fields:

| Field | Type | Keterangan |
| --- | --- | --- |
| `kode` | string | Nama asli jenis pajak |
| `slug` | string | Slug jenis pajak |
| `nama` | string | Nama tampilan untuk frontend |
| `sumber_data` | string | Saat ini selalu `sts_history` untuk endpoint ini |

`data.ringkasan` fields:

| Field | Type | Keterangan |
| --- | --- | --- |
| `target` | number | Target anggaran jenis pajak |
| `realisasi` | number | Total realisasi pada tahun tersebut |
| `persentase_capaian` | number | Persentase capaian |
| `sisa_anggaran` | number | `target - realisasi` |
| `jumlah_transaksi` | number | Total transaksi yang match dengan filter tahun |

`data.pembayaran` fields:

| Field | Type | Keterangan |
| --- | --- | --- |
| `data` | array | Daftar pembayaran untuk halaman aktif |
| `pagination` | object | Metadata pagination |
| `sorting` | object | Informasi sorting default backend |

Item `data.pembayaran.data[]` fields:

| Field | Type | Keterangan |
| --- | --- | --- |
| `no_sts` | string \| null | Nomor STS |
| `no_sspd` | string \| null | Nomor SSPD |
| `no_ketetapan` | string \| null | Nomor ketetapan |
| `no_pokok_wp` | string \| null | Nomor pokok wajib pajak |
| `nama_pemilik` | string \| null | Nama wajib pajak/pemilik |
| `alamat_pemilik` | string \| null | Alamat pemilik |
| `mata_anggaran` | string \| null | Kode mata anggaran |
| `nilai` | number | Nilai pembayaran pokok |
| `denda` | number | Nilai denda |
| `total_bayar` | number | `nilai + denda` |
| `tgl_bayar` | string \| null | Tanggal pembayaran |
| `nama_channel` | string \| null | Channel pembayaran |
| `pembayaran_ke` | number \| null | Pembayaran ke berapa |
| `kode_pembayaran` | string \| null | Kode pembayaran |

`data.pembayaran.pagination` fields:

| Field | Type | Keterangan |
| --- | --- | --- |
| `current_page` | number | Halaman aktif |
| `per_page` | number | Jumlah item per halaman |
| `total` | number | Total seluruh item |
| `last_page` | number | Total halaman |
| `from` | number \| null | Index awal item pada halaman aktif |
| `to` | number \| null | Index akhir item pada halaman aktif |
| `has_more_pages` | boolean | Apakah masih ada halaman berikutnya |

`data.pembayaran.sorting` fields:

| Field | Type | Keterangan |
| --- | --- | --- |
| `sort_by` | string | Saat ini `Tgl_Bayar` |
| `sort_direction` | string | Saat ini `desc` |

`filters` fields:

| Field | Type | Keterangan |
| --- | --- | --- |
| `tahun` | number | Tahun aktif |
| `page` | number | Halaman aktif |
| `per_page` | number | Jumlah item per halaman |

## Error Response

Format error umum:

```json
{
  "success": false,
  "message": "Pesan error"
}
```

Status yang perlu diperhatikan frontend:

| HTTP Status | Kondisi |
| --- | --- |
| `404` | Jenis pajak tidak ditemukan |
| `422` | Detail belum tersedia untuk jenis pajak tersebut |
| `500` | Terjadi kesalahan pada backend atau sumber data |

## Rekomendasi Integrasi Frontend

- tabel utama gunakan endpoint `GET /api/realisasi-pajak`
- tombol/detail row gunakan `detail_tersedia`
- untuk load detail, simpan `slug_jenis_pajak` dari tabel utama
- untuk tabel transaksi detail, gunakan `data.pembayaran.data`
- untuk pagination tabel transaksi, gunakan `data.pembayaran.pagination`
- jangan hitung ulang total summary di frontend; gunakan `data.ringkasan` langsung dari backend
