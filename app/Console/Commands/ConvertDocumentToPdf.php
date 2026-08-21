<?php

namespace App\Console\Commands;

use App\Services\Documents\PdfConverter;
use Illuminate\Console\Command;

class ConvertDocumentToPdf extends Command
{
    protected $signature = 'sim-pd:convert-document {input} {output}';

    protected $description = 'Mengonversi dokumen SIM-PD menjadi PDF melalui proses CLI terisolasi';

    public function handle(): int
    {
        $converter = new PdfConverter(
            (string) config('sim_pd.documents.libreoffice.binary'),
            (int) config('sim_pd.documents.libreoffice.timeout', 60),
        );

        $outputPath = $converter->convert(
            (string) $this->argument('input'),
            (string) $this->argument('output'),
        );

        $this->line($outputPath);

        return self::SUCCESS;
    }
}
