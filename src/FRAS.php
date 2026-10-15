<?php

require_once __DIR__ . DIRECTORY_SEPARATOR . 'Levenshtein.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'LCS.php';

/**
 * FRAS - Field Rank And Sort
 *
 * A modular search helper that ranks an array of associative records
 * against a query string using three signals:
 *   - Prefix match (including a token-boundary bonus)
 *   - Longest Common Subsequence (LCS)
 *   - Levenshtein edit distance
 *
 * This is the REWRITTEN version. See README.md for the full before/after
 * writeup. On top of the original three-signal design, this version adds:
 *
 *   1. Normalization fix: LCS/Levenshtein scores are normalized against
 *      per-pair theoretical bounds (query/field lengths), not against the
 *      max value seen across the current batch of records. This makes
 *      similarityScore stable and comparable across calls.
 *
 *   2. Multibyte safety: string comparisons use mb_str_split()/mb_strlen()
 *      instead of raw byte indexing.
 *
 *   3. Adaptive weighting: weights are resolved per-call from (in priority
 *      order): explicit constructor weights, learned weights loaded from a
 *      JSON file for the given profile, a built-in named profile, learned
 *      "global" weights from the JSON file, or a query-length heuristic.
 *
 *   4. Token-boundary prefix bonus: a query that matches the start of a
 *      later word in the field (e.g. "smith" against "John Smith") now
 *      gets partial credit instead of scoring zero on the prefix signal.
 *
 *   5. Short-circuiting for scale: a lossless character-overlap check
 *      skips the LCS DP entirely when the query and field share no
 *      characters, and an optional, opt-in threshold check skips the
 *      Levenshtein DP for pairs that are already guaranteed to score
 *      below a relevance cutoff.
 *
 *   6. Learned weights: weights produced by an external tuning process
 *      (see the companion `fras-weight-trainer` CLI project) can be loaded
 *      from a JSON file and applied automatically, either globally or per
 *      named profile.
 */
class FRAS
{
    /**
     * Tuned weight presets for common field types.
     * Each entry is [weightPrefix, weightLCS, weightLevenshtein].
     * These are hand-picked defaults; see $weightsFile for a way to
     * override them with weights learned from real data.
     */
    private const PROFILES = [
        'sku'       => [0.60, 0.25, 0.15], // short, structured, prefix-driven codes
        'name'      => [0.40, 0.35, 0.25], // people/company names: reordering + typos both matter
        'email'     => [0.50, 0.20, 0.30], // prefix (local part) matters, typos common
        'address'   => [0.25, 0.45, 0.30], // word order varies a lot, LCS matters most
        'free_text' => [0.20, 0.40, 0.40], // long text: prefix nearly meaningless
    ];

    /** Keys required in every learned-weight entry loaded from JSON. */
    private const LEARNED_WEIGHT_KEYS = ['prefix', 'lcs', 'levenshtein'];

    private string $query;
    private array $records;
    private ?float $weightPrefix;
    private ?float $weightLCS;
    private ?float $weightLevenshtein;
    private ?string $profile;
    private ?string $weightsFile;
    private bool $enableShortCircuit;
    private float $shortCircuitThreshold;

    /** @var array{0:float,1:float,2:float}|null Weights actually used by the last look() call. */
    private ?array $resolvedWeights = null;

    /** @var string|null Which branch of resolveWeights() fired on the last look() call. */
    private ?string $resolvedWeightSource = null;

    /** @var array|null Cache of the decoded learned-weights JSON file (avoid re-reading it). */
    private ?array $learnedWeightsCache = null;

