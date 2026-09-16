<?php

namespace Tests\Unit;

use App\Services\Documents\SptOfficialDocumentException;
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

    public function test_it_only_treats_an_unresolved_number_as_a_draft_blocker(): void
    {
        $parser = new SptOfficialNumberParser();

        $this->assertTrue($parser->hasUnresolvedNumberPlaceholders('Nomor: ${nomor_naskah}'));
        $this->assertFalse($parser->hasUnresolvedNumberPlaceholders('Tanda tangan $ { ttd }'));
        $this->assertFalse($parser->hasUnresolvedNumberPlaceholders('Pengirim ${ttd_pengirim}'));
        $this->assertFalse($parser->hasUnresolvedNumberPlaceholders('Nomor: 2304'));
    }

    public function test_it_does_not_use_a_numbered_legal_basis_after_an_empty_number_label(): void
    {
        $text = "SURAT PERINTAH TUGAS\nNOMOR\nDasar\n1. Undang-Undang Nomor 5 Tahun 2014";

        $this->assertNull((new SptOfficialNumberParser())->parse($text));
    }

    public function test_it_reads_a_srikandi_number_from_a_detached_overlay_text_layer(): void
    {
        $text = <<<'TEXT'
SURAT TUGAS
NOMOR ${nomor_naskah}
Dasar : Peraturan Menteri Keuangan Nomor 119/PMK.05/2023
Pelaksana tidak menerima gratifikasi dalam bentuk apapun
2.27/253/LP.02/V/2026
${ttd}
Dokumen ini telah ditandatangani secara elektronik menggunakan sertifikat elektronik
TEXT;

        $this->assertSame(
            '2.27/253/LP.02/V/2026',
            (new SptOfficialNumberParser())->parse($text),
        );
    }

    public function test_it_does_not_use_a_detached_legal_basis_number_as_the_spt_number(): void
    {
        $text = <<<'TEXT'
SURAT TUGAS
NOMOR ${nomor_naskah}
Dasar
B-123/LP.02/V/2026
Uraian dasar surat yang panjang
Kepada pegawai
Untuk melaksanakan perjalanan
Demikian Surat Tugas ini dibuat
Pangkep, 20 Mei 2026
${ttd}
TEXT;

        $this->assertNull((new SptOfficialNumberParser())->parse($text));
    }

    public function test_it_ignores_a_similar_memo_number_in_the_document_body(): void
    {
        $text = <<<'TEXT'
SURAT TUGAS
NOMOR : 2.27/253/LP.02/V/2026
Menimbang
Dasar
Memo Internal Nomor B-124/LP.02.01/V/2026
TEXT;

        $this->assertSame(
            '2.27/253/LP.02/V/2026',
            (new SptOfficialNumberParser())->parse($text),
        );
    }

    public function test_it_does_not_read_a_wrapped_legal_number_as_the_spt_number(): void
    {
        $text = <<<'TEXT'
SURAT TUGAS
NOMOR ${nomor_naskah}
Menimbang
Nomor 20 Tahun 2024 tentang Organisasi dan Tata Kerja
TEXT;

        $this->assertNull((new SptOfficialNumberParser())->parse($text));
    }

    public function test_it_rejects_two_equally_strong_detached_numbers(): void
    {
        $text = <<<'TEXT'
SURAT TUGAS
NOMOR ${nomor_naskah}
Pelaksana tidak menerima gratifikasi
2.27/253/LP.02/V/2026
2.27/254/LP.02/V/2026
${ttd}
Dokumen ini telah ditandatangani secara elektronik
TEXT;

        $this->expectException(SptOfficialDocumentException::class);
        $this->expectExceptionMessage('lebih dari satu nomor');

        (new SptOfficialNumberParser())->parse($text);
    }
}
