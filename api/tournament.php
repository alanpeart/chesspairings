<?php

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/../lib/ChessResultsScraper.php';
require_once __DIR__ . '/../lib/SwissPairing.php';
require_once __DIR__ . '/../lib/JaVaFoPairing.php';

try {
    $action = $_GET['action'] ?? '';

    if ($action !== 'analyze') {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid action. Use ?action=analyze&url=<chess-results-url>']);
        exit;
    }

    $url = $_GET['url'] ?? '';
    if (empty($url)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Missing url parameter']);
        exit;
    }

    // Validate URL format
    if (!preg_match('#chess-results\.com/tnr\d+#i', $url)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid URL. Must be a chess-results.com tournament URL (e.g. https://chess-results.com/tnr123456.aspx?lan=1)']);
        exit;
    }

    // Optional: predict a specific round (for completed tournaments)
    $targetRound = isset($_GET['round']) ? (int)$_GET['round'] : 0;

    // Optional: manual half-point byes (comma-separated startNos)
    $manualByes = [];
    if (!empty($_GET['byes'])) {
        $manualByes = array_map('intval', explode(',', $_GET['byes']));
        $manualByes = array_filter($manualByes, fn($v) => $v > 0);
        $manualByes = array_values(array_unique($manualByes));
    }

    // Scrape tournament data
    $scraper = new ChessResultsScraper($url);
    $data = $scraper->analyze();

    // Validate manual byes against actual player startNos
    $manualByes = array_values(array_filter($manualByes, fn($sno) => isset($data['players'][$sno])));

    $tournament = $data['tournament'];
    $isCompleted = $tournament['completedRounds'] >= $tournament['totalRounds']
                   && $tournament['totalRounds'] > 0;
    $isNotStarted = ($tournament['completedRounds'] ?? 0) === 0
                    && empty($data['rounds']);

    // If target round specified, rewind player state to before that round
    $fullData = $data; // Keep full data for JaVaFo (needs future-round info for bye inference)
    if ($targetRound > 0) {
        if ($targetRound < 1 || $targetRound > $tournament['totalRounds']) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => "Round must be between 1 and {$tournament['totalRounds']}"]);
            exit;
        }

        // Extract actual player pool from the target round (who actually played, not bye)
        $actualPool = null;
        if (isset($data['rounds'][$targetRound])) {
            $actualPool = [];
            foreach ($data['rounds'][$targetRound]['pairings'] as $pairing) {
                if (!$pairing['isBye']) {
                    if ($pairing['whiteNo'] > 0) $actualPool[] = $pairing['whiteNo'];
                    if ($pairing['blackNo'] > 0) $actualPool[] = $pairing['blackNo'];
                }
            }
            $actualPool = array_unique($actualPool);
        }

        // Rewind for display purposes (standings, player history, scores)
        $data = SwissPairing::rewindToRound($fullData, $targetRound);

        // Generate predicted pairings using JaVaFo with full data
        $pairer = new JaVaFoPairing($fullData, $actualPool, $targetRound, $manualByes);
    } elseif ($isCompleted) {
        // Completed tournament, no round specified — return info mode
        echo json_encode([
            'success' => true,
            'mode' => 'completed',
            'tournament' => $tournament,
            'playerCount' => count($data['players']),
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    } else {
        // Live tournament — predict next round
        $pairer = new JaVaFoPairing($data, null, null, $manualByes);
    }

    $predictions = $pairer->predict();

    // Determine which rounds to show in history/standings
    $effectiveCompleted = $targetRound > 0 ? $targetRound - 1 : ($tournament['completedRounds'] ?? 0);

    // Build standings array sorted by score for the effective state
    $standings = array_values($data['players']);
    usort($standings, function ($a, $b) {
        if ($b['currentScore'] !== $a['currentScore']) return $b['currentScore'] <=> $a['currentScore'];
        return $b['rating'] <=> $a['rating'];
    });
    // Assign rank by position
    foreach ($standings as $i => &$s) {
        $s['rank'] = $i + 1;
    }
    unset($s);

    // Build player details for history tab
    $playerDetails = [];
    foreach ($data['players'] as $startNo => $player) {
        $history = [];
        for ($r = 1; $r <= $effectiveCompleted; $r++) {
            $oppNo = $player['opponents'][$r] ?? null;
            $color = $player['colors'][$r] ?? null;
            $result = $player['results'][$r] ?? null;

            $history[] = [
                'round' => $r,
                'opponentNo' => $oppNo,
                'opponentName' => ($oppNo && isset($data['players'][$oppNo])) ? $data['players'][$oppNo]['name'] : ($oppNo === 0 ? 'Bye' : '-'),
                'opponentRating' => ($oppNo && isset($data['players'][$oppNo])) ? $data['players'][$oppNo]['rating'] : null,
                'color' => $color,
                'result' => $result,
            ];
        }
        $playerDetails[] = [
            'startNo' => $startNo,
            'name' => $player['name'],
            'rating' => $player['rating'],
            'currentScore' => $player['currentScore'],
            'history' => $history,
        ];
    }

    // Format pairings for response
    // Use rewound player data ($data) for scores so they reflect state before the target round
    $formattedPairings = [];
    foreach ($predictions['pairings'] as $pairing) {
        $whiteNo = $pairing['white']['startNo'];
        $blackNo = $pairing['black']['startNo'];
        $formattedPairings[] = [
            'board' => $pairing['board'],
            'white' => [
                'startNo' => $whiteNo,
                'name' => $pairing['white']['name'],
                'rating' => $pairing['white']['rating'],
                'score' => $data['players'][$whiteNo]['currentScore'] ?? $pairing['white']['currentScore'],
            ],
            'black' => [
                'startNo' => $blackNo,
                'name' => $pairing['black']['name'],
                'rating' => $pairing['black']['rating'],
                'score' => $data['players'][$blackNo]['currentScore'] ?? $pairing['black']['currentScore'],
            ],
        ];
    }

    // Build final standings for completed tournaments
    $finalStandings = null;
    if ($isCompleted && $targetRound > 0) {
        $finalStandings = array_values($fullData['players']);
        usort($finalStandings, function ($a, $b) {
            if ($b['currentScore'] !== $a['currentScore']) return $b['currentScore'] <=> $a['currentScore'];
            return $b['rating'] <=> $a['rating'];
        });
        foreach ($finalStandings as $i => &$fs) {
            $fs['rank'] = $i + 1;
        }
        unset($fs);
    }

    $response = [
        'success' => true,
        'mode' => 'predictions',
        'tournament' => $tournament,
        'isCompleted' => $isCompleted,
        'isNotStarted' => $isNotStarted,
        'standings' => $standings,
        'predictions' => [
            'nextRound' => $predictions['nextRound'],
            'pairings' => $formattedPairings,
            'bye' => $predictions['bye'],
            'manualByes' => $predictions['manualByes'] ?? [],
        ],
        'playerDetails' => $playerDetails,
    ];

    if ($finalStandings !== null) {
        $response['finalStandings'] = $finalStandings;
    }

    if (isset($actualPool)) {
        $response['actualPoolSize'] = count($actualPool);
        $response['totalPlayers'] = count($data['players']);
    }

    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

} catch (InvalidArgumentException $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
} catch (RuntimeException $e) {
    http_response_code(502);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Internal error: ' . $e->getMessage()]);
}
