# ED-1072 (Bug, label ai-found) - Archive FE - klik tombol ⋯ pada baris dokumen ikut memicu klik sel dokumen (breadcrumb & pilihan baris berubah)

Deskripsi Jira (disalin epic loop 2026-10-08):

Ditemukan saat long run epic ED-1022 (item ED-1025, FE dev dan QA FE ronde 1), di luar cakupan ED-1025. Perilaku sudah ada sebelum ED-1025 (kode di HEAD branch `next-canvasing-opname-archive`); tidak diperbaiki di ED-1025.

**Gejala:** di halaman Archive, klik tombol ⋯ (menu aksi) pada baris **dokumen** ikut menjalankan klik sel dokumen: baris dokumen terpilih (`setSelectedRowKeys`) dan breadcrumb diganti breadcrumb dokumen. Harapan: membuka menu aksi saja, tanpa efek klik sel.

**Dugaan penyebab:** `src/containers/Archive/ArchivePage/index.js` - kolom memasang `onCell` dengan `onClick: (e) => onClickDocument(e, record)` untuk baris dokumen (HEAD `:302-306`, `:350-352`), sedangkan klik tombol ⋯ di sel itu tidak menghentikan propagasi; `onClickDocument` (HEAD `:258-275`) memanggil `setSelectedRowKeys([record.idArchive])` dan `setBreadcrumbs(...)`.

**Usulan:** hentikan propagasi klik pada tombol/menu ⋯ (`e.stopPropagation()`), atau abaikan klik yang berasal dari elemen aksi di `onClickDocument`. Uji di browser (jsdom tidak membuka menu ini).

Rujukan: `agentic/features/ED-1025-archive-folder-permission/qa-fe.md` § Catatan (C-5); task `ksp-react/docs/specs/Archive/002-feat-folder-access-denied.md` (Daftar tes UI butir 7).
