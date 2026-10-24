# ED-1250 (Story, Medium, label v5) - Archive - filter Verification Status (Verified / Unverified)

Feedback client, disampaikan developer 2026-10-23 (teks asli developer, disalin epic loop):

> tambah filter "Verification Status", tipe nya select, isi pilihannya: `Verified` dan `Unverified`, filter nya optional, default nya kosong (artinya semua Verification Status tampil). ketika filter ini aktif maka harus memunculkan document dan folder yang memiliki document dengan status verifikasi sesuai filter. filter ini sifatnya hanya menyembunyikan document yang status verifikasinya tidak sesuai dengan yang ada filter, jadi tidak mengubah struktur folder seperti filter yang lain. counter di kolom "Document Verified" untuk tipe data folder pun tetap sama, menghitung semua nya bukan hanya yang difilter saja ketika filter ini aktif. oiya filter ini juga di hide ketika user tidak memiliki permission Opname Document di Archive, sama seperti column Document Verified yang hilang ketika tidak memiliki permission Opname Document di Archive

Deskripsi Jira berisi hal yang sama dalam bentuk butir; rujukan kolom Document Verified: ED-1027.
