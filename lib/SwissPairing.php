<?php

class SwissPairing
{
    private array $players;
    private array $rounds;
    private array $tournamentInfo;
    private int $nextRound;

    /** @var array Predicted pairings for next round */
    private array $pairings = [];

    /** @var int|null Player who gets a bye */
    private ?int $byePlayer = null;

    /** @var int[]|null If set, only these startNos are eligible (actual pool from completed round) */
    private ?array $actualPool = null;

    public function __construct(array $data, ?array $actualPool = null)
    {
        $this->players = $data['players'];
        $this->rounds = $data['rounds'];
        $this->tournamentInfo = $data['tournament'];
        $this->nextRound = ($this->tournamentInfo['completedRounds'] ?? 0) + 1;
        $this->actualPool = $actualPool;
    }

    /**
     * Rewind tournament data so that player state reflects only rounds 1..targetRound-1.
     * Returns a modified copy of $data suitable for passing to the constructor.
     */
    public static function rewindToRound(array $data, int $targetRound): array
    {
        $cutoff = $targetRound - 1; // rounds to keep

        // Rebuild each player's state from scratch using only rounds 1..$cutoff
        foreach ($data['players'] as $startNo => &$player) {
            $newOpponents = [];
            $newColors = [];
            $newResults = [];
            $hadBye = false;
            $byeRounds = [];
            $score = 0;

            for ($r = 1; $r <= $cutoff; $r++) {
                if (isset($player['opponents'][$r])) {
                    $newOpponents[$r] = $player['opponents'][$r];
                }
                if (isset($player['colors'][$r])) {
                    $newColors[$r] = $player['colors'][$r];
                }
                if (isset($player['results'][$r])) {
                    $newResults[$r] = $player['results'][$r];
                    $res = $player['results'][$r];
                    if ($res === '1') $score += 1;
                    elseif ($res === '½') $score += 0.5;
                }
            }

            // Recompute bye status from the kept rounds
            foreach ($player['byeRounds'] as $br) {
                if ($br <= $cutoff) {
                    $hadBye = true;
                    $byeRounds[] = $br;
                }
            }

            $player['opponents'] = $newOpponents;
            $player['colors'] = $newColors;
            $player['results'] = $newResults;
            $player['currentScore'] = $score;
            $player['hadBye'] = $hadBye;
            $player['byeRounds'] = $byeRounds;
        }
        unset($player);

        // Trim rounds to only those before target
        $keptRounds = [];
        foreach ($data['rounds'] as $rn => $rd) {
            if ($rn <= $cutoff) {
                $keptRounds[$rn] = $rd;
            }
        }
        $data['rounds'] = $keptRounds;

        // Update tournament metadata
        $data['tournament']['completedRounds'] = $cutoff;

        return $data;
    }

