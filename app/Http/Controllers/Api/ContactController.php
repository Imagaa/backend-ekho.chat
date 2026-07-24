<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ImportContactsJob;
use App\Models\ContactGroup;
use App\Models\ContactImport;
use Illuminate\Http\Request;

class ContactController extends Controller
{
    /**
     * Terima file, simpan sementara, lalu proses async lewat queue.
     * File besar tidak lagi diproses sinkron dalam siklus HTTP (anti-timeout).
     */
    public function import(Request $request)
    {
        // Validasi mutlak: Hanya menerima spreadsheet murni, maksimal 10MB (Anti-RCE & Cegah Memory Dump)
        $request->validate([
            'contact_group_id' => 'required|exists:contact_groups,id',
            'file' => 'required|file|mimes:xlsx,xls,csv|max:10240',
            'retention_policy' => 'nullable|in:keep,auto,manual',
            'retention_days' => 'required_if:retention_policy,auto|nullable|integer|min:1|max:365',
        ]);

        // Global scope tenant otomatis memastikan group ini milik user yang login
        $group = ContactGroup::findOrFail($request->contact_group_id);

        $file = $request->file('file');
        $storedPath = $file->store('imports', 'local');

        $retentionPolicy = $request->input('retention_policy', 'manual');
        $retentionDays = $retentionPolicy === 'auto' ? (int) $request->input('retention_days') : null;

        $contactImport = ContactImport::create([
            'contact_group_id' => $group->id,
            'user_id' => $request->user()->id,
            'file_name' => $file->getClientOriginalName(),
            'file_path' => $storedPath,
            'status' => 'PENDING',
            'retention_policy' => $retentionPolicy,
            'retention_days' => $retentionDays,
            'expires_at' => $retentionDays ? now()->addDays($retentionDays) : null,
        ]);

        ImportContactsJob::dispatch($contactImport->id)->onQueue('default');

        return response()->json([
            'status' => 'success',
            'message' => 'Import sedang diproses di background.',
            'data' => ['import_id' => $contactImport->id],
        ], 202);
    }

    /**
     * Status/progress import — fallback polling untuk saat frontend belum/tidak connect ke Reverb.
     */
    public function importStatus(ContactImport $contactImport)
    {
        return response()->json([
            'status' => 'success',
            'data' => $contactImport,
        ]);
    }

    /**
     * Hapus riwayat import (retention_policy: manual atau keep, dihapus atas aksi eksplisit user).
     */
    public function destroyImport(ContactImport $contactImport)
    {
        $contactImport->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Riwayat import dihapus.',
        ]);
    }
}