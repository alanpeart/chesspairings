<?php
require_once __DIR__ . '/lib/ChessResultsScraper.php';
require_once __DIR__ . '/lib/SwissPairing.php';

// Check both tournaments' R1 bye counts and detailed R2+ diagnostics for U2000
$urls = [
    'Open' => 'https://chess-results.com/tnr1284262.aspx?lan=1',
    'U2000' => 'https://chess-results.com/tnr1284261.aspx?lan=1',
];

foreach ($urls as $label => $url) {
    $scraper = new ChessResultsScraper($url);
    $fullData = $scraper->analyze();

    echo "=== $label SECTION ===\n";
    echo "Players: " . count($fullData['players']) . "\n";

    // Count R1 byes
    $r1Byes = 0;
    foreach ($fullData['players'] as $p) {
        if (in_array(1, $p['byeRounds'])) $r1Byes++;
    }
    echo "R1 byes: $r1Byes\n";

    if (isset($fullData['rounds'][1])) {
        $r1Boards = 0;
        foreach ($fullData['rounds'][1]['pairings'] as $p) {
            if (!$p['isBye']) $r1Boards++;
        }
        echo "R1 boards: $r1Boards (active: " . ($r1Boards * 2) . " players)\n";
    }
    echo "\n";
}

// Now do detailed R2-R5 diagnostics for U2000
$scraper = new ChessResultsScraper($urls['U2000']);
$fullData = $scraper->analyze();

for ($targetRound = 2; $targetRound <= 5; $targetRound++) {
    echo "=== U2000 ROUND $targetRound DETAIL ===\n";

    $actual = [];
    if (!isset($fullData['rounds'][$targetRound])) continue;

    foreach ($fullData['rounds'][$targetRound]['pairings'] as $p) {
        if (!$p['isBye']) {
            $actual[$p['board']] = [$p['whiteNo'], $p['blackNo']];
        }
    }

    $actualPool = [];
    foreach ($fullData['rounds'][$targetRound]['pairings'] as $pairing) {
        if (!$pairing['isBye']) {
            if ($pairing['whiteNo'] > 0) $actualPool[] = $pairing['whiteNo'];
            if ($pairing['blackNo'] > 0) $actualPool[] = $pairing['blackNo'];
        }
    }
    $actualPool = array_unique($actualPool);

    $data = SwissPairing::rewindToRound($fullData, $targetRound);
    $pairer = new SwissPairing($data, $actualPool);
    $predictions = $pairer->predict();

    $predicted = [];
    foreach ($predictions['pairings'] as $p) {
        $predicted[$p['board']] = [$p['white']['startNo'], $p['black']['startNo']];
    }

    $misses = [];
    foreach ($actual as $bd => $pair) {
        $actSet = $pair; sort($actSet);
        $found = false;
        foreach ($predicted as $pBd => $pPair) {
            $predSet = $pPair; sort($predSet);
            if ($actSet === $predSet) {
                $found = true;
                if ($bd !== $pBd || $pair !== $pPair) {
                    // Board or color mismatch but pairing correct
                }
                break;
            }
        }
        if (!$found) {
            $predPair = $predicted[$bd] ?? [0, 0];
            $misses[] = sprintf("Bd %2d: actual [#%d vs #%d]  pred [#%d vs #%d]",
                $bd, $pair[0], $pair[1], $predPair[0], $predPair[1]);
        }
    }

    $matchCount = count($actual) - count($misses);
    echo sprintf("  %d/%d pairings correct (%d%%)\n", $matchCount, count($actual),
        count($actual) > 0 ? round(100 * $matchCount / count($actual)) : 0);

    if (!empty($misses)) {
        echo "  Mismatches:\n";
        foreach ($misses as $m) echo "    $m\n";
    }
    echo "\n";
}
