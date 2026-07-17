# Product Requirements Document (PRD) - SaaS WA Blast B2B

## 1. Visi & Tujuan
Membangun platform SaaS Business-to-Business (B2B) Multi-Client untuk kebutuhan Customer Service (Chat) dan WhatsApp Blast berbasis official WABA Meta (melalui provider `api.co.id`). Sistem mengutamakan stabilitas pengiriman masal tanpa terblokir, kemudahan manajemen tenant, dan integritas perhitungan saldo/wallet.

## 2. Arsitektur Utama
- **Backend:** Laravel 12 (Core API, Queue Worker, Webhook Handler).
- **Frontend:** Next.js 14 (Dashboard Client & Superadmin).
- **Database:** PostgreSQL (Multi-tenancy via column `tenant_id`).
- **Cache & Queue:** Redis.
- **Payment Gateway:** Midtrans Core API.

## 3. Alur Pengguna (User Flow) Utama
1. Registrasi Tenant & Setup WABA API Key.
2. Top-Up Saldo via Midtrans (Auto-verify).
3. Upload Kontak (CSV/Excel) & Sanitasi Nomor.
4. Buat & Submit Template Meta (Tunggu Webhook Approved).
5. Jalankan Campaign Blast (Kalkulasi Saldo -> Lock -> Dispatch Queue).
6. Pantau Real-time Report & Balas Pesan Masuk (Jendela 24 Jam).
