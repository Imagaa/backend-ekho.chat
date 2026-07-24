# Product Requirements Document (PRD) - SaaS WA Blast B2B

## 1. Visi & Tujuan
Membangun platform SaaS Business-to-Business (B2B) Multi-Client untuk kebutuhan Customer Service (Chat) dan WhatsApp Blast berbasis official WABA Meta (melalui provider `api.co.id`). Sistem mengutamakan stabilitas pengiriman masal tanpa terblokir, kemudahan manajemen tenant, dan integritas perhitungan saldo/wallet.

**Model bisnis Ekho — Reseller, bukan sekadar pengguna:** Ekho memegang **1 akun master api.co.id** (1 API key, bayar Rp100rb per nomor WhatsApp per bulan), lalu menjual kembali akses itu ke tiap tenant lewat sistem saldo/wallet (harga per kategori pesan sudah ada markup). Ini alasan sistem wallet jadi krusial — bukan sekadar fitur pelengkap, tapi mekanisme utama Ekho menghasilkan margin. Detail: [documentation.md §7](documentation.md#7-waba-provider-integration-apicoid).

## 2. Arsitektur Utama
- **Backend:** Laravel 12 (Core API, Queue Worker, Webhook Handler).
- **Frontend (Tenant-facing):** Next.js 14, deploy Vercel — landing page + dashboard klien (login, chat, kontak, campaign, top-up). Konsumsi backend via REST + Reverb WebSocket.
- **Frontend (Internal/Superadmin):** Filament v4 — panel terpisah di subdomain `admin.ekho.imaga.site`, **bukan** bagian dari aplikasi Next.js. Auth realm sendiri (`admin_users`, guard `admin`), session-based, 2FA wajib. Dipakai tim Ekho untuk mendaftarkan akun tenant, manajemen user lintas tenant, dan operasional dasar. Lihat [Feature.md §9](Feature.md) & [AGENTS.md §SUPERADMIN DASHBOARD](AGENTS.md).
- **Database:** PostgreSQL (Multi-tenancy via column `tenant_id`).
- **Cache & Queue:** Redis.
- **Payment Gateway:** Midtrans Core API.

## 3. Alur Pengguna (User Flow) Utama

### Tenant (via Next.js)
1. Akun didaftarkan oleh tim Ekho lewat Superadmin Dashboard (bukan self-registration).
2. **Ajukan & setup nomor WhatsApp** — tenant isi form pengajuan, lalu dipandu (bukan digantikan) lewat proses Embedded Signup Meta; tenant WAJIB login Facebook pribadi sendiri untuk verifikasi. Lihat [Feature.md §9](Feature.md) untuk alur & syarat lengkap.
3. Top-Up Saldo via Midtrans (Auto-verify).
4. Upload Kontak (CSV/Excel) & Sanitasi Nomor — async, progress realtime.
5. Buat & Submit Template Meta (Tunggu Approval).
6. Jalankan Campaign Blast (Kalkulasi Saldo -> Lock -> Dispatch Queue, rate limit 60 pesan/menit).
7. Pantau Real-time Report & Balas Pesan Masuk (Jendela 24 Jam).

### Tim Internal (via Superadmin Dashboard)
1. Login dengan email/password + 2FA (realm terpisah dari tenant).
2. Daftarkan tenant baru & buat akun user pertama untuk tenant tersebut.
3. **Proses onboarding nomor WhatsApp tenant** di dashboard api.co.id (Embedded Signup bersama tenant), input `whatsapp_phone_number_id` hasilnya ke record tenant.
4. Pantau/kelola user lintas tenant, audit log aktivitas admin, cek log aplikasi.
5. **Operasional WABA provider:** monitor kesehatan webhook, kelola approval template, pantau quality rating nomor, rekonsiliasi biaya bulanan api.co.id. Detail: [Feature.md §10](Feature.md).
