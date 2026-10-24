<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StockTransferEvent extends Model
{
    public $timestamps = false;

    protected $fillable = ['stock_transfer_id', 'type', 'idempotency_key', 'created_by'];
}
