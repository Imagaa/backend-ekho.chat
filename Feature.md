# Feature Breakdown & Acceptance Criteria

> ✅ **Provider WABA: api.co.id** (BSP resmi, model reseller — Ekho 1 akun master,
> jual kembali ke tenant lewat wallet). Integrasi teknis (§5, §6, §8 di bawah)
> **sudah diimplementasikan di kode, belum pernah ditest traffic asli** —
> detail lengkap di [documentation.md §7](documentation.md#7-waba-provider-integration-apicoid).

## 1. Dashboard & Overview
- **Fitur:** Statistik blast, Delivery Rate, Read Rate, sisa saldo real-time.
- **Limitasi:** Chart mengambil data dari tabel agregat (cron-based), bukan query langsung ke raw `message_logs` agar tidak membebani server.

## 2. Manajemen Kontak (Import Excel/CSV)
- **Fitur:** Import massal dengan template siap unduh.
- **Limitasi:** Wajib ada script auto-sanitasi nomor ke format E.164 (628xxx). Jika ada baris error di Excel, sistem melakukan *skip* pada baris tersebut tanpa membatalkan keseluruhan file.

## 3. Template Management
- **Fitur:** Buat + ajukan template ke Meta lewat api.co.id dalam 1 aksi (`POST /templates`), cek status approval manual (`GET /templates/{id}/refresh`). Frontend: galeri contoh siap-pakai per kategori (Marketing/Utility/Authentication) dengan penjelasan kenapa kemungkinan besar disetujui, plus edukasi CTA & jendela 24 jam.
- **Limitasi:** Hanya template berstatus `APPROVED` yang muncul di dropdown saat pembuatan campaign. Sinkronisasi status **manual** (tombol refresh) — confirmed vendor (2026-07-24) memang tidak akan pernah ada webhook untuk status template, desain manual ini sudah final, lihat `documentation.md §7`. Header dengan media (gambar/video) belum didukung, cuma header teks.

## 4. Wallet & Billing (Midtrans)
- **Fitur:** Top-Up otomatis terintegrasi.
- **Limitasi:** Minimum top-up sistem diterapkan. Campaign dilarang berjalan jika kalkulasi (Total Kontak valid × Harga Kategori Template) melebihi saldo aktif.

## 5. Campaign & Blast Engine
- **Fitur:** Pengaturan waktu pengiriman (Scheduler) & eksekusi massal.
- **Limitasi:** Worker berjalan asinkron via Redis. Rate limit api.co.id **60 pesan/menit per akun** (bukan per tenant — limitnya di level akun master Ekho yang dipakai bersama semua tenant). Throttle global sudah diimplementasikan di `ProcessWaBlast` (1 pesan/detik). Lihat [documentation.md §7](documentation.md#7-waba-provider-integration-apicoid).

## 6. Chatbox (Jendela 24 Jam)
- **Fitur:** Real-time chat berbasis WebSocket (Laravel Reverb).
- **Limitasi:** Input teks terkunci otomatis (disabled) jika timestamp pesan terakhir klien melewati batas 24 jam — aturan resmi WhatsApp, bukan kebijakan Ekho.

## 7. Sandbox Testing
- **Fitur:** Pengiriman 1 request sinkron untuk melihat render UI template ke nomor admin (tanpa queue).
- **Catatan:** Belum terkonfirmasi apakah api.co.id punya endpoint khusus untuk ini — perlu dicek dokumentasi mereka lebih lanjut sebelum implementasi, jangan asumsikan tersedia.

## 8. Webhook Handler & Error Logs
- **Fitur:** Menangkap *message.sent, message.delivered, message.read, message.failed* (event terpisah per status, bukan satu array status seperti Meta native).
- **Limitasi:** Log error ditampilkan eksplisit di dashboard (contoh: "Saldo kurang", "Format nomor salah", "Banned Meta").
- **Kritis:** Webhook api.co.id **auto-nonaktif setelah 10 kali gagal berturut-turut** (1 sukses reset counter). Satu webhook dipakai SEMUA tenant — kalau nonaktif, semua tenant kehilangan pesan masuk sekaligus. Wajib ada monitoring operasional (lihat §9).

## 9. Onboarding Nomor WhatsApp Tenant (Model "Assisted")
- **Fitur:** Tenant mengajukan nomor WhatsApp baru lewat form di dashboard Ekho, lalu dipandu (bukan digantikan) melalui proses Embedded Signup Meta — tenant WAJIB login Facebook pribadi sendiri, Ekho tidak bisa/boleh melakukan ini atas nama tenant.
- **Alur:** Tenant isi form → Ekho cek syarat → Ekho staf proses di dashboard api.co.id (Embedded Signup, tenant verifikasi OTP) → api.co.id terbitkan `whatsapp_phone_number_id` → Superadmin input ke record tenant → tenant siap kirim/terima pesan.
- **Syarat yang harus disiapkan tenant** (wajib ditampilkan jelas di UI, target audiens awam): akun Facebook pribadi, nomor WhatsApp yang belum aktif di WA/WA Business biasa, akses terima OTP, nama bisnis sesuai kebijakan Meta, opsional dokumen legal (NIB/SIUP) untuk limit kirim lebih tinggi.
- **Limitasi:** Pendaftaran nomor baru di api.co.id bisa ditutup sementara kalau ada gangguan sistem di sisi Meta — cek status ini sebelum menjanjikan kecepatan onboarding ke tenant.
- **Belum diimplementasikan:** halaman "Ajukan Nomor WhatsApp Baru" di frontend tenant.

## 10. Tanggung Jawab Operasional Vendor (Ekho)
- **Fitur:** Bukan fitur produk, tapi proses wajib yang harus dijalankan tim Ekho supaya platform tetap sehat:
  - Monitor kesehatan webhook (auto-disable, lihat §8) — prioritas tinggi
  - Kelola submit & approval template ke Meta
  - Pantau quality rating nomor per tenant (GREEN/YELLOW/RED), proaktif ingatkan tenant kalau turun
  - Enforce & catat consent pelanggan sebelum blast MARKETING
  - Rekonsiliasi biaya bulanan api.co.id vs tagihan wallet tenant

## 9. Superadmin Dashboard (Implemented)
- **Fitur:** Panel internal tim Ekho (Filament, subdomain `admin.ekho.imaga.site`, terisolasi total dari auth tenant — lihat [AGENTS.md §SUPERADMIN DASHBOARD](AGENTS.md)) untuk:
  - Manajemen Tenant — list, detail (kredensial WABA di-mask, reveal eksplisit & ter-log), suspend/reaktivasi.
  - Manajemen User — list lintas tenant, **membuat akun user baru** (assign tenant + role), revoke token aktif.
  - Manajemen Admin — CRUD sesama akun superadmin. Tidak ada self-registration; akun pertama dibuat via `php artisan admin:create`.
  - Audit Log — setiap aksi admin tercatat (siapa, apa, kapan, IP, before/after), searchable.
  - Log & Config Viewer — baca `storage/logs/laravel.log` (read-only) dan config non-secret (read-only).
- **Acceptance Criteria Keamanan (wajib, non-negotiable):**
  - Autentikasi terpisah total dari tenant — tabel `admin_users` + guard `admin`, tidak ada shared session/token dengan `users`/`sanctum`.
  - 2FA (TOTP) wajib untuk semua akun admin, tidak bisa dinonaktifkan dari UI.
  - Tidak ada endpoint publik untuk registrasi admin.
  - Kredensial tenant (`waba_api_key`) tidak pernah tampil plaintext tanpa aksi "reveal" eksplisit yang ter-log.
- **Limitasi (disepakati eksplisit dengan user):** Scope "manajemen server" **sengaja dibatasi** ke baca log aplikasi + lihat config non-secret saja. **Tidak** ada retry/restart job, **tidak** ada remote command execution — fitur semacam itu secara fungsional setara backdoor kalau panel ter-compromise. Jangan tambahkan tanpa konfirmasi ulang ke user.
