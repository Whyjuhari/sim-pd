<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Uploads\UserImageStorage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;

class ProfileController extends Controller
{
    public function __construct(private readonly UserImageStorage $images) {}

    public function edit(Request $request): View
    {
        return view('profile.edit', ['employee' => $request->user()]);
    }

    public function update(Request $request): RedirectResponse
    {
        /** @var User $employee */
        $employee = $request->user();
        $data = $request->validate([
            'nama_lengkap' => ['required', 'string', 'max:100'],
            'pangkat' => ['nullable', 'string', 'max:50'],
            'current_password' => ['required_with:password', 'nullable', 'current_password'],
            'password' => ['nullable', 'string', 'min:8', 'confirmed'],
            'foto' => [
                'nullable', 'file', 'image', 'mimes:jpg,jpeg,png',
                'mimetypes:image/jpeg,image/png', 'max:2048',
                'dimensions:min_width=100,min_height=100,max_width=6000,max_height=6000',
            ],
        ]);

        $values = [
            'nama_lengkap' => $data['nama_lengkap'],
            'pangkat_golongan' => $data['pangkat'] ?? null,
        ];

        if (! empty($data['password'])) {
            $values['password'] = Hash::make($data['password']);
            $values['force_password_change'] = false;
        }

        $oldPhoto = $employee->foto;
        $newPhoto = null;
        if (isset($data['foto'])) {
            $newPhoto = $this->images->storeProfilePhoto($data['foto']);
            $values['foto'] = $newPhoto;
        }

        try {
            $employee->update($values);
        } catch (\Throwable $exception) {
            $this->images->deleteProfilePhoto($newPhoto);
            throw $exception;
        }

        if ($newPhoto) {
            $this->images->deleteProfilePhoto($oldPhoto);
        }

        return back()->with(
            'success',
            $employee->force_password_change
                ? 'Profil berhasil diperbarui.'
                : 'Profil dan keamanan akun berhasil diperbarui.'
        );
    }
}
