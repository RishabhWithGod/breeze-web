<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

/** A photo or document kept as evidence for a change order. */
class ChangeOrderAttachment extends Model
{
    protected $fillable = ['change_order_id', 'user_id', 'name', 'path', 'size', 'mime'];

    public function deleteWithFile(): void
    {
        Storage::disk('local')->delete($this->path);
        $this->delete();
    }
}
