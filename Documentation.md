# Ekho.chat Backend — Documentation

> **Status:** Post-Hardening (Patch 1–3 Complete)
> **Last Updated:** 2026-07-20
> **Stack:** Laravel 11 · PostgreSQL · Redis · Laravel Reverb · Sanctum

---

## Table of Contents

1. [Architecture Overview](#1-architecture-overview)
2. [Directory Structure](#2-directory-structure)
3. [Environment Variables Reference](#3-environment-variables-reference)
4. [Database Schema](#4-database-schema)
5. [API Endpoints Reference](#5-api-endpoints-reference)
6. [Authentication Flow](#6-authentication-flow)
7. [Webhook Pipeline](#7-webhook-pipeline)
8. [Wallet & Payment Flow](#8-wallet--payment-flow)
9. [Contact Import Pipeline](#9-contact-import-pipeline)
10. [Real-time Broadcasting](#10-real-time-broadcasting)
11. [Security Architecture](#11-security-architecture)
12. [Queue & Worker Configuration](#12-queue--worker-configuration)
13. [Scaling Guide](#13-scaling-guide)
14. [Known Limitations & Future Work](#14-known-limitations--future-work)

---

## 1. Architecture Overview

Ekho.chat adalah platform **WhatsApp Business API (WABA) SaaS multi-tenant**. Satu backend melayani banyak Tenant (perusahaan), masing-masing memiliki kredensial WABA, wallet saldo, dan data kontak yang terisolasi.

```
┌──────────────────────────────────────────────────────────┐
│                    EKHO.CHAT BACKEND                     │
│                                                          │
│  ┌──────────┐   ┌──────────┐   ┌────────────────────┐  │
│  │  Auth    │   │ Webhook  │   │  Midtrans Webhook  │  │
│  │ (OTP +   │   │ (WABA    │   │  (Top-up Payment)  │  │
│  │  Sanctum)│   │  HMAC)   │   │                    │  │
│  └────┬─────┘   └────┬─────┘   └────────┬───────────┘  │
│       │              │                   │               │
│       ▼              ▼                   ▼               │
│  ┌────────────────────────────────────────────────────┐  │
│  │              Laravel Application Core              │  │
│  │  Controllers → Jobs (Queue) → Models → DB          │  │
│  └───────────────────────┬────────────────────────────┘  │
│                          │                               │
│             ┌────────────┼────────────┐                  │
│             ▼            ▼            ▼                  │
│        PostgreSQL      Redis      Reverb                 │
│        (Primary DB)  (Cache/Queue) (WebSocket)           │
└──────────────────────────────────────────────────────────┘
```

### Aliran Data Utama

| Flow | Komponen yang Terlibat |
|------|------------------------|
| Login | `POST /request-otp` → Redis OTP → `POST /login` → Sanctum Token |
| Inbound Chat | Meta → `POST /webhook/waba` (HMAC verify) → Redis Queue → `ProcessWebhook` job → DB + Reverb broadcast |
| Top-up | Frontend → Midtrans Snap → `POST /webhook/midtrans` (SHA512 verify) → Wallet credit |
| Blast Campaign | (Future) Campaign job → `Wallet::deductBalance()` → WABA API send |

---

## 2. Directory Structure

```
app/
├── Events/
│   └── ChatReceived.php        # Broadcast event untuk pesan inbound realtime
├── Http/Controllers/Api/
│   ├── AuthController.php      # OTP request & verifikasi, logout, /me
│   ├── ContactController.php   # Import kontak via spreadsheet (xlsx/csv)
│   ├── DashboardController.php # Statistik 30 hari & saldo wallet
│   ├── MidtransController.php  # Buat Snap transaction & terima webhook
│   └── WebhookController.php   # Terima webhook WABA (HMAC-SHA256)
├── Jobs/
│   └── ProcessWebhook.php      # Proses payload WABA: DLR status + inbound chat
├── Models/
│   ├── Campaign.php            # Blast campaign
│   ├── Chat.php                # Pesan masuk (inbound) + balasan (outbound)
│   ├── Contact.php             # Kontak per tenant
│   ├── ContactGroup.php        # Grup kontak
│   ├── DailyMessageStat.php    # Statistik harian aggregat
│   ├── MessageLog.php          # Log per-pesan dari blast campaign
│   ├── Template.php            # Template pesan WABA
│   ├── Tenant.php              # Entitas perusahaan (multi-tenant root)
│   ├── User.php                # Staff/admin per-tenant
│   └── Wallet.php              # Saldo & deducting logic per-tenant
└── Traits/
    └── BelongsToTenant.php     # Global scope otomatis filter by tenant_id

config/
└── services.php                # SOT semua config eksternal (NO env() di controller)

routes/
└── api.php                     # Semua API route (public + protected)

database/migrations/            # Semua schema DB terurut
```

---

## 3. Environment Variables Reference

Semua `env()` **hanya boleh dipanggil di `config/`**, tidak di controller atau model.

```dotenv
# === APP ===
APP_KEY=                        # Laravel app key (php artisan key:generate)
APP_ENV=production
APP_DEBUG=false

# === DATABASE ===
DB_CONNECTION=pgsql
DB_HOST=
DB_PORT=5432
DB_DATABASE=ekho_chat
DB_USERNAME=
DB_PASSWORD=

# === REDIS ===
REDIS_HOST=
REDIS_PASSWORD=
REDIS_PORT=6379
CACHE_STORE=redis
QUEUE_CONNECTION=redis

# === REVERB (WebSocket) ===
REVERB_APP_ID=
REVERB_APP_KEY=
REVERB_APP_SECRET=
REVERB_HOST=
REVERB_PORT=8080
REVERB_SCHEME=https

# === RESEND (Email OTP) ===
RESEND_API_KEY=                 # Dari dashboard resend.com
RESEND_FROM_ADDRESS=auth@ekho.imaga.site  # Domain harus verified di Resend

# === WABA (Meta WhatsApp Business) ===
WABA_WEBHOOK_VERIFY_TOKEN=      # Token bebas, dipakai saat registrasi webhook di Meta Dashboard
WABA_APP_SECRET=                # Dari Meta Developer Dashboard → App Settings → App Secret
                                 # Digunakan untuk verifikasi HMAC-SHA256 semua POST webhook

# === MIDTRANS ===
MIDTRANS_SERVER_KEY=            # Dari dashboard Midtrans
MIDTRANS_CLIENT_KEY=            # Untuk frontend Snap.js
MIDTRANS_IS_PRODUCTION=false    # Set true di production
```

> ⚠️ **WABA_APP_SECRET** adalah kunci kriptografis paling kritis. Tanpa ini semua
> webhook WABA ditolak 401. Ambil dari: Meta Developer Dashboard → App Settings
> → Basic → **App Secret**.

---

## 4. Database Schema

### Relasi Antar Tabel

```
tenants
  └── users (hasMany)          — Staff yang bisa login
  └── wallet (hasOne)          — Saldo kredit
  └── contact_groups (hasMany) — Grup kontak
  └── campaigns (hasMany)      — Blast campaign
  └── chats (hasMany)          — Riwayat chat

contact_groups
  └── contacts (hasMany)       — Kontak individual

campaigns
  └── message_logs (hasMany)   — Log per-pesan blast

daily_message_stats            — Statistik aggregat harian (denormalisasi untuk performa)
```

### Tabel Kunci

#### `tenants`
| Kolom | Tipe | Keterangan |
|-------|------|------------|
| `id` | bigint PK | |
| `company_name` | string | |
| `waba_api_key` | text (encrypted) | Dienkripsi at-rest via Laravel `encrypted` cast |
| `waba_endpoint` | string | URL endpoint WABA API provider |
| `waba_phone_id` | string (indexed) | Phone Number ID Meta, dipakai routing webhook inbound |

#### `wallets`
| Kolom | Tipe | Keterangan |
|-------|------|------------|
| `tenant_id` | FK | |
| `balance` | decimal | Selalu dioperasikan via `Wallet::deductBalance()` |

#### `chats`
| Kolom | Tipe | Keterangan |
|-------|------|------------|
| `tenant_id` | FK | |
| `contact_id` | FK nullable | Linked ke contacts jika nomor sudah tersimpan |
| `customer_phone` | string | Format E.164 (`628xxxxxxx`) |
| `message` | text | Isi pesan |
| `direction` | string | `inbound` / `outbound` |
| `message_id_meta` | string **UNIQUE** | ID unik dari Meta, kunci idempotency |

#### `message_logs`
| Kolom | Tipe | Keterangan |
|-------|------|------------|
| `message_id_meta` | string (indexed) | Dipakai update status DLR |
| `status` | string | `SENT`, `DELIVERED`, `READ`, `FAILED` |
| `error_reason` | text nullable | Alasan gagal dari Meta |

### Index yang Ada

```
tenants.waba_phone_id           — ProcessWebhook routing per-tenant
message_logs.message_id_meta    — Update status DLR
chats.message_id_meta           — firstOrCreate idempotency
campaigns.scheduled_at          — Scheduler blast
campaigns.status                — Filter campaign aktif
```

---

## 5. API Endpoints Reference

### Base URL
```
https://api.ekho.imaga.site/api
```

### Public Routes (No Auth)

| Method | Endpoint | Throttle | Deskripsi |
|--------|----------|----------|-----------|
| `POST` | `/request-otp` | throttle:login | Kirim OTP ke email |
| `POST` | `/login` | throttle:login | Verifikasi OTP, return Bearer token |
| `POST` | `/webhook/midtrans` | throttle:webhook | Notifikasi pembayaran Midtrans |
| `POST` | `/webhook/waba` | throttle:webhook | Webhook WABA (wajib `X-Hub-Signature-256`) |
| `GET` | `/webhook/waba` | throttle:webhook | Verifikasi URL webhook oleh Meta |

### Protected Routes (Bearer Token)

| Method | Endpoint | Deskripsi |
|--------|----------|-----------|
| `POST` | `/logout` | Revoke token aktif |
| `GET` | `/me` | Data user + tenant |
| `POST` | `/topup` | Buat Midtrans Snap transaction |
| `GET` | `/dashboard` | Statistik 30 hari + saldo |
| `POST` | `/contacts/import` | Import kontak dari file |

### Contoh Request & Response

#### POST `/request-otp`
```json
// Request
{ "email": "user@company.com" }

// 200
{ "message": "Kode OTP telah dikirim ke email Anda" }

// 404
{ "message": "Kredensial tidak valid" }
```

#### POST `/login`
```json
// Request
{ "email": "user@company.com", "otp": "123456" }

// 200
{
  "access_token": "1|xxxxxx",
  "token_type": "Bearer",
  "user": { "id": 1, "name": "...", "tenant": { ... } }
}

// 401 — OTP salah, sisa 2 percobaan
{ "message": "Kode OTP tidak valid atau sudah kedaluwarsa. Sisa percobaan: 2" }

// 429 — Locked setelah 3x gagal
{ "message": "Terlalu banyak percobaan gagal. OTP dibatalkan. Silakan request OTP baru." }
```

#### GET `/dashboard`
```json
{
  "status": "success",
  "data": {
    "overview": {
      "balance": 150000.00,
      "delivery_rate_percent": 94.5,
      "read_rate_percent": 61.2,
      "total_failed": 12
    },
    "charts": [
      { "date": "2026-06-20", "sent": 120, "delivered": 115, "read": 70, "failed": 5 }
    ]
  }
}
```

#### POST `/contacts/import`
```
Content-Type: multipart/form-data

contact_group_id: 3
file: [spreadsheet.xlsx]  (max 10MB, mimes: xlsx,xls,csv)
```

Kolom yang dikenali otomatis (case-insensitive):
- **Nomor HP:** `phone`, `nomor`, `no_hp`, `whatsapp`
- **Nama:** `name`, `nama`, `pelanggan`
- **Kolom lain:** tersimpan sebagai `dynamic_data` JSON untuk placeholder template WABA

---

## 6. Authentication Flow

```
1. POST /request-otp
   ├── Validasi email ada di DB
   ├── Generate OTP 6 digit
   ├── Simpan Redis: otp_login_{email}, TTL=300s
   ├── Reset counter: otp_attempts_{email}, otp_locked_{email}
   └── Kirim email via Resend API

2. POST /login
   ├── Cek otp_locked_{email} → 429 jika terkunci
   ├── Ambil cached OTP dari Redis
   ├── Jika OTP salah:
   │   ├── Increment otp_attempts_{email}
   │   ├── Jika attempts >= 3:
   │   │   ├── Hapus OTP dari Redis
   │   │   ├── Set otp_locked_{email} = true, TTL=600s
   │   │   └── Return 429
   │   └── Return 401 dengan sisa percobaan
   └── Jika OTP benar:
       ├── Hapus OTP + attempts + locked dari Redis
       ├── Buat Sanctum Personal Access Token
       └── Return token + user data
```

### Security Properties
- **Replay Attack Prevention:** OTP dihapus setelah sekali pakai
- **Brute Force Prevention:** Lockout 10 menit setelah 3x gagal, OTP dihancurkan
- **Config Safety:** Semua credential via `config()`, tidak ada `env()` di controller

---

## 7. Webhook Pipeline

### WABA Webhook (Meta)

```
Meta Platform
   │
   ▼ POST /api/webhook/waba
   │  Header: X-Hub-Signature-256: sha256={hmac}
   │
WebhookController::handle()
   ├── Guard: payload > 1MB → 413
   ├── Guard: header tidak ada → 401
   ├── Verifikasi HMAC:
   │   computed = sha256(rawBody, WABA_APP_SECRET)
   │   hash_equals('sha256='+computed, headerValue)
   │   → Tidak cocok: 401
   ├── Dispatch ProcessWebhook ke queue 'webhook'
   └── Return 200 immediately (non-blocking)

ProcessWebhook::handle() [Worker]
   ├── Parse entry.changes[0].value
   ├── IF statuses → Update MessageLog (DLR)
   └── IF messages
       ├── Cari Tenant by waba_phone_id
       ├── Chat::firstOrCreate(message_id_meta)
       └── IF wasRecentlyCreated:
           └── broadcast(ChatReceived) → Reverb → Frontend
```

### Cara Daftarkan Webhook di Meta
1. Meta Developer Dashboard → App → WhatsApp → Configuration
2. Callback URL: `https://api.ekho.imaga.site/api/webhook/waba`
3. Verify Token: nilai `WABA_WEBHOOK_VERIFY_TOKEN` di `.env`
4. Meta kirim GET → backend echo `hub_challenge` → sukses
5. Subscribe ke fields: `messages`

### Midtrans Webhook

```
MidtransController::webhook()
   ├── Verifikasi signature SHA512:
   │   hash(order_id + status_code + gross_amount + server_key)
   │   hash_equals(computed, request.signature_key) → Tidak cocok: 403
   ├── Cek status: settlement | capture
   ├── Parse tenant_id dari order_id (TOPUP-{tenantId}-{timestamp})
   └── DB::transaction() lockForUpdate:
       wallet.balance += (float) gross_amount
```

---

## 8. Wallet & Payment Flow

### Top-up
```
1. POST /topup { amount: 100000 }
   └── Validasi: amount >= 50000
   └── order_id: TOPUP-{tenant_id}-{time()}
   └── Call Midtrans Snap API → return { token, redirect_url }

2. Frontend load Snap.js popup
3. User bayar → Midtrans kirim POST /webhook/midtrans
4. Backend verifikasi → credit wallet
```

### Deduction (Campaign Blast)

Gunakan `Wallet::deductBalance(float $amount)` — **jangan** langsung manipulasi `balance`.

```php
// ✅ BENAR — ada lockForUpdate + DB transaction + validasi saldo
$tenant->wallet->deductBalance(500.00);

// ❌ SALAH — rawan race condition
$tenant->wallet->balance -= 500;
$tenant->wallet->save();
```

---

## 9. Contact Import Pipeline

```
POST /contacts/import
   ├── Validasi: file max 10MB, mimes xlsx/xls/csv, group exists
   ├── Verifikasi group ownership via BelongsToTenant scope (otomatis)
   ├── DB::beginTransaction()
   ├── SimpleExcelReader stream rows:
   │   ├── Deteksi kolom dinamis (case-insensitive)
   │   ├── Sanitasi nomor: 08xxx → 628xxx (E.164)
   │   ├── Skip row tanpa nomor valid
   │   └── Contact::create() + dynamic_data JSON
   ├── DB::commit()
   └── @unlink temp file (garbage collection manual)
```

---

## 10. Real-time Broadcasting

- **Driver:** Laravel Reverb (Pusher-compatible protocol)
- **Channel:** `PrivateChannel: tenant.{tenant_id}.chats`
- **Event:** `ChatReceived` — dikirim hanya jika `wasRecentlyCreated = true`

### Payload ChatReceived
```json
{
  "id": 42,
  "customer_phone": "6281234567890",
  "message": "Halo, ada promo?",
  "direction": "inbound",
  "created_at": "2026-07-20T04:30:00+00:00"
}
```

---

## 11. Security Architecture

### 12 Layer Pertahanan

| Layer | Mekanisme | Implementasi |
|-------|-----------|--------------|
| L1 | Config safety — no `env()` di controller | Semua via `config('services.*')` |
| L2 | WABA integrity — HMAC-SHA256 | `WebhookController::handle()` |
| L3 | Payment integrity — SHA512 signature | `MidtransController::webhook()` |
| L4 | Auth brute force — OTP lockout 3-strike | `AuthController::login()` |
| L5 | Auth replay — OTP single-use | `Cache::forget()` post-login |
| L6 | Timing attack — `hash_equals()` | Semua komparasi kriptografis |
| L7 | Race condition — pessimistic locking | `Wallet::deductBalance()` |
| L8 | Credential encryption — `encrypted` cast | `Tenant::waba_api_key` |
| L9 | Tenant isolation — Global Scope | `BelongsToTenant` trait |
| L10 | Idempotency — `firstOrCreate` | `ProcessWebhook` job |
| L11 | Payload bomb — max 1MB check | `WebhookController` |
| L12 | File upload — MIME + size + unlink | `ContactController` |

### Throttle Configuration
```php
// app/Providers/RouteServiceProvider.php
RateLimiter::for('login', fn(Request $req) => Limit::perMinute(5)->by($req->ip()));
RateLimiter::for('webhook', fn(Request $req) => Limit::perMinute(60)->by($req->ip()));
```

---

## 12. Queue & Worker Configuration

| Queue | Job | Prioritas |
|-------|-----|-----------|
| `webhook` | `ProcessWebhook` | High — Meta akan retry jika tidak dapat 200 dalam 20 detik |
| `default` | General jobs | Normal |

### Supervisor Config (Production)
```ini
[program:ekho-worker]
command=php /var/www/ekho/artisan queue:work redis --queue=webhook,blast,default --sleep=3 --tries=3 --max-time=3600
autostart=true
autorestart=true
stopasecs=10
user=www-data
```

### Monitor Failed Jobs
```bash
php artisan queue:failed
php artisan queue:retry {id}
php artisan queue:flush
```

---

## 13. Scaling Guide

### Horizontal Scaling
✅ Aman karena session/cache/queue semuanya di Redis — tidak ada state di memori app.

### Bottleneck yang Perlu Diperhatikan

1. **`DashboardController`** — Query `DailyMessageStat` sudah difilter per `tenant_id` ✅
   ```php
   // Sudah diimplementasi:
   DailyMessageStat::where('tenant_id', $tenant->id)
       ->where('date', '>=', now()->subDays(30))
       ->orderBy('date', 'asc')
       ->get();
   ```

2. **`Wallet::deductBalance()`** — `lockForUpdate()` bisa bottleneck jika 100+ blast paralel satu tenant. Batasi concurrency worker per tenant.

3. **Reverb** — Untuk ribuan koneksi WebSocket, pertimbangkan migrasi ke Pusher/Ably atau scale Reverb horizontal.

4. **Contact Import** — File besar dibaca in-memory. Untuk file >5MB, pertimbangkan dispatch ke background job.

### Caching Opportunities
```php
// Dashboard — kandidat cache 5 menit
$stats = Cache::remember('dashboard_stats_' . $tenant->id, 300, fn() => ...);
```

---

## 14. Known Limitations & Future Work

| Item | Prioritas | Keterangan |
|------|-----------|------------|
| Dashboard tidak filter per tenant | ✅ FIXED | `DailyMessageStat` sudah filter `WHERE tenant_id` — tidak ada kebocoran data antar tenant |
| OTP generator pakai `rand()` | ✅ FIXED | Sudah diganti ke `random_int()` — CSPRNG compliant |
| Outbound chat belum ada endpoint | ✅ FIXED | GET /chats, GET /chats/{phone}, POST /chats/{phone}/send |
| File import tidak async | 🟡 MEDIUM | Import besar bisa timeout HTTP |
| `waba_endpoint` per-tenant tidak dipakai | ✅ FIXED | Dipakai di MessageController::send() untuk routing per-tenant |
| Tidak ada API versioning | 🟢 LOW | Pertimbangkan `/api/v1/` untuk masa depan |
| Campaign blast job belum ada | ✅ FIXED | ProcessBlastCampaign + SendBlastMessage jobs, CampaignController dengan immediate & scheduled dispatch |