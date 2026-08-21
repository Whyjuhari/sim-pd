<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class EmployeeManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_create_update_and_delete_employee(): void
    {
        $admin = User::factory()->role(User::ROLE_ADMIN)->create();

        $this->actingAs($admin)->post(route('employees.store'), [
            'nama_lengkap' => 'Pegawai Baru',
            'nip' => '199901012026011001',
            'pangkat' => 'III/a',
            'jabatan' => 'Staf',
            'username' => 'pegawai-baru',
            'password' => '123456',
            'role' => User::ROLE_USER,
        ])->assertRedirect(route('employees.index'));

        $employee = User::query()->where('username', 'pegawai-baru')->firstOrFail();
        $this->assertTrue(Hash::check('123456', $employee->password));
        $this->assertTrue($employee->force_password_change);

        $this->actingAs($admin)->post(route('employees.update'), [
            'id' => $employee->id,
            'nama_lengkap' => 'Pegawai Diperbarui',
            'nip' => $employee->nip,
            'pangkat' => 'III/b',
            'jabatan' => 'Instruktur',
            'username' => $employee->username,
            'password' => '',
            'role' => User::ROLE_USER,
        ])->assertRedirect(route('employees.index'));

        $this->assertDatabaseHas('users', ['id' => $employee->id, 'nama_lengkap' => 'Pegawai Diperbarui']);

        $this->actingAs($admin)->post(route('employees.destroy'), ['id' => $employee->id])
            ->assertRedirect(route('employees.index'));
        $this->assertDatabaseMissing('users', ['id' => $employee->id]);
    }

    public function test_admin_cannot_delete_self_or_remove_the_last_admin_role(): void
    {
        $admin = User::factory()->role(User::ROLE_ADMIN)->create();

        $this->actingAs($admin)
            ->from(route('employees.roles'))
            ->post(route('employees.destroy'), ['id' => $admin->id])
            ->assertSessionHasErrors('employee');

        $this->actingAs($admin)
            ->from(route('employees.edit', ['id' => $admin->id]))
            ->post(route('employees.update'), [
                'id' => $admin->id,
                'nama_lengkap' => $admin->nama_lengkap,
                'nip' => '',
                'pangkat' => '',
                'jabatan' => '',
                'username' => $admin->username,
                'password' => '',
                'role' => User::ROLE_OFFICER,
            ])
            ->assertSessionHasErrors('role');

        $this->assertDatabaseHas('users', ['id' => $admin->id, 'role' => User::ROLE_ADMIN]);
    }

    public function test_profile_photo_and_signature_are_reencoded_with_safe_names_and_cleaned_up(): void
    {
        Storage::fake('local');
        $admin = User::factory()->role(User::ROLE_ADMIN)->create();

        $this->actingAs($admin)->post(route('employees.store'), [
            'nama_lengkap' => 'Pegawai Gambar',
            'nip' => '199901012026011099',
            'pangkat' => 'III/a',
            'jabatan' => 'Staf',
            'username' => 'pegawai-gambar',
            'password' => '123456',
            'role' => User::ROLE_USER,
            'foto' => UploadedFile::fake()->image('nama-asli.png', 1600, 1200),
            'ttd' => UploadedFile::fake()->image('tanda-tangan.png', 500, 200),
        ])->assertSessionHasNoErrors();

        $employee = User::query()->where('username', 'pegawai-gambar')->firstOrFail();
        $this->assertMatchesRegularExpression('/^foto_[0-9a-f-]+\.jpg$/i', $employee->foto);
        $this->assertMatchesRegularExpression('#^signatures/ttd_[0-9a-f-]+\.png$#i', $employee->ttd_path);
        $photoPath = public_path('uploads/profil/'.$employee->foto);
        $this->assertFileExists($photoPath);
        $this->assertSame('image/jpeg', mime_content_type($photoPath));
        [$width, $height] = getimagesize($photoPath);
        $this->assertLessThanOrEqual(1024, max($width, $height));
        Storage::disk('local')->assertExists($employee->ttd_path);

        $this->post(route('employees.destroy'), ['id' => $employee->id])->assertSessionHasNoErrors();
        $this->assertFileDoesNotExist($photoPath);
        Storage::disk('local')->assertMissing($employee->ttd_path);
    }

    public function test_non_image_profile_upload_is_rejected(): void
    {
        $admin = User::factory()->role(User::ROLE_ADMIN)->create();

        $this->actingAs($admin)->post(route('employees.store'), [
            'nama_lengkap' => 'Unggah Tidak Aman',
            'nip' => '199901012026011088',
            'username' => 'unggah-tidak-aman',
            'password' => '123456',
            'role' => User::ROLE_USER,
            'foto' => UploadedFile::fake()->create('payload.php', 20, 'application/x-php'),
        ])->assertSessionHasErrors('foto');

        $this->assertDatabaseMissing('users', ['username' => 'unggah-tidak-aman']);
    }
}
