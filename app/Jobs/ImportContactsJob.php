<?php

namespace App\Jobs;

use App\Events\ImportProgressUpdated;
use App\Models\Contact;
use App\Models\ContactImport;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Spatie\SimpleExcel\SimpleExcelReader;

class ImportContactsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    // Jangan retry — retry akan menduplikasi Contact yang sudah ter-insert dari batch sebelumnya
    public int $tries = 1;
    public int $timeout = 600;

    // Commit & broadcast progress tiap N baris agar tidak membebani DB/Reverb per-row
    private const BATCH_SIZE = 500;

    public function __construct(public readonly int $contactImportId) {}

    public function handle(): void
    {
        $contactImport = ContactImport::findOrFail($this->contactImportId);

        if ($contactImport->status !== 'PENDING') {
            Log::warning("ContactImport #{$this->contactImportId} dilewati, status: {$contactImport->status}");
            return;
        }

        $contactImport->update(['status' => 'PROCESSING', 'started_at' => now()]);

        $imported = 0;
        $skipped = 0;

        try {
            $absolutePath = Storage::disk('local')->path($contactImport->file_path);

            $rowsInBatch = 0;
            DB::beginTransaction();

            SimpleExcelReader::create($absolutePath)
                ->getRows()
                ->each(function (array $row) use (&$imported, &$skipped, &$rowsInBatch, $contactImport) {
                    $phoneKey = $this->findKey($row, ['phone', 'nomor', 'no_hp', 'whatsapp']);
                    $nameKey = $this->findKey($row, ['name', 'nama', 'pelanggan']);

                    $phone = $phoneKey ? $row[$phoneKey] : null;
                    $name = $nameKey ? $row[$nameKey] : null;

                    if (!$phone) {
                        $skipped++;
                    } else {
                        $sanitizedPhone = $this->sanitizePhone($phone);

                        if (!$sanitizedPhone) {
                            $skipped++;
                        } else {
                            $dynamicData = collect($row)->except([$phoneKey, $nameKey])->filter()->toArray();

                            Contact::create([
                                'tenant_id' => $contactImport->tenant_id,
                                'contact_group_id' => $contactImport->contact_group_id,
                                'name' => $name,
                                'phone' => $sanitizedPhone,
                                'dynamic_data' => empty($dynamicData) ? null : $dynamicData,
                            ]);

                            $imported++;
                        }
                    }

                    $rowsInBatch++;

                    if ($rowsInBatch >= self::BATCH_SIZE) {
                        DB::commit();
                        $this->reportProgress($contactImport, $imported, $skipped);
                        DB::beginTransaction();
                        $rowsInBatch = 0;
                    }
                });

            DB::commit();

            $contactImport->update([
                'status' => 'COMPLETED',
                'imported_count' => $imported,
                'skipped_count' => $skipped,
                'finished_at' => now(),
                'file_path' => null,
            ]);

            broadcast(new ImportProgressUpdated($contactImport->fresh()));
        } catch (\Exception $e) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }

            $contactImport->update([
                'status' => 'FAILED',
                'error_message' => $e->getMessage(),
                'finished_at' => now(),
            ]);

            broadcast(new ImportProgressUpdated($contactImport->fresh()));

            Log::error("ContactImport #{$this->contactImportId} gagal: " . $e->getMessage());
        } finally {
            $this->cleanupFile($contactImport->file_path);
        }
    }

    private function reportProgress(ContactImport $contactImport, int $imported, int $skipped): void
    {
        $contactImport->update([
            'imported_count' => $imported,
            'skipped_count' => $skipped,
        ]);

        broadcast(new ImportProgressUpdated($contactImport));
    }

    private function cleanupFile(?string $path): void
    {
        if ($path && Storage::disk('local')->exists($path)) {
            Storage::disk('local')->delete($path);
        }
    }

    /**
     * Sanitasi nomor ke format E.164 (628xxx)
     */
    private function sanitizePhone($phone)
    {
        $number = preg_replace('/[^0-9]/', '', (string) $phone);

        if (str_starts_with($number, '08')) {
            $number = '62' . substr($number, 1);
        }

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
        foreach (array_keys($row) as $key) {
            if (in_array(strtolower(trim($key)), $possibleKeys)) {
                return $key;
            }
        }

        return null;
    }

    public function failed(\Throwable $e): void
    {
        $contactImport = ContactImport::find($this->contactImportId);

        if ($contactImport) {
            $contactImport->update([
                'status' => 'FAILED',
                'error_message' => $e->getMessage(),
                'finished_at' => now(),
            ]);

            $this->cleanupFile($contactImport->file_path);

            broadcast(new ImportProgressUpdated($contactImport->fresh()));
        }

        Log::error("ImportContactsJob #{$this->contactImportId} unexpected failure: " . $e->getMessage());
    }
}
