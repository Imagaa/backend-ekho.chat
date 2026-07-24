# ATURAN MUTLAK KODE (AI AGENT INSTRUCTIONS)

**PROJECT:** Ekho.chat — SaaS WhatsApp Business API (WABA) B2B Multi-Tenant.
**SISTEM & STACK:** Laravel 12 (PostgreSQL, Redis, Laravel Reverb, Sanctum) & Next.js 14 (tenant-facing). Superadmin: Filament v4, isolated, lihat §SUPERADMIN DASHBOARD. **WABA Provider: api.co.id** (BSP resmi, model reseller — lihat §WABA PROVIDER INTEGRATION).
**STATUS:** Post-Hardening (Security Patch 1–3 selesai) + async import kontak + Superadmin Dashboard (implemented) + integrasi api.co.id (implemented, **belum ditest traffic asli** — lihat §WABA PROVIDER INTEGRATION). Lihat [documentation.md](documentation.md) untuk referensi lengkap API/DB/security, [Architecture.md](Architecture.md) untuk topologi, [Feature.md](Feature.md) untuk acceptance criteria per fitur, [PRD.md](PRD.md) untuk visi produk.

---

**ATURAN MUTLAK EDITING KODE (BERLAKU KETAT):**
1. **Berbasis GitHub:** File yang diunggah user adalah SOT (Source of Truth). JANGAN ngoding dari nol. Jika konteks hilang, minta user unggah ulang file aslinya.
2. **Haram Menghapus:** DILARANG menghapus/mengubah [DOKUMENTASI BACKEND], komentar API, atau UI/State/Fitur eksisting yang tidak berkaitan dengan revisi.
3. **Edit Terisolasi:** Gunakan instruksi 'Cari baris ini... Ubah menjadi...'.
4. **Batch Editing:** Jika ada banyak fitur yang merevisi satu file yang sama, BERIKAN KODE PERUBAHAN SECARA BERSAMAAN DALAM SATU WAKTU agar proses revisi efisien. Komunikasi brutal, efisien, tanpa basa-basi.
5. **Isolasi Superadmin (MUTLAK, lihat §SUPERADMIN DASHBOARD):** `admin_users` DILARANG KERAS digabung, direferensikan silang, atau dibuat bisa saling login dengan tabel `users` (tenant). Guard `admin` dan guard `sanctum`/`web` tenant harus tetap dua realm auth yang sepenuhnya terpisah selamanya — ini bukan keputusan sementara.

---

## STRUKTUR PROYEK SAAT INI

