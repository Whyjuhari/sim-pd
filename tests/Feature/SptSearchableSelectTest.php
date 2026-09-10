<?php

namespace Tests\Feature;

use App\Models\MasterTarif;
use App\Models\PerjalananDinas;
use App\Models\Province;
use App\Models\SptTemplate;
use App\Models\User;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class SptSearchableSelectTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_form_uses_searchable_city_and_single_employee_multi_select(): void
    {
        $officer = User::factory()->role(User::ROLE_OFFICER)->create();
        $employeeA = User::factory()->create([
            'nama_lengkap' => 'Andi Analis',
            'nip' => '199001012020011001',
            'jabatan' => 'Analis Kepegawaian',
        ]);
        $employeeB = User::factory()->create([
            'nama_lengkap' => 'Zahra Pengelola',
            'nip' => '199202022021022002',
            'jabatan' => 'Pengelola Program',
        ]);
        $province = Province::query()->where('code', '73')->firstOrFail();

        MasterTarif::query()->create([
            'kota_tujuan' => 'Kab. Pangkep',
            'province_id' => $province->id,
            'uang_saku_per_hari' => 0,
        ]);

        $template = SptTemplate::query()->create([
            'nama' => 'Template Utama',
            'file_path' => 'spt-templates/search.docx',
            'original_filename' => 'search.docx',
            'file_size' => 100,
            'file_sha256' => 'abc',
            'is_active' => true,
            'is_default' => true,
            'created_by' => $officer->id,
        ]);

        $response = $this->actingAs($officer)
            ->withSession([
                '_old_input' => [
                    'user_ids' => [(string) $employeeB->id, (string) $employeeA->id],
                    'kota_tujuan' => 'Kab. Pangkep',
                ],
            ])
            ->get(route('travel-orders.create', ['template' => $template->id]));

        $response->assertOk()
            ->assertSee('data-spt-searchable="employees"', false)
            ->assertSee('data-spt-layout="dropdown-search"', false)
            ->assertSee('data-spt-searchable="destination"', false)
            ->assertSee('data-label-description="SULAWESI SELATAN"', false)
            ->assertSee('199001012020011001', false)
            ->assertSee('Analis Kepegawaian', false)
            ->assertDontSee('id="add-employee"', false)
            ->assertDontSee('employee-row', false);

        $html = $response->getContent();
        $document = $this->htmlDocument($html);
        $xpath = new DOMXPath($document);

        $employeeSelects = $xpath->query('//select[@name="user_ids[]"]');
        $this->assertSame(1, $employeeSelects->length);
        $this->assertInstanceOf(DOMElement::class, $employeeSelects->item(0));
        $this->assertTrue($employeeSelects->item(0)->hasAttribute('multiple'));
        $this->assertTrue($employeeSelects->item(0)->hasAttribute('required'));
        $this->assertSame(
            [(string) $employeeB->id, (string) $employeeA->id],
            $this->selectedValues($xpath, 'employeeSelect'),
        );

        $destination = $xpath->query('//select[@id="destinationSelect"]/option[@value="Kab. Pangkep"]')->item(0);
        $this->assertInstanceOf(DOMElement::class, $destination);
        $this->assertSame(
            ['province' => 'SULAWESI SELATAN'],
            json_decode($destination->getAttribute('data-custom-properties'), true, flags: JSON_THROW_ON_ERROR),
        );

        $script = file_get_contents(resource_path('js/spt-searchable-selects.js'));
        $this->assertStringContainsString('allowHTML: false', $script);
        $this->assertStringContainsString('duplicateItemsAllowed: false', $script);
        $this->assertStringContainsString('"customProperties.province"', $script);
        $this->assertStringContainsString('"customProperties.nip"', $script);
        $this->assertStringContainsString('"customProperties.position"', $script);
        $this->assertStringContainsString('choices.getValue(true)', $script);
        $this->assertStringContainsString('choices__input--dropdown-search', $script);
        $this->assertStringContainsString('dropdown.insertBefore(searchInput, dropdownResults)', $script);

        $package = json_decode(file_get_contents(base_path('package.json')), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('11.2.3', $package['dependencies']['choices.js'] ?? null);
        $this->assertArrayNotHasKey('tom-select', $package['dependencies']);
    }

    public function test_edit_form_preserves_existing_employee_order_in_one_control(): void
    {
        $officer = User::factory()->role(User::ROLE_OFFICER)->create();
        $employeeA = User::factory()->create(['nama_lengkap' => 'Andi Pertama']);
        $employeeB = User::factory()->create(['nama_lengkap' => 'Budi Kedua']);
        User::factory()->create(['nama_lengkap' => 'Citra Tidak Dipilih']);
        $groupId = (string) Str::uuid();

        foreach ([$employeeB, $employeeA] as $employee) {
            PerjalananDinas::query()->create([
                'spt_group_id' => $groupId,
                'no_spt' => 'TEST/SEARCH/001',
                'menimbang' => 'Kebutuhan dinas',
                'no_memo' => 'MEMO/SEARCH/001',
                'perihal_memo' => 'Koordinasi',
                'tgl_memo' => '2026-09-01',
                'maksud_perjalanan' => 'Melaksanakan koordinasi',
                'user_id' => $employee->id,
                'kota_tujuan' => 'Makassar',
                'tempat_berangkat' => 'Pangkep',
                'tgl_berangkat' => '2026-09-10',
                'tgl_kembali' => '2026-09-11',
                'lama_hari' => 2,
                'angkutan' => 'Transportasi Darat',
                'akun_anggaran' => '4053.PDI.002.054.B.524111',
                'status' => PerjalananDinas::STATUS_READY,
            ]);
        }

        $response = $this->actingAs($officer)->get(route('travel-orders.edit', $groupId));

        $response->assertOk()
            ->assertSee('data-spt-searchable="employees"', false)
            ->assertDontSee('id="add-employee"', false);

        $xpath = new DOMXPath($this->htmlDocument($response->getContent()));
        $this->assertSame(1, $xpath->query('//select[@name="user_ids[]"]')->length);
        $this->assertSame(
            [(string) $employeeB->id, (string) $employeeA->id],
            $this->selectedValues($xpath, 'employeeSelect'),
        );
    }

    private function htmlDocument(string $html): DOMDocument
    {
        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8">'.$html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return $document;
    }

    /** @return list<string> */
    private function selectedValues(DOMXPath $xpath, string $selectId): array
    {
        $options = $xpath->query(sprintf('//select[@id="%s"]/option[@selected]', $selectId));
        $values = [];

        foreach ($options as $option) {
            if ($option instanceof DOMElement) {
                $values[] = $option->getAttribute('value');
            }
        }

        return $values;
    }
}
