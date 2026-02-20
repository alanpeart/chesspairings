<?php

class JaVaFoPairing
{
    private array $players;
    private array $rounds;
    private array $tournamentInfo;
    private int $nextRound;
    private ?array $actualPool;
    private ?int $byePlayer = null;

    /**
     * @param array $data Tournament data (can be full or rewound)
     * @param array|null $actualPool StartNos of players eligible for pairing
     * @param int|null $targetRound If set, pair this round (uses full data up to targetRound-1)
     */
    public function __construct(array $data, ?array $actualPool = null, ?int $targetRound = null)
    {
        $this->players = $data['players'];
        $this->rounds = $data['rounds'];
        $this->tournamentInfo = $data['tournament'];
        $this->nextRound = $targetRound ?? (($this->tournamentInfo['completedRounds'] ?? 0) + 1);
        $this->actualPool = $actualPool;
    }

    public static function rewindToRound(array $data, int $targetRound): array
    {
        return SwissPairing::rewindToRound($data, $targetRound);
    }

    public function predict(): array
    {
        $trf = $this->generateTrf();

        $trfFile = tempnam(sys_get_temp_dir(), 'trf');
        file_put_contents($trfFile, $trf);

        try {
            $jarPath = __DIR__ . '/../vendor/javafo.jar';
            $javaPath = 'C:\\Program Files (x86)\\Common Files\\Oracle\\Java\\javapath\\java.exe';
            if (!file_exists($javaPath)) {
                $javaPath = 'java'; // Fall back to PATH
            }
            $cmd = sprintf(
                '%s -jar %s %s -p 2>&1',
                escapeshellarg($javaPath),
                escapeshellarg($jarPath),
                escapeshellarg($trfFile)
            );

            exec($cmd, $outputLines, $exitCode);
            $output = implode("\n", $outputLines);

            if ($exitCode !== 0) {
                throw new RuntimeException("JaVaFo failed (exit $exitCode): $output");
            }

            $pairings = $this->parseOutput($output);

            return [
                'nextRound' => $this->nextRound,
                'pairings' => $pairings,
                'bye' => $this->byePlayer ? [
                    'playerNo' => $this->byePlayer,
                    'playerName' => $this->players[$this->byePlayer]['name'] ?? 'Unknown',
                    'playerRating' => $this->players[$this->byePlayer]['rating'] ?? 0,
                ] : null,
            ];
        } finally {
            @unlink($trfFile);
        }
    }

    private function generateTrf(): string
    {
        $name = $this->tournamentInfo['name'] ?? 'Tournament';
        $totalRounds = $this->tournamentInfo['totalRounds'] ?? $this->nextRound;
        $completedRounds = $this->nextRound - 1;

        $trf = "012 $name\n";
        $trf .= "032 ENG\n";
        $trf .= "062 " . count($this->players) . "\n";
        $trf .= "092 Individual: Swiss-System\n";
        $trf .= "XXR $totalRounds\n";

        // Sort players by startNo for TRF (required by spec)
        $sortedPlayers = $this->players;
        ksort($sortedPlayers);

        foreach ($sortedPlayers as $startNo => $player) {
            $roundData = [];
            $inferredByePoints = 0.0;

            // Find the last round this player has actual data for (look at ALL rounds
            // including future ones, so we can infer byes from later-round participation)
            $lastDataRound = 0;
            foreach ($player['opponents'] as $r => $opp) {
                if ($r > $lastDataRound) $lastDataRound = $r;
            }

            for ($r = 1; $r <= $completedRounds; $r++) {
                if (isset($player['opponents'][$r])) {
                    $opp = $player['opponents'][$r];
                    $color = $player['colors'][$r] ?? '-';
                    $result = $player['results'][$r] ?? '-';

                    if ($opp === 0) {
                        $roundData[] = [
                            'opponent' => 0,
                            'color' => '-',
                            'result' => $this->mapResult($result, true),
                        ];
                    } else {
                        $isForfeit = ($color === '-');
                        $roundData[] = [
                            'opponent' => $opp,
                            'color' => $isForfeit ? '-' : strtolower($color),
                            'result' => $this->mapResult($result, $isForfeit),
                        ];
                    }
                } elseif ($r < $lastDataRound) {
                    // No data but played later: infer half-point bye
                    $roundData[] = ['opponent' => 0, 'color' => '-', 'result' => 'H'];
                    $inferredByePoints += 0.5;
                } else {
                    // No data and didn't play later: absent
                    $roundData[] = ['opponent' => 0, 'color' => '-', 'result' => '-'];
                }
            }

            // Mark absent for current round if not in pool
            if ($this->actualPool !== null && !in_array($startNo, $this->actualPool)) {
                $roundData[] = ['opponent' => 0, 'color' => '-', 'result' => '-'];
            }

            // Compute score from rounds 1..completedRounds only (not from full tournament score)
            $points = $inferredByePoints;
            for ($r = 1; $r <= $completedRounds; $r++) {
                if (isset($player['results'][$r])) {
                    $res = $player['results'][$r];
                    if ($res === '1') $points += 1.0;
                    elseif ($res === '½') $points += 0.5;
                }
            }

            $trf .= $this->formatPlayerLine(
                $startNo,
                $player['name'] ?? 'Unknown',
                (int)($player['rating'] ?? 0),
                $player['federation'] ?? '',
                $points,
                $startNo,
                $roundData
            ) . "\n";
        }

        return $trf;
    }

