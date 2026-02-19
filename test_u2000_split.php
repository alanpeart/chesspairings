<?php
require_once __DIR__ . '/lib/ChessResultsScraper.php';

$url = 'https://chess-results.com/tnr1284261.aspx?lan=1';
$scraper = new ChessResultsScraper($url);
$fullData = $scraper->analyze();

// Show all players sorted by startNo with ratings
echo "=== ALL PLAYERS BY STARTNO ===\n";
$players = $fullData['players'];
ksort($players);
foreach ($players as $sno => $p) {
    $byeR1 = in_array(1, $p['byeRounds']) ? ' [BYE R1]' : '';
    echo sprintf("#%2d  rating=%d  %s%s\n", $sno, $p['rating'], $p['name'], $byeR1);
}

// Theory 1: Split based on full field (N=50), then rebalance
echo "\n=== THEORY 1: Full field split (25/25) then rebalance ===\n";
$totalPlayers = count($players);
$fullHalf = intdiv($totalPlayers, 2); // 25
echo "Full field: $totalPlayers players, split at $fullHalf\n";

$activePool = [];
foreach ($fullData['rounds'][1]['pairings'] as $pairing) {
    if (!$pairing['isBye']) {
        if ($pairing['whiteNo'] > 0) $activePool[] = $pairing['whiteNo'];
        if ($pairing['blackNo'] > 0) $activePool[] = $pairing['blackNo'];
    }
}
$activePool = array_unique($activePool);
sort($activePool);

$s1Full = array_filter($activePool, fn($sno) => $sno <= $fullHalf);
$s2Full = array_filter($activePool, fn($sno) => $sno > $fullHalf);
$s1Full = array_values($s1Full);
$s2Full = array_values($s2Full);
echo "Active in original S1 (≤$fullHalf): " . count($s1Full) . " → " . implode(',', $s1Full) . "\n";
echo "Active in original S2 (>$fullHalf): " . count($s2Full) . " → " . implode(',', $s2Full) . "\n";

$needed = intdiv(count($activePool), 2); // 19
$excess = count($s1Full) - $needed; // 2
echo "Need $needed per half, S1 excess = $excess\n";

// Move bottom $excess of S1 to top of S2
$movedToS2 = array_splice($s1Full, -$excess);
$s2Full = array_merge($movedToS2, $s2Full);
echo "After rebalance - S1: " . implode(',', $s1Full) . "\n";
echo "After rebalance - S2: " . implode(',', $s2Full) . "\n";

// Theory 2: What if we move from TOP of S2 to BOTTOM of S1 instead?
echo "\n=== THEORY 2: Move top of S2 to bottom of S1 ===\n";
$s1Full2 = array_filter($activePool, fn($sno) => $sno <= $fullHalf);
$s2Full2 = array_filter($activePool, fn($sno) => $sno > $fullHalf);
$s1Full2 = array_values($s1Full2);
$s2Full2 = array_values($s2Full2);

$deficit = $needed - count($s2Full2); // 2
$movedToS1 = array_splice($s2Full2, 0, $deficit);
$s1Full2 = array_merge($s1Full2, $movedToS1);
echo "After rebalance - S1: " . implode(',', $s1Full2) . "\n";
echo "After rebalance - S2: " . implode(',', $s2Full2) . "\n";

// Theory 3: Interleave — pair original S1 with original S2, excess S1 play each other
echo "\n=== THEORY 3: Pair original halves, excess play each other ===\n";
$s1Orig = array_filter($activePool, fn($sno) => $sno <= $fullHalf);
$s2Orig = array_filter($activePool, fn($sno) => $sno > $fullHalf);
$s1Orig = array_values($s1Orig);
$s2Orig = array_values($s2Orig);
$hetPairs = min(count($s1Orig), count($s2Orig));
echo "Can pair $hetPairs cross-half pairs\n";
for ($i = 0; $i < $hetPairs; $i++) {
    echo sprintf("  S1[%d]=#%d vs S2[%d]=#%d\n", $i, $s1Orig[$i], $i, $s2Orig[$i]);
}
echo "Excess S1 players: ";
for ($i = $hetPairs; $i < count($s1Orig); $i++) {
    echo "#" . $s1Orig[$i] . " ";
}
echo "\n";

// Theory 4: What if the pairing number is NOT the same as startNo?
// Check if there's a different ordering that produces the actual S1/S2
echo "\n=== ACTUAL S1/S2 from pairings ===\n";
$actualS1 = [1, 2, 3, 4, 5, 6, 9, 10, 11, 12, 13, 16, 17, 18, 19, 20, 21, 22, 31];
$actualS2 = [23, 24, 25, 26, 27, 28, 32, 33, 35, 37, 40, 41, 42, 43, 44, 45, 47, 48, 50];
echo "Actual S1: " . implode(',', $actualS1) . "\n";
echo "Actual S2: " . implode(',', $actualS2) . "\n";

// What ordering would produce this split?
// If we sort the pool by some criterion and split at 19, position 19 must be #23
// Check: sort by rating
$poolPlayers = [];
foreach ($activePool as $sno) {
    $poolPlayers[] = ['sno' => $sno, 'rating' => $players[$sno]['rating'], 'name' => $players[$sno]['name']];
}
usort($poolPlayers, fn($a, $b) => $b['rating'] <=> $a['rating'] ?: $a['sno'] <=> $b['sno']);
echo "\nPool sorted by rating (split at pos 19):\n";
foreach ($poolPlayers as $i => $p) {
    $marker = ($i == 18) ? ' <<<< our boundary' : (($i == 17) ? '' : '');
    $inActualS1 = in_array($p['sno'], $actualS1) ? 'S1' : 'S2';
    echo sprintf("  %2d: #%2d rating=%d %s [actual: %s]%s\n", $i+1, $p['sno'], $p['rating'], $p['name'], $inActualS1, $marker);
}

// What about sorting by name?
echo "\nPool sorted by NAME:\n";
$poolByName = $poolPlayers;
usort($poolByName, fn($a, $b) => strcmp($a['name'], $b['name']));
foreach ($poolByName as $i => $p) {
    $inActualS1 = in_array($p['sno'], $actualS1) ? 'S1' : 'S2';
    if ($i >= 17 && $i <= 20) {
        echo sprintf("  %2d: #%2d rating=%d %s [actual: %s]\n", $i+1, $p['sno'], $p['rating'], $p['name'], $inActualS1);
    }
}
