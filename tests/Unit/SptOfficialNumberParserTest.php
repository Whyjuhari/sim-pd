<?php

namespace Tests\Unit;

use App\Services\Documents\SptOfficialNumberParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SptOfficialNumberParserTest extends TestCase
{
    #[DataProvider('officialNumberTexts')]
    public function test_it_reads_an_official_number_near_the_spt_heading(string $text, string $expected): void
    {
        $this->assertSame($expected, (new SptOfficialNumberParser())->parse($text));
    }

    public static function officialNumberTexts(): array
    {
        return [
            'same line' => [
                "SURAT PERINTAH TUGAS\nNOMOR : 2.23/5622/LP.00.05/IX/2026\nDasar",
                '2.23/5622/LP.00.05/IX/2026',
            ],
            'following line' => [
                "SURAT TUGAS\nNOMOR\nB-123/KK.04.00/IX/2026\nMenimbang",
                'B-123/KK.04.00/IX/2026',
            ],
            'number label' => [
                "SURAT PERINTAH TUGAS\nNomor Naskah: 45/SET/IX/2026",
                '45/SET/IX/2026',
            ],
            'numeric number' => [
                "Menimbang SURAT TUGAS\nDasar NOMOR : 2304\nKepada",
                '2304',
            ],
            'surrounding extracted text' => [
                "SURAT PERINTAH TUGAS\nNOMOR : 45/SET/IX/2026 Lampiran\nDasar",
                '45/SET/IX/2026',
            ],
        ];
    }

    public function test_it_does_not_treat_the_draft_parameter_as_an_official_number(): void
    {
        $text = "SURAT PERINTAH TUGAS\nNOMOR : \${nomor_naskah}\nDasar";

        $this->assertNull((new SptOfficialNumberParser())->parse($text));
    }

    public function test_it_detects_unresolved_number_and_signature_placeholders(): void
    {
        $parser = new SptOfficialNumberParser();

        $this->assertTrue($parser->hasUnresolvedDraftPlaceholders('Nomor: ${nomor_naskah}'));
        $this->assertTrue($parser->hasUnresolvedDraftPlaceholders('Tanda tangan $ { ttd }'));
        $this->assertTrue($parser->hasUnresolvedDraftPlaceholders('Pengirim ${ttd_pengirim}'));
        $this->assertFalse($parser->hasUnresolvedDraftPlaceholders('Nomor: 2304'));
    }

    public function test_it_does_not_use_a_numbered_legal_basis_after_an_empty_number_label(): void
    {
        $text = "SURAT PERINTAH TUGAS\nNOMOR\nDasar\n1. Undang-Undang Nomor 5 Tahun 2014";

        $this->assertNull((new SptOfficialNumberParser())->parse($text));
    }
}
