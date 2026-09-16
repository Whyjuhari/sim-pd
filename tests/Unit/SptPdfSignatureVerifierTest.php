<?php

namespace Tests\Unit;

use App\Services\Documents\SptOfficialDocumentException;
use App\Services\Documents\SptPdfSignatureVerifier;
use Tests\TestCase;

class SptPdfSignatureVerifierTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'sim_pd.documents.pdf_signature.enabled' => true,
            'sim_pd.documents.pdf_signature.python_binary' => PHP_BINARY,
            'sim_pd.documents.pdf_signature.script' => base_path('tests/Fixtures/fake_pdf_signature_verifier.php'),
            'sim_pd.documents.pdf_signature.timeout' => 5,
            'sim_pd.documents.pdf_signature.require_trusted' => false,
            'sim_pd.documents.pdf_signature.allow_fetching' => false,
            'sim_pd.documents.pdf_signature.trust_roots' => [],
        ]);
    }

    public function test_it_accepts_a_cryptographically_valid_signature_result(): void
    {
        $path = $this->temporaryPdf('valid');

        (new SptPdfSignatureVerifier())->assertValid($path);

        $this->addToAssertionCount(1);
        @unlink($path);
    }

    public function test_it_rejects_a_pdf_without_an_embedded_signature(): void
    {
        $path = $this->temporaryPdf('unsigned');

        try {
            (new SptPdfSignatureVerifier())->assertValid($path);
            $this->fail('Unsigned PDF should be rejected.');
        } catch (SptOfficialDocumentException $exception) {
            $this->assertStringContainsString('belum memiliki tanda tangan elektronik', $exception->getMessage());
        } finally {
            @unlink($path);
        }
    }

    public function test_health_check_reports_the_verifier_as_ready(): void
    {
        $this->assertSame(
            ['ok' => true, 'message' => 'Pemeriksa TTE tersedia'],
            (new SptPdfSignatureVerifier())->health(),
        );
    }

    private function temporaryPdf(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'sim-pd-signature-');
        file_put_contents($path, $contents);

        return $path;
    }
}
