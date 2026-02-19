<?php
require_once __DIR__ . '/lib/ChessResultsScraper.php';
require_once __DIR__ . '/lib/SwissPairing.php';

$url = 'https://chess-results.com/tnr1284261.aspx?lan=1';
$scraper = new ChessResultsScraper($url);
$fullData = $scraper->analyze();

echo "Tournament: " . ($fullData['tournament']['name'] ?? '?') . "\n";
echo "Players: " . count($fullData['players']) . ", Rounds: " . $fullData['tournament']['totalRounds'] . "\n\n";

// Show R1 actual pairings
echo "=== ACTUAL R1 PAIRINGS ===\n";
$actual = [];
foreach ($fullData['rounds'][1]['pairings'] as $p) {
    if (!$p['isBye']) {
        echo sprintf("Bd %2d: #%d (%s, %d) vs #%d (%s, %d)\n",
            $p['board'], $p['whiteNo'],
            $fullData['players'][$p['whiteNo']]['name'] ?? '?',
            $fullData['players'][$p['whiteNo']]['rating'] ?? 0,
            $p['blackNo'],
            $fullData['players'][$p['blackNo']]['name'] ?? '?',
            $fullData['players'][$p['blackNo']]['rating'] ?? 0);
        $actual[$p['board']] = [$p['whiteNo'], $p['blackNo']];
    } else {
        echo sprintf("BYE: #%d (%s)\n", $p['whiteNo'],
            $fullData['players'][$p['whiteNo']]['name'] ?? '?');
    }
}

// Show players who had R1 byes
echo "\n=== PLAYERS WITH R1 BYES ===\n";
foreach ($fullData['players'] as $sno => $p) {
    if (in_array(1, $p['byeRounds'])) {
        echo sprintf("#%d %s (rating: %d)\n", $sno, $p['name'], $p['rating']);
    }
}

// Extract actual R1 pool
$actualPool = [];
foreach ($fullData['rounds'][1]['pairings'] as $pairing) {
    if (!$pairing['isBye']) {
        if ($pairing['whiteNo'] > 0) $actualPool[] = $pairing['whiteNo'];
        if ($pairing['blackNo'] > 0) $actualPool[] = $pairing['blackNo'];
    }
}
$actualPool = array_unique($actualPool);
sort($actualPool);
echo "\nActual pool size: " . count($actualPool) . " players\n";
echo "Pool: " . implode(', ', $actualPool) . "\n";

// Rewind and predict
$data = SwissPairing::rewindToRound($fullData, 1);
$pairer = new SwissPairing($data, $actualPool);
$predictions = $pairer->predict();

echo "\n=== PREDICTED R1 PAIRINGS ===\n";
foreach ($predictions['pairings'] as $p) {
    echo sprintf("Bd %2d: #%d (%s, %d) vs #%d (%s, %d)\n",
        $p['board'],
        $p['white']['startNo'], $p['white']['name'] ?? '?', $p['white']['rating'] ?? 0,
        $p['black']['startNo'], $p['black']['name'] ?? '?', $p['black']['rating'] ?? 0);
}

// Compare board by board
echo "\n=== COMPARISON ===\n";
$predicted = [];
foreach ($predictions['pairings'] as $p) {
    $predicted[$p['board']] = [$p['white']['startNo'], $p['black']['startNo']];
}

foreach ($actual as $bd => $pair) {
    $actSet = $pair; sort($actSet);
    $match = false;
    $matchBd = false;
    foreach ($predicted as $pBd => $pPair) {
        $predSet = $pPair; sort($predSet);
        if ($actSet === $predSet) {
            $match = true;
            if ($bd === $pBd) $matchBd = true;
            break;
        }
    }
    $predPair = $predicted[$bd] ?? [0, 0];
    $status = $matchBd ? 'OK' : ($match ? 'BOARD' : 'MISS');
    echo sprintf("Bd %2d: actual [#%d vs #%d]  pred [#%d vs #%d]  %s\n",
        $bd, $pair[0], $pair[1], $predPair[0], $predPair[1], $status);
}

// Show the split we use vs what actual implies
echo "\n=== S1/S2 ANALYSIS ===\n";
echo "For R1 (all scores 0), standard split: top half of pool by startNo = S1, bottom = S2\n";
$poolSorted = $actualPool;
sort($poolSorted);
$half = intdiv(count($poolSorted), 2);
$s1 = array_slice($poolSorted, 0, $half);
$s2 = array_slice($poolSorted, $half);
echo "Our S1: " . implode(', ', $s1) . "\n";
echo "Our S2: " . implode(', ', $s2) . "\n";

// Infer actual S1/S2 from actual pairings
echo "\nActual S1 (whites on odd boards, blacks on even): ";
$actualS1 = [];
$actualS2 = [];
foreach ($actual as $bd => $pair) {
    // In R1, S1[i] always pairs with S2[i], S1 is the top half
    // The lower startNo in each pair is from S1
    $low = min($pair[0], $pair[1]);
    $high = max($pair[0], $pair[1]);
    $actualS1[] = $low;
    $actualS2[] = $high;
}
sort($actualS1);
sort($actualS2);
echo implode(', ', $actualS1) . "\n";
echo "Actual S2: " . implode(', ', $actualS2) . "\n";

// Find differences
$s1Diff = array_diff($s1, $actualS1);
$s1Extra = array_diff($actualS1, $s1);
if (!empty($s1Diff) || !empty($s1Extra)) {
    echo "\nS1 differences:\n";
    echo "  In our S1 but not actual: " . implode(', ', $s1Diff) . "\n";
    echo "  In actual S1 but not ours: " . implode(', ', $s1Extra) . "\n";
}