    public function predict(): array
    {
        $pool = $this->getEligiblePlayers();

        // Assign bye if odd number of players
        if (count($pool) % 2 !== 0) {
            $this->byePlayer = $this->assignBye($pool);
            $pool = array_filter($pool, fn($p) => $p['startNo'] !== $this->byePlayer);
            $pool = array_values($pool);
        }

        // Sort by score DESC, then startNo ASC (= TPN ascending = highest rated first)
        usort($pool, function ($a, $b) {
            if ($b['currentScore'] !== $a['currentScore']) {
                return $b['currentScore'] <=> $a['currentScore'];
            }
            return $a['startNo'] <=> $b['startNo'];
        });

        // Build score groups
        $scoreGroups = [];
        foreach ($pool as $player) {
            $score = (string)$player['currentScore'];
            if (!isset($scoreGroups[$score])) {
                $scoreGroups[$score] = [];
            }
            $scoreGroups[$score][] = $player;
        }

        // Sort score groups by score descending
        krsort($scoreGroups);

        // Convert to indexed array for lookahead access
        $groupKeys = array_keys($scoreGroups);
        $groupValues = array_values($scoreGroups);

        // Process pairings
        $this->pairings = [];
        $downfloaters = [];

        for ($gi = 0; $gi < count($groupKeys); $gi++) {
            $group = $groupValues[$gi];
            $nextGroup = ($gi + 1 < count($groupValues)) ? $groupValues[$gi + 1] : [];

            // Heterogeneous bracket: downfloaters form S1, natives form S2
            if (!empty($downfloaters)) {
                $result = $this->pairHeterogeneousBracket($downfloaters, $group);
                foreach ($result['pairings'] as $pair) {
                    $this->pairings[] = $pair;
                }
                // Remaining natives become a homogeneous sub-bracket
                $group = $result['remainingNatives'];
                $downfloaters = $result['unpaired'];
            }

            if (empty($group)) continue;

            // Sort within group: startNo ASC (= TPN order)
            usort($group, fn($a, $b) => $a['startNo'] <=> $b['startNo']);

            // If odd group size, select best downfloater candidate (with C8 lookahead)
            if (count($group) % 2 !== 0) {
                $bestIdx = $this->selectDownfloater($group, $nextGroup);
                $downfloaters[] = $group[$bestIdx];
                array_splice($group, $bestIdx, 1);
            }

            if (empty($group)) continue;

            // Split into S1 (top half) and S2 (bottom half)
            $half = intdiv(count($group), 2);
            $s1 = array_slice($group, 0, $half);
            $s2 = array_slice($group, $half);

            // Try to pair S1[i] with S2[i]
            $paired = $this->pairScoreGroup($s1, $s2);

            foreach ($paired['pairings'] as $pair) {
                $this->pairings[] = $pair;
            }

            $downfloaters = array_merge($downfloaters, $paired['unpaired']);
        }

        // Handle remaining downfloaters - try to pair them with each other
        if (count($downfloaters) >= 2) {
            usort($downfloaters, fn($a, $b) => $a['startNo'] <=> $b['startNo']);
            $half = intdiv(count($downfloaters), 2);
            $s1 = array_slice($downfloaters, 0, $half);
            $s2 = array_slice($downfloaters, $half);
            $paired = $this->pairScoreGroup($s1, $s2);
            foreach ($paired['pairings'] as $pair) {
                $this->pairings[] = $pair;
            }
        }

        // Assign board numbers using FIDE criteria
        $this->assignBoardNumbers();

        // Assign colors
        $this->assignColors();

        return [
            'nextRound' => $this->nextRound,
            'pairings' => $this->pairings,
            'bye' => $this->byePlayer ? [
                'playerNo' => $this->byePlayer,
                'playerName' => $this->players[$this->byePlayer]['name'] ?? 'Unknown',
                'playerRating' => $this->players[$this->byePlayer]['rating'] ?? 0,
            ] : null,
        ];
    }

    private function getEligiblePlayers(): array
    {
        if ($this->actualPool !== null) {
            return array_values(array_filter(
                $this->players,
                fn($p) => in_array($p['startNo'], $this->actualPool)
            ));
        }
        return array_values($this->players);
    }

    /**
     * Assign bye to lowest-scored player who hasn't had one yet.
     * Among tied scores, pick highest startNo (lowest-ranked).
     */
    private function assignBye(array $pool): int
    {
        // Sort: score ASC, then startNo DESC (lowest-ranked gets bye)
        usort($pool, function ($a, $b) {
            if ($a['currentScore'] !== $b['currentScore']) {
                return $a['currentScore'] <=> $b['currentScore'];
            }
            return $b['startNo'] <=> $a['startNo'];
        });

        foreach ($pool as $player) {
            if (!$player['hadBye']) {
                return $player['startNo'];
            }
        }

        // Everyone has had a bye — give it to lowest-ranked anyway
        return $pool[0]['startNo'];
    }