```
app/
├── Console/Commands/
│   ├── AggregateMessageStats.php         # stats:aggregate — hourly
│   ├── DispatchScheduledCampaigns.php    # campaign:dispatch — everyMinute
│   └── CleanupExpiredContactImports.php  # contacts:cleanup-imports — daily, hapus retention_policy=auto expired
├── Http/Controllers/Api/
│   ├── AuthController.php        # OTP request/verify, logout, /me
│   ├── CampaignController.php    # CRUD & dispatch blast campaign
│   ├── ContactController.php     # Import kontak async (dispatch job) + status polling + delete riwayat
│   ├── ContactGroupController.php # GET/POST /contact-groups — list & create group (dropdown import/campaign)
│   ├── DashboardController.php   # Statistik 30 hari & saldo wallet (sudah difilter per tenant_id)
│   ├── MessageController.php     # GET /chats, GET /chats/{phone}, POST /chats/{phone}/send
│   ├── MidtransController.php    # Buat Snap transaction & terima webhook top-up
│   ├── OnboardingController.php  # GET/POST /onboarding/request-number — pengajuan nomor WA tenant, lihat §WABA PROVIDER INTEGRATION
│   ├── TemplateController.php    # GET/POST /templates, GET /templates/{id}/refresh — create+submit+sync status ke api.co.id
│   └── WebhookController.php     # Terima webhook WABA (verifikasi HMAC-SHA256)
├── Jobs/
│   ├── ImportContactsJob.php     # Proses file import async, commit+broadcast progress tiap 500 baris
│   ├── ProcessBlastCampaign.php  # Orkestrasi campaign: kalkulasi saldo, dispatch per-pesan
│   ├── ProcessWaBlast.php        # Eksekusi kirim 1 pesan blast ke WABA API
│   └── ProcessWebhook.php        # Proses payload WABA: DLR status + inbound chat
├── Events/
│   ├── ChatReceived.php          # PrivateChannel tenant.{id}.chats
│   └── ImportProgressUpdated.php # PrivateChannel tenant.{id}.imports — progress import realtime
├── Models/
│   ├── Campaign.php / MessageLog.php / Template.php
│   ├── Chat.php                  # Pesan inbound/outbound, unique message_id_meta (idempotency)
│   ├── Contact.php / ContactGroup.php
│   ├── ContactImport.php         # Riwayat & status import kontak (status, counts, retention_policy)
│   ├── DailyMessageStat.php      # Statistik harian aggregat (dipakai dashboard, bukan raw query)
│   ├── Tenant.php                # Root multi-tenant, waba_api_key terenkripsi (cast `encrypted`)
│   ├── User.php                  # Staff per-tenant (role: Owner/Admin/CS) — BUKAN admin platform, lihat §SUPERADMIN
│   ├── Wallet.php                # WAJIB pakai deductBalance(), jangan manipulasi balance langsung
│   └── WhatsappNumberRequest.php # Pengajuan nomor WA tenant (status: pending/processing/completed/rejected), lihat §WABA PROVIDER INTEGRATION
├── Providers/
│   └── AppServiceProvider.php    # preventLazyLoading() + 3 RateLimiter (login/api/webhook)
└── Traits/
    └── BelongsToTenant.php       # Global scope otomatis filter by tenant_id — JANGAN di-bypass

config/services.php                # SOT semua config eksternal (resend, waba, midtrans) — NO env() di controller
config/cors.php                    # allowed_origins: FRONTEND_URL (custom domain) + wildcard 'https://*.vercel.app'
routes/api.php                     # Semua API route (public + protected via auth:sanctum)
database/migrations/               # Semua schema DB terurut
```

**Catatan:** `ProcessBlastCampaign`, `ProcessWaBlast`, `MessageController`, `CampaignController` sudah live — bukan lagi item "future work". Jangan asumsikan campaign blast belum ada.

