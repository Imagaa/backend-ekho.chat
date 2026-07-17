# ATURAN MUTLAK KODE (AI AGENT INSTRUCTIONS)

**SISTEM & STACK:** Laravel 12 (PostgreSQL, Redis) & Next.js 14.

**ATURAN MUTLAK EDITING KODE (BERLAKU KETAT):**
1. **Berbasis GitHub:** File yang diunggah user adalah SOT (Source of Truth). JANGAN ngoding dari nol. Jika konteks hilang, minta user unggah ulang file aslinya.
2. **Haram Menghapus:** DILARANG menghapus/mengubah [DOKUMENTASI BACKEND], komentar API, atau UI/State/Fitur eksisting yang tidak berkaitan dengan revisi.
3. **Edit Terisolasi:** Gunakan instruksi 'Cari baris ini... Ubah menjadi...'.
4. **Batch Editing:** Jika ada banyak fitur yang merevisi satu file yang sama, BERIKAN KODE PERUBAHAN SECARA BERSAMAAN DALAM SATU WAKTU agar proses revisi efisien. Komunikasi brutal, efisien, tanpa basa-basi.

**KRITIKAL TEKNIS (BACKEND):**
- **Transaksi Saldo/Wallet:** WAJIB implementasi Pessimistic Locking (`lockForUpdate`) minimal 15 menit untuk semua operasi pemotongan saldo saat eksekusi blast.
- **Queue Worker:** Harus ada *auto-release*, *rate-limiting* dinamis Meta (Redis throttle), dan sistem retry untuk mencegah *drop message*.
- **Performa DB:** Anti N+1 mutlak (Gunakan `preventLazyLoading()` di AppServiceProvider).
- **Inbound DLR (Webhook):** Endpoint webhook HARUS sangat ringan. Hanya validasi payload -> lempar ke Redis Queue.