    /**
     * @param string      $query                 The search string to compare against.
     * @param array       $namedArray            Array of associative arrays containing the field.
     * @param float|null  $weightPrefix          Explicit prefix weight. Pass all three weights to
     *                                            force fixed weighting (legacy behavior). Leave null
     *                                            (default) to let FRAS pick weights adaptively.
     * @param float|null  $weightLCS             Explicit LCS weight.
     * @param float|null  $weightLevenshtein     Explicit Levenshtein weight.
     * @param string|null $profile               Optional named domain profile (see self::PROFILES)
     *                                            used when explicit weights are not all provided.
     * @param string|null $weightsFile           Optional path to a JSON file of learned weights
     *                                            (see README.md "Learned weights" section for the
     *                                            expected format). Checked before the built-in
     *                                            profile table and the heuristic.
     * @param bool        $enableShortCircuit    Opt-in performance short-circuit for Levenshtein.
     *                                            When true, pairs that are already mathematically
     *                                            guaranteed to score below $shortCircuitThreshold
     *                                            skip the DP and get an approximate worst-case
     *                                            distance instead. Off by default so scores are
     *                                            always exact unless explicitly relaxed.
     * @param float       $shortCircuitThreshold Minimum Levenshtein-score ceiling a pair must be
     *                                            able to reach to bother computing it exactly, when
     *                                            $enableShortCircuit is true.
     */
    public function __construct(
        string $query,
        array $namedArray,
        ?float $weightPrefix = null,
        ?float $weightLCS = null,
        ?float $weightLevenshtein = null,
        ?string $profile = null,
        ?string $weightsFile = null,
        bool $enableShortCircuit = false,
        float $shortCircuitThreshold = 0.05
    ) {
        $this->query = $query;
        $this->records = $namedArray;
        $this->weightPrefix = $weightPrefix;
        $this->weightLCS = $weightLCS;
        $this->weightLevenshtein = $weightLevenshtein;
        $this->profile = $profile;
        $this->weightsFile = $weightsFile;
        $this->enableShortCircuit = $enableShortCircuit;
        $this->shortCircuitThreshold = $shortCircuitThreshold;
    }

    public function look(string $field): array
    {
        if (empty($this->records)) {
            throw new InvalidArgumentException("The records array is empty.");
        }

        foreach ($this->records as $index => $record) {
            if (!array_key_exists($field, $record)) {
                throw new InvalidArgumentException("Field '{$field}' not found in record at index {$index}.");
            }
        }

        [$weightPrefix, $weightLCS, $weightLevenshtein] = $this->resolveWeights();
        $this->resolvedWeights = [$weightPrefix, $weightLCS, $weightLevenshtein];

        $queryLower = mb_strtolower($this->query);
        $queryChars = mb_str_split($queryLower);
        $qLen = count($queryChars);
        $queryCharSet = array_unique($queryChars);

        foreach ($this->records as &$record) {
            $fieldValue = (string) $record[$field];
            $fieldLower = mb_strtolower($fieldValue);
            $fieldChars = mb_str_split($fieldLower);
            $fLen = count($fieldChars);

            $minLen = min($qLen, $fLen);
            $maxLen = max($qLen, $fLen);

            // --- LCS, with a lossless short-circuit -------------------------
            // If the query and field share no characters at all, the LCS is
            // guaranteed to be 0. This check is O(n), always correct (not an
            // approximation), and always enabled -- unlike the Levenshtein
            // short-circuit below, it never changes the result.
            $fieldCharSet = array_unique($fieldChars);
            if ($qLen === 0 || $fLen === 0 || count(array_intersect($queryCharSet, $fieldCharSet)) === 0) {
                $record['LCSlength'] = 0;
            } else {
                $lcs = new LCS($queryLower, $fieldLower);
                $record['LCSlength'] = $lcs->dynamic();
            }

            // --- Levenshtein, with an optional approximate short-circuit ----
            $record['levenshteinApproximated'] = false;
            $skipLevenshtein = false;

            if ($this->enableShortCircuit && $maxLen > 0) {
                // The minimum possible edit distance between two strings is
                // the difference in their lengths (pure insertions/deletions,
                // everything else matching). If even that best case can't
                // clear the relevance threshold, the true distance (which
                // can only be larger) definitely won't either -- skip the DP.
                $lowerBoundDistance = abs($qLen - $fLen);
                $bestPossibleLevScore = 1 - ($lowerBoundDistance / $maxLen);

                if ($bestPossibleLevScore < $this->shortCircuitThreshold) {
                    $record['levenshteinDistance'] = $maxLen; // worst-case approximation
                    $record['levenshteinApproximated'] = true;
                    $skipLevenshtein = true;
                }
            }

            if (!$skipLevenshtein) {
                $lev = new Levenshtein($queryLower, $fieldLower);
                $record['levenshteinDistance'] = $lev->dynamic();
            }

            // --- Prefix score, with a token-boundary bonus ------------------
            // Literal prefix: how much of the query matches from position 0.
            $literalPrefixLen = 0;
            $limit = min($qLen, $fLen);
            for ($i = 0; $i < $limit; $i++) {
                if ($queryChars[$i] === $fieldChars[$i]) {
                    $literalPrefixLen++;
                } else {
                    break;
                }
            }
            $literalPrefixScore = $qLen > 0 ? $literalPrefixLen / $qLen : 0.0;

            // Token bonus: a query matching the start of a later word (e.g.
            // "smith" against "John Smith") gets partial credit, decayed by
            // how far into the field that word appears. Token 0 is included
            // at full weight: when the field starts with it, the literal
            // prefix is already at least as high and max() leaves it alone,
            // but when the field starts with a delimiter (" smith", ".NET")
            // this is the only check that sees the first word at all.
            $tokenBonusScore = 0.0;
            if ($qLen > 0) {
                $tokens = preg_split('/[\s\-_,.\/]+/u', $fieldLower, -1, PREG_SPLIT_NO_EMPTY);
                foreach ($tokens as $tokenIndex => $token) {
                    $tokenChars = mb_str_split($token);
                    $tokenMatchLen = 0;
                    $tokenLimit = min($qLen, count($tokenChars));
                    for ($i = 0; $i < $tokenLimit; $i++) {
                        if ($queryChars[$i] === $tokenChars[$i]) {
                            $tokenMatchLen++;
                        } else {
                            break;
                        }
                    }
                    if ($tokenMatchLen === 0) {
                        continue;
                    }
                    $positionDecay = 1 / (1 + $tokenIndex);
                    $tokenBonusScore = max($tokenBonusScore, ($tokenMatchLen / $qLen) * $positionDecay);
                }
            }

            $prefixScore = max($literalPrefixScore, $tokenBonusScore);

            // --- LCS / Levenshtein scores, normalized per-pair --------------
            $lcsScore = $minLen > 0 ? $record['LCSlength'] / $minLen : 0.0;
            $levScore = $maxLen > 0 ? 1 - ($record['levenshteinDistance'] / $maxLen) : 1.0;

            $record['similarityScore'] =
                ($weightPrefix * $prefixScore) +
                ($weightLCS * $lcsScore) +
                ($weightLevenshtein * $levScore);
        }
        unset($record);

        usort($this->records, function ($a, $b) {
            return $b['similarityScore'] <=> $a['similarityScore'];
        });

        return $this->records;
    }

