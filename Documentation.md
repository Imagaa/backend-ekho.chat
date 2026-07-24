# Ekho.chat Backend — Documentation

> **Status:** Post-Hardening (Patch 1–3) + Async Import + Superadmin Dashboard (Implemented) + WABA Provider Integration api.co.id (Implemented — lihat §7)
> **Last Updated:** 2026-07-24
> **Stack:** Laravel 12 · PostgreSQL · Redis · Laravel Reverb · Sanctum · WABA Provider: api.co.id

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
9. [Contact Import Pipeline (Async)](#9-contact-import-pipeline-async)
10. [Real-time Broadcasting](#10-real-time-broadcasting)
11. [Security Architecture](#11-security-architecture)
12. [Queue & Worker Configuration](#12-queue--worker-configuration)
13. [CORS & Frontend Deployment (Vercel)](#13-cors--frontend-deployment-vercel)
14. [Superadmin Dashboard (Planned)](#14-superadmin-dashboard-planned)
15. [Scaling Guide](#15-scaling-guide)
16. [Known Limitations & Future Work](#16-known-limitations--future-work)

---

## 1. Architecture Overview

Ekho.chat adalah platform **WhatsApp Business API (WABA) SaaS multi-tenant**. Satu backend melayani banyak Tenant (perusahaan), masing-masing memiliki kredensial WABA, wallet saldo, dan data kontak yang terisolasi.

```
┌────────────────────────────────────────────────────────────────────┐
│                        EKHO.CHAT BACKEND                            │
│                                                                       │
│  ┌──────────┐  ┌──────────┐  ┌────────────┐  ┌───────────────────┐ │
│  │  Auth    │  │ Webhook  │  │  Midtrans  │  │  Contact Import   │ │
│  │ (OTP +   │  │ (WABA    │  │  Webhook   │  │  (async, chunked  │ │
│  │  Sanctum)│  │  HMAC)   │  │ (Top-up)   │  │  progress)        │ │
│  └────┬─────┘  └────┬─────┘  └─────┬──────┘  └─────────┬─────────┘ │
│       │             │              │                    │           │
│       ▼             ▼              ▼                    ▼           │
│  ┌──────────────────────────────────────────────────────────────┐  │
│  │                   Laravel Application Core                    │  │
│  │        Controllers → Jobs (Queue) → Models → DB               │  │
│  └────────────────────────────┬─────────────────────────────────┘  │
│                                │                                     │
│               ┌────────────────┼────────────────┐                   │
│               ▼                ▼                ▼                   │
│          PostgreSQL          Redis            Reverb                 │
│          (Primary DB)     (Cache/Queue)     (WebSocket)              │
└────────────────────────────────────────────────────────────────────┘

┌───────────────────────────┐        ┌──────────────────────────────┐
│  Next.js 14 (Vercel)       │        │  Filament v4 (Planned)        │
│  Tenant-facing dashboard   │───API──▶  Superadmin — subdomain       │
│  Bearer token (Sanctum)    │        │  terpisah, guard `admin`,     │
│                             │        │  session-based, TIDAK lewat   │
└───────────────────────────┘        │  /api/*, TIDAK di CORS list.   │
                                       └──────────────────────────────┘
```

### Aliran Data Utama

| Flow | Komponen yang Terlibat |
|------|------------------------|
| Login | `POST /request-otp` → Redis OTP → `POST /login` → Sanctum Token |
| Inbound Chat | Meta → `POST /webhook/waba` (HMAC verify) → Redis Queue → `ProcessWebhook` job → DB + Reverb broadcast |
| Top-up | Frontend → Midtrans Snap → `POST /webhook/midtrans` (SHA512 verify) → Wallet credit |
| Blast Campaign | `POST /campaigns` → pre-flight balance check → `ProcessBlastCampaign` → `Wallet::deductBalance()` (lockForUpdate) → dispatch `ProcessWaBlast` per kontak ke `queue:blast` |
| Import Kontak | `POST /contacts/import` → simpan file + `ContactImport` row (PENDING) → 202 + `import_id` → `ImportContactsJob` proses async, broadcast progress tiap 500 baris |
| Superadmin (Planned) | Realm auth terpisah total (`admin_users` + guard `admin`), tidak menyentuh flow di atas sama sekali |

---

## 2. Directory Structure

```
app/
├── Console/Commands/
│   ├── AggregateMessageStats.php         # stats:aggregate — hourly, agregasi DailyMessageStat
│   ├── DispatchScheduledCampaigns.php    # campaign:dispatch — everyMinute, jalankan campaign terjadwal
│   └── CleanupExpiredContactImports.php  # contacts:cleanup-imports — daily, hapus riwayat retention_policy=auto expired
├── Events/
│   ├── ChatReceived.php          # PrivateChannel tenant.{id}.chats — broadcast pesan inbound
│   └── ImportProgressUpdated.php # PrivateChannel tenant.{id}.imports — broadcast progres import kontak
├── Http/Controllers/Api/
│   ├── AuthController.php        # OTP request/verify, logout, /me
│   ├── CampaignController.php    # CRUD & dispatch blast campaign, pre-flight balance check
│   ├── ContactController.php     # Import kontak async (dispatch job), status polling, delete riwayat
│   ├── ContactGroupController.php # List & create contact group (dropdown import & campaign)
│   ├── DashboardController.php   # Statistik 30 hari & saldo wallet (difilter per tenant_id)
│   ├── MessageController.php     # GET /chats, GET /chats/{phone}, POST /chats/{phone}/send
│   ├── MidtransController.php    # Buat Snap transaction & terima webhook top-up
│   └── WebhookController.php     # Terima webhook WABA (verifikasi HMAC-SHA256)
├── Jobs/
│   ├── ImportContactsJob.php     # Proses file import async, commit+broadcast progress tiap 500 baris
│   ├── ProcessBlastCampaign.php  # Orkestrasi campaign: kalkulasi saldo, dispatch per-pesan ke queue:blast
│   ├── ProcessWaBlast.php        # Eksekusi kirim 1 pesan blast ke WABA API
│   └── ProcessWebhook.php        # Proses payload WABA: DLR status + inbound chat
├── Models/
│   ├── Campaign.php              # Header/config blast campaign
│   ├── Chat.php                  # Pesan inbound/outbound, unique message_id_meta (idempotency)
│   ├── Contact.php                # Kontak individual per tenant
│   ├── ContactGroup.php           # Grup kontak
│   ├── ContactImport.php          # Riwayat & status import kontak (status, counts, retention_policy)
│   ├── DailyMessageStat.php       # Statistik harian aggregat (dipakai dashboard, bukan raw query)
│   ├── MessageLog.php             # Log per-pesan dari blast campaign (status DLR)
│   ├── Template.php               # Template pesan WABA (mirror status approval Meta)
│   ├── Tenant.php                 # Root multi-tenant, waba_api_key terenkripsi (cast `encrypted`)
│   ├── User.php                   # Staff per-tenant (role: Owner/Admin/CS) — BUKAN admin platform
│   └── Wallet.php                 # WAJIB pakai deductBalance(), jangan manipulasi balance langsung
├── Providers/
│   └── AppServiceProvider.php    # preventLazyLoading() + 3 RateLimiter (login/api/webhook)
└── Traits/
    └── BelongsToTenant.php       # Global scope otomatis filter by tenant_id (aktif hanya saat Auth::check())

config/
├── services.php                  # SOT config eksternal (resend, waba, midtrans) — NO env() di controller
└── cors.php                      # allowed_origins: FRONTEND_URL (custom domain) + wildcard 'https://*.vercel.app'

routes/
├── api.php                       # Semua API route (public + protected via auth:sanctum)
└── channels.php                  # Broadcast channel authorization (tenant.{id}.chats, tenant.{id}.imports)

bootstrap/app.php                 # withBroadcasting() manual dengan middleware ['api','auth:sanctum']
                                   # (BUKAN shorthand channels: withRouting() — itu default ke ['web'])

database/migrations/              # Semua schema DB terurut
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

# === WABA — api.co.id (lihat §7) ===
APICOID_API_KEY=                # 1 API key untuk SEMUA tenant (akun master Ekho), bukan per-tenant
APICOID_WEBHOOK_SECRET=         # Dari dashboard api.co.id → Developers → Webhooks (ditampilkan sekali saat dibuat)
APICOID_BASE_URL=https://chat.api.co.id/api/v1/public

# === MIDTRANS ===
MIDTRANS_SERVER_KEY=            # Dari dashboard Midtrans
MIDTRANS_CLIENT_KEY=            # Untuk frontend Snap.js
MIDTRANS_IS_PRODUCTION=false    # Set true di production

# === CORS / FRONTEND ===
FRONTEND_URL=http://localhost:3000   # Custom domain final Next.js. Wildcard 'https://*.vercel.app'
                                       # sudah di-hardcode di config/cors.php — preview & default deployment
                                       # Vercel otomatis lolos CORS tanpa perlu update env ini tiap deploy.

# === SUPERADMIN (Filament) ===
ADMIN_DOMAIN=                         # mis. admin.ekho.imaga.site. Kosong = panel jalan di domain manapun (local dev).
```

> ⚠️ **WABA_APP_SECRET** adalah kunci kriptografis paling kritis. Tanpa ini semua
> webhook WABA ditolak 401. Ambil dari: Meta Developer Dashboard → App Settings
> → Basic → **App Secret**.

---

## 4. Database Schema

### Relasi Antar Tabel

```
tenants
  └── users (hasMany)             — Staff yang bisa login (role: Owner/Admin/CS)
  └── wallet (hasOne)             — Saldo kredit
  └── contact_groups (hasMany)    — Grup kontak
  │     └── contacts (hasMany)    — Kontak individual
  └── contact_imports (hasMany)   — Riwayat & status proses import
  └── campaigns (hasMany)         — Blast campaign
  │     └── message_logs (hasMany) — Log per-pesan blast
  └── chats (hasMany)             — Riwayat chat
  └── whatsapp_number_requests (hasMany) — Pengajuan nomor WA tenant, lihat §7

daily_message_stats               — Statistik aggregat harian (denormalisasi untuk performa)

admin_users (Planned)             — Realm terpisah, TIDAK terhubung ke tenants/users
admin_audit_logs (Planned)        — Jejak aksi admin
```

### Tabel Kunci

#### `tenants`
| Kolom | Tipe | Keterangan |
|-------|------|------------|
| `id` | bigint PK | |
| `company_name` | string | |
| `is_active` | boolean | Suspend/reaktivasi via Superadmin — lihat §14 |
| `waba_phone_id` | string nullable (indexed) | `whatsapp_phone_number_id` dari api.co.id (bukan raw Meta ID) — `null` = tenant belum bisa pakai blast/chat sama sekali (di-gate penuh oleh frontend), diisi manual oleh Superadmin setelah `whatsapp_number_requests` diproses, lihat §7 |

`waba_api_key` dan `waba_endpoint` sudah **dihapus** (migration `2026_07_24_000004`) — API key sekarang 1 config level-aplikasi di `config('services.apicoid')`, base URL selalu sama untuk semua tenant.

#### `wallets`
| Kolom | Tipe | Keterangan |
|-------|------|------------|
| `tenant_id` | FK | Relasi 1:1 |
| `balance` | decimal(15,2) | Selalu dioperasikan via `Wallet::deductBalance()` (lockForUpdate + retry deadlock 5x) |

#### `contact_groups`
| Kolom | Tipe | Keterangan |
|-------|------|------------|
| `tenant_id` | FK | |
| `name` | string | |
| `description` | string nullable | |

#### `contacts`
| Kolom | Tipe | Keterangan |
|-------|------|------------|
| `tenant_id`, `contact_group_id` | FK | |
| `name` | string nullable | |
| `phone` | string | Format E.164 (`628xxxxxxx`), hasil sanitasi otomatis |
| `dynamic_data` | json (encrypted:array) | Kolom Excel dinamis lainnya — placeholder variabel template |

#### `contact_imports`
| Kolom | Tipe | Keterangan |
|-------|------|------------|
| `tenant_id`, `contact_group_id`, `user_id` | FK | |
| `file_name`, `file_path` | string | `file_path` di-null-kan + file di-unlink setelah job selesai |
| `status` | string | `PENDING`, `PROCESSING`, `COMPLETED`, `FAILED` |
| `imported_count`, `skipped_count` | unsigned int | Update setiap 500 baris (throttled, bukan per-row) |
| `error_message` | text nullable | |
| `retention_policy` | string | `keep` (permanen) / `auto` (auto-delete) / `manual` (default) |
| `retention_days`, `expires_at` | nullable | Hanya terisi jika `retention_policy = auto` |
| `started_at`, `finished_at` | timestamp nullable | |

Index: `expires_at` (dipakai command `contacts:cleanup-imports`).

#### `campaigns`
| Kolom | Tipe | Keterangan |
|-------|------|------------|
| `tenant_id`, `template_id`, `contact_group_id` | FK | |
| `name` | string | |
| `scheduled_at` | timestamp nullable | Null = kirim segera |
| `status` | string | `PENDING`, `PROCESSING`, `COMPLETED`, `FAILED` |
| `total_contacts` | int | |
| `total_cost` | decimal(15,2) | Estimasi biaya = jumlah kontak × harga per kategori template |

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
| `campaign_id`, `contact_id` | FK | |
| `message_id_meta` | string (indexed) | Dipakai update status DLR |
| `status` | string | `QUEUED`, `SENT`, `DELIVERED`, `READ`, `FAILED` |
| `error_reason` | text nullable | Alasan gagal dari Meta |

#### `whatsapp_number_requests`
| Kolom | Tipe | Keterangan |
|-------|------|------------|
| `tenant_id` | FK | |
| `business_name` | string | Nama tampilan bisnis yang diajukan tenant |
| `phone_number` | string | Nomor yang mau dihubungkan ke WABA |
| `notes` | text nullable | Catatan tambahan dari tenant |
| `status` | string | `pending`, `processing`, `completed`, `rejected` (default `pending`) |
| `rejection_reason` | text nullable | Diisi Superadmin kalau `status = rejected` |

Diisi tenant lewat `POST /onboarding/request-number`, diproses Superadmin lewat
`WhatsappNumberRequestResource` (Filament). `status = completed` di sini **tidak**
otomatis membuka gate — gate dashboard tenant murni ditentukan oleh
`tenants.waba_phone_id` terisi atau tidak (Superadmin isi manual di
`TenantResource` setelah nomor benar-benar aktif di api.co.id). Lihat alur
lengkap di §7.

### Index yang Ada

```
tenants.waba_phone_id           — ProcessWebhook routing per-tenant
message_logs.message_id_meta    — Update status DLR
chats.message_id_meta           — firstOrCreate idempotency
campaigns.scheduled_at          — Scheduler blast
campaigns.status                — Filter campaign aktif
contact_imports.expires_at      — Scheduled cleanup command
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
| `POST` | `/webhook/waba` | throttle:webhook | Webhook api.co.id, verifikasi `X-Webhook-Signature` (HMAC-SHA256 hex) — lihat §7 |

### Protected Routes (`auth:sanctum` + `throttle:api`)

| Method | Endpoint | Deskripsi |
|--------|----------|-----------|
| `POST` | `/logout` | Revoke token aktif |
| `GET` | `/me` | Data user + tenant |
| `POST` | `/topup` | Buat Midtrans Snap transaction |
| `GET` | `/dashboard` | Statistik 30 hari + saldo |
| `GET` | `/onboarding/request-number` | Status pengajuan nomor WA terakhir milik tenant (atau `null`) |
| `POST` | `/onboarding/request-number` | Ajukan nomor WA baru — ditolak (422) kalau masih ada pengajuan `pending`/`processing` |
| `POST` | `/contacts/import` | Submit import kontak — **async**, return 202 |
| `GET` | `/contacts/import/{contactImport}` | Status/progress import (fallback polling) |
| `DELETE` | `/contacts/import/{contactImport}` | Hapus riwayat import |
| `GET` | `/contact-groups` | List group + jumlah kontak (dropdown import/campaign) |
| `POST` | `/contact-groups` | Buat group baru |
| `GET` | `/templates` | List template (filter `?status=`) |
| `POST` | `/templates` | Buat + submit template ke Meta lewat api.co.id (1 aksi) |
| `GET` | `/templates/{template}/refresh` | Tarik status approval terbaru dari api.co.id |
| `GET` | `/campaigns` | List campaign (paginated) |
| `POST` | `/campaigns` | Buat & dispatch campaign (immediate atau scheduled) |
| `GET` | `/campaigns/{campaign}` | Detail + progress pengiriman per status |
| `GET` | `/chats` | List percakapan per customer_phone |
| `GET` | `/chats/{phone}` | Riwayat pesan dengan satu customer |
| `POST` | `/chats/{phone}/send` | Kirim balasan (outbound) |

### Broadcasting

| Method | Endpoint | Middleware |
|--------|----------|------------|
| `GET/POST` | `/broadcasting/auth` | `['api', 'auth:sanctum']` — **manual**, lihat §10 |

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

#### GET `/onboarding/request-number`
```json
// 200 — belum pernah mengajukan
{ "status": "success", "data": null }

// 200 — sudah pernah mengajukan
{
  "status": "success",
  "data": {
    "id": 4,
    "tenant_id": 7,
    "business_name": "Toko Sejahtera",
    "phone_number": "6281234567890",
    "notes": null,
    "status": "pending",
    "rejection_reason": null,
    "created_at": "2026-07-24T03:10:00.000000Z"
  }
}
```

#### POST `/onboarding/request-number`
```json
// Request
{
  "business_name": "Toko Sejahtera",
  "phone_number": "6281234567890",
  "notes": "Nomor sudah tidak dipakai WhatsApp pribadi"
}

// 201
{
  "status": "success",
  "message": "Pengajuan nomor WhatsApp terkirim. Tim kami akan memproses dalam 1-3 hari kerja.",
  "data": { "id": 4, "status": "pending", ... }
}

// 422 — masih ada pengajuan pending/processing
{
  "status": "error",
  "message": "Sudah ada pengajuan yang sedang diproses. Tunggu sampai selesai sebelum mengajukan lagi.",
  "data": { "id": 4, "status": "pending", ... }
}
```

#### POST `/contacts/import`
```
Content-Type: multipart/form-data

contact_group_id: 3
file: [spreadsheet.xlsx]  (max 10MB, mimes: xlsx,xls,csv)
retention_policy: manual  (keep | auto | manual — default manual)
retention_days: 30        (wajib jika retention_policy=auto)
```
```json
// 202 — proses jalan async di background
{
  "status": "success",
  "message": "Import sedang diproses di background.",
  "data": { "import_id": 42 }
}
```

Kolom yang dikenali otomatis (case-insensitive):
- **Nomor HP:** `phone`, `nomor`, `no_hp`, `whatsapp`
- **Nama:** `name`, `nama`, `pelanggan`
- **Kolom lain:** tersimpan sebagai `dynamic_data` JSON untuk placeholder template WABA

Detail alur lengkap: lihat §9.

#### POST `/campaigns`
```json
// Request
{
  "name": "Promo Agustus",
  "template_id": 5,
  "contact_group_id": 3,
  "scheduled_at": null   // null = kirim segera
}

// 201
{
  "status": "success",
  "message": "Campaign sedang diproses secara asinkron.",
  "data": { "id": 12, "status": "PENDING", "total_contacts": 250, "total_cost": 125000 }
}

// 422 — template belum approved / group kosong / saldo tidak cukup
{ "status": "error", "message": "Saldo tidak cukup. Dibutuhkan Rp 125.000, saldo aktif Rp 50.000." }
```

---

## 6. Authentication Flow

```
1. POST /request-otp
   ├── Validasi email ada di DB
   ├── Generate OTP 6 digit (random_int — CSPRNG)
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

### Arsitektur Stateless (Penting)

API ini **murni Bearer token**, bukan cookie-based SPA auth. `bootstrap/app.php` **tidak** memanggil `statefulApi()` — method itu memaksa CSRF+session middleware untuk origin di `SANCTUM_STATEFUL_DOMAINS`, yang akan menyebabkan `"CSRF token mismatch"` pada request dari frontend Next.js yang tidak pernah mengambil `/sanctum/csrf-cookie`. Jangan aktifkan `statefulApi()` kecuali arsitektur auth benar-benar diubah ke cookie-based.

### Security Properties
- **Replay Attack Prevention:** OTP dihapus setelah sekali pakai
- **Brute Force Prevention:** Lockout 10 menit setelah 3x gagal, OTP dihancurkan
- **Config Safety:** Semua credential via `config()`, tidak ada `env()` di controller

---

## 7. WABA Provider Integration (api.co.id)

> ✅ **STATUS: Sudah diimplementasikan.** `MessageController::send()`,
> `WebhookController`, `ProcessWebhook`, dan rate limiter blast (`ProcessWaBlast`)
> sudah ditulis ulang sesuai spesifikasi api.co.id di bawah. **Belum pernah
> ditest dengan traffic asli** (menunggu API key & webhook secret produksi,
> plus konfirmasi beberapa hal dari api.co.id — lihat catatan ⚠️ di tiap
> subsection). Isi `.env`: `APICOID_API_KEY`, `APICOID_WEBHOOK_SECRET`.

### Model Bisnis — Ekho Sebagai Reseller (Bukan Pengguna Biasa)

Ekho **bukan** cuma "pakai" api.co.id — Ekho beli akses grosir dari api.co.id lalu
jual kembali ke tenant dengan markup lewat sistem wallet yang sudah ada:

```
Meta (pemilik WhatsApp)
   └── api.co.id (Tech Provider resmi) ── Ekho bayar Rp100rb/nomor/bulan, 0% markup pesan
         └── Ekho (1 akun master — 1 API key untuk SEMUA tenant)
               └── Tenant A, B, C, ... (masing-masing 1 whatsapp_phone_number_id)
                     └── Tenant bayar Ekho via saldo wallet, dipotong per kategori pesan
```

**Konsekuensi ke skema `tenants` (lihat §4) — akan berubah saat rewrite:**
- `waba_api_key` → **dihapus**. API key jadi satu, level aplikasi (`config/services.php`), bukan per-tenant.
- `waba_endpoint` → **dihapus**. Base URL api.co.id selalu sama untuk semua tenant, tidak per-tenant.
- `waba_phone_id` → **tetap ada**, tapi isinya `whatsapp_phone_number_id` **milik api.co.id** (bentuk `"clyyy9876543210"`), bukan raw Meta phone_number_id.

### Base URL & Autentikasi (Target)

```
Base URL : https://chat.api.co.id/api/v1/public
Auth     : Authorization: Bearer {API_KEY}   (1 API key untuk semua tenant)
```

### Kirim Pesan (Target — Ganti Total Format `MessageController::send()`)

```json
POST /messages/send
Authorization: Bearer {API_KEY}

{
  "phone_number": "628123456789",
  "channel": "whatsapp",
  "message_type": "text",
  "content": "Isi pesan",
  "whatsapp_phone_number_id": "{tenant.waba_phone_id}"
}
```
```json
// Response 200
{ "success": true, "data": { "message_id": "msg_xyz789", "status": "sent", "channel": "whatsapp", "timestamp": "..." } }
```

Kode sekarang mengirim payload gaya Meta (`messaging_product`, `recipient_type`, `to`, `type`, `text.body`) dan membaca `response.json('messages.0.id')` — **keduanya salah**, harus diganti sesuai contoh di atas.

### Webhook Masuk (Target — Ganti Total `WebhookController` + `ProcessWebhook`)

```
api.co.id
   │
   ▼ POST {webhook_url}
   │  Header: X-Webhook-Signature: {hex HMAC-SHA256, TANPA prefix "sha256="}
   │
WebhookController::handle() [TARGET]
   ├── Verifikasi: hash_equals(hash_hmac('sha256', $rawBody, $webhookSecret), $header)
   │   → Tidak cocok: 403 (BUKAN 401)
   ├── Dispatch ProcessWebhook ke queue 'webhook'
   └── Return 200 dalam < 5 detik (non-blocking)

ProcessWebhook::handle() [TARGET]
   ├── Payload FLAT (bukan nested entry.changes.value seperti Meta native):
   │   { "event": "message.received"|"message.sent"|"message.delivered"|"message.read"|"message.failed",
   │     "timestamp": "...", "data": { "message_id", "customer_phone", "channel", "direction",
   │     "message_type", "content", "media_url" } }
   ├── IF event = message.received → Chat::firstOrCreate + broadcast(ChatReceived)
   └── IF event = message.sent|delivered|read|failed → update MessageLog status
```

**Perbedaan kritis dari asumsi lama (Meta native):**
- Header signature: `X-Webhook-Signature` (bukan `X-Hub-Signature-256`)
- Format signature: hex HMAC-SHA256 polos (bukan `sha256={hex}`)
- Payload: flat per-event (bukan nested `entry[0].changes[0].value`)
- Status DLR datang sebagai **event terpisah** per status (bukan satu array `statuses[]`)
- **Tidak ada** GET verification handshake (`hub_challenge`) — webhook didaftarkan lewat dashboard api.co.id, bukan echo dari backend Ekho. Endpoint `GET /webhook/waba` yang ada sekarang kemungkinan tidak dibutuhkan lagi.
- ✅ **CONFIRMED (2026-07-24):** field routing yang benar adalah `data.phone_number_id` — **BUKAN** `whatsapp_phone_number_id` seperti asumsi awal. Nilainya identik dengan `whatsapp_phone_number_id` yang dipakai saat kirim pesan, jadi langsung cocok dengan `tenants.waba_phone_id` tanpa lookup tambahan. `ProcessWebhook.php` sudah diperbaiki memakai field ini. `data.business_phone` juga tersedia tapi tidak dipakai untuk routing.
- ✅ **CONFIRMED:** secret HMAC **berbeda per endpoint terdaftar**, hanya ditampilkan sekali saat endpoint dibuat. Kami hanya register 1 endpoint produksi → `config('services.apicoid.webhook_secret')` tetap 1 nilai, tidak perlu berubah. Kalau nanti register endpoint tambahan (mis. staging terpisah), secret-nya beda dan harus disimpan terpisah. Maksimal 3 endpoint per akun.
- ✅ **CONFIRMED:** cakupan 1 endpoint webhook = SEMUA nomor WhatsApp di akun master (bukan per-nomor) — konsisten dengan desain kami yang sudah routing lewat `phone_number_id`, bukan asumsi 1 endpoint per tenant.

### Jawaban Resmi api.co.id — Update 2026-07-24

Vendor mengoreksi beberapa jawaban sebelumnya yang kurang tepat. Ringkasan lengkap (poin webhook routing & secret sudah dilipat ke atas):

**Retry & auto-disable webhook** — detail lebih presisi dari asumsi awal:
- 5 percobaan total per event (1 awal + 4 retry), jeda 1 menit → 5 menit → 30 menit → 2 jam, timeout 30 detik/request, sukses = respons 2xx.
- Ambang auto-disable **10**, dihitung per **percobaan** gagal (bukan per event) — 1 event yang gagal total sampai habis retry sudah menyumbang 5 ke counter. Counter reset otomatis saat ada pengiriman sukses, atau saat endpoint dinonaktifkan-lalu-diaktifkan manual.
- Untuk maintenance terjadwal: nonaktifkan endpoint dulu dari dashboard api.co.id (bukan biarkan gagal natural) supaya tidak numpuk ke counter auto-disable.
- Kalau ter-auto-disable, api.co.id kirim notifikasi email — tim Ekho wajib monitor email ini (belum ada proses/alert internal untuk ini, lihat §16).

**Rate limit — detail baru:**
- Selain 60 pesan/menit khusus WhatsApp, ada limit **global 100 request/menit per API key** yang mencakup SEMUA endpoint (kirim pesan, template, media, dst) — bukan cuma endpoint kirim pesan.
- Response saat limit tercapai: **429**, body `{ "error": { "code": "RateLimitExceeded", "message": "...", "retryAfter": <detik> } }`, header `Retry-After`, `X-RateLimit-Limit-Channel`, `X-RateLimit-Remaining-Channel`, `X-RateLimit-Reset-Channel`. Tidak ada antrian otomatis di sisi vendor — request yang kena limit **ditolak**, bukan ditunda.
- `ProcessWaBlast` saat ini hanya throttle 1/detik untuk limit 60/menit kirim pesan — **belum** menangani limit global 100/menit lintas endpoint atau membaca header `Retry-After` saat 429. Lihat §16 untuk status ini sebagai limitation, bukan bug yang sudah pernah terjadi.

**Media — klarifikasi angka (sebelumnya diragukan, sekarang jelas beda konteks):**
- **Send Media** (kirim ke customer via `media_url`): maksimal **16MB** untuk semua tipe media, TANPA kecuali.
- **Upload Media** (`/media/upload`, dipakai untuk header template): image 5MB, video 16MB, **document 100MB**.
- Angka 100MB dan 16MB yang sebelumnya kelihatan kontradiktif ternyata berlaku untuk dua endpoint berbeda, bukan salah satu yang salah.

**Status approval template:**
- Belum ada webhook untuk perubahan status template — event webhook yang tersedia cuma `message.*` (received/sent/delivered/read/failed). Desain kami (tombol refresh manual, `GET /templates/{id}/refresh`) sudah sesuai realita vendor.
- Vendor sarankan polling otomatis `GET /templates?status=PENDING` tiap 5–15 menit sebagai pelengkap tombol refresh manual — **belum diimplementasikan** (lihat §16).

**Onboarding & Business Manager:**
- 1 Meta Business Manager bisa menaungi banyak WABA/nomor untuk banyak klien berbeda (pola standar reseller) — tidak perlu BM terpisah per tenant. Business verification melekat di level BM, tapi display name tetap per-nomor sehingga tiap tenant tampil dengan identitas sendiri ke pelanggannya.
- Transfer kepemilikan WABA ke BM milik klien sendiri **dimungkinkan** lewat mekanisme partner access Meta (4 langkah: klien buat BM sendiri → BM Ekho kasih akses partner → transfer kepemilikan → BM Ekho lepas akses). Syarat: nomor aktif & tidak dalam pembatasan. Ada jeda singkat saat transfer di mana pengiriman bisa terganggu.
- Business verification (badan usaha Indonesia): dokumen umum NIB, NPWP Badan, SK Kemenkumham, estimasi **1–5 hari kerja** kalau lengkap (bisa lebih lama kalau Meta minta dokumen tambahan/ada antrian).
- Embedded Signup untuk white-label/partner API (di-embed ke website pihak ketiga) — **belum dikonfirmasi vendor**, masih menunggu jawaban dari tim mereka.
- **WhatsApp Coexistence** (App biasa + Cloud API bareng) **tidak disarankan** untuk model kami — alurnya satu arah (App → API saja), riwayat chat tidak sinkron penuh, sebagian fitur App (katalog, label) hilang di API. Vendor sarankan nomor klien pakai mode API penuh, bukan Coexistence.

**Operasional:**
- SLA/uptime guarantee — **belum dikonfirmasi vendor**, masih menunggu jawaban dari tim mereka.
- Invoice & billing bisa dilihat/didownload langsung di dashboard api.co.id.

### Limitasi Teknis (api.co.id)

| Area | Batasan |
|---|---|
| Rate limit kirim WhatsApp | **60 pesan/menit** per akun (bukan per nomor) |
| Rate limit global | **100 request/menit** per API key, lintas SEMUA endpoint — belum ditangani di kode kami, lihat §16 |
| Jendela 24 jam | Pesan bebas (non-template) hanya bisa dalam 24 jam sejak pesan masuk terakhir dari customer |
| Send Media (ke customer) | Maksimal **16MB** untuk semua tipe media |
| Upload Media (header template) | Image 5MB, Video 16MB, **Document 100MB** |
| Media expired | File hasil `/media/upload` hilang otomatis 30 hari di server Meta |
| Template | Footer maks 60 char, tombol maks 20 char; WAJIB approved Meta dulu (proses menit–24 jam, bisa ditolak); status HANYA lewat polling `GET /templates`, tidak ada webhook |
| Interactive message | List menu maks 10 section × 10 item; reply button maks 3 |
| Typing indicator | Maks 1 request/3 detik per customer |
| **Webhook auto-disable** | Nonaktif otomatis setelah **10 percobaan gagal** (bukan 10 event — 1 event gagal total = 5 percobaan). Reset otomatis saat ada sukses atau saat diaktifkan ulang manual. Satu webhook dipakai SEMUA tenant — kalau nonaktif, SEMUA tenant kehilangan pesan masuk. Vendor kirim email notifikasi saat auto-disable — wajib monitoring internal, lihat §16 |

### ✅ FIXED — Rate Limit Blast (Sebelumnya Salah)

`ProcessWaBlast::handle()` sudah punya `Redis::throttle()` aktual, tapi sebelumnya
**dua masalah**: (1) rate `allow(50)->every(1)` = 50/detik, jauh di atas limit
asli api.co.id 60/**menit**; (2) key throttle `'wa_blast_tenant_' . $tenant->id`
— **per-tenant**, padahal limit api.co.id di level akun master yang dipakai
SEMUA tenant bersama. Kalau 10 tenant blast bersamaan, total bisa tembus 500/detik
gabungan meski masing-masing "cuma" 50/detik.

**Sudah diperbaiki:** throttle key jadi global (`'apicoid_send_rate_limit'`,
tanpa suffix tenant), rate jadi `allow(1)->every(1)` (1/detik, halus — bukan
60 sekaligus lalu diam 59 detik). Komentar salah di `ProcessBlastCampaign.php`
juga sudah dikoreksi.

### Tanggung Jawab Wajib Vendor (Ekho) — Operasional, Bukan Kode

1. **Monitor kesehatan webhook** — cek `GET /webhooks`, kalau `is_active: false` segera investigasi + `POST /webhooks/:id/enable`. Prioritas tinggi (dampak ke semua tenant sekaligus)
2. **Kelola submit & approval template** — submit lewat `POST /templates` → `POST /templates/:id/submit`, pantau status
3. **Pantau quality rating per nomor tenant** — via `GET /phone-numbers` (`quality_rating`: GREEN/YELLOW/RED), proaktif ingatkan tenant kalau turun
4. **Enforce & track consent** — sebelum blast MARKETING, pastikan ada jejak consent (`POST /customers/:id/consent`) — bukan cuma backend, perlu UI tenant-facing
5. **Onboarding nomor baru** — proses manual di dashboard api.co.id tiap tenant baru (lihat alur di bawah)
6. **Rekonsiliasi biaya** — cocokkan tagihan bulanan api.co.id (Rp100rb × jumlah nomor aktif) dengan yang ditagih ke tenant lewat wallet
7. **Pantau broadcast job gagal** — `GET /broadcast/jobs`, investigasi kalau `failed_count` tinggi

### Alur Onboarding Nomor WhatsApp Tenant (Model "Assisted" — Implemented)

Embedded Signup Meta **mewajibkan tenant login Facebook pribadi mereka sendiri** — Ekho
tidak bisa melakukan ini secara diam-diam atas nama tenant (dan tidak boleh minta
password Facebook tenant). Jadi peran Ekho adalah **memandu**, bukan **menggantikan**.

Frontend & backend untuk alur ini **sudah dibangun** (bukan lagi rencana):

```
1. Tenant login pertama kali, waba_phone_id masih null
   → seluruh dashboard di-gate ke halaman /onboarding (frontend, lihat
     frontend-handover.md §5.x) — tidak bisa akses chat/campaign/dsb
     sebelum langkah ini selesai
2. Tenant isi form di /onboarding (nama bisnis, nomor HP, catatan)
   → POST /onboarding/request-number → WhatsappNumberRequest status: pending
3. Superadmin lihat pengajuan masuk di Filament
   (WhatsappNumberRequestResource), tandai "Diproses" (status: processing)
4. Ekho staf proses di dashboard api.co.id:
   - Buat/pakai Meta Business Manager tenant (tenant login Facebook sendiri)
   - Embedded Signup: tambah nomor, verifikasi OTP oleh tenant
   - Lisensi Rp100rb/bulan aktif
5. api.co.id terbitkan whatsapp_phone_number_id, nomor berstatus GREEN
6. Superadmin input phone_number_id ke record tenant (TenantResource)
   — INI yang benar-benar membuka gate, bukan status di
   WhatsappNumberRequestResource (lihat §4 whatsapp_number_requests)
7. Superadmin update status pengajuan jadi "completed" (opsional, untuk
   histori) — atau "rejected" + rejection_reason kalau syarat tidak terpenuhi
   (tenant akan lihat alasannya dan bisa ajukan ulang)
8. Tenant otomatis ter-unlock saat load dashboard berikutnya (frontend
   re-fetch GET /me setiap masuk area (app), tidak perlu re-login)
```

**Checklist yang harus disiapkan tenant** (sudah ditampilkan di halaman `/onboarding`
dan di landing page publik, section "Yang Perlu Disiapkan" — target audiens awam):
1. Akun Facebook pribadi (admin Business Manager)
2. Nomor WhatsApp khusus bisnis — **tidak sedang aktif** di WhatsApp/WhatsApp Business biasa
3. Akses terima OTP (SMS/telepon) di nomor itu
4. Nama bisnis yang mau ditampilkan (sesuai kebijakan Meta, bukan kalimat promosi)
5. *(Opsional, untuk limit kirim lebih tinggi)* Dokumen legal bisnis — NIB/SIUP

**Catatan operasional:** pendaftaran nomor baru di api.co.id bisa **ditutup sementara**
kalau ada masalah sistem di sisi Meta (pernah terjadi, dibuka lagi setelah beberapa
hari) — cek status ini sebelum janji ke tenant soal kecepatan onboarding.

### Provider yang Sudah Dievaluasi (Untuk Konteks Keputusan)

| Provider | Status | Alasan |
|---|---|---|
| **api.co.id** | ✅ Dipilih | Official-only, dokumentasi lengkap (CRM/consent/broadcast job API sudah ada), webhook signature jelas (HMAC-SHA256 + secret), harga linear Rp100rb/nomor |
| Watzap | ❌ Tidak dipilih (untuk sekarang) | Menawarkan jalur "unofficial" (QR-scan, risiko ban) di platform yang sama — risiko salah pilih endpoint/plan; webhook `/set_webhook` **tidak ditemukan mekanisme signature/HMAC** (potensi celah keamanan, belum terkonfirmasi ke mereka); "API slot" di pricing tier ambigu |
| Jadi BSP sendiri (Tech Provider langsung ke Meta) | ❌ Tidak dipilih (untuk sekarang) | Butuh approval Meta yang bisa ditolak, effort engineering besar (replikasi webhook infra + Embedded Signup + monitoring quality rating), biaya api.co.id (Rp100rb/nomor) jauh lebih murah dari waktu development yang dibutuhkan di tahap ini. Bisa dipertimbangkan ulang kalau skala sudah besar (ratusan nomor) |

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

Harga per kategori template (`CampaignController::getPriceByCategory()`):

| Kategori | Harga |
|----------|-------|
| MARKETING | Rp 500 |
| UTILITY | Rp 200 |
| AUTHENTICATION | Rp 300 |

---

## 9. Contact Import Pipeline (Async)

Import kontak **tidak lagi diproses sinkron** dalam siklus HTTP — file besar dulu berisiko timeout, sekarang berjalan di background dengan progress realtime.

```
POST /contacts/import
   ├── Validasi: file max 10MB, mimes xlsx/xls/csv, group exists
   ├── Validasi retention_policy (keep/auto/manual) + retention_days (wajib jika auto)
   ├── Simpan file ke disk 'local' → storage/app/private/imports/{uuid}
   ├── ContactImport::create(status: PENDING, expires_at dari retention_days jika auto)
   ├── ImportContactsJob::dispatch($contactImport->id)->onQueue('default')
   └── Return 202 { import_id }

ImportContactsJob::handle() [Worker]
   ├── status → PROCESSING, started_at = now()
   ├── SimpleExcelReader stream rows dalam batch DB transaction (BATCH_SIZE = 500):
   │   ├── Deteksi kolom dinamis (case-insensitive)
   │   ├── Sanitasi nomor: 08xxx → 628xxx (E.164)
   │   ├── Skip row tanpa nomor valid
   │   ├── Contact::create() + dynamic_data JSON
   │   └── Tiap 500 baris: DB::commit() + broadcast(ImportProgressUpdated) + DB::beginTransaction() baru
   ├── status → COMPLETED (atau FAILED + error_message jika exception)
   ├── file_path di-null-kan, file di-unlink (baik sukses maupun gagal)
   └── tries = 1 — TIDAK retry (retry akan menduplikasi Contact yang sudah ter-insert dari batch sebelumnya)
```

### Retensi Riwayat Import

| `retention_policy` | Perilaku |
|---|---|
| `keep` | Simpan permanen, tidak pernah dihapus otomatis |
| `auto` | Dihapus otomatis oleh command `contacts:cleanup-imports` (scheduled daily) saat `expires_at` terlampaui |
| `manual` (default) | Tidak ada auto-delete; user hapus eksplisit via `DELETE /contacts/import/{id}` |

### Progress Realtime

Broadcast ke `PrivateChannel: tenant.{tenant_id}.imports`, event `ImportProgressUpdated`, payload:
```json
{
  "id": 42,
  "status": "PROCESSING",
  "imported_count": 1500,
  "skipped_count": 12,
  "error_message": null
}
```

Frontend **wajib** tetap punya fallback polling `GET /contacts/import/{id}` untuk kasus reload halaman atau socket belum connect saat job sudah berjalan.

---

## 10. Real-time Broadcasting

- **Driver:** Laravel Reverb (Pusher-compatible protocol)
- **Channels:**
  - `PrivateChannel: tenant.{tenant_id}.chats` — event `ChatReceived`, dikirim hanya jika `wasRecentlyCreated = true`
  - `PrivateChannel: tenant.{tenant_id}.imports` — event `ImportProgressUpdated`, lihat §9
- **Otorisasi channel:** `routes/channels.php` — cek `(int) $user->tenant_id === (int) $tenantId`

### `/broadcasting/auth` — Registrasi Manual (Penting)

Laravel menyediakan shorthand `withRouting(channels: '...')` yang **otomatis** mendaftarkan `/broadcasting/auth` dengan middleware default `['web']` (session-based). Itu **tidak kompatibel** dengan arsitektur stateless Bearer token di app ini — Echo di frontend mengirim `Authorization: Bearer <token>`, bukan session cookie, jadi middleware `web` tidak akan pernah mengenali user login.

Fix: `bootstrap/app.php` mendaftarkan broadcasting secara **manual**:
```php
->withBroadcasting(
    __DIR__.'/../routes/channels.php',
    ['middleware' => ['api', 'auth:sanctum']],
)
```
Verifikasi: `php artisan route:list --path=broadcasting` harus menunjukkan middleware `api` + `Authenticate:sanctum`, bukan `web`.

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

### 12 Layer Pertahanan (Tenant API)

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
| L12 | File upload — MIME + size + unlink | `ContactController` / `ImportContactsJob` |
| L13 | Stateless auth — no CSRF/session dependency | `bootstrap/app.php` tanpa `statefulApi()` |
| L14 | Broadcasting auth stateless | `/broadcasting/auth` manual `['api','auth:sanctum']`, bukan default `web` |
| L15 | CORS scoped | `config/cors.php` — origin eksplisit (`FRONTEND_URL`) + wildcard Vercel, bukan `*` |

### Throttle Configuration
```php
// app/Providers/AppServiceProvider.php
RateLimiter::for('login', fn(Request $req) => Limit::perMinute(5)->by($req->ip()));
RateLimiter::for('api', fn(Request $req) => Limit::perMinute(100)->by($req->user()?->id ?: $req->ip()));
RateLimiter::for('webhook', fn(Request $req) => Limit::perMinute(200)->by($req->ip()));
```

### Superadmin Realm — Model Keamanan Terpisah

Layer di atas berlaku untuk **API tenant**. Superadmin Dashboard (planned, lihat §14) sengaja **tidak** memakai satupun dari guard/token di atas — realm auth benar-benar independen. Jangan asumsikan layer keamanan tenant otomatis melindungi sisi admin; keduanya harus dievaluasi terpisah.

---

## 12. Queue & Worker Configuration

| Queue | Job | Prioritas |
|-------|-----|-----------|
| `webhook` | `ProcessWebhook` | Tertinggi — Meta akan retry jika tidak dapat 200 dalam 20 detik |
| `blast` | `ProcessWaBlast` (per kontak) | Heavy duty — kalkulasi wallet, lockForUpdate, kirim ke WABA |
| `default` | `ImportContactsJob`, general jobs | Normal |

### Scheduled Commands
```php
// routes/console.php
Schedule::command('stats:aggregate')->hourly();
Schedule::command('campaign:dispatch')->everyMinute();
Schedule::command('contacts:cleanup-imports')->daily();
```

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

## 13. CORS & Frontend Deployment (Vercel)

`config/cors.php`:
```php
'allowed_origins' => array_values(array_filter([
    env('FRONTEND_URL', 'http://localhost:3000'), // custom domain final, diisi setelah dibeli
    'https://*.vercel.app',                         // wildcard — cover production default domain
])),                                                 // (project.vercel.app) & semua preview deployment
'supports_credentials' => true,                      // wajib true untuk Sanctum
```

- Preview deployment Vercel (`project-hash-team.vercel.app`) otomatis lolos CORS tanpa perlu update `.env` tiap deploy, karena Laravel mengonversi wildcard `*` di `allowed_origins` jadi regex pattern otomatis (`fruitcake/php-cors`).
- Saat custom domain final dibeli, set `FRONTEND_URL` — ini **tambahan**, bukan pengganti wildcard vercel.app.
- Karena ada wildcard, matching origin masuk mode dinamis (`Access-Control-Allow-Origin` di-echo balik dari `Origin` request yang tervalidasi, bukan header statis `*`) — tetap aman dipakai bersama `supports_credentials: true`.

---

## 14. Superadmin Dashboard (Implemented)

> Detail desain lengkap & aturan mutlak isolasi: [AGENTS.md §SUPERADMIN DASHBOARD](AGENTS.md).

Ringkasan:
- **Tool:** Filament v4.12, panel `admin` di-domain-kan via `env('ADMIN_DOMAIN')` (kosong = jalan di domain manapun, untuk local dev), **tidak** lewat `/api/*`, **tidak** masuk CORS (`config/cors.php` hanya cover `api/*` & `sanctum/csrf-cookie`).
- **Auth:** realm terpisah total — tabel `admin_users` + guard `admin` (bukan `sanctum`/`web` tenant), session-based, 2FA TOTP **bawaan Filament v4** (`Filament\Auth\MultiFactor\App\AppAuthentication`, bukan package pihak ketiga) wajib & tidak bisa dilewati (`isRequired: true`), tanpa self-registration (bootstrap via `php artisan admin:create`, tidak ada route web untuk ini).
- **Fitur v1:** `TenantResource` (mask kredensial WABA, reveal ter-log, suspend/reaktivasi + auto-revoke Sanctum token saat suspend), `UserResource` (manajemen user lintas tenant, create akun baru, revoke token), `AdminUserResource` (CRUD sesama admin, proteksi tidak bisa hapus diri sendiri), `AuditLogResource` (read-only, `spatie/laravel-activitylog` dengan tabel di-override jadi `admin_audit_logs`), `SystemLogs` page (tail `laravel.log` + whitelist config non-secret, read-only, tanpa remote command execution).
- **Bug tambahan yang ditemukan & diperbaiki selama build ini:** `User` model tenant belum punya trait `Laravel\Sanctum\HasApiTokens` — `AuthController::login()` memanggil `$user->createToken()` yang sebelumnya akan fatal error. Sudah diperbaiki di `app/Models/User.php`.
- **Fitur baru menyertai:** kolom `tenants.is_active` (migration `2026_07_24_000003`) + guard di `AuthController::login()` menolak login user dari tenant yang di-suspend (403).
- **Fitur baru menyertai (onboarding):** `WhatsappNumberRequestResource` — list pengajuan nomor WA tenant (filter by status, aksi cepat "Tandai Diproses"), edit status/`rejection_reason`. Tidak bisa create manual dari sini (`getHeaderActions()` dikosongkan) — record hanya dibuat tenant lewat `POST /onboarding/request-number`. Membuka gate tetap lewat `TenantResource.waba_phone_id`, bukan resource ini — lihat §7.

---

## 15. Scaling Guide

### Horizontal Scaling
✅ Aman karena session/cache/queue semuanya di Redis — tidak ada state di memori app.

### Bottleneck yang Perlu Diperhatikan

1. **`DashboardController`** — Query `DailyMessageStat` sudah difilter per `tenant_id` ✅
   ```php
   DailyMessageStat::where('tenant_id', $tenant->id)
       ->where('date', '>=', now()->subDays(30))
       ->orderBy('date', 'asc')
       ->get();
   ```

2. **`Wallet::deductBalance()`** — `lockForUpdate()` bisa bottleneck jika 100+ blast paralel satu tenant. Batasi concurrency worker per tenant.

3. **Reverb** — Untuk ribuan koneksi WebSocket, pertimbangkan migrasi ke Pusher/Ably atau scale Reverb horizontal.

4. **Contact Import** — ✅ Sudah async (§9). Bottleneck sisa: throughput `queue:default` jika banyak import besar berjalan bersamaan — pertimbangkan queue/worker khusus jika volume naik signifikan.

### Caching Opportunities
```php
// Dashboard — kandidat cache 5 menit, belum diimplementasikan
$stats = Cache::remember('dashboard_stats_' . $tenant->id, 300, fn() => ...);
```

---

## 16. Known Limitations & Future Work

| Item | Prioritas | Keterangan |
|------|-----------|------------|
| Dashboard tidak filter per tenant | ✅ FIXED | `DailyMessageStat` sudah filter `WHERE tenant_id` |
| OTP generator pakai `rand()` | ✅ FIXED | Sudah diganti ke `random_int()` — CSPRNG compliant |
| Outbound chat belum ada endpoint | ✅ FIXED | `GET /chats`, `GET /chats/{phone}`, `POST /chats/{phone}/send` |
| File import tidak async | ✅ FIXED | `ImportContactsJob` async, progress realtime, retensi konfigurable |
| `waba_endpoint` per-tenant tidak dipakai | ✅ FIXED | Dipakai di `MessageController::send()` untuk routing per-tenant |
| Campaign blast job belum ada | ✅ FIXED | `ProcessBlastCampaign` + `ProcessWaBlast`, `CampaignController` immediate & scheduled dispatch |
| CSRF token mismatch dari frontend | ✅ FIXED | `statefulApi()` dihapus — API stateless murni Bearer token |
| `/broadcasting/auth` pakai middleware `web` | ✅ FIXED | Registrasi manual `['api','auth:sanctum']` di `bootstrap/app.php` |
| Belum ada endpoint list contact group | ✅ FIXED | `GET/POST /contact-groups` |
| CORS single-origin, tidak cocok preview Vercel | ✅ FIXED | Wildcard `https://*.vercel.app` di `config/cors.php` |
| Tidak ada API versioning | 🟢 LOW | Pertimbangkan `/api/v1/` untuk masa depan |
| Dashboard belum caching | 🟢 LOW | Kandidat `Cache::remember()` 5 menit per tenant |
| Superadmin Dashboard | ✅ FIXED | Lihat §14 — Filament v4, guard terpisah, 2FA wajib |
| `User` model tanpa `HasApiTokens` | ✅ FIXED | `createToken()` di `AuthController::login()` sebelumnya fatal error — trait ditambahkan |
| Tidak ada mekanisme suspend tenant | ✅ FIXED | `tenants.is_active` + guard di `AuthController::login()` + auto-revoke token via `TenantResource` |
| Template management belum ada endpoint CRUD | ✅ FIXED | `GET/POST /templates` + `GET /templates/{id}/refresh` — create+submit ke api.co.id jadi 1 aksi, sync status manual via refresh. **Confirmed vendor**: tidak akan pernah ada webhook status template, cuma polling — desain kami sudah sesuai, lihat §7 |
| Admin pertama belum dibuat | 🟡 ACTION NEEDED | Jalankan `php artisan admin:create` — belum otomatis, sengaja manual (lihat §14) |
| Integrasi WABA pakai format Meta native (salah) | ✅ FIXED | `MessageController::send()`, `WebhookController`, `ProcessWebhook` ditulis ulang sesuai format api.co.id — lihat §7 |
| Rate limit blast salah & tidak diimplementasi | ✅ FIXED | `ProcessWaBlast` sekarang throttle global 1/detik, sesuai limit 60/menit api.co.id — lihat §7 |
| Skema `tenants` belum disesuaikan model 1-akun-master | ✅ FIXED | `waba_api_key`/`waba_endpoint` dihapus (migration `2026_07_24_000004`), API key jadi config level-aplikasi |
| Belum ada halaman "Ajukan Nomor WhatsApp Baru" di frontend tenant | ✅ FIXED | `POST/GET /onboarding/request-number` + halaman `/onboarding` (gate seluruh dashboard sampai `waba_phone_id` terisi) + `WhatsappNumberRequestResource` di Superadmin — lihat §7 |
| Field routing webhook salah nama (`whatsapp_phone_number_id`) | ✅ FIXED | Vendor confirmed field yang benar `data.phone_number_id` (2026-07-24) — `ProcessWebhook.php` sudah diperbaiki. **Masih perlu test dengan payload webhook asli** sebelum go-live (fail-safe log+stop tetap aktif kalau field tidak ada/tenant tidak ketemu), tapi risiko salah-nama-field sudah hilang, lihat §7 |
| Rate limit global 100 req/menit per API key belum ditangani | 🟡 MEDIUM | `ProcessWaBlast` cuma throttle 60/menit khusus kirim pesan — belum ada penanganan limit global lintas endpoint (template/media/dst) atau baca header `Retry-After` saat 429. Confirmed vendor tidak antre otomatis, request kena limit langsung ditolak. Lihat §7 |
| Polling status template belum otomatis | 🟢 LOW | Saat ini hanya manual (tombol refresh user). Vendor sarankan polling terjadwal `?status=PENDING` tiap 5–15 menit sebagai pelengkap — belum diimplementasikan sebagai scheduled command |
| Monitoring auto-disable webhook belum ada proses internal | 🟡 MEDIUM | Vendor kirim notifikasi email saat webhook ter-auto-disable (setelah 10 percobaan gagal) — belum ada yang secara eksplisit ditugaskan memonitor email ini atau alert internal terpisah. Berdampak ke SEMUA tenant sekaligus kalau kejadian, lihat §7 |
| Integrasi belum pernah ditest dengan traffic asli | 🔴 ACTION NEEDED | Isi `APICOID_API_KEY`/`APICOID_WEBHOOK_SECRET` di `.env`, test kirim pesan & webhook masuk dengan nomor WhatsApp aktif sebelum production |
