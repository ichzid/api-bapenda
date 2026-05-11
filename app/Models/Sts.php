<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Sts extends Model
{
    use HasFactory;

    protected $table = 'STS_History';
    // protected $connection = 'simpada'; // Uncomment if using specific connection
    public $timestamps = false;
    protected $guarded = [];
}