    /**
     * Returns the [weightPrefix, weightLCS, weightLevenshtein] tuple that
     * was actually applied during the most recent look() call, or null if
     * look() has not been called yet.
     */
    public function getResolvedWeights(): ?array
    {
        return $this->resolvedWeights;
    }

    /**
     * Returns which branch of resolveWeights() produced the weights used
     * on the most recent look() call: 'explicit', 'learned:profile',
     * 'profile', 'learned:global', 'heuristic', or null if look() has not
     * been called yet. Useful for debugging / verifying a learned-weights
     * file is actually being picked up.
     */
    public function getResolvedWeightSource(): ?string
    {
        return $this->resolvedWeightSource;
    }

    /**
     * Decides which weights to use, in priority order:
     *   1. Explicit weights, if all three were passed to the constructor.
     *   2. Learned weights for the given $profile, if a weights file was
     *      given and contains a "profiles" entry matching $profile.
     *   3. A built-in named profile, if $profile is given and recognized.
     *   4. Learned "global" weights, if a weights file was given and
     *      contains a "global" entry.
     *   5. A query-length heuristic.
     * The result is always normalized to sum to 1.0.
     *
     * @return array{0:float,1:float,2:float}
     */
    private function resolveWeights(): array
    {
        if ($this->weightPrefix !== null && $this->weightLCS !== null && $this->weightLevenshtein !== null) {
            $this->resolvedWeightSource = 'explicit';
            return $this->normalize([$this->weightPrefix, $this->weightLCS, $this->weightLevenshtein]);
        }

        $learned = $this->loadLearnedWeights();

        if ($this->profile !== null) {
            if ($learned !== null && isset($learned['profiles'][$this->profile])) {
                $this->resolvedWeightSource = 'learned:profile';
                return $this->normalize($this->tripleFromAssoc($learned['profiles'][$this->profile], "profiles.{$this->profile}"));
            }

            if (array_key_exists($this->profile, self::PROFILES)) {
                $this->resolvedWeightSource = 'profile';
                return self::PROFILES[$this->profile];
            }
        }

        if ($learned !== null && isset($learned['global'])) {
            $this->resolvedWeightSource = 'learned:global';
            return $this->normalize($this->tripleFromAssoc($learned['global'], 'global'));
        }

        $this->resolvedWeightSource = 'heuristic';
        return $this->normalize($this->heuristicWeights());
    }

