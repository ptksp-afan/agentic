# ED-1070 + jalur `()` - temuan untuk diskusi tim

> **Dokumen internal.** Berisi detail celah yang belum ditambal. Jangan dibagikan ke luar tim.

**Sumber:**
- BA ED-1070, diperbarui 2026-10-14, BE `ksp-erp` branch `v5-opname-archive` (`6fcc91fd0`).
- Reproduksi HTTP di DB uji `api_sidomaju`.
- Spec: `features/ED-1070-v5-customize-builder-hardening/spec.md`.
- Jalur `()` = keputusan A-3 di `knowledge/decisions.md` (dulu ED-1144, tiketnya sudah dihapus).

**Kode yang dibahas:** trait `Modules/V5/Entities/Traits/CustomizeBuilder.php`, yaitu `scopeSearchAll` dan `scopeSortAll`.
- Dipakai ±190 model V5: `searchAll` dipanggil 173 kali di 155 berkas, `sortAll` 185 kali di 165 berkas.
- Hampir semua list v5 lewat sini.
- Salinan trait di **V4** (`Modules/V4/Entities/Traits/CustomizeBuilder.php`, ±125 berkas V4) punya cabang `()` yang sama di `:101-102`.
- FE memakai V5, bukan V4.

**Arti kolom Bukti:**
- *Terbukti* = direproduksi lewat HTTP.
- *Dari kode* = disimpulkan dari membaca kode; sengaja tidak dijalankan karena merusak data.

## 1. Temuan

| # | Temuan | Contoh `GET ...?search=` / parameter | Hasil sekarang | Bukti | Siapa yang bisa memicu | Dampak | Risiko |
|---|---|---|---|---|---|---|---|
| 1 | **Jalur `()`**: kunci `search` berakhiran `()` memanggil method query builder **apa pun** dengan nilai dari user (`CustomizeBuilder.php:154-156`) | `{"delete()":1}`, `{"update()":{"kolom":"x"}}`, `{"truncate()":"x"}`, `{"whereRaw()":"..."}` | Method dijalankan pada query list | Dari kode (V5 dan V4) | User login dengan akses ke list v5 mana pun, lewat **GET** | Hapus/ubah massal baris yang terjangkau query list, TRUNCATE tabel, SQL mentah. Data hilang tanpa error yang jelas. | **Kritis** |
| 2 | **Filter relasi** `{"<kunci>":{...}}`: kunci yang bukan kolom diperlakukan sebagai nama relasi, lalu `orWhereHas(kunci)` memanggil `$model-><kunci>()` tanpa cek bahwa itu relasi (`:164-171`) | `{"latest":{"a":"b"}}`, `{"getTable":{"a":"b"}}`, `{"truncate":{"a":"b"}}` | `latest` dan `getTable` **dijalankan**, lalu 500. `truncate` akan men-TRUNCATE tabel list. | `latest`/`getTable` terbukti; `truncate` dari kode | Sama dengan #1 | Sama kelasnya dengan #1: method model dijalankan dari input user | **Tinggi** |
| 3 | **Kunci `sum*`**: nama kunci ditempel ke SQL mentah `DB::raw('COALESCE(' . $attribute . ', 0)')` (`:161`); hanya dilewatkan `Str::snake` (spasi dihapus, tidak disaring) | `{"sumXyz":[...]}` dengan nama kunci berisi SQL | SQL dari nama kunci ikut dieksekusi | Dari kode | Sama dengan #1 | Rawan injeksi SQL | **Tinggi** |
| 4 | `search` bukan objek JSON | `bukan-json`, `123`, `"str"` | 500 `INVALID_DATA` (exception tanpa kode HTTP) | Terbukti (archives, customers, purchase-orders) | Siapa saja | Error 500, log/monitoring berisik; data tidak berubah | Rendah |
| 5 | Isi `search` bertipe salah | `{"query":["a"]}`, `{"zzz":["a"]}`, `{"subXyz":"a"}`, `{"sumXyz":"a"}` | 500 (Array to string, BadMethodCall, Undefined var) | Terbukti | Siapa saja | Sama dengan #4 | Rendah |
| 6 | `sorts` salah bentuk | `sorts=bukan-json`, `{"name":"asc"}`, `sorts[0]={"sortBy":"name"}` tanpa `sortType` | 500 `INVALID_DATA` / Illegal offset | Terbukti | Siapa saja | Sama dengan #4 | Rendah |
| 7 | `pagination` dipakai mentah ke `paginate()` (`ArchiveService.php:32`, `:128`; pola sama 160× di 143 berkas) | `pagination=abc`, `[]=1`, `-1` | 500 (`-1` = SQL syntax error). `0` dan `2.5` diterima (200). | Terbukti di archives | Siapa saja | Sama dengan #4 | Rendah |
| 8 | Range tanggal tidak valid / 1 elemen, dan kolom tak dikenal di filter relasi | `{"updatedAt":["abc","def"]}`, `["2026-01-01"]`, customers `{"location":{"zzz":"a"}}` | 500 | Terbukti | Siapa saja | Sama dengan #4 | Rendah |
| 9 | **Bug FE nyata:** sort kolom "Karyawan terkait" di list Pelanggan. Sort relasi memakai PK model relasi sebagai FK (`:235-243`). | `sorts[]={"sortBy":"firstName","sortType":"desc"}` (dikirim FE sendiri) | 500 | Terbukti | User biasa, dari UI | Fitur sort di layar Pelanggan rusak | Sedang |
| 10 | Bentuk aneh tapi diterima | `search=[1,2]` | 200 (archives: mode cari tanpa filter) | Terbukti | Siapa saja | Tidak konsisten; tidak berbahaya | Rendah |
| 11 | Butir 2 Jira: folder menjadi induk dirinya sendiri | `PUT archives/{id}` dengan `id_archive_parent` = id itu sendiri | **Sudah beres di ED-1069** (400 `ARCHIVE404`) | Terbukti | - | - | - |

