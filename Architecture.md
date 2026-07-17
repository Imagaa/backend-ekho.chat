# Architecture & Database Topology

## 1. Skema Multi-Tenancy
Single Database, Multi-Tenant Logic. Setiap tabel data wajib memiliki relasi ke entitas `Tenant`. Isolasi data menggunakan Scope Laravel.

## 2. Core Entities
- `tenants` (Entitas klien perusahaan)
- `users` (Akses Role: Owner, CS, Admin — terikat pada `tenant_id`)
- `wallets` (Saldo keuangan, relasi 1:1 ke tenant)
- `contacts` & `contact_groups` (Data list nomor)
- `templates` (Miror dari API WABA Meta)
- `campaigns` (Header/Config pengiriman blast)
- `message_logs` (Detail granular tiap pesan & webhook status)

## 3. Pipeline Queue (Redis)
- `queue:webhook` : Prioritas tertinggi. Murni menyimpan payload webhook `api.co.id` secepat mungkin lalu auto-release.
- `queue:blast` : Heavy duty. Mengatur kalkulasi wallet, lockForUpdate, memformat pesan, dan hit outbound endpoint `api.co.id`.
- `queue:default` : Sinkronisasi template, import/export excel, report generator.
