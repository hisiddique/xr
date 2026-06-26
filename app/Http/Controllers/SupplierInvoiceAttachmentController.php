<?php

namespace App\Http\Controllers;

use App\Models\SupplierInvoice;
use Illuminate\Support\Facades\Storage;
use ZipArchive;

class SupplierInvoiceAttachmentController extends Controller
{
    public function download(SupplierInvoice $supplierInvoice): mixed
    {
        $attachments = $supplierInvoice->attachments ?? [];

        if (empty($attachments)) {
            abort(404);
        }

        if (count($attachments) === 1) {
            $att = $attachments[0];

            return Storage::disk('public')->download($att['path'], $att['original_name']);
        }

        $zipPath = tempnam(sys_get_temp_dir(), 'supinv_');
        $zip = new ZipArchive;
        $zip->open($zipPath, ZipArchive::OVERWRITE);

        foreach ($attachments as $att) {
            $fullPath = Storage::disk('public')->path($att['path']);
            if (file_exists($fullPath)) {
                $zip->addFile($fullPath, $att['original_name']);
            }
        }

        $zip->close();

        $filename = $supplierInvoice->supplier_invoice_no.'-attachments.zip';

        return response()->download($zipPath, $filename)->deleteFileAfterSend(true);
    }
}
