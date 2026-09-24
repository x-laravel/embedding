<?php

namespace XLaravel\Embedding\Similarity;

final class Metrics
{
    /**
     * Compute the cosine similarity between two vectors.
     *
     * @param  array<int, float>  $a
     * @param  array<int, float>  $b
     */
    public static function cosine(array $a, array $b): float
    {
        $dot = 0.0;
        $magA = 0.0;
        $magB = 0.0;

        foreach ($a as $i => $val) {
            $bVal = $b[$i] ?? 0.0;
            $dot += $val * $bVal;
            $magA += $val * $val;
            $magB += $bVal * $bVal;
        }

        $magA = sqrt($magA);
        $magB = sqrt($magB);

        return ($magA && $magB) ? ($dot / ($magA * $magB)) : 0.0;
    }
}
