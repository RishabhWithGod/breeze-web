<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A generic reference icon for a standard electrical symbol (a duplex
 * receptacle, a light switch, a junction box…), shown on a review card in
 * place of a real crop when the takeoff has none to offer — see
 * `App\Services\Review\SymbolIconMatcher`.
 *
 * Never project-specific and never sensitive, so the file itself lives
 * under `public/symbol-icons/` as a plain static asset; only the matching
 * metadata (name, keywords) needs the database.
 */
class SymbolIcon extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'keywords',
        'path',
    ];

    protected function casts(): array
    {
        return [
            'keywords' => 'array',
        ];
    }

    public function url(): string
    {
        return asset($this->path);
    }
}
