<?php

namespace App\Modules\Promotion\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Base model modul Promotion (A10 — Kenaikan Kelas & Kelulusan).
 *
 * Menyembunyikan primary key dari mass assignment, seperti modul lain.
 */
abstract class PromotionModel extends Model
{
    protected $guarded = ['id'];
}
