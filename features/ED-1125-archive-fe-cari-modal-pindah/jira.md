# ED-1125 (Bug, label ai-found) - Archive FE - Cari di modal Pindahkan / Pilih Folder pindah massal memuat ulang aplikasi (form bersarang)

Deskripsi Jira (disalin epic loop 2026-10-14):

Ditemukan saat long run epic ED-1022 (item ED-1069: QA FE, e2e Playwright), di luar cakupan ED-1069. FE tidak berubah di ED-1069 (`ksp-react` branch `next-canvasing-opname-archive`), jadi perilaku ini sudah ada sebelumnya.

**Gejala:** di modal **Pindahkan** (⋯ → Pindahkan) dan di langkah **Pilih Folder** pindah massal, mengetik di kolom **Cari** lalu Enter (atau ikon filter → tombol Cari) memicu submit form HTML asli (CDP `formSubmissionGet http://<host>/?`). Seluruh aplikasi dimuat ulang, modal dan keadaan layar hilang.

**Reproduksi:** Archive → ⋯ Pindahkan pada folder mana pun → ketik teks di kolom Cari dalam modal → Enter.

**Penyebab yang terlihat:** form `Search` (`ant-form-horizontal`, dengan `<button type="submit" hidden>`) bersarang di dalam form modal (`ant-form-vertical`); submit-nya tidak dicegah sehingga menjadi navigasi asli. Dugaan berkas: `ArchiveMoveModal/ArchiveMoveModalForm.js` (`<Form>` pembungkus `ArchiveTable`), `ArchiveBulkMoveModal/ArchiveBulkMoveModalForm.js`, `archive-components/ArchiveTable/index.js` (`<Search>`), `components/Search/Search/index.js`.

**Dampak:** folder yang hanya terjangkau lewat pencarian tidak bisa dipilih dari UI; penolakan siklus ED-1069 (pindah folder ke turunannya, `ARCHIVE404`) saat ini juga tidak bisa dipicu dari UI karena turunan folder sumber hanya terjangkau lewat Cari.

**Usulan:** cegah submit asli (mis. `onSubmit` + `preventDefault`, atau `Search` tanpa elemen `<form>` di dalam form modal), lalu uji ulang Enter dan tombol Cari di kedua modal.

Rujukan: `agentic/features/ED-1069-archive-validasi-pindah-rename-folder/qa-fe.md` § Bug di luar item (B-FE-1); test karakterisasi "karakterisasi B-FE-1" di `qa/e2e/ED-1069-validasi-pindah.spec.js` (sengaja gagal bila bug sudah diperbaiki).
