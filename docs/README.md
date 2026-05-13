# API Bapenda Documentation

Dokumen utama project ada di folder ini agar dokumentasi backend dan frontend tersimpan di satu tempat.

## Daftar Dokumen

- [Project Overview](D:/Project/www/api-bapenda/docs/README.md:1)
- [API Response Contract](D:/Project/www/api-bapenda/docs/api-response-contract.md:1)

## Tujuan

Backend Laravel untuk menyediakan data agregasi realisasi pajak daerah bagi aplikasi lain.

Service ini berperan sebagai sumber API yang:
- mengambil data realisasi dari beberapa database yang sudah berjalan
- menggabungkan hasilnya ke dalam format yang konsisten
- menyediakan endpoint ringkas untuk dipakai dashboard atau aplikasi konsumen lain

## Stack

- PHP 8.3
- Laravel 13
- Laravel Sanctum

## Sumber Data

API ini saat ini membaca data dari beberapa sumber:
- koneksi default database aplikasi untuk tabel `STS_History`
- koneksi `epbb_db` untuk data PBB dari tabel `pembayaran_sppt`
- koneksi `simpada` untuk data opsen dari tabel `realisasi_opsen`

## Endpoint

### `GET /api/realisasi-pajak`

Mengembalikan ringkasan target dan realisasi pajak daerah per jenis pajak.

Query parameter:
- `tahun` opsional, default tahun berjalan

Contoh request:

```http
GET /api/realisasi-pajak?tahun=2026
```

Contoh response:

```json
{
  "success": true,
  "message": "Data ringkasan realisasi & target berhasil diambil.",
  "tahun": 2026,
  "ringkasan": {
    "total_target": 0,
    "total_realisasi": 0,
    "persentase_capaian": 0,
    "selisih_anggaran": 0
  },
  "rincian": [
    {
      "jenis_pajak": "Pajak Bumi dan Bangunan",
      "target": 0,
      "realisasi": 0,
      "persentase": 0,
      "selisih": 0
    }
  ]
}
```

Catatan:
- setiap item `rincian` sekarang memiliki `kode_jenis_pajak`, `slug_jenis_pajak`, `detail_tersedia`, dan `detail_endpoint`
- detail saat ini baru tersedia untuk jenis pajak yang sumber utamanya dari `STS_History`

### `GET /api/realisasi-pajak/{jenisPajak}/detail`

Mengembalikan ringkasan detail untuk satu jenis pajak beserta daftar pembayaran terbaru dari tabel `STS_History`.
Response endpoint ini dibuat terstruktur agar mudah dikonsumsi frontend: `data.jenis_pajak`, `data.ringkasan`, `data.pembayaran.data`, dan `data.pembayaran.pagination`.

Path parameter:
- `jenisPajak` dapat memakai slug seperti `bphtb`, `pajak-restoran`, `pajak-reklame`

Query parameter:
- `tahun` opsional, default tahun berjalan
- `page` opsional, default `1`
- `per_page` opsional, default `10`, maksimum `100`
- `limit` masih didukung sebagai alias lama dari `per_page`

Contoh request:

```http
GET /api/realisasi-pajak/pajak-restoran/detail?tahun=2026&page=1&per_page=10
```

Contoh response:

```json
{
  "success": true,
  "message": "Detail realisasi pajak berhasil diambil.",
  "data": {
    "jenis_pajak": {
      "kode": "Pajak Restoran",
      "slug": "pajak-restoran",
      "nama": "PBJT Atas Makanan dan Minuman",
      "sumber_data": "sts_history"
    },
    "ringkasan": {
      "target": 4000000000,
      "realisasi": 1250000000,
      "persentase_capaian": 31.25,
      "sisa_anggaran": 2750000000,
      "jumlah_transaksi": 120
    },
    "pembayaran": {
      "data": [
        {
          "no_sts": "STS-001",
          "no_sspd": "SSPD-001",
          "no_ketetapan": "KET-001",
          "no_pokok_wp": "12.34.56.789",
          "nama_pemilik": "Nama Wajib Pajak",
          "alamat_pemilik": "Alamat Wajib Pajak",
          "mata_anggaran": "4.1.1.01",
          "nilai": 1000000,
          "denda": 0,
          "total_bayar": 1000000,
          "tgl_bayar": "2026-05-11 09:10:11",
          "nama_channel": "Bank/Channel",
          "pembayaran_ke": 1,
          "kode_pembayaran": "ABC123"
        }
      ],
      "pagination": {
        "current_page": 1,
        "per_page": 10,
        "total": 50,
        "last_page": 5,
        "from": 1,
        "to": 10,
        "has_more_pages": true
      },
      "sorting": {
        "sort_by": "Tgl_Bayar",
        "sort_direction": "desc"
      }
    }
  },
  "filters": {
    "tahun": 2026,
    "page": 1,
    "per_page": 10
  }
}
```

Jenis yang belum didukung pada endpoint detail ini:
- `PBB`
- `Opsen PKB`
- `Opsen BBNKB`

## Setup

1. Install dependency:

```bash
composer install
```

2. Buat file environment:

```bash
cp .env.example .env
```

3. Isi konfigurasi database di `.env`, terutama:
- `DB_*` untuk database utama yang menyimpan `STS_History`
- `DB_SIMPADA_*` untuk koneksi opsen
- `DB_EPBB_*` untuk koneksi PBB

4. Generate app key:

```bash
php artisan key:generate
```

5. Jalankan aplikasi:

```bash
php artisan serve
```

## Catatan Implementasi

- target realisasi saat ini masih didefinisikan statis di controller
- jika koneksi `epbb_db` atau `simpada` gagal, nilai realisasi dari sumber tersebut akan dianggap `0`
- jika struktur response berubah, dokumentasi di folder `docs/` perlu ikut diperbarui
