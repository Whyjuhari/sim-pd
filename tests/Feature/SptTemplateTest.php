<?php

namespace Tests\Feature;

use App\Models\MasterTarif;
use App\Models\PerjalananDinas;
use App\Models\Province;
use App\Models\SptTemplate;
use App\Models\User;
use App\Services\Documents\GeneratedPdfCache;
use App\Support\SptTemplateVariant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SptTemplateTest extends TestCase
{
    use RefreshDatabase;

    private const PLACEHOLDERS = [
        'nomor_pegawai',
        'kepada_pegawai',
        'nama_pegawai',
        'nip',
        'pangkat_golongan',
        'jabatan',
        'nomor_surat',
        'menimbang',
        'nomor_memo',
        'perihal_memo',
        'tanggal_memo',
        'akun',
        'tanggal_pelaksanaan',
        'tanggal_surat',
        'untuk_kegiatan',
    ];

    protected function tearDown(): void
    {
        foreach (glob(storage_path('app/spt-templates/*')) ?: [] as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }

        foreach (glob(storage_path('app/spt-templates/thumbs/*')) ?: [] as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }

        parent::tearDown();
    }

    public function test_index_is_accessible_only_to_officer(): void
    {
        $officer = User::factory()->role(User::ROLE_OFFICER)->create();

        $this->actingAs($officer)->get(route('spt-templates.index'))->assertOk();

        $employee = User::factory()->create();
        $this->actingAs($employee)->get(route('spt-templates.index'))->assertForbidden();
    }

    public function test_selection_screen_uses_pdf_thumbnails_for_built_in_templates(): void
    {
        $officer = User::factory()->role(User::ROLE_OFFICER)->create();

        $response = $this->actingAs($officer)->get(route('travel-orders.create'));

        $response->assertOk();

        foreach (SptTemplateVariant::all() as $key => $variant) {
            $response->assertSee(
                'data-spt-template-thumb="'.route(
                    'spt-templates.built-in-thumbnail',
                    ['variant' => $key],
                ).'"',
                false,
            );
            $response->assertSee('aria-label="Pratinjau '.$variant['label'].'"', false);
        }
    }

    public function test_built_in_variants_have_automatic_attachment_layouts(): void
    {
        foreach (SptTemplateVariant::all() as $key => $variant) {
            $inlinePath = SptTemplateVariant::pathForEmployeeCount($key, 2);
            $attachmentPath = SptTemplateVariant::pathForEmployeeCount($key, 3);

            $this->assertSame(resource_path('documents/'.$variant['filename']), $inlinePath);
            $this->assertSame(
                resource_path('documents/'.$variant['attachment_filename']),
                $attachmentPath,
            );
            $this->assertFileExists($inlinePath);
            $this->assertFileExists($attachmentPath);
            $this->assertNotSame($inlinePath, $attachmentPath);
        }
    }

    public function test_built_in_travel_tables_fit_a4_and_stay_together(): void
    {
        foreach (SptTemplateVariant::all() as $variant) {
            foreach ([$variant['filename'], $variant['attachment_filename']] as $filename) {
                $path = resource_path('documents/'.$filename);
                $zip = new \ZipArchive();
                $this->assertTrue($zip->open($path) === true, "DOCX {$path} harus dapat dibuka.");
                $xml = $zip->getFromName('word/document.xml');
                $zip->close();
                $this->assertIsString($xml);

                $document = new \DOMDocument();
                $this->assertTrue($document->loadXML($xml));
                $xpath = new \DOMXPath($document);
                $namespace = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';
                $xpath->registerNamespace('w', $namespace);

                $tables = $xpath->query(
                    '/w:document/w:body/w:tbl'
                    .'[contains(., "VI.")][contains(., "Catatan lain-lain")]'
                    .'[contains(., "VII.")][contains(., "PERHATIAN")]'
                );
                $this->assertNotFalse($tables);
                $this->assertSame(1, $tables->length);
                $table = $tables->item(0);
                $this->assertNotNull($table);
                $this->assertSame(
                    1,
                    $xpath->query(
                        'preceding-sibling::*[1][self::w:p[.//w:br[@w:type="page"]]]',
                        $table,
                    )->length,
                    'Tabel perjalanan harus selalu dimulai pada halaman tersendiri.',
                );

                $allRows = $xpath->query('./w:tr', $table);
                $this->assertNotFalse($allRows);
                $this->assertContains($allRows->length, [8, 9]);
                $travelRowsPath = $allRows->length === 9
                    ? './w:tr[position() > 1]'
                    : './w:tr';
                $rows = $xpath->query($travelRowsPath, $table);
                $this->assertNotFalse($rows);
                $this->assertSame(8, $rows->length);

                $minimumHeight = 0;
                foreach ($rows as $row) {
                    $this->assertSame(1, $xpath->query('./w:trPr/w:cantSplit', $row)->length);
                    $height = $xpath->query('./w:trPr/w:trHeight', $row)->item(0);
                    $this->assertNotNull($height);
                    $this->assertSame('atLeast', $height->getAttributeNS($namespace, 'hRule'));
                    $minimumHeight += (int) $height->getAttributeNS($namespace, 'val');
                }

                $section = $xpath->query('/w:document/w:body/w:sectPr')->item(0);
                $this->assertNotNull($section);
                $pageSize = $xpath->query('./w:pgSz', $section)->item(0);
                $pageMargin = $xpath->query('./w:pgMar', $section)->item(0);
                $this->assertNotNull($pageSize);
                $this->assertNotNull($pageMargin);
                $usableHeight = (int) $pageSize->getAttributeNS($namespace, 'h')
                    - (int) $pageMargin->getAttributeNS($namespace, 'top')
                    - (int) $pageMargin->getAttributeNS($namespace, 'bottom');
                $this->assertLessThanOrEqual(
                    $usableHeight,
                    $minimumHeight,
                    'Tinggi minimum tabel perjalanan harus muat pada area cetak A4.',
                );

                $travelParagraphsPath = $allRows->length === 9
                    ? './w:tr[position() > 1]//w:p'
                    : './w:tr//w:p';
                $paragraphs = $xpath->query($travelParagraphsPath, $table);
                $this->assertNotFalse($paragraphs);
                $this->assertGreaterThan(1, $paragraphs->length);
                $this->assertSame(
                    $paragraphs->length,
                    $xpath->query($travelParagraphsPath.'[w:pPr/w:keepLines]', $table)->length,
                );
                $this->assertSame(
                    $paragraphs->length - 1,
                    $xpath->query(
                        $travelParagraphsPath
                        .'[w:pPr/w:keepNext[not(@w:val) or @w:val != "0"]]',
                        $table,
                    )->length,
                );
                $this->assertSame(
                    1,
                    $xpath->query('following-sibling::*[1][self::w:sectPr]', $table)->length,
                    'Paragraf kosong setelah tabel dapat menghasilkan halaman terakhir kosong.',
                );
            }
        }
    }

    public function test_built_in_thumbnail_is_private_and_served_from_generated_cache(): void
    {
        Storage::fake('local');

        $officer = User::factory()->role(User::ROLE_OFFICER)->create();
        $variant = SptTemplateVariant::REGULATIONS;
        $pdfPath = Storage::disk('local')->path('testing/built-in-template.pdf');
        Storage::disk('local')->put('testing/built-in-template.pdf', '%PDF-1.4 fake');

        $cache = \Mockery::mock(GeneratedPdfCache::class);
        $cache->shouldReceive('remember')
            ->once()
            ->withArgs(fn ($type, $scope, $payload, $dependencies, $generate): bool =>
                $type === 'spt-template-thumbnail'
                && $scope === 'built-in-'.$variant
                && ($payload['variant'] ?? null) === $variant
                && in_array(SptTemplateVariant::path($variant), $dependencies, true)
                && $generate instanceof \Closure
            )
            ->andReturn($pdfPath);
        $this->app->instance(GeneratedPdfCache::class, $cache);

        $this->actingAs($officer)
            ->get(route('spt-templates.built-in-thumbnail', ['variant' => $variant]))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf')
            ->assertHeader('cache-control', 'max-age=86400, private');

        $employee = User::factory()->create();
        $this->actingAs($employee)
            ->get(route('spt-templates.built-in-thumbnail', ['variant' => $variant]))
            ->assertForbidden();

        $this->actingAs($officer)
            ->get(route('spt-templates.built-in-thumbnail', ['variant' => 'unknown']))
            ->assertNotFound();
    }

    public function test_officer_can_upload_a_valid_template(): void
    {
        Storage::fake('local');

        $officer = User::factory()->role(User::ROLE_OFFICER)->create();

        $file = UploadedFile::fake()->createWithContent(
            'template.docx',
            $this->docxContent(self::PLACEHOLDERS)
        );

        $response = $this->actingAs($officer)->post(route('spt-templates.store'), [
            'nama' => 'Template Dinas',
            'deskripsi' => 'Template untuk dinas dalam kota',
            'file' => $file,
        ]);

        $response->assertRedirect(route('spt-templates.index'));

        $this->assertDatabaseHas('spt_templates', [
            'nama' => 'Template Dinas',
            'deskripsi' => 'Template untuk dinas dalam kota',
            'original_filename' => 'template.docx',
            'is_active' => true,
            'is_default' => false,
            'created_by' => $officer->id,
        ]);

        $template = SptTemplate::query()->where('nama', 'Template Dinas')->firstOrFail();
        Storage::disk('local')->assertExists($template->file_path);
    }

    public function test_upload_rejects_missing_placeholders(): void
    {
        Storage::fake('local');

        $officer = User::factory()->role(User::ROLE_OFFICER)->create();

        $missingPlaceholders = array_slice(self::PLACEHOLDERS, 0, 5);
        $file = UploadedFile::fake()->createWithContent(
            'template.docx',
            $this->docxContent($missingPlaceholders)
        );

        $response = $this->actingAs($officer)->post(route('spt-templates.store'), [
            'nama' => 'Template Tidak Lengkap',
            'file' => $file,
        ]);

        $response->assertSessionHasErrors('file');
        $this->assertDatabaseCount('spt_templates', 0);
    }

    public function test_upload_rejects_non_docx_file(): void
    {
        Storage::fake('local');

        $officer = User::factory()->role(User::ROLE_OFFICER)->create();

        $file = UploadedFile::fake()->createWithContent('template.docx', 'not a real docx file');

        $this->actingAs($officer)
            ->post(route('spt-templates.store'), [
                'nama' => 'Template Salah',
                'file' => $file,
            ])
            ->assertSessionHasErrors('file');

        $this->assertDatabaseCount('spt_templates', 0);
    }

    public function test_officer_toggle_active_and_block_removing_last_active_template(): void
    {
        $officer = User::factory()->role(User::ROLE_OFFICER)->create();

        $first = SptTemplate::query()->create([
            'nama' => 'Template Pertama',
            'file_path' => 'spt-templates/1.docx',
            'original_filename' => '1.docx',
            'file_size' => 100,
            'file_sha256' => 'abc',
            'is_active' => true,
            'is_default' => true,
            'created_by' => $officer->id,
        ]);

        $second = SptTemplate::query()->create([
            'nama' => 'Template Kedua',
            'file_path' => 'spt-templates/2.docx',
            'original_filename' => '2.docx',
            'file_size' => 100,
            'file_sha256' => 'def',
            'is_active' => true,
            'is_default' => false,
            'created_by' => $officer->id,
        ]);

        $this->actingAs($officer)
            ->post(route('spt-templates.toggle', $second))
            ->assertRedirect(route('spt-templates.index'));

        $this->assertFalse($second->fresh()->is_active);

        $this->actingAs($officer)
            ->post(route('spt-templates.toggle', $first))
            ->assertSessionHasErrors('template');

        $this->assertTrue($first->fresh()->is_active);
    }

    public function test_destroy_rejects_in_use_template_and_succeeds_for_unused(): void
    {
        $officer = User::factory()->role(User::ROLE_OFFICER)->create();

        $default = SptTemplate::query()->create([
            'nama' => 'Template Default',
            'file_path' => 'spt-templates/default.docx',
            'original_filename' => 'default.docx',
            'file_size' => 100,
            'file_sha256' => 'abc',
            'is_active' => true,
            'is_default' => true,
            'created_by' => $officer->id,
        ]);

        $unused = SptTemplate::query()->create([
            'nama' => 'Template Bekas',
            'file_path' => 'spt-templates/unused.docx',
            'original_filename' => 'unused.docx',
            'file_size' => 100,
            'file_sha256' => 'def',
            'is_active' => true,
            'is_default' => false,
            'created_by' => $officer->id,
        ]);

        $this->actingAs($officer)
            ->delete(route('spt-templates.destroy', $default))
            ->assertSessionHasErrors('template');

        $this->assertDatabaseHas('spt_templates', ['id' => $default->id, 'is_default' => true]);

        $this->actingAs($officer)
            ->delete(route('spt-templates.destroy', $unused))
            ->assertRedirect(route('spt-templates.index'));

        $this->assertDatabaseMissing('spt_templates', ['id' => $unused->id]);
    }

    public function test_spt_stores_selected_template_id_for_all_group_members(): void
    {
        $officer = User::factory()->role(User::ROLE_OFFICER)->create();
        $employeeA = User::factory()->create(['nama_lengkap' => 'Andi Analis']);
        $employeeB = User::factory()->create(['nama_lengkap' => 'Budi Pengelola']);

        $province = Province::query()->where('code', '73')->firstOrFail();
        MasterTarif::query()->create([
            'kota_tujuan' => 'Makassar',
            'province_id' => $province->id,
            'uang_saku_per_hari' => 0,
        ]);

        $template = SptTemplate::query()->create([
            'nama' => 'Template Group',
            'file_path' => 'spt-templates/group.docx',
            'original_filename' => 'group.docx',
            'file_size' => 100,
            'file_sha256' => 'abc',
            'is_active' => true,
            'is_default' => true,
            'created_by' => $officer->id,
        ]);

        $response = $this->actingAs($officer)->post(route('travel-orders.store'), [
            'user_ids' => [$employeeA->id, $employeeB->id],
            'no_spt' => 'TEST/TPL/001',
            'menimbang' => 'Kebutuhan dinas',
            'no_memo' => 'MEMO/TPL/001',
            'perihal_memo' => 'Koordinasi',
            'tgl_memo' => '2026-09-01',
            'maksud_perjalanan' => 'Melaksanakan koordinasi',
            'kota_tujuan' => 'Makassar',
            'tempat_berangkat' => 'Pangkep',
            'tgl_berangkat' => '2026-09-10',
            'tgl_kembali' => '2026-09-11',
            'lama_hari' => 2,
            'angkutan' => 'Transportasi Darat',
            'akun_anggaran' => '4053.PDI.002.054.B.524111',
            'spt_template_id' => $template->id,
        ]);

        $response->assertSessionHasNoErrors();
        $this->assertSame(2, PerjalananDinas::query()->where('spt_template_id', $template->id)->count());
    }

    public function test_create_starts_on_template_selection_then_preselects_chosen_template(): void
    {
        $officer = User::factory()->role(User::ROLE_OFFICER)->create();
        $province = Province::query()->where('code', '73')->firstOrFail();
        MasterTarif::query()->create([
            'kota_tujuan' => 'Makassar',
            'province_id' => $province->id,
            'uang_saku_per_hari' => 0,
        ]);

        $defaultTemplate = SptTemplate::query()->create([
            'nama' => 'Template Utama',
            'file_path' => 'spt-templates/default.docx',
            'original_filename' => 'default.docx',
            'file_size' => 100,
            'file_sha256' => 'abc',
            'is_active' => true,
            'is_default' => true,
            'created_by' => $officer->id,
        ]);

        $activeTemplate = SptTemplate::query()->create([
            'nama' => 'Template Aktif',
            'file_path' => 'spt-templates/active.docx',
            'original_filename' => 'active.docx',
            'file_size' => 100,
            'file_sha256' => 'def',
            'is_active' => true,
            'is_default' => false,
            'created_by' => $officer->id,
        ]);

        SptTemplate::query()->create([
            'nama' => 'Template Nonaktif',
            'file_path' => 'spt-templates/inactive.docx',
            'original_filename' => 'inactive.docx',
            'file_size' => 100,
            'file_sha256' => 'ghi',
            'is_active' => false,
            'is_default' => false,
            'created_by' => $officer->id,
        ]);

        $selection = $this->actingAs($officer)->get(route('travel-orders.create'));

        $selection->assertOk()
            ->assertSee('Pilih Template SPT', false)
            ->assertSee($defaultTemplate->nama, false)
            ->assertSee($activeTemplate->nama, false)
            ->assertSee('Dasar Regulasi dan Memo', false)
            ->assertDontSee('Template Nonaktif', false)
            ->assertDontSee('name="spt_template_id"', false);

        $selectionXpath = new \DOMXPath($this->htmlDocument($selection->getContent()));
        $this->assertSame(
            1,
            $selectionXpath->query(sprintf('//a[@href="%s"]', route('travel-orders.create', ['template' => $activeTemplate->id])))->length,
        );

        $form = $this->actingAs($officer)->get(route('travel-orders.create', ['template' => $activeTemplate->id]));

        $form->assertOk()
            ->assertSee('name="spt_template_id"', false)
            ->assertSee($activeTemplate->nama, false)
            ->assertSee('Ganti template', false)
            ->assertDontSee($defaultTemplate->nama, false)
            ->assertDontSee('Template Nonaktif', false);

        $xpath = new \DOMXPath($this->htmlDocument($form->getContent()));
        $selectedInput = $xpath->query(sprintf('//input[@type="hidden" and @name="spt_template_id" and @value="%d"]', $activeTemplate->id));
        $this->assertSame(1, $selectedInput->length);
        $this->assertSame(0, $xpath->query('//select[@name="spt_template_id"]')->length);
    }

    public function test_invalid_or_unknown_template_param_falls_back_to_selection_screen(): void
    {
        $officer = User::factory()->role(User::ROLE_OFFICER)->create();
        $inactive = SptTemplate::query()->create([
            'nama' => 'Template Nonaktif',
            'file_path' => 'spt-templates/inactive.docx',
            'original_filename' => 'inactive.docx',
            'file_size' => 100,
            'file_sha256' => 'abc',
            'is_active' => false,
            'is_default' => false,
            'created_by' => $officer->id,
        ]);

        foreach (['999999', 'abc', null] as $param) {
            $query = $param === null ? [] : ['template' => $param];
            $response = $this->actingAs($officer)->get(route('travel-orders.create', $query));
            $response->assertOk()
                ->assertSee('Pilih Template SPT', false)
                ->assertDontSee('name="spt_template_id"', false);
        }

        $this->actingAs($officer)
            ->get(route('travel-orders.create', ['template' => $inactive->id]))
            ->assertOk()
            ->assertSee('Pilih Template SPT', false)
            ->assertDontSee('name="spt_template_id"', false);
    }

    public function test_selection_screen_shows_thumbnail_canvas_when_thumbnail_exists(): void
    {
        Storage::fake('local');

        $officer = User::factory()->role(User::ROLE_OFFICER)->create();

        $template = SptTemplate::query()->create([
            'nama' => 'Template Bertumbnail',
            'file_path' => 'spt-templates/thumb.docx',
            'original_filename' => 'thumb.docx',
            'file_size' => 100,
            'file_sha256' => 'abc',
            'thumbnail_path' => 'spt-templates/thumbs/thumb.pdf',
            'thumbnail_sha256' => 'def',
            'is_active' => true,
            'is_default' => true,
            'created_by' => $officer->id,
        ]);

        Storage::disk('local')->put('spt-templates/thumbs/thumb.pdf', '%PDF-1.4 fake');

        $response = $this->actingAs($officer)->get(route('travel-orders.create'));

        $response->assertOk()
            ->assertSee('data-spt-template-thumb="' . route('spt-templates.thumbnail', $template) . '"', false);

        $this->actingAs($officer)
            ->get(route('spt-templates.thumbnail', $template))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
    }

    public function test_thumbnail_route_returns_404_when_missing(): void
    {
        Storage::fake('local');

        $officer = User::factory()->role(User::ROLE_OFFICER)->create();

        $noPath = SptTemplate::query()->create([
            'nama' => 'Tanpa Thumbnail',
            'file_path' => 'spt-templates/no.docx',
            'original_filename' => 'no.docx',
            'file_size' => 100,
            'file_sha256' => 'abc',
            'is_active' => true,
            'is_default' => true,
            'created_by' => $officer->id,
        ]);

        $stalePath = SptTemplate::query()->create([
            'nama' => 'Thumbnail Stale',
            'file_path' => 'spt-templates/stale.docx',
            'original_filename' => 'stale.docx',
            'file_size' => 100,
            'file_sha256' => 'abc',
            'thumbnail_path' => 'spt-templates/thumbs/ghost.pdf',
            'thumbnail_sha256' => 'def',
            'is_active' => true,
            'is_default' => false,
            'created_by' => $officer->id,
        ]);

        $this->actingAs($officer)->get(route('spt-templates.thumbnail', $noPath))->assertNotFound();
        $this->actingAs($officer)->get(route('spt-templates.thumbnail', $stalePath))->assertNotFound();

        $employee = User::factory()->create();
        $this->actingAs($employee)->get(route('spt-templates.thumbnail', $noPath))->assertForbidden();
    }

    public function test_destroy_removes_thumbnail_file(): void
    {
        Storage::fake('local');

        $officer = User::factory()->role(User::ROLE_OFFICER)->create();

        $template = SptTemplate::query()->create([
            'nama' => 'Template Dengan Thumbnail',
            'file_path' => 'spt-templates/clean.docx',
            'original_filename' => 'clean.docx',
            'file_size' => 100,
            'file_sha256' => 'abc',
            'thumbnail_path' => 'spt-templates/thumbs/clean.pdf',
            'thumbnail_sha256' => 'def',
            'is_active' => true,
            'is_default' => false,
            'created_by' => $officer->id,
        ]);

        Storage::disk('local')->put('spt-templates/thumbs/clean.pdf', '%PDF-1.4 fake');
        $this->assertTrue(Storage::disk('local')->exists('spt-templates/thumbs/clean.pdf'));

        $this->actingAs($officer)->delete(route('spt-templates.destroy', $template));

        $this->assertDatabaseMissing('spt_templates', ['id' => $template->id]);
        $this->assertFalse(Storage::disk('local')->exists('spt-templates/thumbs/clean.pdf'));
    }

    private function htmlDocument(string $html): \DOMDocument
    {
        $document = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8">' . $html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return $document;
    }

    private function docxContent(array $placeholders): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
            . '<w:body><w:p>';

        foreach ($placeholders as $placeholder) {
            $xml .= '<w:r><w:t>{{' . $placeholder . '}}</w:t></w:r>';
        }

        $xml .= '</w:p></w:body></w:document>';

        $zip = new \ZipArchive();
        $path = tempnam(sys_get_temp_dir(), 'spt') . '.docx';
        if ($zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Tidak dapat membuat DOCX.');
        }
        $zip->addFromString('word/document.xml', $xml);
        $zip->close();

        $content = file_get_contents($path);
        @unlink($path);

        return $content === false ? '' : $content;
    }
}
