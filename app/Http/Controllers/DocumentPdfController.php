<?php

namespace App\Http\Controllers;

use App\Models\Document;
use App\Services\DocumentPdfService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class DocumentPdfController extends Controller
{
    public function __construct(private DocumentPdfService $pdfService) {}

    /**
     * Stream the document PDF inline.
     */
    public function show(Document $document): Response
    {
        return $this->pdfService->stream($document);
    }

    /**
     * Download the document PDF as an attachment.
     */
    public function download(Document $document): Response
    {
        return $this->pdfService->download($document);
    }

    public function recordPrint(Document $document): JsonResponse
    {
        $document->increment('print_count');

        return response()->json(['print_count' => $document->print_count]);
    }
}