    /**
     * Select the best downfloater from an odd-sized group.
     * Evaluates both the current group's pairing quality (C6/C12/C13) and
     * the next bracket's quality when the candidate joins it (FIDE C8).
     * Returns the index of the chosen player in $group.
     */
    private function selectDownfloater(array $group, array $nextGroup = []): int
    {
        $count = count($group);

        // Try candidates from the bottom half (or at least the last few)
        $candidateStart = max(intdiv($count, 2), $count - 6);
        $bestIdx = $count - 1; // default: last player
        $bestPenalty = PHP_INT_MAX;

        for ($ci = $count - 1; $ci >= $candidateStart; $ci--) {
            $candidate = $group[$ci];

            // Remove candidate and evaluate current group
            $remaining = $group;
            array_splice($remaining, $ci, 1);

            $half = intdiv(count($remaining), 2);
            $s1 = array_slice($remaining, 0, $half);
            $s2 = array_slice($remaining, $half);

            $currentResult = $this->evaluateBestPairing($s1, $s2);
            $currentPenalty = $currentResult['penalty'];

            // C8: evaluate how well the candidate fits in the next bracket
            $nextPenalty = 0;
            if (!empty($nextGroup)) {
                $nextPenalty = $this->evaluateNextBracketFit($candidate, $nextGroup);
            }

            // Total penalty: current group quality + next bracket compatibility
            // Weight current group higher (it's the primary criterion)
            $totalPenalty = $currentPenalty * 100 + $nextPenalty;

            if ($totalPenalty < $bestPenalty) {
                $bestPenalty = $totalPenalty;
                $bestIdx = $ci;
                if ($bestPenalty === 0) break;
            }
        }

        return $bestIdx;
    }

    /**
     * Evaluate how well a downfloater candidate fits in the next bracket (FIDE C8).
     * Simulates pairing the candidate into the next bracket as a heterogeneous bracket,
     * then evaluates the quality of the remaining homogeneous sub-bracket.
     * Returns a penalty: 0 = perfect fit, higher = worse fit.
     */
    private function evaluateNextBracketFit(array $candidate, array $nextGroup): int
    {
        if (empty($nextGroup)) return 0;

        // Sort next group by startNo
        usort($nextGroup, fn($a, $b) => $a['startNo'] <=> $b['startNo']);

        // Find the best native to pair with the candidate (color-compatible, can play)
        $bestNativeIdx = -1;
        $bestNativePenalty = PHP_INT_MAX;

        for ($i = 0; $i < count($nextGroup); $i++) {
            $native = $nextGroup[$i];
            if (!$this->canPlay($candidate, $native)) continue;

            $pairColorPenalty = $this->colorConflictCost($candidate, $native);

            // Evaluate remaining natives after removing this one
            $remaining = $nextGroup;
            array_splice($remaining, $i, 1);

            $remainPenalty = 0;
            if (count($remaining) >= 2) {
                // Check if the remaining group (which may be odd) can pair well
                $rHalf = intdiv(count($remaining), 2);
                if ($rHalf > 0) {
                    $rS1 = array_slice($remaining, 0, $rHalf);
                    $rS2 = array_slice($remaining, $rHalf, $rHalf); // take only $rHalf from S2
                    $rResult = $this->evaluateBestPairing($rS1, $rS2);
                    $remainPenalty = $rResult['penalty'];
                }
            }

            $totalPenalty = $pairColorPenalty * 10 + $remainPenalty;

            if ($totalPenalty < $bestNativePenalty) {
                $bestNativePenalty = $totalPenalty;
                $bestNativeIdx = $i;
                if ($bestNativePenalty === 0) break;
            }
        }

        return ($bestNativeIdx >= 0) ? $bestNativePenalty : 100;
    }

