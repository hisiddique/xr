<?php

namespace App\Http\Controllers;

use App\Models\Document;
use App\Services\DocumentPdfService;
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
}
