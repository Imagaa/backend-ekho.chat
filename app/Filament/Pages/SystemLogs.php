<?php

namespace App\Filament\Pages;

use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

/**
 * Read-only, scope disepakati SEMPIT (lihat AGENTS.md §SUPERADMIN DASHBOARD):
 * baca log aplikasi + lihat config non-secret. TIDAK ADA retry/restart job,
 * TIDAK ADA remote command execution. JANGAN tambah aksi tulis/eksekusi di
 * halaman ini tanpa konfirmasi ulang ke user — itu di luar scope yang disepakati.
 */
class SystemLogs extends Page
{
    protected string $view = 'filament.pages.system-logs';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCommandLine;

    protected static ?string $navigationLabel = 'System Logs';

    public string $search = '';

    public int $lines = 200;

    /**
     * Whitelist eksplisit — JANGAN ganti jadi dump config() penuh, itu akan
     * membocorkan secret (APP_KEY, DB_PASSWORD, WABA_APP_SECRET, dll).
     */
    protected function nonSecretConfigKeys(): array
    {
        return [
            'app.name',
            'app.env',
            'app.debug',
            'app.url',
            'app.timezone',
            'database.default',
            'cache.default',
            'queue.default',
            'session.driver',
            'session.lifetime',
            'filesystems.default',
            'broadcasting.default',
            'mail.default',
        ];
    }

    public function getConfigSnapshot(): array
    {
        return collect($this->nonSecretConfigKeys())
            ->mapWithKeys(fn (string $key) => [$key => var_export(config($key), true)])
            ->toArray();
    }

    public function logLines(): array
    {
        $path = storage_path('logs/laravel.log');

        if (! is_file($path)) {
            return ['(storage/logs/laravel.log belum ada)'];
        }

        $tail = $this->tailFile($path, 2000); // ambil buffer lebih besar dari $lines untuk difilter search
        $entries = array_reverse(array_filter(explode("\n", $tail)));

        if (filled($this->search)) {
            $entries = array_filter(
                $entries,
                fn (string $line) => str_contains(strtolower($line), strtolower($this->search))
            );
        }

        return array_slice($entries, 0, $this->lines);
    }

    /**
     * Baca N baris terakhir tanpa load seluruh file ke memori — penting
     * karena laravel.log production bisa tumbuh besar.
     */
    protected function tailFile(string $path, int $maxLines): string
    {
        $size = filesize($path);
        $chunkSize = 8192;
        $handle = fopen($path, 'r');
        $buffer = '';
        $lineCount = 0;
        $pos = $size;

        while ($pos > 0 && $lineCount <= $maxLines) {
            $readSize = min($chunkSize, $pos);
            $pos -= $readSize;
            fseek($handle, $pos);
            $chunk = fread($handle, $readSize);
            $buffer = $chunk . $buffer;
            $lineCount = substr_count($buffer, "\n");
        }

        fclose($handle);

        return $buffer;
    }
}