    /**
     * Evaluate the best possible pairing quality for S1/S2 without committing.
     * Returns the best result from trying transpositions.
     */
    private function evaluateBestPairing(array $s1, array $s2): array
    {
        $count = min(count($s1), count($s2));
        if ($count === 0) {
            return ['penalty' => 0, 'pairings' => [], 'unpaired' => array_merge($s1, $s2)];
        }

        $directResult = $this->tryPairing($s1, $s2);
        if ($directResult['penalty'] === 0) {
            return $directResult;
        }

        if ($count <= 8) {
            $bestResult = $directResult;
            $bestPenalty = $directResult['penalty'];

            $permutations = $this->getPermutations(array_keys($s2));
            foreach ($permutations as $perm) {
                $permS2 = [];
                foreach ($perm as $idx) {
                    $permS2[] = $s2[$idx];
                }
                $result = $this->tryPairing($s1, $permS2);
                if ($result['penalty'] < $bestPenalty) {
                    $bestPenalty = $result['penalty'];
                    $bestResult = $result;
                    if ($bestPenalty === 0) break;
                }
            }
            return $bestResult;
        }

        // Forward-biased combined swaps for larger groups
        $bestS2 = $s2;
        $bestPenalty = $directResult['penalty'];
        $bestResult = $directResult;

        for ($pass = 0; $pass < $count * 2; $pass++) {
            $improved = false;
            for ($i = 0; $i < $count; $i++) {
                $hasRepeat = !$this->canPlay($s1[$i], $bestS2[$i]);
                $hasColor = !$hasRepeat && $this->colorConflictCost($s1[$i], $bestS2[$i]) > 0;
                if (!$hasRepeat && !$hasColor) continue;

                for ($j = $i + 1; $j < count($bestS2); $j++) {
                    if (!$this->canPlay($s1[$i], $bestS2[$j])) continue;
                    if ($j < $count && !$this->canPlay($s1[$j], $bestS2[$i])) continue;
                    if ($j < $count && $this->canPlay($s1[$j], $bestS2[$j]) && !$this->canPlay($s1[$j], $bestS2[$i])) continue;

                    $swappedS2 = $bestS2;
                    [$swappedS2[$i], $swappedS2[$j]] = [$swappedS2[$j], $swappedS2[$i]];
                    $result = $this->tryPairing($s1, $swappedS2);
                    if ($result['penalty'] < $bestPenalty) {
                        $bestPenalty = $result['penalty'];
                        $bestResult = $result;
                        $bestS2 = $swappedS2;
                        $improved = true;
                        break;
                    }
                }
            }
            if (!$improved) break;
        }
        return $bestResult;
    }

    /**
     * Pair a heterogeneous bracket: downfloaters (S1) vs native players (S2).
     * Downfloaters are paired with the best-matching native players.
     * Returns pairings, unpaired downfloaters, and remaining native players.
     */
    private function pairHeterogeneousBracket(array $downfloaters, array $natives): array
    {
        // Sort natives by startNo (TPN order)
        usort($natives, fn($a, $b) => $a['startNo'] <=> $b['startNo']);

        $s1 = $downfloaters;
        $m = count($s1);

        // S2 candidates: first M natives (but try transpositions for color compatibility)
        if ($m > count($natives)) {
            // More downfloaters than natives - pair what we can
            $s2 = $natives;
        } else {
            $s2 = array_slice($natives, 0, $m);
        }

        // Try transpositions of native candidates to optimize colors
        $bestResult = null;
        $bestPenalty = PHP_INT_MAX;

        // Try different native candidates (up to all of them, for small groups)
        $maxCandidates = min(count($natives), $m + 6);
        $candidateNatives = array_slice($natives, 0, $maxCandidates);

        if ($m <= 3 && $maxCandidates <= 8) {
            // Try all combinations of M natives from the candidate pool
            $combos = $this->getCombinations($candidateNatives, $m);
            foreach ($combos as $combo) {
                $result = $this->tryPairing($s1, $combo);
                if ($result['penalty'] < $bestPenalty) {
                    $bestPenalty = $result['penalty'];
                    $bestResult = $result;
                    $bestS2 = $combo;
                    if ($bestPenalty === 0) break;
                }
            }
        } else {
            // Direct pairing with transpositions
            $result = $this->pairScoreGroup($s1, $s2);
            $bestResult = ['pairings' => $result['pairings'], 'penalty' => 0, 'unpaired' => $result['unpaired']];
            $bestS2 = $s2;
        }

        // Determine which natives were used
        $usedNatives = [];
        if ($bestResult) {
            foreach ($bestResult['pairings'] as $pair) {
                $usedNatives[$pair['player2']['startNo']] = true;
            }
        }

        // Remaining natives = those not used in heterogeneous pairings
        $remainingNatives = array_values(array_filter($natives, fn($p) => !isset($usedNatives[$p['startNo']])));

        // Unpaired downfloaters
        $unpairedDown = [];
        if ($bestResult) {
            foreach ($bestResult['unpaired'] as $p) {
                // Only keep downfloaters in unpaired (natives go back to remaining)
                $isDown = false;
                foreach ($downfloaters as $d) {
                    if ($d['startNo'] === $p['startNo']) { $isDown = true; break; }
                }
                if ($isDown) {
                    $unpairedDown[] = $p;
                } else {
                    $remainingNatives[] = $p;
                }
            }
            // Re-sort remaining natives
            usort($remainingNatives, fn($a, $b) => $a['startNo'] <=> $b['startNo']);
        }

        return [
            'pairings' => $bestResult ? $bestResult['pairings'] : [],
            'remainingNatives' => $remainingNatives,
            'unpaired' => $unpairedDown,
        ];
    }

