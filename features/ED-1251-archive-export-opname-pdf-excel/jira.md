# ED-1251 (Story, Medium, label v5) - Archive - export PDF & Excel hasil opname (drawer Opname History & Opname Result)

Feedback client, disampaikan developer 2026-10-23 (teks asli developer, disalin epic loop):

> tambah export excel dan pdf untuk data opname archive. di drawer "Opname History" tambah 1 kolom fixed disebelah kanan yang isinya tombol print (icon only) dan tombol excel (icon only). di drawer "Opname Result" tambahkan tombol print (icon only) dan tombol excel (icon only) sejajarh dengan title "Opname Result" dengan jarak `space-between`  jadi ada div untuk membungkus title "Opname Result" dengan button-button export. untuk tampilan export pdf dan excel nya mirip Purchase Order Report di v5.
>   * untuk header nya nanti seperti ini per baris nya (ini hanya contoh ya, nanti kamu sesuaikan):
>      * Sido Maju Lestari
>      * Opname Archive
>      * Folder: root + 3 subfolder
>      * Opname Date: 21 Oct 2026 13:18
>      * Opname By: admintest
>   * untuk kontennya nanti ada 2 table:
>      * 1. table summary isinya: Total Documents, Verified, Not Found, Invalid, Not Scanned (sama seperti tampilan summary di drawer "Opname Result" yang sekarang). Untuk table ini di pdf maupun di excel tidak perlu diberi warna seperti di FE
>      * 2. table detail isinya: Document No., Transaction Type, Folder, Time, Result. Untuk table ini di pdf maupun di excel tidak perlu diberi warna seperti di FE. cukup hitam putih saja. kolom result pun tidak perlu diwarnai. row/cell nya juga tidak perlu diwarnai seperti di FE
>   * 2 table diatas masuk `column_display_settings` tapi saat ini memang belum ada setting di FE nya, jadi hanya dari sisi BE saja

Deskripsi Jira berisi hal yang sama dalam bentuk butir; rujukan drawer Opname History: ED-1028, Opname Result: ED-1026.
