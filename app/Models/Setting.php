<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    public $timestamps = false;

    protected $table = 'settings';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['nilai_setting' => 'decimal:2'];
    }
}