    /**
     * Pair S1 and S2, trying transpositions to minimize repeat-opponent
     * conflicts (FIDE C5/C6) and color conflicts (FIDE C12/C13).
     * Returns ['pairings' => [...], 'unpaired' => [...]]
     */
    private function pairScoreGroup(array $s1, array $s2): array
    {
        $count = min(count($s1), count($s2));
        if ($count === 0) {
            return ['pairings' => [], 'unpaired' => array_merge($s1, $s2)];
        }

        // Try direct pairing first
        $directResult = $this->tryPairing($s1, $s2);
        if ($directResult['penalty'] === 0) {
            return ['pairings' => $directResult['pairings'], 'unpaired' => $directResult['unpaired']];
        }

        // Try all permutations for small groups (≤ 8 per half)
        if ($count <= 8) {
            $bestResult = $directResult;
            $bestPenalty = $directResult['penalty'];

            $permutations = $this->getPermutations(array_keys($s2));
            foreach ($permutations as $perm) {
                $permS2 = [];
                foreach ($perm as $idx) {
                    $permS2[] = $s2[$idx];
                }
                $result = $this->tryPairing($s1, $permS2);
                if ($result['penalty'] < $bestPenalty) {
                    $bestPenalty = $result['penalty'];
                    $bestResult = $result;
                    if ($bestPenalty === 0) break;
                }
            }

            return ['pairings' => $bestResult['pairings'], 'unpaired' => $bestResult['unpaired']];
        } else {
            // Combined conflict + color resolution for larger groups.
            // Uses forward-biased swaps that consider both repeat-opponent
            // and color compatibility simultaneously (BSN-like ordering).
            $bestS2 = $s2;
            $maxPasses = $count * 3;

            for ($pass = 0; $pass < $maxPasses; $pass++) {
                $swapped = false;

                for ($i = 0; $i < $count; $i++) {
                    $hasRepeatConflict = !$this->canPlay($s1[$i], $bestS2[$i]);
                    $iColorCost = !$hasRepeatConflict ? $this->colorConflictCost($s1[$i], $bestS2[$i]) : 0;

                    if (!$hasRepeatConflict && $iColorCost === 0) continue;

                    // Find the best swap partner, preferring forward positions (BSN-like)
                    $bestJ = -1;
                    $bestSwapScore = -PHP_INT_MAX;

                    // Search forward first (j > i), then backward (BSN-like order)
                    $searchOrder = [];
                    for ($j = $i + 1; $j < count($bestS2); $j++) $searchOrder[] = $j;
                    for ($j = $i - 1; $j >= 0; $j--) $searchOrder[] = $j;

                    foreach ($searchOrder as $j) {
                        // Can S1[i] play the candidate S2[j]?
                        if (!$this->canPlay($s1[$i], $bestS2[$j])) continue;
                        // Can S1[j] play the displaced S2[i]?
                        if ($j < $count && !$this->canPlay($s1[$j], $bestS2[$i])) continue;

                        // Never create new repeat-opponent conflicts at j
                        if ($j < $count) {
                            $jHadRepeat = !$this->canPlay($s1[$j], $bestS2[$j]);
                            $jWouldHaveRepeat = !$this->canPlay($s1[$j], $bestS2[$i]);
                            if (!$jHadRepeat && $jWouldHaveRepeat) continue;
                        }

                        // Score this swap: fix at i + color optimization
                        $score = 0;

                        // Repeat-opponent resolution at i (highest priority)
                        if ($hasRepeatConflict) $score += 100000;

                        // Weighted color improvement at position i
                        $oldCostI = $this->colorConflictCost($s1[$i], $bestS2[$i]);
                        $newCostI = $this->colorConflictCost($s1[$i], $bestS2[$j]);
                        $score += ($oldCostI - $newCostI) * 100;

                        // Weighted color improvement at position j
                        if ($j < $count) {
                            $oldCostJ = $this->colorConflictCost($s1[$j], $bestS2[$j]);
                            $newCostJ = $this->colorConflictCost($s1[$j], $bestS2[$i]);
                            $score += ($oldCostJ - $newCostJ) * 100;
                        }

                        // BSN preference: prefer forward and closer swaps
                        if ($j > $i) $score += 10;
                        $score -= abs($j - $i);

                        if ($score > $bestSwapScore) {
                            $bestSwapScore = $score;
                            $bestJ = $j;
                        }
                    }

                    // Only swap if it's a net improvement
                    if ($bestJ >= 0 && $bestSwapScore > 0) {
                        [$bestS2[$i], $bestS2[$bestJ]] = [$bestS2[$bestJ], $bestS2[$i]];
                        $swapped = true;
                    }
                }

                if (!$swapped) break;
            }

            $bestResult = $this->tryPairing($s1, $bestS2);
            return ['pairings' => $bestResult['pairings'], 'unpaired' => $bestResult['unpaired']];
        }
    }

