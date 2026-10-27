# ED-1293 (Task, Medium, label v5) - Archive FE - filter Verification Status kembali ke root seperti filter lain (halaman & picker)

Feedback QA manusia, disampaikan developer 2026-10-27 (teks asli developer):

> ada feedback dari QA manusia
> sebelumnya filter "Verification Status" tidak kembali ke root seperti filter lainnya. nah supaya konsisten, tolong ubah filter "Verification Status" supaya kembali ke root seperti filter lainnya

Keputusan developer 2026-10-27 (dijawab sebelum BA, tabel pertanyaan epic loop):
1. Sesudah kembali ke root: **a** - struktur tetap (folder root + hanya dokumen root yang sesuai; bukan hasil datar). Hanya FE yang berubah.
2. Sesudah di root, membuka folder / breadcrumb: **a** - filter tetap aktif (ED-1250 K-1 a tetap).
3. Picker Pindahkan / Pilih Folder: **a** - ikut kembali ke root.
4. Jira: **a** - item baru di epic ED-1022 (ED-1250 tetap Done).
Digabung filter lain: tidak berubah (datar dari root seperti sekarang).
