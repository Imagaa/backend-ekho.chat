<?php

namespace App\Console\Commands;

use App\Models\AdminUser;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

/**
 * Satu-satunya cara membuat akun Superadmin. TIDAK ada route/endpoint web
 * untuk registrasi admin — lihat AGENTS.md §SUPERADMIN DASHBOARD.
 */
class CreateAdminUser extends Command
{
    protected $signature = 'admin:create';
    protected $description = 'Buat akun Superadmin baru (satu-satunya cara — tidak ada self-registration)';

    public function handle(): int
    {
        $name = text(
            label: 'Nama',
            required: true,
            validate: fn (string $value) => match (true) {
                strlen($value) < 2 => 'Nama minimal 2 karakter.',
                default => null,
            },
        );

        $email = text(
            label: 'Email',
            required: true,
            validate: function (string $value) {
                $validator = Validator::make(['email' => $value], [
                    'email' => ['required', 'email', 'unique:admin_users,email'],
                ]);

                return $validator->fails() ? $validator->errors()->first('email') : null;
            },
        );

        $password = password(
            label: 'Password',
            required: true,
            validate: fn (string $value) => match (true) {
                strlen($value) < 12 => 'Password minimal 12 karakter.',
                default => null,
            },
        );

        password(
            label: 'Konfirmasi Password',
            required: true,
            validate: fn (string $value) => $value !== $password ? 'Password tidak cocok.' : null,
        );

        $admin = AdminUser::create([
            'name' => $name,
            'email' => $email,
            'password' => Hash::make($password),
            'is_active' => true,
        ]);

        $this->newLine();
        $this->components->info("Akun Superadmin '{$admin->email}' berhasil dibuat.");
        $this->components->warn('2FA (TOTP) WAJIB disetup saat login pertama — panel tidak bisa diakses tanpanya.');

        return self::SUCCESS;
    }
}