    private function mapResult(string $result, bool $isBye): string
    {
        if ($isBye) {
            if ($result === '1') return '+';
            if ($result === '½') return 'H';
            return '-';
        }
        if ($result === '1') return '1';
        if ($result === '0') return '0';
        if ($result === '½') return '=';
        return '-';
    }

    /**
     * Format a TRF 001 player line (TRF16 spec).
     */
    private function formatPlayerLine(
        int $startRank,
        string $name,
        int $rating,
        string $federation,
        float $points,
        int $rank,
        array $rounds
    ): string {
        $line = '001';                                      // 1-3
        $line .= sprintf(' %4d', $startRank);               // 4-8
        $line .= ' m';                                      // 9-10 (sex)
        $line .= '    ';                                    // 11-14 (space + title)
        $nameTrunc = mb_substr($name, 0, 33);                   // 15-47 (name)
        $line .= $nameTrunc . str_repeat(' ', 33 - mb_strlen($nameTrunc));
        $line .= sprintf('%4d', $rating);                    // 48-51 (rating)
        $line .= ' ' . str_pad($federation, 3);             // 52-55
        $line .= str_pad('', 12);                            // 56-67 (FIDE ID)
        $line .= str_pad('', 5);                             // 68-72 (birth year)
        $line .= str_pad('', 8);                             // 73-80 (spaces)
        $line .= sprintf('%4s', number_format($points, 1));  // 81-84 (points)
        $line .= sprintf(' %4d', $rank);                     // 85-89

        foreach ($rounds as $rd) {
            $opp = $rd['opponent'] ?? 0;
            $color = $rd['color'] ?? '-';
            $result = $rd['result'] ?? '-';

            if ($opp === 0) {
                $line .= sprintf('  0000 - %s', $result);
            } else {
                $line .= sprintf('  %4d %s %s', $opp, $color, $result);
            }
        }

        return $line;
    }

    private function parseOutput(string $output): array
    {
        $lines = array_values(array_filter(
            explode("\n", trim($output)),
            fn($l) => trim($l) !== ''
        ));

        if (empty($lines)) {
            throw new RuntimeException("JaVaFo produced no output");
        }

        $numPairs = (int)$lines[0];
        $pairings = [];
        $board = 1;

        for ($i = 1; $i <= $numPairs && isset($lines[$i]); $i++) {
            $parts = preg_split('/\s+/', trim($lines[$i]));
            if (count($parts) < 2) continue;

            $whiteNo = (int)$parts[0];
            $blackNo = (int)$parts[1];

            if ($whiteNo === 0 || $blackNo === 0) {
                $this->byePlayer = ($blackNo === 0) ? $whiteNo : $blackNo;
                continue;
            }

            $pairings[] = [
                'player1' => $this->players[$whiteNo],
                'player2' => $this->players[$blackNo],
                'white' => $this->players[$whiteNo],
                'black' => $this->players[$blackNo],
                'board' => $board++,
            ];
        }

        return $pairings;
    }
}
