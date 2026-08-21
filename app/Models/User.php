<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\Storage;

class User extends Authenticatable
{
    use HasFactory;

    public const ROLE_ADMIN = 'admin';
    public const ROLE_OFFICER = 'officer';
    public const ROLE_PROGRAM = 'program';
    public const ROLE_USER = 'user';
    public const ROLE_VERIFIER = 'verifikator';
    public const ROLE_HEAD = 'head';

    public const ROLES = [
        self::ROLE_ADMIN,
        self::ROLE_OFFICER,
        self::ROLE_PROGRAM,
        self::ROLE_USER,
        self::ROLE_VERIFIER,
        self::ROLE_HEAD,
    ];

    public $timestamps = false;

    protected $table = 'users';

    protected $fillable = [
        'username',
        'password',
        'force_password_change',
        'nama_lengkap',
        'nip',
        'jabatan',
        'role',
        'pangkat_golongan',
        'foto',
        'ttd_path',
    ];

    protected $hidden = ['password'];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
            'force_password_change' => 'boolean',
        ];
    }

    public function perjalananDinas(): HasMany
    {
        return $this->hasMany(PerjalananDinas::class, 'user_id');
    }

    public function hasRole(string|array $roles): bool
    {
        return in_array($this->role, (array) $roles, true);
    }

    public function photoUrl(): string
    {
        $filename = $this->foto ?: 'default.png';
        $relativePath = 'uploads/profil/' . $filename;

        return is_file(public_path($relativePath))
            ? asset($relativePath)
            : asset('assets/images/default-avatar.svg');
    }

    public function hasSignature(): bool
    {
        return ! empty($this->ttd_path) && Storage::disk('local')->exists($this->ttd_path);
    }
    public function signatureAbsolutePath(): ?string
    {
        if (! $this->hasSignature()) {
            return null;
        }

        return Storage::disk('local')->path($this->ttd_path);
    }
}
