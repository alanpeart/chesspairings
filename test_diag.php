<?php
require_once __DIR__ . '/lib/ChessResultsScraper.php';
require_once __DIR__ . '/lib/SwissPairing.php';

$url = 'https://s2.chess-results.com/tnr1284262.aspx?lan=1';

$scraper = new ChessResultsScraper($url);
$fullData = $scraper->analyze();

$targetRound = (int)($argv[1] ?? 5);

// Get actual pairings
$actual = [];
$actualByes = [];
foreach ($fullData['rounds'][$targetRound]['pairings'] as $p) {
    if ($p['isBye']) {
        $actualByes[] = $p['whiteNo'];
    } else {
        $actual[$p['board']] = ['w' => $p['whiteNo'], 'b' => $p['blackNo']];
    }
}

// Extract actual pool
$actualPool = [];
foreach ($fullData['rounds'][$targetRound]['pairings'] as $pairing) {
    if (!$pairing['isBye']) {
        if ($pairing['whiteNo'] > 0) $actualPool[] = $pairing['whiteNo'];
        if ($pairing['blackNo'] > 0) $actualPool[] = $pairing['blackNo'];
    }
}
$actualPool = array_unique($actualPool);

// Rewind
$data = SwissPairing::rewindToRound($fullData, $targetRound);

// Show score groups with details
echo "=== Score Groups going into Round $targetRound ===\n";
$players = $data['players'];

// Filter to actual pool only
$poolPlayers = array_filter($players, fn($p) => in_array($p['startNo'], $actualPool));

$groups = [];
foreach ($poolPlayers as $sno => $p) {
    $score = (string)$p['currentScore'];
    $groups[$score][] = $p;
}
krsort($groups);

foreach ($groups as $score => $group) {
    usort($group, fn($a, $b) => $b['rating'] <=> $a['rating']);
    $count = count($group);
    $half = intdiv($count, 2);
    $odd = $count % 2 !== 0;

    echo "\n--- Score $score ($count players" . ($odd ? ", ODD - needs downfloater" : "") . ") ---\n";
    echo "S1 (top half, i=0.." . ($half-1) . "):\n";
    for ($i = 0; $i < $half; $i++) {
        $p = $group[$i];
        $colors = implode('', array_values($p['colors']));
        $pref = colorPref($p);
        echo sprintf("  S1[%d] #%2d %-30s Rtg=%4d Colors=[%s] Pref=%s\n",
            $i, $p['startNo'], $p['name'], $p['rating'], $colors, $pref);
    }
    echo "S2 (bottom half, i=0.." . ($count - $half - 1) . "):\n";
    for ($i = $half; $i < $count; $i++) {
        $p = $group[$i];
        $colors = implode('', array_values($p['colors']));
        $pref = colorPref($p);
        $marker = ($odd && $i === $count - 1) ? " <-- WOULD DOWNFLOAT" : "";
        echo sprintf("  S2[%d] #%2d %-30s Rtg=%4d Colors=[%s] Pref=%s%s\n",
            $i - $half, $p['startNo'], $p['name'], $p['rating'], $colors, $pref, $marker);
    }
}

// Show actual board-by-board comparison
echo "\n=== Board-by-Board Comparison (Round $targetRound) ===\n";

// Get predictions
$pairer = new SwissPairing($data, $actualPool);
$predictions = $pairer->predict();

$predicted = [];
foreach ($predictions['pairings'] as $p) {
    $predicted[$p['board']] = ['w' => $p['white']['startNo'], 'b' => $p['black']['startNo']];
}

$maxBd = max(max(array_keys($actual)), max(array_keys($predicted)));
for ($bd = 1; $bd <= $maxBd; $bd++) {
    $a = $actual[$bd] ?? null;
    $p = $predicted[$bd] ?? null;

    $aStr = $a ? sprintf("#%2d %-20s vs #%2d %-20s",
        $a['w'], $players[$a['w']]['name'] ?? '?',
        $a['b'], $players[$a['b']]['name'] ?? '?') : "---";
    $pStr = $p ? sprintf("#%2d %-20s vs #%2d %-20s",
        $p['w'], $players[$p['w']]['name'] ?? '?',
        $p['b'], $players[$p['b']]['name'] ?? '?') : "---";

    // Check match
    $pairingMatch = false;
    $boardMatch = false;
    if ($a && $p) {
        $aSet = [$a['w'], $a['b']]; sort($aSet);
        $pSet = [$p['w'], $p['b']]; sort($pSet);
        $pairingMatch = $aSet === $pSet;
        $boardMatch = $pairingMatch && $a['w'] === $p['w']; // same colors too
    }

    $status = !$a || !$p ? '?' : ($boardMatch ? 'OK' : ($pairingMatch ? '~COL' : 'MISS'));

    echo sprintf("Bd %2d: %-50s | %-50s [%s]\n", $bd, $aStr, $pStr, $status);
}

function colorPref(array $player): string {
    $whites = 0; $blacks = 0;
    foreach ($player['colors'] as $c) {
        if ($c === 'W') $whites++;
        elseif ($c === 'B') $blacks++;
    }
    $colorHistory = array_values(array_filter($player['colors'], fn($c) => $c === 'W' || $c === 'B'));
    $count = count($colorHistory);
    $lastTwo = [];
    if ($count >= 2) $lastTwo = [$colorHistory[$count-2], $colorHistory[$count-1]];
    if (count($lastTwo) === 2 && $lastTwo[0] === $lastTwo[1]) return $lastTwo[0] === 'W' ? 'B!' : 'W!';
    if ($whites > $blacks) return 'B';
    if ($blacks > $whites) return 'W';
    $last = $count > 0 ? $colorHistory[$count-1] : '-';
    return $last === 'W' ? 'B' : 'W';
}
