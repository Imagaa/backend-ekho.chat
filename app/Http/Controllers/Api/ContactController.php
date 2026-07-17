<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Models\ContactGroup;
use Illuminate\Http\Request;
use Spatie\SimpleExcel\SimpleExcelReader;
use Illuminate\Support\Facades\DB;

class ContactController extends Controller
{
    public function import(Request $request)
    {
        $request->validate([
            'contact_group_id' => 'required|exists:contact_groups,id',
            'file' => 'required|mimes:csv,xlsx,xls|max:10240', // Max 10MB
        ]);

        // Global scope tenant otomatis memastikan group ini milik user yang login
        $group = ContactGroup::findOrFail($request->contact_group_id);

        $file = $request->file('file');
        
        $imported = 0;
        $skipped = 0;

        DB::beginTransaction();
        try {
            SimpleExcelReader::create($file->path())
                ->getRows()
                ->each(function(array $row) use (&$imported, &$skipped, $group) {
                    // Cari header dinamis (case-insensitive)
                    $phoneKey = $this->findKey($row, ['phone', 'nomor', 'no_hp', 'whatsapp']);
                    $nameKey = $this->findKey($row, ['name', 'nama', 'pelanggan']);

                    $phone = $phoneKey ? $row[$phoneKey] : null;
                    $name = $nameKey ? $row[$nameKey] : null;

                    if (!$phone) {
                        $skipped++;
                        return; // Skip jika tidak ada nomor
                    }

                    $sanitizedPhone = $this->sanitizePhone($phone);

                    if (!$sanitizedPhone) {
                        $skipped++;
                        return; // Skip jika nomor tidak valid
                    }

                    // Ambil sisa kolom sebagai variabel dinamis (untuk placeholder Meta Template)
                    $dynamicData = collect($row)->except([$phoneKey, $nameKey])->filter()->toArray();

                    Contact::create([
                        'contact_group_id' => $group->id,
                        'name' => $name,
                        'phone' => $sanitizedPhone,
                        'dynamic_data' => empty($dynamicData) ? null : $dynamicData,
                    ]);

                    $imported++;
                });

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'Proses import selesai.',
                'data' => [
                    'imported' => $imported,
                    'skipped' => $skipped
                ]
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'status' => 'error',
                'message' => 'Gagal memproses file: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Sanitasi nomor ke format E.164 (628xxx)
     */
    private function sanitizePhone($phone)
    {
        // 1. Hilangkan semua karakter non-numerik (spasi, strip, plus, dll)
        $number = preg_replace('/[^0-9]/', '', (string)$phone);

        // 2. Jika diawali '08', ubah menjadi '628'
        if (str_starts_with($number, '08')) {
            $number = '62' . substr($number, 1);
        }

        // 3. Validasi minimal panjang standar Indonesia & berawalan 62
        if (strlen($number) >= 10 && str_starts_with($number, '62')) {
            return $number;
        }

        return null;
    }

    /**
     * Helper mencari nama kolom case-insensitive
     */
    private function findKey(array $row, array $possibleKeys)
    {
        $keys = array_keys($row);
        foreach ($keys as $key) {
            if (in_array(strtolower(trim($key)), $possibleKeys)) {
                return $key;
            }
        }
        return null;
    }
}