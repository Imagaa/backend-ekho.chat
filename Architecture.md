# Architecture & Database Topology

## 1. Skema Multi-Tenancy
Single Database, Multi-Tenant Logic. Setiap tabel data wajib memiliki relasi ke entitas `Tenant`. Isolasi data menggunakan Scope Laravel (`BelongsToTenant` trait — global scope otomatis filter `tenant_id`, aktif hanya saat `Auth::check()`).

## 2. Core Entities (Tenant Realm)
- `tenants` — Entitas klien perusahaan. ⚠️ Skema kredensial WABA sedang berubah: model Ekho adalah **reseller 1-akun-master** (lihat §7 di bawah), bukan kredensial WABA per-tenant seperti asumsi awal
- `users` (Akses Role: Owner, CS, Admin — terikat pada `tenant_id`, login via OTP email + Sanctum token)
- `wallets` (Saldo keuangan, relasi 1:1 ke tenant, mutasi wajib lewat `Wallet::deductBalance()` dengan `lockForUpdate`)
- `contacts` & `contact_groups` (Data list nomor)
- `contact_imports` (Riwayat & status proses import async, lihat §5)
- `templates` (Mirror dari API WABA Meta)
- `campaigns` (Header/Config pengiriman blast)
- `message_logs` (Detail granular tiap pesan & webhook status)
- `chats` (Riwayat pesan inbound/outbound, unique `message_id_meta` untuk idempotency)

## 3. Core Entities (Admin Realm — Terpisah Total)
- `admin_users` — akun tim internal Ekho. **Tidak** terhubung/terikat ke `tenant_id`, **tidak** boleh saling referensi dengan `users`. Guard Laravel `admin` sendiri, session-based, 2FA wajib.
- `admin_audit_logs` — jejak setiap aksi admin (siapa, apa, kapan, IP, before/after).

Lihat [AGENTS.md §SUPERADMIN DASHBOARD](AGENTS.md) untuk detail desain & batasan scope.

## 4. Pipeline Queue (Redis)
- `queue:webhook` : Prioritas tertinggi. Murni menyimpan payload webhook WABA secepat mungkin lalu auto-release (`ProcessWebhook` job).
- `queue:blast` : Heavy duty. Mengatur kalkulasi wallet, lockForUpdate, memformat pesan, dan hit outbound endpoint WABA (`ProcessBlastCampaign` → `ProcessWaBlast` per kontak). Rate limit api.co.id 60 pesan/menit per akun — throttle global (`Redis::throttle`, 1/detik) sudah diimplementasikan di `ProcessWaBlast`, lihat §7.
- `queue:default` : Sinkronisasi template, import kontak async (`ImportContactsJob`), report generator.

## 7. WABA Provider (api.co.id) — Model Reseller

Ekho **bukan** pengguna api.co.id biasa — Ekho beli akses grosir (1 akun master,
1 API key, Rp100rb/nomor/bulan) lalu jual kembali ke tenant lewat markup di
sistem wallet. Setiap tenant dapat 1 `whatsapp_phone_number_id` di bawah akun
master itu — bukan kredensial WABA terpisah per tenant.

Integrasi teknis (format kirim pesan, verifikasi webhook, rate limiter) **sudah
diimplementasikan, belum pernah ditest traffic asli** — spesifikasi lengkap
di [documentation.md §7](documentation.md#7-waba-provider-integration-apicoid),
ringkasan aturan di [AGENTS.md §WABA PROVIDER INTEGRATION](AGENTS.md). Alur
onboarding nomor tenant (assisted, lewat dashboard api.co.id) sudah
didokumentasikan tapi halaman frontend-nya belum dibangun.

## 5. Import Kontak — Alur Async
`POST /contacts/import` tidak lagi memproses file secara sinkron. Controller menyimpan file + membuat baris `contact_imports` (status `PENDING`), lalu dispatch `ImportContactsJob` ke `queue:default`. Job memproses per-baris, commit DB + broadcast progres (`ImportProgressUpdated` via Reverb, channel `tenant.{id}.imports`) tiap 500 baris untuk menghindari overload. Retensi riwayat (`retention_policy`: `keep`/`auto`/`manual`) dibersihkan otomatis untuk kebijakan `auto` oleh command terjadwal `contacts:cleanup-imports`. Detail lengkap: [AGENTS.md §IMPORT KONTAK](AGENTS.md).

## 6. Realtime (Laravel Reverb)
Channel privat per-tenant: `tenant.{id}.chats` (pesan masuk) dan `tenant.{id}.imports` (progres import). Autentikasi channel lewat `/broadcasting/auth` — didaftarkan manual dengan middleware `['api', 'auth:sanctum']` (stateless, Bearer token), **bukan** default `['web']` bawaan Laravel yang session-based dan tidak kompatibel dengan frontend Next.js yang tidak pernah membuat session.
