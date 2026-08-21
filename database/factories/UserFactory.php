<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

/** @extends Factory<User> */
class UserFactory extends Factory
{
    protected $model = User::class;

    public function definition(): array
    {
        return [
            'username' => fake()->unique()->userName(),
            'password' => Hash::make('123456'),
            'nama_lengkap' => fake()->name(),
            'nip' => fake()->unique()->numerify('##################'),
            'jabatan' => 'Staf',
            'pangkat_golongan' => 'Penata Muda / IIIa',
            'role' => User::ROLE_USER,
            'foto' => 'default.png',
        ];
    }

    public function role(string $role): static
    {
        return $this->state(fn () => ['role' => $role]);
    }
}