    /**
     * Query-length heuristic used when no explicit weights, learned
     * weights, or matching profile are available:
     *   - Very short queries (<=3 chars) behave like autocomplete input,
     *     where matching from the start of the field matters most.
     *   - Medium queries (4-8 chars) balance prefix matching with
     *     structural similarity.
     *   - Long queries (>8 chars) tolerate typos/reordering better, so
     *     LCS and Levenshtein carry more weight than raw prefix matching.
     *
     * @return array{0:float,1:float,2:float}
     */
    private function heuristicWeights(): array
    {
        $queryLen = mb_strlen($this->query);

        if ($queryLen <= 3) {
            return [0.70, 0.20, 0.10];
        }

        if ($queryLen <= 8) {
            return [0.50, 0.30, 0.20];
        }

        return [0.30, 0.40, 0.30];
    }

    /**
     * Loads and caches the learned-weights JSON file, if one was given.
     * Expected shape:
     *   {
     *     "profiles": { "<profileName>": {"prefix":..,"lcs":..,"levenshtein":..}, ... },
     *     "global":   {"prefix":..,"lcs":..,"levenshtein":..}
     *   }
     * Both top-level keys are optional; FRAS falls back to the built-in
     * profile table / heuristic for whatever isn't present. Throws when
     * the file itself can't be read or isn't valid JSON; an entry that is
     * present but malformed throws when it is used (see tripleFromAssoc).
     */
    private function loadLearnedWeights(): ?array
    {
        if ($this->weightsFile === null) {
            return null;
        }

        if ($this->learnedWeightsCache !== null) {
            return $this->learnedWeightsCache;
        }

        if (!is_file($this->weightsFile) || !is_readable($this->weightsFile)) {
            throw new InvalidArgumentException("Learned weights file not found or not readable: {$this->weightsFile}");
        }

        $contents = file_get_contents($this->weightsFile);
        $decoded = json_decode($contents, true);

        if (!is_array($decoded)) {
            throw new InvalidArgumentException("Learned weights file is not valid JSON: {$this->weightsFile}");
        }

        return $this->learnedWeightsCache = $decoded;
    }

    /**
     * Converts a decoded {"prefix":..,"lcs":..,"levenshtein":..} entry from
     * the learned-weights file into the [prefix, lcs, levenshtein] tuple
     * used internally. Each value must be a finite, non-negative JSON
     * number and they must not all be zero: anything else would rank
     * silently wrongly rather than fail.
     *
     * @param mixed  $weights The decoded entry.
     * @param string $where   Where the entry sits in the file, for messages.
     * @return array{0:float,1:float,2:float}
     */
    private function tripleFromAssoc($weights, string $where): array
    {
        if (!is_array($weights)) {
            throw new InvalidArgumentException("Learned weights entry '{$where}' must be an object.");
        }

        $triple = [];
        foreach (self::LEARNED_WEIGHT_KEYS as $key) {
            if (!array_key_exists($key, $weights)) {
                throw new InvalidArgumentException("Learned weights entry '{$where}' is missing required key '{$key}'.");
            }
            $value = $weights[$key];
            if (!is_int($value) && !is_float($value)) {
                throw new InvalidArgumentException("Learned weight '{$where}.{$key}' must be a number.");
            }
            if (!is_finite((float) $value) || $value < 0) {
                throw new InvalidArgumentException("Learned weight '{$where}.{$key}' must be finite and not negative.");
            }
            $triple[] = (float) $value;
        }

        if (array_sum($triple) <= 0.0) {
            throw new InvalidArgumentException("Learned weights entry '{$where}' has no positive weight.");
        }

        return $triple;
    }

    /**
     * @param array{0:float,1:float,2:float} $weights
     * @return array{0:float,1:float,2:float}
     */
    private function normalize(array $weights): array
    {
        $sum = array_sum($weights);
        if ($sum <= 0.0) {
            // Degenerate input (e.g. all zeros/negative): fall back to equal weighting.
            return [1 / 3, 1 / 3, 1 / 3];
        }
        return [$weights[0] / $sum, $weights[1] / $sum, $weights[2] / $sum];
    }
}