    /**
     * Try pairing S1[i] with S2[i]. Returns pairings, conflicts, and color conflicts.
     */
    private function tryPairing(array $s1, array $s2): array
    {
        $pairings = [];
        $conflicts = [];
        $colorCost = 0;
        $unpaired = [];
        $count = min(count($s1), count($s2));

        for ($i = 0; $i < $count; $i++) {
            if ($this->canPlay($s1[$i], $s2[$i])) {
                $pairings[] = [
                    'player1' => $s1[$i],
                    'player2' => $s2[$i],
                    'white' => null,
                    'black' => null,
                    'board' => 0,
                ];
                $colorCost += $this->colorConflictCost($s1[$i], $s2[$i]);
            } else {
                $conflicts[] = $i;
                $unpaired[] = $s1[$i];
                $unpaired[] = $s2[$i];
            }
        }

        // Any remaining unmatched players from unequal sizes
        for ($i = $count; $i < count($s1); $i++) $unpaired[] = $s1[$i];
        for ($i = $count; $i < count($s2); $i++) $unpaired[] = $s2[$i];

        // Combined penalty: repeat-opponent conflicts far outweigh color conflicts
        // Color cost is already weighted by preference strength
        $penalty = count($conflicts) * 10000 + $colorCost;

        return ['pairings' => $pairings, 'conflicts' => $conflicts, 'colorCost' => $colorCost, 'penalty' => $penalty, 'unpaired' => $unpaired];
    }

    /**
     * Check if two players can play each other (no repeat).
     */
    private function canPlay(array $p1, array $p2): bool
    {
        $no1 = $p1['startNo'];
        $no2 = $p2['startNo'];

        // Cannot play yourself
        if ($no1 === $no2) return false;

        // Cannot play someone you've already played
        foreach ($p1['opponents'] as $opp) {
            if ($opp === $no2) return false;
        }

        return true;
    }

    /**
     * Assign board numbers using FIDE C.04.2 criteria:
     * 1. Highest score of the higher-ranked player in the pair
     * 2. Highest sum of scores of both players
     * 3. Smallest TPN (startNo) of the higher-ranked player
     */
    private function assignBoardNumbers(): void
    {
        usort($this->pairings, function ($a, $b) {
            $aHigherTPN = min($a['player1']['startNo'], $a['player2']['startNo']);
            $bHigherTPN = min($b['player1']['startNo'], $b['player2']['startNo']);

            // Higher-ranked player = lower TPN
            $aHigherScore = ($a['player1']['startNo'] < $a['player2']['startNo'])
                ? $a['player1']['currentScore'] : $a['player2']['currentScore'];
            $bHigherScore = ($b['player1']['startNo'] < $b['player2']['startNo'])
                ? $b['player1']['currentScore'] : $b['player2']['currentScore'];

            // 1. Highest score of the higher-ranked player
            if ($bHigherScore !== $aHigherScore) return $bHigherScore <=> $aHigherScore;

            // 2. Highest sum of scores
            $aSum = $a['player1']['currentScore'] + $a['player2']['currentScore'];
            $bSum = $b['player1']['currentScore'] + $b['player2']['currentScore'];
            if ($bSum !== $aSum) return $bSum <=> $aSum;

            // 3. Smallest TPN of the higher-ranked player
            return $aHigherTPN <=> $bHigherTPN;
        });

        foreach ($this->pairings as $i => &$pairing) {
            $pairing['board'] = $i + 1;
        }
        unset($pairing);
    }

