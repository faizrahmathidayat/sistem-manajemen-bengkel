<?php

namespace Tests\Concerns;

use Smalot\PdfParser\Parser;

trait ExtractsPdfText
{
    protected function extractPdfText(string $pdfBinary): string
    {
        $parser = new Parser();
        $document = $parser->parseContent($pdfBinary);

        return $document->getText();
    }
}
