# ED-1071 (Bug, label ai-found) - V5 - input berkarakter di luar latin1 (emoji, CJK, Yunani) = 500 "Illegal mix of collations" (Archive dan tabel latin1 lain)

Deskripsi Jira (disalin epic loop 2026-10-08):

Ditemukan saat long run epic ED-1022 (item ED-1025, QA BE ronde 2-3), di luar cakupan ED-1025. Perilaku sudah ada sebelum ED-1025 (kueri yang sama di HEAD branch `v5-opname-archive`); tidak diperbaiki di ED-1025.

**Gejala:** string dengan karakter di luar Windows-1252 (Yunani `Ω`, CJK, emoji, aksen gabung `e`+U+0301, tanda RTL U+202E) pada nilai yang dipakai sebagai pembanding SQL → HTTP 500 `SQLSTATE[HY000]: 1267 Illegal mix of collations (latin1_general_ci,IMPLICIT) and (utf8mb3_unicode_ci,COERCIBLE)`.
Contoh: `PUT api/v5/document-archive/archives/{id}` / `POST .../archives/create-folder` dengan `name` = `"<nama>Ω"`; juga `id_archive_parent`, `id_locations[]`, `folder_permissions.*.id_user`, put-in (`id_archive_parent`, `id_archives[]`, `name`), `GET archives?id_archive=...`, `GET archives?search={"query":"..."}`, `GET archives/{id}`, `history/{id}`, `DELETE delete/{id}` (75 dari 80 kasus uji). Rename lolos tetapi karakter tersimpan sebagai `?`. Karakter yang ada di Windows-1252 (`ü`, `’`, `—`, `€`, `™`) aman.

**Termasuk aturan baru ED-1025:** `folder_permissions.*.id_user` → `exists:users,id_user` di `UpdateRequest` (tab Permission folder) kena akar yang sama (5 dari 75 kasus). Perbaikan tiket ini harus mencakup aturan itu juga.

**Dugaan penyebab:** kolom tabel `archives*` / `users` / `locations` berkolasi `latin1_general_ci`, koneksi `utf8`/`utf8_unicode_ci` (`config/database.php:52-53`); kueri pembanding mis. `Rules/DocumentArchive/Archive/UniqueNameRule.php` (`Archive::where('name', $value)`), aturan `exists`, `DocumentArchiveService::findVisible`. Kemungkinan berlaku di semua modul v5 yang membandingkan input dengan kolom latin1.

**Harapan:** 4xx yang jelas (mis. 422 "karakter tidak didukung") atau input diterima tanpa 500. Keputusan arah (validasi karakter vs konversi kolasi) ada di developer.

Rujukan: `agentic/features/ED-1025-archive-folder-permission/qa-be.md` § Dilacak di luar item (D-4); skenario `qa/scenario5-input-types.php` X-12 (karakterisasi bertag ED-1071). Juga ED-1029 QA BE D-1 (id non-latin1 → 500) dikomentari di tiket ini.