    /**
     * Assign white/black for each pairing based on color history.
     * FIDE C.04.3 A11 rules:
     * - If one has a preference and other doesn't, the one with preference gets their choice
     * - If different preferences, each gets what they want
     * - If same preference: stronger (bigger imbalance) wins, then less recently, then lower-ranked
     * - If neither has preference, use board alternation
     */
    private function assignColors(): void
    {
        foreach ($this->pairings as &$pairing) {
            $p1 = $pairing['player1'];
            $p2 = $pairing['player2'];

            $p1Pref = $this->colorPreference($p1);
            $p2Pref = $this->colorPreference($p2);

            if ($p1Pref === null && $p2Pref === null) {
                // Neither has a preference — alternate by board (odd=higher-ranked gets W)
                $higherRanked = ($p1['startNo'] < $p2['startNo']) ? $p1 : $p2;
                $lowerRanked = ($p1['startNo'] < $p2['startNo']) ? $p2 : $p1;
                if ($pairing['board'] % 2 === 1) {
                    $pairing['white'] = $higherRanked;
                    $pairing['black'] = $lowerRanked;
                } else {
                    $pairing['white'] = $lowerRanked;
                    $pairing['black'] = $higherRanked;
                }
            } elseif ($p1Pref === null) {
                if ($p2Pref === 'W') {
                    $pairing['white'] = $p2;
                    $pairing['black'] = $p1;
                } else {
                    $pairing['white'] = $p1;
                    $pairing['black'] = $p2;
                }
            } elseif ($p2Pref === null) {
                if ($p1Pref === 'W') {
                    $pairing['white'] = $p1;
                    $pairing['black'] = $p2;
                } else {
                    $pairing['white'] = $p2;
                    $pairing['black'] = $p1;
                }
            } elseif ($p1Pref !== $p2Pref) {
                // Different preferences — each gets what they want
                if ($p1Pref === 'W') {
                    $pairing['white'] = $p1;
                    $pairing['black'] = $p2;
                } else {
                    $pairing['white'] = $p2;
                    $pairing['black'] = $p1;
                }
            } else {
                // Both prefer same color — determine who gets it
                $winner = $this->colorTiebreak($p1, $p2, $p1Pref);

                if ($p1Pref === 'W') {
                    $pairing['white'] = $winner;
                    $pairing['black'] = ($winner === $p1) ? $p2 : $p1;
                } else {
                    $pairing['black'] = $winner;
                    $pairing['white'] = ($winner === $p1) ? $p2 : $p1;
                }
            }
        }
        unset($pairing);
    }

    /**
     * When both players prefer the same color, determine who gets it.
     * FIDE tiebreak: (1) stronger preference (bigger imbalance), (2) had preferred
     * color less recently, (3) S1 player (player1) gets preference.
     */
    private function colorTiebreak(array $p1, array $p2, string $preferredColor): array
    {
        // Color imbalance = |whites - blacks| (higher = stronger preference)
        $p1Imbalance = $this->colorImbalance($p1);
        $p2Imbalance = $this->colorImbalance($p2);

        if ($p1Imbalance !== $p2Imbalance) {
            return $p1Imbalance > $p2Imbalance ? $p1 : $p2;
        }

        // Had the preferred color less recently (longer since last time) gets priority
        $p1LastPref = $this->lastRoundWithColor($p1, $preferredColor);
        $p2LastPref = $this->lastRoundWithColor($p2, $preferredColor);

        if ($p1LastPref !== $p2LastPref) {
            return $p1LastPref < $p2LastPref ? $p1 : $p2;
        }

        // Final tiebreak: S1 player (player1 in our pairing structure) gets preference
        return $p1;
    }

    private function colorImbalance(array $player): int
    {
        $whites = 0;
        $blacks = 0;
        foreach ($player['colors'] as $c) {
            if ($c === 'W') $whites++;
            elseif ($c === 'B') $blacks++;
        }
        return abs($whites - $blacks);
    }

    private function lastRoundWithColor(array $player, string $color): int
    {
        $lastRound = 0;
        foreach ($player['colors'] as $round => $c) {
            if ($c === $color) $lastRound = $round;
        }
        return $lastRound;
    }

