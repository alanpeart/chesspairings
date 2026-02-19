<?php
require_once __DIR__ . '/lib/ChessResultsScraper.php';
require_once __DIR__ . '/lib/SwissPairing.php';

$url = $argv[1] ?? 'https://chess-results.com/tnr1284261.aspx?lan=1';

$scraper = new ChessResultsScraper($url);
$fullData = $scraper->analyze();
$totalRounds = $fullData['tournament']['totalRounds'];

echo "Tournament: " . ($fullData['tournament']['name'] ?? '?') . "\n";
echo "Players: " . count($fullData['players']) . ", Rounds: $totalRounds\n\n";

for ($targetRound = 1; $targetRound <= $totalRounds; $targetRound++) {
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

    $matchPairing = 0;
    $matchBoard = 0;
    $matchColor = 0;
    $totalBoards = count($actual);

    foreach ($actual as $bd => $pair) {
        $actSet = $pair; sort($actSet);
        foreach ($predicted as $pBd => $pPair) {
            $predSet = $pPair; sort($predSet);
            if ($actSet === $predSet) {
                $matchPairing++;
                if ($bd === $pBd) {
                    $matchBoard++;
                    if ($pair === $pPair) $matchColor++;
                }
                break;
            }
        }
    }

    echo sprintf("Round %d: %d/%d pairings (%d%%)  %d/%d correct board (%d%%)  %d/%d exact match (%d%%)\n",
        $targetRound, $matchPairing, $totalBoards, $totalBoards > 0 ? round(100*$matchPairing/$totalBoards) : 0,
        $matchBoard, $totalBoards, $totalBoards > 0 ? round(100*$matchBoard/$totalBoards) : 0,
        $matchColor, $totalBoards, $totalBoards > 0 ? round(100*$matchColor/$totalBoards) : 0);
}