> ✅ `MessageController::send()`, `WebhookController`, `ProcessWebhook`, dan
> rate limiter blast (`ProcessWaBlast`) sudah ditulis ulang sesuai format
> api.co.id (bukan Meta native lagi). **Belum pernah ditest dengan traffic
> asli** — field `whatsapp_phone_number_id` di payload webhook belum
> terkonfirmasi ada (`ProcessWebhook` sudah fail-safe kalau tidak ada/tenant
> tidak ketemu: log+stop, TIDAK menebak routing). Detail lengkap:
> [documentation.md §7](documentation.md#7-waba-provider-integration-apicoid).

---

## API ENDPOINTS AKTUAL (`routes/api.php`)

### Public (throttle:login / throttle:webhook)
- `POST /request-otp`, `POST /login`
- `POST /webhook/midtrans`, `POST /webhook/waba` (HMAC wajib — TIDAK ada `GET /webhook/waba`, api.co.id tidak butuh hub_challenge handshake seperti Meta native, lihat §WABA PROVIDER INTEGRATION)

### Protected (`auth:sanctum` + `throttle:api`)
- `POST /logout`, `GET /me`
- `POST /topup`
- `GET /dashboard`
- `GET /onboarding/request-number`, `POST /onboarding/request-number` (pengajuan nomor WA tenant — prasyarat wajib sebelum `waba_phone_id` diisi, lihat §WABA PROVIDER INTEGRATION)
- `POST /contacts/import` (async — return 202 + `import_id`, proses jalan di queue `default`)
- `GET /contacts/import/{contactImport}` (status/progress — fallback polling di luar Reverb)
- `DELETE /contacts/import/{contactImport}` (hapus riwayat import)
- `GET /contact-groups`, `POST /contact-groups`
- `GET /templates`, `POST /templates` (create+submit ke api.co.id jadi 1 aksi), `GET /templates/{template}/refresh` (sync status approval)
- `GET /campaigns`, `POST /campaigns`, `GET /campaigns/{campaign}`
- `GET /chats`, `GET /chats/{phone}`, `POST /chats/{phone}/send`

Detail request/response contoh: lihat [documentation.md §5](documentation.md#5-api-endpoints-reference).

---

## KRITIKAL TEKNIS (BACKEND) — WAJIB DIPATUHI

- **Transaksi Saldo/Wallet:** WAJIB pakai `Wallet::deductBalance()` (implementasi `lockForUpdate` + DB transaction). DILARANG manipulasi `balance` langsung — race condition.
- **Queue Worker:** 3 queue aktif — `webhook` (prioritas tertinggi), `blast` (heavy duty: kalkulasi wallet, lockForUpdate, kirim ke WABA), `default` (sinkronisasi template, import/export, report). Worker production: `queue:work redis --queue=webhook,blast,default --tries=3`.
- **✅ Rate limit blast** — `ProcessWaBlast::handle()` pakai `Redis::throttle('apicoid_send_rate_limit')->allow(1)->every(1)`, **global** (bukan per-tenant, karena limit 60/menit api.co.id di level akun master yang dipakai semua tenant). Jangan ubah jadi per-tenant lagi — itu bug yang sudah diperbaiki.
- **Performa DB:** Anti N+1 mutlak — `Model::preventLazyLoading()` aktif di non-production (`AppServiceProvider::boot()`), akan **fatal error** kalau lazy load kedeteksi saat testing.
- **Inbound DLR (Webhook):** Endpoint webhook WAJIB ringan — hanya verifikasi HMAC lalu dispatch ke queue `webhook`, return 200 segera (non-blocking). Jangan proses payload secara sinkron di controller.
- **Tenant Isolation:** Semua model tenant-scoped WAJIB pakai trait `BelongsToTenant` (global scope otomatis filter `tenant_id`). Jangan query manual tanpa scope ini di controller/job.
- **Config Safety:** `env()` HANYA boleh dipanggil di `config/*.php`. Controller/Model/Job selalu pakai `config('services.xxx')`.
- **Rate Limiting:** 3 limiter terdaftar di `AppServiceProvider` — `login` (5/menit by email/IP), `api` (100/menit by user ID), `webhook` (200/menit by IP). Jangan tambah endpoint publik baru tanpa throttle middleware.

---

## SECURITY ARCHITECTURE (12 Layer — Sudah Diimplementasi)

Referensi lengkap: [documentation.md §11](documentation.md#11-security-architecture).

| Layer | Mekanisme | Lokasi |
|-------|-----------|--------|
| L1 | No `env()` di controller | `config('services.*')` |
| L2 | WABA HMAC-SHA256 | `WebhookController::handle()` |
| L3 | Midtrans SHA512 signature | `MidtransController::webhook()` |
| L4 | OTP brute force lockout (3-strike) | `AuthController::login()` |
| L5 | OTP single-use (replay prevention) | `Cache::forget()` post-login |
| L6 | `hash_equals()` untuk semua komparasi kriptografis | — |
| L7 | Pessimistic locking (`lockForUpdate`) | `Wallet::deductBalance()` |
| L8 | Credential encryption (`encrypted` cast) | `Tenant::waba_api_key` |
| L9 | Tenant isolation (Global Scope) | `BelongsToTenant` trait |
| L10 | Idempotency (`firstOrCreate`) | `ProcessWebhook` job |
| L11 | Payload bomb guard (max 1MB) | `WebhookController` |
| L12 | File upload guard (MIME + size + unlink) | `ContactController` |

Jangan hapus/lemahkan layer ini tanpa instruksi eksplisit dari user — ini hasil hardening patch yang sudah diverifikasi lewat AI pentest.

---

## WABA PROVIDER INTEGRATION (api.co.id) — Implemented, Belum Ditest Traffic Asli

> Detail lengkap + spesifikasi API + checklist onboarding tenant:
> [documentation.md §7](documentation.md#7-waba-provider-integration-apicoid).
> Ini ringkasan untuk quick-reference.

**Model bisnis:** Ekho = reseller. 1 akun master api.co.id (1 API key untuk
SEMUA tenant), bayar Rp100rb/nomor/bulan, jual kembali ke tenant lewat markup
di sistem wallet yang sudah ada. **BUKAN** model "tiap tenant punya akun api.co.id
sendiri" — jangan desain fitur dengan asumsi itu.

**Sudah diimplementasikan:**
- `tenants.waba_api_key` & `tenants.waba_endpoint` → **dihapus** (migration `2026_07_24_000004`). API key jadi config level-aplikasi di `config('services.apicoid')`, base URL `https://chat.api.co.id/api/v1/public`
- `tenants.waba_phone_id` → isi `whatsapp_phone_number_id` dari api.co.id, diinput manual oleh Superadmin (`TenantResource`)
- `MessageController::send()` & `ProcessWaBlast` → payload `{phone_number, channel, message_type, content/template, whatsapp_phone_number_id}`, parse response `data.message_id`
- `WebhookController` → verifikasi header `X-Webhook-Signature` (hex HMAC-SHA256 polos, TANPA prefix `sha256=`). Secret **beda per endpoint terdaftar** (confirmed vendor) — kita cuma register 1 endpoint produksi jadi tetap 1 secret di `config('services.apicoid.webhook_secret')`, maks 3 endpoint/akun kalau butuh tambah nanti
- `ProcessWebhook` → payload flat `{event, timestamp, data}`, event terpisah per status (`message.received`/`sent`/`delivered`/`read`/`failed`). Field routing tenant: `data.phone_number_id` (✅ confirmed vendor 2026-07-24 — BUKAN `whatsapp_phone_number_id` seperti asumsi awal, sudah diperbaiki). **Fail-safe** tetap aktif: kalau field tidak ada di payload atau tenant tidak ketemu, job log error lalu STOP — TIDAK menebak routing (mencegah kebocoran data antar tenant)
- Endpoint `GET /webhook/waba` (hub_challenge Meta native) → **dihapus**, tidak dibutuhkan api.co.id
- Rate limiter blast → `Redis::throttle('apicoid_send_rate_limit')->allow(1)->every(1)`, **global** (bukan per-tenant). ⚠️ Ini cuma cover limit 60/menit kirim WhatsApp — vendor confirmed ADA JUGA limit global 100 request/menit per API key lintas semua endpoint (template/media/dst), belum ditangani di kode manapun. Vendor tidak antre otomatis saat limit tercapai (429 langsung, respect header `Retry-After`)

**⚠️ Belum pernah ditest dengan traffic asli** — isi `APICOID_API_KEY`/`APICOID_WEBHOOK_SECRET` di `.env`. Field routing webhook sudah dikonfirmasi & diperbaiki (`data.phone_number_id`), tapi **tetap wajib test dengan payload webhook asli** sebelum production untuk validasi end-to-end.

**Onboarding nomor WhatsApp tenant (Implemented):** model "assisted" — tenant
WAJIB login Facebook pribadi sendiri saat Embedded Signup (Ekho tidak
bisa/boleh melakukan ini atas nama tenant). Alurnya sekarang penuh
end-to-end: tenant ajukan lewat halaman `/onboarding` (frontend) →
`POST /onboarding/request-number` → Superadmin lihat & proses di
`WhatsappNumberRequestResource` (Filament) → Ekho staf proses manual di
dashboard api.co.id → Superadmin input `whatsapp_phone_number_id` hasilnya ke
`TenantResource.waba_phone_id` — **field inilah** yang benar-benar membuka
gate, bukan status di `WhatsappNumberRequestResource`. Selama `waba_phone_id`
masih `null`, frontend nge-block SELURUH dashboard tenant (bukan cuma
blast/chat) dan redirect ke `/onboarding`. Checklist syarat tenant ada di
documentation.md §7.

**Tanggung jawab operasional wajib (bukan kode, tapi harus ada proses/UI-nya):**
monitor kesehatan webhook (auto-disable setelah 10 gagal berturut-turut,
dampaknya ke SEMUA tenant sekaligus), kelola submit/approval template, pantau
quality rating nomor per tenant, enforce consent sebelum blast MARKETING,
rekonsiliasi biaya bulanan.

---

## IMPORT KONTAK — ASYNC PIPELINE (Fixed)

`ContactController::import()` tidak lagi memproses file secara sinkron dalam siklus HTTP. Alurnya sekarang:

```
POST /contacts/import
  ├── Validasi file (max 10MB, mimes xlsx/xls/csv) + retention_policy/retention_days
  ├── Simpan file ke disk 'local' (storage/app/private/imports/{uuid})
  ├── ContactImport::create(status: PENDING, expires_at dari retention_days jika policy=auto)
  ├── ImportContactsJob::dispatch()->onQueue('default')
  └── Return 202 { import_id }

ImportContactsJob [Worker]
  ├── status → PROCESSING, started_at
  ├── Baca file via SimpleExcelReader, proses per-baris (sanitasi nomor E.164, dynamic_data)
  ├── Commit DB + broadcast ImportProgressUpdated tiap 500 baris (BATCH_SIZE, bukan per-row — anti overload)
  ├── status → COMPLETED/FAILED, file_path di-null-kan + file di-unlink
  └── tries=1 — TIDAK retry (retry akan menduplikasi Contact yang sudah ter-insert)
```

**Retention riwayat import** (`contact_imports.retention_policy`):
- `keep` — simpan permanen, tidak pernah dihapus otomatis
- `auto` — dihapus otomatis oleh `contacts:cleanup-imports` (scheduled daily) saat `expires_at` (dihitung dari `retention_days` saat request) terlampaui
- `manual` (default) — tidak ada auto-delete, user hapus eksplisit via `DELETE /contacts/import/{id}`

**Realtime progress:** broadcast ke `PrivateChannel: tenant.{tenant_id}.imports` (pola sama seperti `ChatReceived`). Frontend WAJIB tetap punya fallback polling `GET /contacts/import/{id}` untuk kasus reload halaman / socket belum connect saat job sudah mulai jalan.

---

## SUPERADMIN DASHBOARD (Implemented)

> Status: **sudah diimplementasi** (Filament v4). Baca ini sebelum mengubah
> apapun di area ini agar tidak menyimpang dari desain yang sudah disepakati.

**Tujuan:** panel internal untuk tim Ekho (bukan tenant) — mendaftarkan akun
user baru, manajemen tenant, manajemen sesama admin, audit log, dan
akses log/config server. Karena panel ini bisa melihat semua data tenant dan
membuat akun, ini permukaan risiko tertinggi di seluruh sistem.

### Prinsip Arsitektur (Non-Negotiable)
- **Realm auth terpisah total dari tenant.** Tabel baru `admin_users` (bukan
  `users`), guard baru `admin` di `config/auth.php` (bukan `sanctum`/`web`
  yang dipakai tenant). Tidak ada shared session, shared token, atau
  shared login page dengan sisi tenant.
- **Tool:** Filament v4, dijalankan di codebase Laravel yang sama tapi
  di-serve di **subdomain terpisah** `admin.ekho.imaga.site` (`->domain()`),
  **tidak** lewat prefix `/api/*`, **tidak** masuk daftar CORS (`config/cors.php`)
  sama sekali — panel ini server-rendered, auth pakai session cookie
  (`httpOnly` + `secure` + `SameSite=Strict`), bukan Bearer token di
  localStorage.
- **Tidak ada self-registration.** Akun admin pertama dibuat via
  `php artisan admin:create` (interactive, bukan route publik). Admin
  berikutnya hanya bisa dibuat oleh admin yang sudah login.
- **2FA (TOTP) wajib**, tidak bisa dilewati/dinonaktifkan dari UI.

### Skema yang Akan Ditambah
```
admin_users
├── name, email (unique), password (hashed)
├── two_factor_secret (encrypted), two_factor_recovery_codes (encrypted), two_factor_confirmed_at
├── is_active
├── last_login_at, last_login_ip
├── created_by (FK ke admin_users.id sendiri — self-referential)
└── timestamps

admin_audit_logs   (via spatie/laravel-activitylog + z3d0x/filament-logger — jangan hand-roll)
├── admin_user_id, action, subject_type, subject_id
├── before[], after[] (snapshot)
├── ip_address
└── created_at
```

### Scope Fitur v1 (Disepakati dengan User)
1. Manajemen Tenant — list, detail (kredensial WABA di-mask, reveal eksplisit & ter-log), suspend/reaktivasi
2. Manajemen User — list lintas tenant, **create akun user baru** (assign tenant+role), revoke token
3. Manajemen Admin — CRUD sesama admin, tanpa self-registration
4. Audit Log Viewer — searchable
5. **Server management scope disepakati SEMPIT:** hanya baca `storage/logs/laravel.log` (read-only) + lihat (bukan edit) config non-secret. **TIDAK** ada job retry/restart, **TIDAK** ada remote command execution — kalau ada permintaan menambah fitur eksekusi command dari dashboard, itu di luar scope yang disepakati dan berisiko setara backdoor; konfirmasi ulang ke user sebelum implementasi.

### File Kunci
- `app/Models/AdminUser.php` — realm terpisah, implements `HasAppAuthentication`/`HasAppAuthenticationRecovery` (2FA bawaan Filament v4, TOTP — TIDAK pakai package 2FA pihak ketiga)
- `database/migrations/2026_07_24_000002_create_admin_users_table.php`
- `config/auth.php` — guard `admin` + provider `admin_users` + password broker `admin_users`
- `config/activitylog.php` — `table_name` di-override ke `admin_audit_logs`, `default_auth_driver` dipaksa ke `admin`
- `app/Providers/Filament/AdminPanelProvider.php` — `authGuard('admin')`, `multiFactorAuthentication(isRequired: true)`, TIDAK memanggil `->registration()`
- `app/Console/Commands/CreateAdminUser.php` — `php artisan admin:create`, satu-satunya jalur bootstrap
- `app/Filament/Resources/{Tenants,Users,AdminUsers,AuditLogs,WhatsappNumberRequests}/` — resource CRUD (`WhatsappNumberRequests` bukan bagian scope v1 asli, ditambahkan menyertai fitur gate onboarding — read/edit status saja, tidak bisa create manual dari panel)
- `app/Filament/Pages/SystemLogs.php` — log/config viewer, read-only, whitelist config eksplisit

### Belum Dikerjakan / Perlu Aksi Manual dari User
- **Jalankan `php artisan admin:create`** di terminal untuk bikin akun superadmin pertama (interactive prompt, password tidak boleh lewat percakapan AI).
- Set `ADMIN_DOMAIN=admin.ekho.imaga.site` di `.env` production setelah DNS subdomain diarahkan. Kosong = panel jalan di domain manapun (dipakai untuk local dev).
- IP allowlist di level reverse-proxy/hosting untuk subdomain admin — di luar kendali kode Laravel, perlu dikonfigurasi terpisah oleh user.

---

## DEMO DASHBOARD (Implemented)

**Tujuan:** dashboard demo tertutup untuk sales/promosi/tutorial end-to-end.
Semua fitur "menyala" (kirim pesan, buat campaign, import, top-up) TAPI datanya
100% dummy di frontend — **tidak terhubung ke WhatsApp, database tenant, atau
API apapun**. Dipakai untuk iklan & visual "how to".

### Prinsip Arsitektur (Non-Negotiable)
- **Realm ketiga, terpisah total.** TIDAK menyentuh `users` (tenant) maupun
  `admin_users`. Sesi demo BUKAN Sanctum token — secara desain tidak bisa lolos
  `auth:sanctum`, jadi tidak bisa dipakai akses `/api/*` produksi walau bocor.
- **Data demo hidup di frontend** (`src/store/demo.ts`, Zustand, in-memory
  per-sesi browser). Backend HANYA menyimpan token akses + allowlist email &
  memvalidasi login — tidak pernah menyuplai data bisnis ke halaman demo.
- **Isolasi crawler:** `/demo/*` di-`disallow` di `robots.ts` frontend.

### Cara Kerja Token
- **Rotating** — 1 baris aktif, di-shuffle tiap 5 jam (`demo:rotate-token`,
  scheduled cron `0 */5 * * *`) DAN saat ada logout manual sesi rotating
  (`DemoAuthController::logout` panggil `DemoTokenService::rotate`). Sesi lama
  langsung invalid (fingerprint sha256 token tak cocok lagi).
- **Permanent** — dibuat manual Superadmin (`DemoAccessTokenResource`), tidak
  pernah rotate, bisa di-revoke kapan saja (`is_revoked`). Sesi via token
  permanent TIDAK memunculkan toast timer (tidak ada countdown).
- **Login demo** = email (∈ `demo_allowed_emails`, default `dev-demo@ekho.chat`)
  + token valid. Email tidak mengirim email nyata — hanya allowlist gaya OTP.
  Sesi = `Crypt::encryptString` payload `{email, token_id, type, fingerprint,
  exp}`, disimpan di `localStorage` frontend, dikirim via header `X-Demo-Session`.

### File Kunci
- `database/migrations/2026_07_24_000006_create_demo_access_tables.php` — 2 tabel + seed
- `app/Models/{DemoAccessToken,DemoAllowedEmail}.php`
- `app/Services/DemoTokenService.php` — generate 8-char (tanpa 0/O/1/I), rotate, validate
- `app/Http/Controllers/Api/DemoAuthController.php` — `POST /demo/login`, `POST /demo/logout`, `GET /demo/session` (semua `throttle:login`)
- `app/Console/Commands/RotateDemoToken.php` — `demo:rotate-token`
- `app/Filament/Resources/{DemoAccessTokens,DemoAllowedEmails}/` — kelola token & allowlist
- Frontend: `src/app/demo/*`, `src/store/demo.ts`, `src/lib/demo/session.ts`, `src/components/demo/*`

---

## KNOWN LIMITATIONS (masih terbuka)

- 🔴 **Integrasi api.co.id belum pernah ditest traffic asli** — field routing webhook sudah dikonfirmasi & diperbaiki (`data.phone_number_id`), tapi tetap wajib test end-to-end dengan payload webhook asli sebelum production. Lihat §WABA PROVIDER INTEGRATION.
- 🟡 Rate limit global 100 req/menit per API key (lintas semua endpoint api.co.id, bukan cuma kirim pesan) belum ditangani di kode — lihat documentation.md §16.
- 🟡 Monitoring auto-disable webhook belum ada proses internal — vendor kirim email notifikasi saat webhook nonaktif otomatis (10 percobaan gagal), tapi belum ada yang ditugaskan memonitor.
- Belum ada API versioning (`/api/v1/`).
- Dashboard belum caching (kandidat `Cache::remember()` 5 menit per tenant).

Sebelum menganggap sebuah fitur "belum ada", cek dulu `app/Http/Controllers/Api/`, `app/Jobs/`, dan `routes/api.php` — dokumentasi historis (documentation.md §14) bisa lebih lama dari kode aktual.