**Catatan:**
- Request sah yang dikirim FE (59 request di 6 list, direkam 2026-10-14) tidak memakai bentuk #1–#8. Perbaikan #1–#8 tidak mengubah hasil list yang sah.
- Pindai statis: tidak ada kode FE maupun BE V5 yang memakai kunci `()`. Klien lain di luar FE (aplikasi mobile, integrasi) tidak bisa dipastikan dari repo ini.
- Pembanding: `GET document-archive/opnames` (ED-1028) sudah menolak bentuk salah dengan 422.

## 2. Opsi perbaikan: berkas yang diubah vs jangkauan perilaku

Hampir semua opsi hanya mengubah 1–2 berkas. Yang luas adalah **jangkauan perilakunya**, karena trait dipakai bersama semua list v5.

| Opsi | Berkas yang diubah | Yang berubah perilakunya | Risiko perubahan |
|---|---|---|---|
| Tutup jalur `()` (#1) | Trait V5, 1 cabang (`:154-156`). Kalau V4 ikut: + trait V4 (`:101-102`) | Semua list yang menerima kunci `()`. Tidak ada pemakai sah di FE/BE V5 (pindai statis). | Rendah, kecuali ada klien luar yang memakainya |
| #2 hanya relasi yang dideklarasikan (rek. K-5 b) | Trait V5, 1 method | Filter relasi di semua list. FE hanya mengirim relasi yang memang ada, jadi tidak berubah. | Rendah |
| #3 kunci `sum*`/`sub*` hanya `[a-z0-9_]` | Trait V5 | Semua list; FE tidak mengirim nama aneh | Rendah |
| #4–#8 bentuk salah → 422 (rek. K-1 b + K-2 a) | 2 berkas: trait + `ArchiveService.php` | Semua list, **hanya untuk request salah bentuk** (500 → 422) | Rendah |
| K-1 a: middleware v5 baru untuk `pagination`/`search` | Trait + middleware baru + pendaftaran di route v5 | **Semua endpoint GET v5**, bukan hanya list. Pola baru. | Sedang |
| K-1 c: Archive saja | `ArchiveService.php` | List Archive saja; list lain tetap 500 | Rendah, tapi masalah tetap ada di tempat lain |
| #9 sort "Karyawan terkait" (K-7) | Trait (sort relasi) | **Urutan list yang sah ikut berubah** di list yang men-sort kolom relasi | Sedang, karena itu rek. tiket terpisah |

## 3. Keputusan yang perlu diambil tim

| # | Pertanyaan | Opsi | Rekomendasi BA | Risiko |
|---|---|---|---|---|
| A-3 | Jalur `()` (#1): tetap diterima, atau ditutup? | a) tutup di V5; b) tutup di V5 + V4; c) tetap diterima | b (pemakai sah tidak ditemukan; dampaknya penghapusan data lewat GET) | **Kritis** bila dibiarkan |
| K-5 | Filter relasi (#2), `sum*` (#3) | a) abaikan bila method tidak ada (`truncate` tertutup, tapi `save`/`touch` tetap terpanggil); b) hanya relasi yang dideklarasikan + nama `sum*`/`sub*` dibatasi; c) biarkan | b | **Tinggi** bila dibiarkan |
| K-1 | Cakupan perbaikan bentuk salah (#4–#8) | a) trait + middleware baru; b) trait untuk semua list + `pagination`/`search` di archives, 142 service lain jadi tiket lanjutan; c) Archive saja; d) tunda | b | Rendah |
| K-2 | Bentuk penolakan | a) 422 validasi Laravel (sama dengan `GET opnames`); b) 400 + kode pesan baru `GE0749` | a | Rendah |
| K-3 | Isi bertipe salah | a) diabaikan, `sortType` hilang = desc; b) 422 | a | Rendah |
| K-4 | Yang sekarang 200 (`search=[1,2]`, `pagination=0`/`2.5`) | a) jadi 422; b) tetap 200 | a | Rendah (klien yang mengirim bentuk ini jadi kena 422) |
| K-6 | Tanggal/kolom tak dikenal (#8) | a) tiket lanjutan; b) ikut di sini | b | Rendah |
| K-7 | Sort "Karyawan terkait" (#9) | a) perbaiki di sini; b) bug terpisah | b | Sedang |

## 4. Yang perlu dicek tim sebelum memutuskan

- Apakah ada klien selain FE `ksp-react` (aplikasi mobile, integrasi pihak ketiga, skrip internal) yang memanggil list V4/V5 dengan kunci `()` atau filter relasi non-standar.
- Apakah route V4 yang memakai trait V4 masih terbuka di produksi.
- Prioritas: #1–#3 menyangkut keamanan data, sedangkan #4–#10 menyangkut kerapian error. Keduanya bisa dipisah menjadi dua item kalau perlu.