    /**
     * Determine a player's color preference ('W', 'B', or null).
     * Returns null if the player has no color history (FIDE: no preference).
     * Based on: no-3-in-a-row rule, color balance, and alternation.
     */
    private function colorPreference(array $player): ?string
    {
        $colorHistory = array_values(array_filter($player['colors'], fn($c) => $c === 'W' || $c === 'B'));
        $count = count($colorHistory);

        // No games played → no color preference (FIDE A6c)
        if ($count === 0) return null;

        $whites = 0;
        $blacks = 0;
        foreach ($colorHistory as $color) {
            if ($color === 'W') $whites++;
            else $blacks++;
        }

        // No 3 in a row — hard constraint (absolute preference)
        if ($count >= 2 && $colorHistory[$count - 2] === $colorHistory[$count - 1]) {
            return $colorHistory[$count - 1] === 'W' ? 'B' : 'W';
        }

        // Equalise color balance
        if ($whites > $blacks) return 'B';
        if ($blacks > $whites) return 'W';

        // Equal — alternate from last
        return $colorHistory[$count - 1] === 'W' ? 'B' : 'W';
    }

    /**
     * Check if two players have a color conflict (both have a preference and it's the same).
     */
    private function hasColorConflict(array $p1, array $p2): bool
    {
        $pref1 = $this->colorPreference($p1);
        $pref2 = $this->colorPreference($p2);
        return $pref1 !== null && $pref2 !== null && $pref1 === $pref2;
    }

    /**
     * Determine the type/strength of a player's color preference.
     * FIDE C.04.3: absolute (last 2 same) > strong (imbalanced) > mild (alternation).
     */
    private function colorPreferenceType(array $player): ?string
    {
        $colorHistory = array_values(array_filter($player['colors'], fn($c) => $c === 'W' || $c === 'B'));
        $count = count($colorHistory);
        if ($count === 0) return null;

        $whites = 0;
        $blacks = 0;
        foreach ($colorHistory as $c) {
            if ($c === 'W') $whites++;
            else $blacks++;
        }

        if ($count >= 2 && $colorHistory[$count - 2] === $colorHistory[$count - 1]) {
            return 'absolute';
        }
        if ($whites !== $blacks) return 'strong';
        return 'mild';
    }

    /**
     * Calculate the weighted cost of a color conflict between two players.
     * When both prefer the same color, the weaker preference concedes.
     * Cost = weight of the violated (weaker) preference type.
     * Returns 0 if no conflict.
     */
    private function colorConflictCost(array $p1, array $p2): int
    {
        if (!$this->hasColorConflict($p1, $p2)) return 0;

        $type1 = $this->colorPreferenceType($p1);
        $type2 = $this->colorPreferenceType($p2);

        $weights = ['absolute' => 100, 'strong' => 10, 'mild' => 1];
        $w1 = $weights[$type1] ?? 0;
        $w2 = $weights[$type2] ?? 0;

        // The weaker preference concedes — cost is the weaker's weight
        return min($w1, $w2);
    }

    /**
     * Generate all permutations of an array of indices.
     */
    private function getPermutations(array $items): array
    {
        if (count($items) <= 1) {
            return [$items];
        }

        $result = [];
        foreach ($items as $key => $item) {
            $remaining = $items;
            unset($remaining[$key]);
            $remaining = array_values($remaining);

            foreach ($this->getPermutations($remaining) as $perm) {
                array_unshift($perm, $item);
                $result[] = $perm;
            }

            // Limit permutations for safety
            if (count($result) > 40320) break; // 8! = 40320
        }

        return $result;
    }

    /**
     * Generate combinations of $k elements from $items.
     */
    private function getCombinations(array $items, int $k): array
    {
        if ($k === 0) return [[]];
        if ($k > count($items)) return [];

        $result = [];
        for ($i = 0; $i <= count($items) - $k; $i++) {
            $head = $items[$i];
            $tailCombos = $this->getCombinations(array_slice($items, $i + 1), $k - 1);
            foreach ($tailCombos as $combo) {
                array_unshift($combo, $head);
                $result[] = $combo;
            }
            if (count($result) > 200) break; // safety limit
        }
        return $result;
    }
}
