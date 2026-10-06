# Contoh prompt - Archive: Opname (epic ED-1022)

Konteks dari developer (2026-10-06):
- **Archive** adalah module yang **berdiri sendiri** (menu sendiri), **bukan** bagian Stock Opname.
- Archive hanya untuk lisensi dengan fitur **Salesman Activity**.
- Brief berupa export Claude Design yang sudah di-extract di `ksp-react/docs/briefs/Archive/Opname`.
  Path ini sudah benar: `Archive` adalah grup tersendiri di `docs/briefs/` (isinya tidak ikut git).

Kondisi repo per 2026-10-06:
- Epic ED-1022 "Next Opname Archive" masih kosong.
- BE `v5-opname-archive` belum memakai RoadRunner (tidak punya `app/RoadRunner/`), jadi `BE_SERVER=fpm`.
  Kalau branch itu nanti di-merge dengan `v5-rr`, ganti ke `rr`; workflow mendukung keduanya.
- FE `next-canvasing-opname-archive` masih sama dengan `next-canvasing`.

## Sebelum menjalankan

1. Commit `ksp-erp/.claude/**` ke `v5-opname-archive` dan isi `ksp-react/` ke
   `next-canvasing-opname-archive`. Tanpa itu `scripts/preflight.sh` FAIL.
2. `config/workspace.env` di mesin itu (sesuaikan path, PHP, URL):
   ```
   BE_DIR=/var/www/opname-archive/ksp-erp
   BE_BRANCH=v5-opname-archive
   FE_DIR=/var/www/opname-archive/ksp-react
   FE_BRANCH=next-canvasing-opname-archive
   PHP_BIN=/usr/bin/php7.3
   API_URL=http://127.0.0.1:<port BE>
   BE_SERVER=fpm                    # branch ini tanpa RoadRunner; rr kalau servernya RoadRunner
   BE_RELOAD_CMD=                   # isi hanya bila php-fpm perlu di-reload sesudah ubah kode
   FE_STAGING_DIR=/tmp/ksp-react-opname-build
   QA_DB=<db uji>
   QA_DBS=<db uji>
   BA_PARALLEL=2
   ```
3. **Uji dengan dan tanpa lisensi Salesman Activity** (`knowledge/license-features.md`). Siapkan dua set
   berkas lisensi uji, lalu:
   ```
   PROFILES=sa,nonsa
   PROFILE_DEFAULT=sa
   PROFILE_sa_LIC_DIR=<folder lisensi dengan Salesman Activity>
   PROFILE_sa_DB=<db uji>
   PROFILE_nonsa_LIC_DIR=<folder lisensi tanpa Salesman Activity>
   PROFILE_nonsa_DB=<db uji>
   ```
   Tanpa profil ini, AC "tanpa lisensi" dilaporkan *tidak bisa diverifikasi* di Gate 2.

## Teks brief

```
Archive - Opname (epic ED-1022).
Archive adalah module baru yang berdiri sendiri (menu sendiri), bukan bagian dari Stock Opname, dan
hanya tersedia untuk lisensi dengan fitur Salesman Activity (tanpa fitur itu: menu tidak tampil, route
dan API menolak).
Desain: export Claude Design (sudah di-extract) di
/var/www/opname-archive/ksp-react/docs/briefs/Archive/Opname/
Desain dibuat tanpa design system EQUAL v5: ambil layar, field, kolom, aksi, dan alurnya; tampilan
mengikuti FE v5.
Tujuan: <masalah yang diselesaikan dan siapa pemakainya, 1-2 kalimat>.
Cakupan: <yang masuk>. Di luar cakupan: <mis. Stock Opname yang sudah ada tidak diubah>.
Aturan yang tidak terlihat di desain: <data apa yang diarsipkan dan kapan, siapa boleh melihat /
mengembalikan, masa simpan, permission>.
```

Teks ini boleh diketik banyak baris langsung di perintah (Shift+Enter). Cara yang lebih enak: simpan
sebagai `/var/www/opname-archive/ksp-react/docs/briefs/Archive/Opname/BRIEF.md` di samping export
desain, lalu cukup rujuk path-nya (contoh di bawah). Dengan begitu, `--dry-run` dan run sesungguhnya
memakai brief yang sama tanpa mengetik ulang.

Bentuk pendek dengan berkas brief:
```
/v5-feature new /var/www/opname-archive/ksp-react/docs/briefs/Archive/Opname/BRIEF.md --epic ED-1022
/v5-epic ED-1022 --dry-run --brief /var/www/opname-archive/ksp-react/docs/briefs/Archive/Opname/
```
Kalau yang dirujuk folder, `BRIEF.md` di dalamnya dibaca sebagai teks brief, dan sisanya (export
Claude Design) sebagai sumber.

## Reguler run (satu Story)

```
/v5-feature new "<teks brief>" --epic ED-1022
```

Hanya sebagian, tambahkan misalnya: `Kerjakan hanya layar daftar arsip dan detail; layar lain menyusul.`

Alurnya: brief dibaca → BA → **Gate 1** (Story dibuat di ED-1022) → BE → QA BE → FE → QA FE (dengan dan
tanpa lisensi Salesman Activity) → **Gate 2** → commit → Done. Dev dan QA bergiliran, satu sekali waktu.

## Long run (seluruh epic)

```
/v5-epic ED-1022 --dry-run --brief "<teks brief>. Pecah jadi Story per kemampuan yang bisa dirilis sendiri; mulai dari fondasi data dan akses lisensi."
```
`--dry-run` hanya membaca brief dan menampilkan rencana item (`epics/ED-1022-plan.md`). Kalau pas:

```
/v5-epic ED-1022 --brief "<teks brief yang sama>" --max 2
```

Kamu menyetujui rencana → BA semua item → **Gate 1 gabungan** → long run tanpa kamu (per item: BE → QA
BE → FE → QA FE → commit lokal) → blocker di kolom Blocked bila ada →

```
/v5-epic review ED-1022
```

`--max 2` untuk percobaan pertama, supaya biaya dan waktu per item bisa diukur.
