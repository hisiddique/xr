<?php

namespace App\Services;

use App\Models\Document;
use Barryvdh\DomPDF\Facade\Pdf;
use Symfony\Component\HttpFoundation\Response;

class DocumentPdfService
{
    /**
     * Generate raw PDF binary for a document.
     */
    public function generate(Document $document): string
    {
        $document->loadMissing([
            'customer.title',
            'customer.creditTerm',
            'customer.creditLimit',
            'items',
        ]);

        return Pdf::loadView('pdfs.document', ['document' => $document])
            ->setOption('isPhpEnabled', true)->output();
    }

    /**
     * Return a streamed inline PDF response.
     */
    public function stream(Document $document): Response
    {
        $document->loadMissing([
            'customer.title',
            'customer.creditTerm',
            'customer.creditLimit',
            'items',
        ]);

        return Pdf::loadView('pdfs.document', ['document' => $document])
            ->setOption('isPhpEnabled', true)
            ->stream("{$document->doc_number}.pdf");
    }

    /**
     * Return a downloadable PDF response.
     */
    public function download(Document $document): Response
    {
        $document->loadMissing([
            'customer.title',
            'customer.creditTerm',
            'customer.creditLimit',
            'items',
        ]);

        return Pdf::loadView('pdfs.document', ['document' => $document])
            ->setOption('isPhpEnabled', true)
            ->download("{$document->doc_number}.pdf");
    }
}
