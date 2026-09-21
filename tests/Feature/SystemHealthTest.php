<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SystemHealthTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_sees_document_dependency_failures_without_a_tte_check(): void
    {
        config(['sim_pd.documents.pdf_text.binary' => base_path('missing-pdftotext')]);
        config(['sim_pd.documents.php_cli_binary' => base_path('missing-php-cli')]);

        $response = $this->actingAs(User::factory()->role(User::ROLE_ADMIN)->create())
            ->get(route('admin.system-health'))
            ->assertOk();

        $checks = collect($response->viewData('checks'));
        $pdfTextReader = $checks->firstWhere('label', 'Pembaca teks PDF');
        $phpCli = $checks->firstWhere('label', 'PHP CLI');

        $this->assertNotNull($pdfTextReader);
        $this->assertFalse($pdfTextReader['ok']);
        $this->assertNotNull($phpCli);
        $this->assertFalse($phpCli['ok']);
        $this->assertFalse($checks->contains('label', 'Pemeriksa tanda tangan elektronik'));
    }
}
