# Feature Breakdown & Acceptance Criteria

## 1. Dashboard & Overview
- **Fitur:** Statistik blast, Delivery Rate, Read Rate, sisa saldo real-time.
- **Limitasi:** Chart mengambil data dari tabel agregat (cron-based), bukan query langsung ke raw `message_logs` agar tidak membebani server.

## 2. Manajemen Kontak (Import Excel/CSV)
- **Fitur:** Import massal dengan template siap unduh.
- **Limitasi:** Wajib ada script auto-sanitasi nomor ke format E.164 (628xxx). Jika ada baris error di Excel, sistem melakukan *skip* pada baris tersebut tanpa membatalkan keseluruhan file.

## 3. Template Management
- **Fitur:** Generator template & sinkronisasi status API.
- **Limitasi:** Hanya template berstatus `Approved` dari webhook `api.co.id` yang bisa muncul di dropdown saat pembuatan campaign.

## 4. Wallet & Billing (Midtrans)
- **Fitur:** Top-Up otomatis terintegrasi.
- **Limitasi:** Minimum top-up sistem diterapkan. Campaign dilarang berjalan jika kalkulasi (Total Kontak valid × Harga Kategori Template) melebihi saldo aktif.

## 5. Campaign & Blast Engine
- **Fitur:** Pengaturan waktu pengiriman (Scheduler) & eksekusi massal.
- **Limitasi:** Worker berjalan asinkron via Redis. Memiliki rate limiter bawaan berdasarkan *tier* akun WABA.

## 6. Chatbox (Jendela 24 Jam)
- **Fitur:** Real-time chat berbasis WebSocket (Laravel Reverb).
- **Limitasi:** Input teks terkunci otomatis (disabled) jika timestamp pesan terakhir klien melewati batas 24 jam.

## 7. Sandbox Testing
- **Fitur:** Pengiriman 1 request sinkron untuk melihat render UI template ke nomor admin (tanpa queue).

## 8. Webhook Handler & Error Logs
- **Fitur:** Menangkap *Sent, Delivered, Read, Failed*.
- **Limitasi:** Log error ditampilkan eksplisit di dashboard (contoh: "Saldo kurang", "Format nomor salah", "Banned Meta").
