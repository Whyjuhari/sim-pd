<?php

namespace App\Http\Controllers;

use App\Models\PerjalananDinas;
use App\Models\User;
use App\Services\Uploads\ReportDocumentationImageStorage;
use App\Services\Uploads\UserImageStorage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class EmployeeController extends Controller
{
    public function __construct(
        private readonly UserImageStorage $images,
        private readonly ReportDocumentationImageStorage $reportImages
    ) {}

    public function index(Request $request): View
    {
        $year = now()->year;
        $search = trim((string) $request->query('q', ''));
        $employees = User::query()
            ->where('role', User::ROLE_USER)
            ->when($search !== '', fn ($query) => $query->where(function ($query) use ($search): void {
                $query->where('nama_lengkap', 'like', "%{$search}%")
                    ->orWhere('nip', 'like', "%{$search}%")
                    ->orWhere('username', 'like', "%{$search}%");
            }))
            ->withCount([
                'perjalananDinas as jumlah_perjadin' => fn ($query) => $query
                    ->where('status', PerjalananDinas::STATUS_APPROVED)
                    ->whereYear('tgl_berangkat', $year),
            ])
            ->orderBy('nama_lengkap')
            ->paginate(15)
            ->withQueryString();

        return view('employees.index', compact('employees', 'year', 'search'));
    }

    public function roles(Request $request): View
    {
        $search = trim((string) $request->query('q', ''));

        return view('employees.roles', [
            'search' => $search,
            'users' => User::query()
                ->where('role', '<>', User::ROLE_USER)
                ->when($search !== '', fn ($query) => $query->where(function ($query) use ($search): void {
                    $query->where('nama_lengkap', 'like', "%{$search}%")
                        ->orWhere('username', 'like', "%{$search}%")
                        ->orWhere('role', 'like', "%{$search}%");
                }))
                ->orderBy('nama_lengkap')
                ->paginate(15)
                ->withQueryString(),
        ]);
    }

    public function create(): View
    {
        return view('employees.form', [
            'employee' => new User(),
            'roles' => User::ROLES,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate($this->rules($request));
        $signature = isset($data['ttd']) ? $this->images->storeSignature($data['ttd']) : null;
        $photo = isset($data['foto']) ? $this->images->storeProfilePhoto($data['foto']) : null;

        try {
            $employee = User::query()->create([
                'username' => $data['username'],
                'password' => Hash::make($data['password'] ?? '123456'),
                'force_password_change' => true,
                'nama_lengkap' => $data['nama_lengkap'],
                'nip' => $this->normalizedNip($data),
                'jabatan' => $data['jabatan'] ?? null,
                'pangkat_golongan' => $data['pangkat'] ?? null,
                'role' => $data['role'],
                'foto' => $photo ?: 'default.png',
                'ttd_path' => $signature,
            ]);
        } catch (\Throwable $exception) {
            $this->images->deleteSignature($signature);
            $this->images->deleteProfilePhoto($photo);
            throw $exception;
        }

        return redirect()
            ->route($employee->role === User::ROLE_USER ? 'employees.index' : 'employees.roles')
            ->with('success', 'Data pengguna berhasil ditambahkan. Password awal wajib diganti saat login pertama.');
    }

    public function show(Request $request): View
    {
        $status = (string) $request->query('status', '');
        $request->validate([
            'status' => ['nullable', Rule::in([
                PerjalananDinas::STATUS_READY,
                PerjalananDinas::STATUS_PENDING,
                PerjalananDinas::STATUS_APPROVED,
                PerjalananDinas::STATUS_REJECTED,
            ])],
        ]);
        $employee = User::query()->findOrFail($request->integer('id'));
        $base = PerjalananDinas::query()->where('user_id', $employee->id);

        return view('employees.show', [
            'employee' => $employee,
            'status' => $status,
            'statusCounts' => (clone $base)
                ->selectRaw('status, COUNT(*) as total')
                ->groupBy('status')
                ->pluck('total', 'status'),
            'travels' => (clone $base)
                ->when($status !== '', fn ($query) => $query->where('status', $status))
                ->latest('id')
                ->paginate(10)
                ->withQueryString(),
        ]);
    }

    public function edit(Request $request): View
    {
        return view('employees.form', [
            'employee' => User::query()->findOrFail($request->integer('id')),
            'roles' => User::ROLES,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $employee = User::query()->findOrFail($request->integer('id'));
        $data = $request->validate($this->rules($request, $employee));
        $this->guardLastAdmin($employee, $data['role']);

        $values = [
            'username' => $data['username'],
            'nama_lengkap' => $data['nama_lengkap'],
            'nip' => $this->normalizedNip($data),
            'jabatan' => $data['jabatan'] ?? null,
            'pangkat_golongan' => $data['pangkat'] ?? null,
            'role' => $data['role'],
        ];
        if (! empty($data['password'])) {
            $values['password'] = Hash::make($data['password']);
            $values['force_password_change'] = true;
        }

        $oldPhoto = $employee->foto;
        $oldSignature = $employee->ttd_path;
        $newPhoto = isset($data['foto']) ? $this->images->storeProfilePhoto($data['foto']) : null;
        $newSignature = isset($data['ttd']) ? $this->images->storeSignature($data['ttd']) : null;
        if ($newPhoto) {
            $values['foto'] = $newPhoto;
        }
        if ($newSignature) {
            $values['ttd_path'] = $newSignature;
        }

        try {
            $employee->update($values);
        } catch (\Throwable $exception) {
            $this->images->deleteProfilePhoto($newPhoto);
            $this->images->deleteSignature($newSignature);
            throw $exception;
        }

        if ($newPhoto) {
            $this->images->deleteProfilePhoto($oldPhoto);
        }
        if ($newSignature) {
            $this->images->deleteSignature($oldSignature);
        }

        return redirect()
            ->route($employee->role === User::ROLE_USER ? 'employees.index' : 'employees.roles')
            ->with('success', 'Data pengguna berhasil diperbarui.');
    }

    public function signature(User $employee): BinaryFileResponse
    {
        abort_unless($employee->hasSignature(), 404, 'Tanda tangan pegawai tidak ditemukan.');

        return response()->file($employee->signatureAbsolutePath(), [
            'Content-Type' => 'image/png',
            'Content-Disposition' => 'inline',
            'Cache-Control' => 'private, no-store, max-age=0',
        ]);
    }

    public function destroySignature(User $employee): RedirectResponse
    {
        $path = $employee->ttd_path;
        $employee->update(['ttd_path' => null]);
        $this->images->deleteSignature($path);

        return redirect()
            ->route('employees.edit', ['id' => $employee->id])
            ->with('success', 'Tanda tangan pegawai berhasil dihapus.');
    }

    public function destroy(Request $request): RedirectResponse
    {
        $employee = User::query()
            ->with('perjalananDinas.laporan.dokumentasi')
            ->findOrFail($request->integer('id'));

        if ((int) $request->user()->id === (int) $employee->id) {
            throw ValidationException::withMessages([
                'employee' => 'Admin tidak dapat menghapus akun yang sedang digunakan.',
            ]);
        }
        $this->guardLastAdmin($employee, null);

        $signature = $employee->ttd_path;
        $photo = $employee->foto;
        $documentation = $employee->perjalananDinas
            ->flatMap(fn (PerjalananDinas $travel) => $travel->laporan?->dokumentasi ?? collect())
            ->pluck('path')
            ->all();
        $wasEmployee = $employee->role === User::ROLE_USER;
        $employee->delete();

        $this->images->deleteSignature($signature);
        $this->images->deleteProfilePhoto($photo);
        $this->reportImages->deleteMany($documentation);

        return redirect()
            ->route($wasEmployee ? 'employees.index' : 'employees.roles')
            ->with('success', 'Data pengguna berhasil dihapus.');
    }

    private function rules(Request $request, ?User $employee = null): array
    {
        return [
            'nama_lengkap' => ['required', 'string', 'max:100'],
            'nip' => [
                Rule::requiredIf($request->input('role') === User::ROLE_USER),
                'nullable', 'string', 'max:30', 'not_in:-',
                Rule::unique('users', 'nip')->ignore($employee?->id),
            ],
            'pangkat' => ['nullable', 'string', 'max:50'],
            'jabatan' => ['nullable', 'string', 'max:50'],
            'username' => [
                'required', 'string', 'max:50',
                Rule::unique('users', 'username')->ignore($employee?->id),
            ],
            'password' => [$employee ? 'nullable' : 'required', 'string', 'min:6'],
            'role' => ['required', Rule::in(User::ROLES)],
            'foto' => [
                'nullable', 'file', 'image', 'mimes:jpg,jpeg,png',
                'mimetypes:image/jpeg,image/png', 'max:2048',
                'dimensions:min_width=100,min_height=100,max_width=6000,max_height=6000',
            ],
            'ttd' => [
                'nullable', 'file', 'image', 'mimes:png', 'mimetypes:image/png', 'max:1024',
                'dimensions:min_width=40,min_height=20,max_width=4000,max_height=2000',
            ],
        ];
    }

    private function normalizedNip(array $data): ?string
    {
        $nip = trim((string) ($data['nip'] ?? ''));

        return $nip !== '' ? $nip : null;
    }

    private function guardLastAdmin(User $employee, ?string $newRole): void
    {
        if (
            $employee->role === User::ROLE_ADMIN
            && $newRole !== User::ROLE_ADMIN
            && User::query()->where('role', User::ROLE_ADMIN)->count() <= 1
        ) {
            throw ValidationException::withMessages([
                'role' => 'Admin terakhir tidak dapat dihapus atau diubah ke role lain.',
            ]);
        }
    }
}
