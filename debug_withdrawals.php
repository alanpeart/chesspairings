<?php
require_once __DIR__ . '/lib/ChessResultsScraper.php';
require_once __DIR__ . '/lib/SwissPairing.php';

$url = 'https://chess-results.com/tnr1284262.aspx?lan=1';
echo str_repeat('=', 80) . "\n";
echo "WITHDRAWAL DETECTION & PREDICTION ACCURACY DEBUG\n";
echo str_repeat('=', 80) . "\n";
echo "Tournament URL: $url\n";
echo "Date: " . date('Y-m-d H:i:s') . "\n\n";
echo "Scraping tournament data...\n";
try {
    $scraper = new ChessResultsScraper($url);
    $data = $scraper->analyze();
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
$players = $data['players'];
$rounds  = $data['rounds'];
$ti = $data['tournament'];
echo "Tournament: " . $ti['name'] . "\n";
echo "Completed rounds: " . $ti['completedRounds'] . "\n";
echo "Total rounds: " . $ti['totalRounds'] . "\n";
echo "Total players: " . count($players) . "\n\n";
$totalRounds = $ti['totalRounds'];
$allStartNos = array_keys($players);
sort($allStartNos);

echo str_repeat('=', 80) . "\n";
echo "SECTION 1: PLAYER PARTICIPATION MATRIX\n";
echo str_repeat('=', 80) . "\n\n";
$hdr = sprintf("%-4s %-30s %5s", "SNo", "Name", "Rtg");
for ($r = 1; $r <= $totalRounds; $r++) { $hdr .= sprintf("  R%d ", $r); }
$hdr .= "  Score";
echo $hdr . "\n" . str_repeat('-', strlen($hdr)) . "\n";
foreach ($allStartNos as $sno) {
    $p = $players[$sno];
    $line = sprintf("%-4d %-30s %5d", $sno, mb_substr($p['name'], 0, 30), $p['rating']);
    for ($r = 1; $r <= $totalRounds; $r++) {
        if (isset($p['opponents'][$r])) {
            $opp = $p['opponents'][$r];
            $c = $p['colors'][$r] ?? '-';
            $rs = $p['results'][$r] ?? '?';
            $cell = ($opp === 0) ? "BYE" : $c . $rs;
        } else { $cell = "---"; }
        $line .= sprintf("  %-3s", $cell);
    }
    $line .= sprintf("  %.1f", $p['currentScore']);
    echo $line . "\n";
}
echo "\nLegend: W1=White win, B0=Black loss, BYE=bye, ---=not paired/absent\n\n";

echo str_repeat('=', 80) . "\n";
echo "SECTION 2: WITHDRAWAL DETECTION\n";
echo str_repeat('=', 80) . "\n\n";
$withdrawals = [];
foreach ($allStartNos as $sno) {
    $p = $players[$sno];
    $lastSeen = 0; $firstSeen = 0; $rPres = [];
    for ($r = 1; $r <= $totalRounds; $r++) {
        if (isset($p['opponents'][$r])) {
            if ($firstSeen === 0) $firstSeen = $r;
            $lastSeen = $r; $rPres[] = $r;
        }
    }
    $missedAfter = [];
    if ($lastSeen > 0 && $lastSeen < $totalRounds) {
        for ($r = $lastSeen + 1; $r <= $totalRounds; $r++) {
            if (!isset($p['opponents'][$r])) $missedAfter[] = $r;
        }
    }
    $gaps = [];
    if ($firstSeen > 0 && $lastSeen > 0) {
        for ($r = $firstSeen; $r <= $lastSeen; $r++) {
            if (!isset($p['opponents'][$r])) $gaps[] = $r;
        }
    }
    $never = ($firstSeen === 0);
    if (!empty($missedAfter) || !empty($gaps) || $never) {
        $withdrawals[$sno] = ['name'=>$p['name'], 'rating'=>$p['rating'],
            'first'=>$firstSeen, 'last'=>$lastSeen, 'present'=>$rPres,
            'missedAfter'=>$missedAfter, 'gaps'=>$gaps, 'never'=>$never];
    }
}
if (empty($withdrawals)) {
    echo "No withdrawals detected.\n\n";
} else {
    echo "Found " . count($withdrawals) . " players with irregular participation:\n\n";
    foreach ($withdrawals as $sno => $w) {
        $status = '';
        if ($w['never']) { $status = 'NEVER PAIRED'; }
        elseif (!empty($w['missedAfter'])) {
            $status = 'WITHDRAWN after R' . $w['last'] . ' (missing: ' . implode(',', $w['missedAfter']) . ')';
        }
        if (!empty($w['gaps'])) {
            $status .= ($status ? ' | ' : '') . 'GAPS: ' . implode(',', $w['gaps']);
        }
        echo sprintf("  SNo %-3d %-30s (Rtg %4d) Played:[%s] %s\n",
            $sno, mb_substr($w['name'], 0, 30), $w['rating'],
            implode(',', $w['present']), $status);
    }
}

echo "\n" . str_repeat('=', 80) . "\n";
echo "SECTION 3: PER-ROUND PRESENCE ANALYSIS\n";
echo str_repeat('=', 80) . "\n\n";
for ($r = 1; $r <= $totalRounds; $r++) {
    echo "--- Round $r ---\n";
    $inR = [];
    if (isset($rounds[$r])) {
        foreach ($rounds[$r]['pairings'] as $pg) {
            $inR[] = $pg['whiteNo'];
            if (!$pg['isBye'] && $pg['blackNo'] > 0) $inR[] = $pg['blackNo'];
        }
    }
    $inR = array_unique($inR); sort($inR);
    $viaPD = [];
    foreach ($allStartNos as $sno) {
        if (isset($players[$sno]['opponents'][$r])) $viaPD[] = $sno;
    }
    sort($viaPD);
    $missing = array_diff($allStartNos, $viaPD);
    $np = isset($rounds[$r]) ? count($rounds[$r]['pairings']) : 0;
    $bc = 0;
    if (isset($rounds[$r])) {
        foreach ($rounds[$r]['pairings'] as $pg) { if ($pg['isBye']) $bc++; }
    }
    echo "  Players present (pairings table): " . count($inR) . "\n";
    echo "  Players present (player data):    " . count($viaPD) . "\n";
    echo "  Pairings/boards: $np (including $bc bye(s))\n";
    echo "  Players NOT present: " . count($missing) . "\n";
    if (!empty($missing)) {
        echo "  Missing players:\n";
        foreach ($missing as $sno) {
            echo sprintf("    SNo %-3d %-30s (Rtg %4d)\n",
                $sno, mb_substr($players[$sno]['name'], 0, 30), $players[$sno]['rating']);
        }
    }
    echo "\n";
}

echo str_repeat('=', 80) . "\n";
echo "SECTION 4: PREDICTED vs ACTUAL PAIRINGS\n";
echo str_repeat('=', 80) . "\n\n";

foreach ([3, 5] as $targetRound) {
    echo str_repeat('-', 80) . "\n";
    echo "ROUND $targetRound: PREDICTED vs ACTUAL\n";
    echo str_repeat('-', 80) . "\n\n";
    $rewound = SwissPairing::rewindToRound($data, $targetRound);
    $engine = new SwissPairing($rewound);
    $prediction = $engine->predict();
    $actualPairings = isset($rounds[$targetRound]) ? $rounds[$targetRound]['pairings'] : [];

    echo "ACTUAL pairings (Round $targetRound):\n";
    echo sprintf("  %-5s  %-4s %-30s  vs  %-4s %-30s  %s\n", "Board","SNo","White","SNo","Black","Result");
    echo "  " . str_repeat('-', 85) . "\n";
    foreach ($actualPairings as $ap) {
        if ($ap['isBye']) {
            $wN = isset($players[$ap['whiteNo']]) ? $players[$ap['whiteNo']]['name'] : '?';
            echo sprintf("  %-5d  %-4d %-30s  BYE\n", $ap['board'], $ap['whiteNo'], mb_substr($wN, 0, 30));
        } else {
            $wN = isset($players[$ap['whiteNo']]) ? $players[$ap['whiteNo']]['name'] : '?';
            $bN = isset($players[$ap['blackNo']]) ? $players[$ap['blackNo']]['name'] : '?';
            echo sprintf("  %-5d  %-4d %-30s  vs  %-4d %-30s  %s\n",
                $ap['board'], $ap['whiteNo'], mb_substr($wN, 0, 30),
                $ap['blackNo'], mb_substr($bN, 0, 30), $ap['result'] ?? '?');
        }
    }
    echo "\nPREDICTED pairings (Round $targetRound):\n";
    echo sprintf("  %-5s  %-4s %-30s  vs  %-4s %-30s\n", "Board","SNo","White","SNo","Black");
    echo "  " . str_repeat('-', 85) . "\n";
    foreach ($prediction['pairings'] as $pp) {
        echo sprintf("  %-5d  %-4d %-30s  vs  %-4d %-30s\n",
            $pp['board'], $pp['white']['startNo'], mb_substr($pp['white']['name'], 0, 30),
            $pp['black']['startNo'], mb_substr($pp['black']['name'], 0, 30));
    }
    if ($prediction['bye']) {
        echo sprintf("  BYE:   %-4d %-30s\n", $prediction['bye']['playerNo'], $prediction['bye']['playerName']);
    }

    echo "\nBOARD-BY-BOARD COMPARISON:\n";
    echo sprintf("  %-5s  %-20s  %-20s  %s\n", "Board", "Actual(W-B)", "Predicted(W-B)", "Match?");
    echo "  " . str_repeat('-', 75) . "\n";
    $aByB = []; foreach ($actualPairings as $ap) { $aByB[$ap['board']] = $ap; }
    $pByB = []; foreach ($prediction['pairings'] as $pp) { $pByB[$pp['board']] = $pp; }
    $maxB = max(
        !empty($aByB) ? max(array_keys($aByB)) : 0,
        !empty($pByB) ? max(array_keys($pByB)) : 0
    );
    for ($b = 1; $b <= $maxB; $b++) {
        $aS = '---'; $pS = '---'; $aP = [0,0]; $pP = [0,0];
        if (isset($aByB[$b])) {
            $a = $aByB[$b];
            if ($a['isBye']) { $aS = $a['whiteNo']." BYE"; $aP = [$a['whiteNo'],0]; }
            else { $aS = $a['whiteNo']."-".$a['blackNo']; $aP = [$a['whiteNo'],$a['blackNo']]; }
        }
        if (isset($pByB[$b])) {
            $q = $pByB[$b];
            $pS = $q['white']['startNo']."-".$q['black']['startNo'];
            $pP = [$q['white']['startNo'], $q['black']['startNo']];
        }
        $exact = ($aP[0]===$pP[0] && $aP[1]===$pP[1]);
        $pmatch = false;
        if ($aP[0]>0 && $aP[1]>0 && $pP[0]>0 && $pP[1]>0) {
            $as2 = [$aP[0],$aP[1]]; sort($as2); $ps2 = [$pP[0],$pP[1]]; sort($ps2);
            $pmatch = ($as2 === $ps2);
        }
        $ms = $exact ? 'EXACT' : ($pmatch ? 'PAIR' : 'MISS');
        echo sprintf("  %-5d  %-20s  %-20s  %s\n", $b, $aS, $pS, $ms);
    }
    $aBye = null;
    foreach ($actualPairings as $ap) { if ($ap['isBye']) { $aBye = $ap['whiteNo']; break; } }
    $pBye = $prediction['bye'] ? $prediction['bye']['playerNo'] : null;
    $bm = ($aBye === $pBye);
    echo "\n  Bye - Actual: " . ($aBye ?? 'none') . ", Predicted: " . ($pBye ?? 'none')
        . ($bm ? ' [MATCH]' : ' [MISS]') . "\n";

    $aPairs = [];
    foreach ($actualPairings as $ap) {
        if (!$ap['isBye'] && $ap['blackNo'] > 0) {
            $pr = [$ap['whiteNo'], $ap['blackNo']]; sort($pr);
            $aPairs[] = implode('-', $pr);
        }
    }
    $pPairs = [];
    foreach ($prediction['pairings'] as $pp) {
        $pr = [$pp['white']['startNo'], $pp['black']['startNo']]; sort($pr);
        $pPairs[] = implode('-', $pr);
    }
    $correct = array_intersect($pPairs, $aPairs);
    $wrong = array_diff($pPairs, $aPairs);
    $missed = array_diff($aPairs, $pPairs);
    echo "\n  SUMMARY:\n";
    echo "    Actual boards (excl byes): " . count($aPairs) . "\n";
    echo "    Predicted boards: " . count($pPairs) . "\n";
    echo "    Correct pairs (any board): " . count($correct) . " / " . count($aPairs) . "\n";
    echo "    Wrong predicted: " . count($wrong) . "\n";
    echo "    Missed actual: " . count($missed) . "\n";

    $aRP = [];
    foreach ($actualPairings as $ap) {
        $aRP[] = $ap['whiteNo'];
        if (!$ap['isBye'] && $ap['blackNo'] > 0) $aRP[] = $ap['blackNo'];
    }
    $aRP = array_unique($aRP);
    $pRP = [];
    foreach ($prediction['pairings'] as $pp) {
        $pRP[] = $pp['white']['startNo'];
        $pRP[] = $pp['black']['startNo'];
    }
    if ($prediction['bye']) $pRP[] = $prediction['bye']['playerNo'];
    $pRP = array_unique($pRP);
    echo "\n    Actual players in R$targetRound: " . count($aRP) . "\n";
    echo "    Predicted players in R$targetRound: " . count($pRP) . "\n";
    $inPNA = array_diff($pRP, $aRP);
    $inANP = array_diff($aRP, $pRP);
    if (!empty($inPNA)) {
        echo "\n    In PREDICTED but NOT ACTUAL (likely withdrawn):\n";
        foreach ($inPNA as $sno) {
            echo sprintf("      SNo %-3d %-30s (Rtg %4d)\n",
                $sno, mb_substr($players[$sno]['name'] ?? '?', 0, 30), $players[$sno]['rating'] ?? 0);
        }
    }
    if (!empty($inANP)) {
        echo "\n    In ACTUAL but NOT PREDICTED:\n";
        foreach ($inANP as $sno) {
            echo sprintf("      SNo %-3d %-30s (Rtg %4d)\n",
                $sno, mb_substr($players[$sno]['name'] ?? '?', 0, 30), $players[$sno]['rating'] ?? 0);
        }
    }
    echo "\n";
}

echo str_repeat('=', 80) . "\n";
echo "SECTION 5: CONCLUSIONS\n";
echo str_repeat('=', 80) . "\n\n";
echo "Key finding: The prediction engine (getEligiblePlayers) uses ALL registered\n";
echo "players, but some players withdraw mid-tournament. The predicted pairings\n";
echo "include absent players, causing cascading mismatches across all boards.\n\n";
if (!empty($withdrawals)) {
    echo "Detected " . count($withdrawals) . " players with irregular participation.\n";
    echo "To fix: exclude players who withdrew before the predicted round.\n\n";
} else {
    echo "No withdrawals detected in this tournament.\n\n";
}
echo "Done.\n";
