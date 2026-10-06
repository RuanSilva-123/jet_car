<?php

namespace App\Support;

/**
 * Net Promoter Score: % de promotores (9–10) menos % de detratores (0–6). Vai de −100 a 100.
 */
final class Nps
{
    /**
     * @param  iterable<int>  $scores
     * @return array{responses: int, nps: int|null, average: float|null, promoters: int, passives: int, detractors: int}
     */
    public static function summarize(iterable $scores): array
    {
        $scores = collect($scores)->map(fn ($score) => (int) $score);
        $total = $scores->count();
        $promoters = $scores->filter(fn (int $score) => $score >= 9)->count();
        $detractors = $scores->filter(fn (int $score) => $score <= 6)->count();

        return [
            'responses' => $total,
            'nps' => $total ? (int) round(($promoters - $detractors) / $total * 100) : null,
            'average' => $total ? round($scores->avg(), 1) : null,
            'promoters' => $promoters,
            'passives' => $total - $promoters - $detractors,
            'detractors' => $detractors,
        ];
    }

    /** promoter (9–10), passive (7–8) ou detractor (0–6). */
    public static function category(int $score): string
    {
        return $score >= 9 ? 'promoter' : ($score >= 7 ? 'passive' : 'detractor');
    }
}
