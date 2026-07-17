<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Wallet extends Model
{
    use HasFactory;

    protected $fillable = ['tenant_id', 'balance'];

    /**
     * KRITIKAL: Pessimistic Locking untuk pemotongan saldo.
     * Mencegah Race Condition saat worker blast berjalan paralel.
     */
    public function deductBalance(float $amount)
    {
        return \Illuminate\Support\Facades\DB::transaction(function () use ($amount) {
            // lockForUpdate memastikan row ini tidak bisa diubah oleh proses/worker lain sampai transaksi ini selesai
            $wallet = self::where('id', $this->id)->lockForUpdate()->first();

            if ($wallet->balance < $amount) {
                throw new \Exception('Saldo tidak mencukupi untuk melanjutkan pengiriman.');
            }

            $wallet->balance -= $amount;
            $wallet->save();

            return $wallet;
        }, 5); // Argumen 5: Otomatis retry 5 kali jika terjadi deadlock di PostgreSQL
    }
}
