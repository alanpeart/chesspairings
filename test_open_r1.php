<?php
require_once __DIR__ . '/lib/ChessResultsScraper.php';

$url = 'https://chess-results.com/tnr1284262.aspx?lan=1';
$scraper = new ChessResultsScraper($url);
$fullData = $scraper->analyze();

$totalPlayers = count($fullData['players']);
$fullHalf = intdiv($totalPlayers, 2);

echo "Open section: $totalPlayers players, full-field midpoint = $fullHalf\n\n";

// Find R1 byes
$byeNos = [];
foreach ($fullData['players'] as $sno => $p) {
    if (in_array(1, $p['byeRounds'])) {
        $byeNos[] = $sno;
    }
}
echo "R1 bye players: " . implode(', ', $byeNos) . "\n";

$byesInTopHalf = count(array_filter($byeNos, fn($n) => $n <= $fullHalf));
$byesInBottomHalf = count(array_filter($byeNos, fn($n) => $n > $fullHalf));
echo "Byes in top half (≤$fullHalf): $byesInTopHalf\n";
echo "Byes in bottom half (>$fullHalf): $byesInBottomHalf\n";

// Active pool
$activePool = [];
foreach ($fullData['rounds'][1]['pairings'] as $pairing) {
    if (!$pairing['isBye']) {
        if ($pairing['whiteNo'] > 0) $activePool[] = $pairing['whiteNo'];
        if ($pairing['blackNo'] > 0) $activePool[] = $pairing['blackNo'];
    }
}
$activePool = array_unique($activePool);
sort($activePool);

$activeTopHalf = count(array_filter($activePool, fn($n) => $n <= $fullHalf));
$activeBottomHalf = count(array_filter($activePool, fn($n) => $n > $fullHalf));

$needed = intdiv(count($activePool), 2);
echo "\nActive pool: " . count($activePool) . " → S1 needs $needed, S2 needs $needed\n";
echo "Active in top half: $activeTopHalf, active in bottom half: $activeBottomHalf\n";
echo "Imbalance: " . abs($activeTopHalf - $activeBottomHalf) . " (excess in " . ($activeTopHalf > $activeBottomHalf ? 'top' : 'bottom') . ")\n";

// Now check actual S1/S2 from pairings
$actualS1 = [];
$actualS2 = [];
foreach ($fullData['rounds'][1]['pairings'] as $p) {
    if (!$p['isBye']) {
        $low = min($p['whiteNo'], $p['blackNo']);
        $high = max($p['whiteNo'], $p['blackNo']);
        $actualS1[] = $low;
        $actualS2[] = $high;
    }
}
sort($actualS1);
sort($actualS2);

// Our S1/S2
$half = intdiv(count($activePool), 2);
$ourS1 = array_slice($activePool, 0, $half);
$ourS2 = array_slice($activePool, $half);

$s1Diff = array_diff($ourS1, $actualS1);
$s1Extra = array_diff($actualS1, $ourS1);

echo "\nOur S1 vs Actual S1:\n";
if (empty($s1Diff) && empty($s1Extra)) {
    echo "  MATCH! Identical S1/S2 split.\n";
} else {
    echo "  In our S1 but not actual: " . implode(', ', $s1Diff) . "\n";
    echo "  In actual S1 but not ours: " . implode(', ', $s1Extra) . "\n";
}

echo "\nNow same analysis for U2000:\n";
$url2 = 'https://chess-results.com/tnr1284261.aspx?lan=1';
$scraper2 = new ChessResultsScraper($url2);
$fullData2 = $scraper2->analyze();
$totalPlayers2 = count($fullData2['players']);
$fullHalf2 = intdiv($totalPlayers2, 2);

$byeNos2 = [];
foreach ($fullData2['players'] as $sno => $p) {
    if (in_array(1, $p['byeRounds'])) $byeNos2[] = $sno;
}
$byesInTop2 = count(array_filter($byeNos2, fn($n) => $n <= $fullHalf2));
$byesInBottom2 = count(array_filter($byeNos2, fn($n) => $n > $fullHalf2));

$activePool2 = [];
foreach ($fullData2['rounds'][1]['pairings'] as $pairing) {
    if (!$pairing['isBye']) {
        if ($pairing['whiteNo'] > 0) $activePool2[] = $pairing['whiteNo'];
        if ($pairing['blackNo'] > 0) $activePool2[] = $pairing['blackNo'];
    }
}
$activePool2 = array_unique($activePool2);
sort($activePool2);

$activeTop2 = count(array_filter($activePool2, fn($n) => $n <= $fullHalf2));
$activeBottom2 = count(array_filter($activePool2, fn($n) => $n > $fullHalf2));

echo "U2000: $totalPlayers2 players, midpoint=$fullHalf2\n";
echo "Byes in top half: $byesInTop2, bottom half: $byesInBottom2\n";
echo "Active in top half: $activeTop2, active in bottom half: $activeBottom2\n";
echo "Imbalance: " . abs($activeTop2 - $activeBottom2) . " (excess in " . ($activeTop2 > $activeBottom2 ? 'top' : 'bottom') . ")\n";
