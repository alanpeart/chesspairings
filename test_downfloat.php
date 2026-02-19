<?php
require_once __DIR__ . '/lib/ChessResultsScraper.php';
require_once __DIR__ . '/lib/SwissPairing.php';

$url = 'https://s2.chess-results.com/tnr1284262.aspx?lan=1';
$scraper = new ChessResultsScraper($url);
$fullData = $scraper->analyze();
$data = SwissPairing::rewindToRound($fullData, 5);

// Get the 2.5 group
$pool25 = [];
$pool20 = [];
// Filter to actual pool
$actualPool = [];
foreach ($fullData['rounds'][5]['pairings'] as $pairing) {
    if (!$pairing['isBye']) {
        if ($pairing['whiteNo'] > 0) $actualPool[] = $pairing['whiteNo'];
        if ($pairing['blackNo'] > 0) $actualPool[] = $pairing['blackNo'];
    }
}

foreach ($data['players'] as $sno => $p) {
    if (!in_array($sno, $actualPool)) continue;
    if ($p['currentScore'] == 2.5) $pool25[] = $p;
    if ($p['currentScore'] == 2.0) $pool20[] = $p;
}
usort($pool25, fn($a, $b) => $a['startNo'] <=> $b['startNo']);
usort($pool20, fn($a, $b) => $a['startNo'] <=> $b['startNo']);

echo "=== 2.5 group (" . count($pool25) . " players) ===\n";
foreach ($pool25 as $i => $p) {
    $pref = getColorPref($p);
    $opps = implode(',', array_values($p['opponents']));
    echo sprintf("  [%2d] #%2d %-25s Pref=%s  Opponents=[%s]\n",
        $i, $p['startNo'], $p['name'], $pref, $opps);
}

echo "\n=== 2.0 group (" . count($pool20) . " players) ===\n";
foreach ($pool20 as $i => $p) {
    $pref = getColorPref($p);
    echo sprintf("  [%2d] #%2d %-25s Pref=%s\n",
        $i, $p['startNo'], $p['name'], $pref);
}

// Compare candidates #48 and #31
$candidates = [48, 31, 38, 29, 28, 19];
echo "\n=== Downfloater candidate analysis ===\n";

foreach ($candidates as $cNo) {
    // Find index in pool25
    $cIdx = -1;
    foreach ($pool25 as $i => $p) {
        if ($p['startNo'] === $cNo) { $cIdx = $i; break; }
    }
    if ($cIdx < 0) continue;

    $candidate = $pool25[$cIdx];

    // Remaining 2.5 group
    $remaining = $pool25;
    array_splice($remaining, $cIdx, 1);
    $half = intdiv(count($remaining), 2);
    $s1 = array_slice($remaining, 0, $half);
    $s2 = array_slice($remaining, $half);

    // Count color conflicts in best S2 transposition
    $bestConflicts = PHP_INT_MAX;
    $bestPerm = null;
    if ($half <= 6) {
        $perms = getPerms(range(0, count($s2)-1));
        foreach ($perms as $perm) {
            $conflicts = 0;
            $canPlayOk = true;
            for ($i = 0; $i < $half; $i++) {
                $p1 = $s1[$i];
                $p2 = $s2[$perm[$i]];
                if (in_array($p2['startNo'], array_values($p1['opponents']))) {
                    $canPlayOk = false;
                    break;
                }
                if (getColorPref($p1) === getColorPref($p2)) $conflicts++;
            }
            if ($canPlayOk && $conflicts < $bestConflicts) {
                $bestConflicts = $conflicts;
                $bestPerm = $perm;
                if ($conflicts === 0) break;
            }
        }
    }

    // Check fit in 2.0 group
    $candPref = getColorPref($candidate);
    $compatNatives = [];
    foreach ($pool20 as $native) {
        $canPlay = true;
        foreach ($candidate['opponents'] as $opp) {
            if ($opp === $native['startNo']) { $canPlay = false; break; }
        }
        if (!$canPlay) continue;
        $natPref = getColorPref($native);
        $compatible = ($natPref !== $candPref);
        $compatNatives[] = [
            'no' => $native['startNo'],
            'name' => $native['name'],
            'pref' => $natPref,
            'colorOk' => $compatible,
        ];
    }

    echo sprintf("\n--- Remove #%d %s (idx=%d, pref=%s) ---\n",
        $cNo, $candidate['name'], $cIdx, $candPref);
    echo "  Current group: best S2 transposition gives $bestConflicts color conflicts\n";
    echo "  Next group (2.0) compatible natives:\n";
    foreach ($compatNatives as $cn) {
        $mark = $cn['colorOk'] ? '✓' : '✗';
        echo sprintf("    #%2d %-25s Pref=%s %s\n", $cn['no'], $cn['name'], $cn['pref'], $mark);
    }
    echo "  Color-compatible options: " . count(array_filter($compatNatives, fn($cn) => $cn['colorOk'])) . "/" . count($compatNatives) . "\n";
}

function getColorPref(array $player): string {
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

function getPerms(array $items): array {
    if (count($items) <= 1) return [$items];
    $result = [];
    foreach ($items as $key => $item) {
        $rem = $items; unset($rem[$key]); $rem = array_values($rem);
        foreach (getPerms($rem) as $perm) { array_unshift($perm, $item); $result[] = $perm; }
        if (count($result) > 720) break;
    }
    return $result;
}
