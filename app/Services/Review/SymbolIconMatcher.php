<?php

namespace App\Services\Review;

use App\Models\SymbolIcon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Matches a symbol's name against the generic reference icon library
 * (`SymbolIcon`, imported via `takeoff:import-symbol-icons`) for review
 * cards that have no real crop to show.
 *
 * A guess, not a detection: nothing here claims a symbol looks like its
 * matched icon, only that the reviewer sees *something* recognisable
 * instead of a blank tile. A name that matches no icon well enough shows
 * nothing, same as today — this never invents a match to avoid an empty card.
 */
class SymbolIconMatcher
{
    /** Below this, two names are considered unrelated rather than a weak match. */
    private const MIN_SCORE = 2;

    /** Mirrors `ImportSymbolIcons::NUMBER_WORDS` so "3-way" and "three way" match either direction. */
    private const NUMBER_WORDS = [
        '1' => 'one', '2' => 'two', '3' => 'three', '4' => 'four', '5' => 'five',
    ];

    /** @var Collection<int, SymbolIcon>|null */
    private ?Collection $icons = null;

    public function urlFor(?string $name): ?string
    {
        if (blank($name)) {
            return null;
        }

        $words = $this->words($name);

        if ($words === []) {
            return null;
        }

        $best = null;
        $bestScore = 0;

        foreach ($this->icons() as $icon) {
            $score = $this->score($words, $icon->keywords);

            if ($score > $bestScore) {
                $best = $icon;
                $bestScore = $score;
            }
        }

        return $bestScore >= self::MIN_SCORE ? $best?->url() : null;
    }

    /** @return Collection<int, SymbolIcon> */
    private function icons(): Collection
    {
        return $this->icons ??= SymbolIcon::all();
    }

    /**
     * A phrase keyword (spaces, e.g. "duplex receptacle") scores high when
     * it appears verbatim in the name — a specific, multi-word match, worth
     * more than any number of single-word hits — scaled by how many words
     * it has, so a 4-word phrase's match outranks a 2-word phrase's the way
     * a more specific icon should. A single-word keyword scores one point
     * per whole-word match.
     *
     * @param  list<string>  $words
     * @param  list<string>  $keywords
     */
    private function score(array $words, array $keywords): int
    {
        $nameSet = array_flip($words);
        $joined = ' '.implode(' ', $words).' ';
        $score = 0;

        foreach ($keywords as $keyword) {
            if (str_contains($keyword, ' ')) {
                if (str_contains($joined, ' '.$keyword.' ')) {
                    $score += 3 * (substr_count($keyword, ' ') + 1);
                }

                continue;
            }

            if (isset($nameSet[$keyword])) {
                $score += 1;
            }
        }

        return $score;
    }

    /**
     * Every digit token (1–5) also contributes its word form, so "3-WAY"
     * matches an icon keyworded "three way" and vice versa — the import
     * command applies the same aliasing to a filename's own words.
     *
     * @return list<string>
     */
    private function words(string $name): array
    {
        $normalised = Str::lower(preg_replace('/[^a-z0-9]+/i', ' ', $name) ?? '');
        $tokens = array_values(array_filter(explode(' ', trim($normalised))));

        // In place, not appended — a phrase match needs the word form
        // sitting where the digit was, e.g. "3 way" → "three way".
        return array_map(fn (string $word) => self::NUMBER_WORDS[$word] ?? $word, $tokens);
    }
}
