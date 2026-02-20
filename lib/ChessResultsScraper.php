<?php

class ChessResultsScraper
{
    private string $baseUrl;
    private string $tournamentId;
    private array $players = [];
    private array $rounds = [];
    private array $tournamentInfo = [];

    public function __construct(string $url)
    {
        $this->baseUrl = $this->normalizeUrl($url);
        $this->tournamentId = $this->extractTournamentId($url);
    }

    private function normalizeUrl(string $url): string
    {
        if (preg_match('#(https?://[^/]*chess-results\.com/tnr\d+\.aspx\?lan=\d+)#i', $url, $m)) {
            return $m[1];
        }
        if (preg_match('#(https?://[^/]*chess-results\.com/tnr\d+\.aspx)#i', $url, $m)) {
            return $m[1] . '?lan=1';
        }
        throw new InvalidArgumentException('Invalid chess-results.com URL');
    }

    private function extractTournamentId(string $url): string
    {
        if (preg_match('#tnr(\d+)#i', $url, $m)) {
            return $m[1];
        }
        throw new InvalidArgumentException('Could not extract tournament ID from URL');
    }

    public function analyze(): array
    {
        $standingsHtml = $this->fetchPage('&art=1');
        $this->parseStandings($standingsHtml);

        $pairingsHtml = $this->fetchPage('&art=2');
        $this->parsePairings($pairingsHtml);

        $this->fetchMissingRounds();

        $this->buildPlayerData();

        return [
            'tournament' => $this->tournamentInfo,
            'players' => $this->players,
            'rounds' => $this->rounds,
        ];
    }

    /**
     * Fetch any completed rounds that are missing or truncated from the default pairings page.
     * chess-results.com only shows the last few rounds on &art=2; earlier rounds need &art=2&rd=N.
     */
    private function fetchMissingRounds(): void
    {
        $completedRounds = $this->tournamentInfo['completedRounds'] ?? 0;
        if ($completedRounds <= 0) return;

        // Expect roughly half the player count as pairings per round;
        // use 40% to allow for withdrawals and byes
        $threshold = max(1, count($this->players) * 0.4);

        for ($r = 1; $r <= $completedRounds; $r++) {
            if (isset($this->rounds[$r]) && count($this->rounds[$r]['pairings']) >= $threshold) {
                continue;
            }

            // Clear any partial data before re-fetching
            unset($this->rounds[$r]);
            $html = $this->fetchPage("&art=2&rd=$r");
            $this->parsePairings($html);
        }
    }

    private function fetchPage(string $params): string
    {
        $url = $this->baseUrl . $params;

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_ENCODING => '',
        ]);

        $html = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($html === false) {
            throw new RuntimeException("Failed to fetch page: $error");
        }
        if ($httpCode !== 200) {
            throw new RuntimeException("HTTP error $httpCode fetching $url");
        }

        return $this->sanitizeHtml($html);
    }

    private function sanitizeHtml(string $html): string
    {
        // Fix malformed tags from chess-results
        $html = preg_replace('/<(td|th|tr)\s+&[^>]*?class=/i', '<$1 class=', $html);
        $html = preg_replace('/<(td|th|tr)\s+&[^>]*?>/i', '<$1>', $html);

        if (mb_detect_encoding($html, 'UTF-8', true) === false) {
            $html = mb_convert_encoding($html, 'UTF-8', 'ISO-8859-1');
        }

        return $html;
    }

    // ── Standings ──────────────────────────────────────────────

    private function parseStandings(string $html): void
    {
        // Tournament name from first <h2>
        if (preg_match('#<h2[^>]*>(.*?)</h2>#si', $html, $m)) {
            $this->tournamentInfo['name'] = html_entity_decode(trim(strip_tags($m[1])), ENT_QUOTES, 'UTF-8');
        } else {
            $this->tournamentInfo['name'] = 'Unknown Tournament';
        }

        $this->tournamentInfo['id'] = $this->tournamentId;

        // Round info from page text
        // Pattern 1: "after Round 5" (Round then number)
        if (preg_match('#(?:after|nach|dopo|après|tras)\s+(?:Round|Runde|Ronda|Turno|Tour)\s+(\d+)#i', $html, $m)) {
            $this->tournamentInfo['completedRounds'] = (int)$m[1];
        }
        // Pattern 2: "after 5 Rounds" (number then Rounds) — common for completed tournaments
        elseif (preg_match('#(?:after|nach|dopo|après|tras)\s+(\d+)\s+(?:Rounds|Runden|Rondas|Turni|Tours)#i', $html, $m)) {
            $this->tournamentInfo['completedRounds'] = (int)$m[1];
        }
        if (preg_match('#(\d+)\s+(?:Rounds|Runden|Rondas|Turni|Tours)#i', $html, $m)) {
            $this->tournamentInfo['totalRounds'] = (int)$m[1];
        }

        $dom = new DOMDocument();
        @$dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOERROR | LIBXML_NOWARNING);
        $xpath = new DOMXPath($dom);

        $tables = $xpath->query('//table[contains(@class, "CRs1") or contains(@class, "CRs2")]');

        foreach ($tables as $table) {
            $rows = $xpath->query('.//tr', $table);
            $colMap = null;

            foreach ($rows as $row) {
                $cells = $this->getCellTexts($xpath, $row);
                if (count($cells) < 3) continue;

                // Detect header row by looking for known column names
                if ($colMap === null) {
                    $colMap = $this->detectStandingsColumns($cells);
                    if ($colMap !== null) continue; // This was the header row
                    continue;
                }

                // Data row — map by detected columns
                $startNo = isset($colMap['startNo']) ? (int)$cells[$colMap['startNo']] : 0;
                if ($startNo <= 0) continue;

                $name = isset($colMap['name']) ? trim($cells[$colMap['name']]) : '';
                $rating = isset($colMap['rating']) ? (int)$cells[$colMap['rating']] : 0;
                $federation = isset($colMap['federation']) ? trim($cells[$colMap['federation']]) : '';
                $rank = isset($colMap['rank']) ? (int)$cells[$colMap['rank']] : 0;
                $score = isset($colMap['score']) ? $this->parseScore($cells[$colMap['score']]) : 0;

                $name = preg_replace('#^(GM|IM|FM|CM|WGM|WIM|WFM|WCM|NM)\s+#', '', $name);

                $this->players[$startNo] = [
                    'startNo' => $startNo,
                    'name' => $name,
                    'rating' => $rating,
                    'federation' => $federation,
                    'currentScore' => $score,
                    'rank' => $rank,
                    'opponents' => [],
                    'colors' => [],
                    'results' => [],
                    'hadBye' => false,
                    'byeRounds' => [],
                ];
            }

            if (!empty($this->players)) break;
        }

        if (empty($this->players)) {
            throw new RuntimeException('Could not parse standings table — no players found');
        }
    }

    private function detectStandingsColumns(array $cells): ?array
    {
        $map = [];
        $hasName = false;

        foreach ($cells as $i => $val) {
            $val = trim($val);
            if (preg_match('#^Rk\.?$#i', $val))         $map['rank'] = $i;
            elseif (preg_match('#^(SNo|No)\.?$#i', $val)) $map['startNo'] = $i;
            elseif (preg_match('#^Name$#i', $val))       { $map['name'] = $i; $hasName = true; }
            elseif (preg_match('#^(Rtg|Rating|Elo)$#i', $val)) $map['rating'] = $i;
            elseif (preg_match('#^(FED|Fed)$#i', $val))  $map['federation'] = $i;
            elseif (preg_match('#^Pts\.?$#i', $val) && !isset($map['score'])) $map['score'] = $i;
        }

        return $hasName ? $map : null;
    }

    // ── Pairings ──────────────────────────────────────────────

    private function parsePairings(string $html): void
    {
        $dom = new DOMDocument();
        @$dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOERROR | LIBXML_NOWARNING);
        $xpath = new DOMXPath($dom);

        // Collect round headers (h3) with their round numbers
        $roundHeaders = [];
        $headers = $xpath->query('//h3');
        foreach ($headers as $header) {
            if (preg_match('#(?:Round|Runde|Ronda|Turno|Tour)\s+(\d+)#i', $header->textContent, $m)) {
                $roundHeaders[] = (int)$m[1];
            }
        }

        $tables = $xpath->query('//table[contains(@class, "CRs1") or contains(@class, "CRs2")]');

        if (!empty($roundHeaders)) {
            // Format A: each h3 "Round N" is followed by one CRs1 table
            foreach ($tables as $ti => $table) {
                if (!isset($roundHeaders[$ti])) break;
                $roundNum = $roundHeaders[$ti];
                $this->rounds[$roundNum] = ['pairings' => []];
                $this->parseRoundTable($xpath, $table, $roundNum);
            }
        } else {
            // Format B: single table with round divider rows
            foreach ($tables as $table) {
                $rows = $xpath->query('.//tr', $table);
                $currentRound = 0;
                foreach ($rows as $row) {
                    $text = trim($row->textContent);
                    if (preg_match('#(?:Round|Runde|Ronda|Turno|Tour)\s+(\d+)#i', $text, $m)) {
                        $currentRound = (int)$m[1];
                        $this->rounds[$currentRound] = ['pairings' => []];
                        continue;
                    }
                    if ($currentRound > 0) {
                        $this->parsePairingRowMapped($xpath, $row, $currentRound, null);
                    }
                }
                if (!empty($this->rounds)) break;
            }
        }

        // Remove empty rounds (e.g. rounds that only had "not paired" entries)
        foreach ($this->rounds as $roundNum => $roundData) {
            if (empty($roundData['pairings'])) {
                unset($this->rounds[$roundNum]);
            }
        }

        // Derive completed rounds from data if not set by standings page
        // Note: empty(0) is true in PHP, so use !isset() to respect completedRounds=0 from "after Round 0"
        if (!isset($this->tournamentInfo['completedRounds'])) {
            $completed = 0;
            $roundNums = array_keys($this->rounds);
            sort($roundNums);
            foreach ($roundNums as $roundNum) {
                $allDone = true;
                foreach ($this->rounds[$roundNum]['pairings'] as $p) {
                    if ($p['result'] === null && !$p['isBye']) { $allDone = false; break; }
                }
                if ($allDone) $completed = $roundNum;
            }
            $this->tournamentInfo['completedRounds'] = $completed;
        }

        if (empty($this->tournamentInfo['totalRounds'])) {
            $this->tournamentInfo['totalRounds'] = !empty($this->rounds) ? max(array_keys($this->rounds)) : 0;
        }
    }

    private function parseRoundTable(DOMXPath $xpath, DOMElement $table, int $roundNum): void
    {
        $rows = $xpath->query('.//tr', $table);
        $colMap = null;

        foreach ($rows as $row) {
            $cells = $this->getCellTexts($xpath, $row);
            if (count($cells) < 4) continue;

            if ($colMap === null) {
                $colMap = $this->detectPairingColumns($cells);
                if ($colMap !== null) continue;
                continue;
            }

            $this->parsePairingRowMapped($xpath, $row, $roundNum, $colMap);
        }
    }

    /**
     * Detect pairing table column layout from header row.
     * Known format: Bo. | No. | [empty] | White | Rtg | Pts. | Result | Pts. | [empty] | Black | Rtg | No.
     */
    private function detectPairingColumns(array $cells): ?array
    {
        $map = [];
        $hasResult = false;
        $noCount = 0;
        $ptsCount = 0;
        $rtgCount = 0;

        foreach ($cells as $i => $val) {
            $val = trim($val);
            if (preg_match('#^Bo\.?$#i', $val))             $map['board'] = $i;
            elseif (preg_match('#^(No|Nr)\.?$#i', $val)) {
                $noCount++;
                if ($noCount === 1) $map['whiteNo'] = $i;
                else                $map['blackNo'] = $i;
            }
            elseif (preg_match('#^White$#i', $val))          $map['whiteName'] = $i;
            elseif (preg_match('#^Black$#i', $val))          $map['blackName'] = $i;
            elseif (preg_match('#^Result$#i', $val))         { $map['result'] = $i; $hasResult = true; }
            elseif (preg_match('#^(Rtg|Elo)$#i', $val)) {
                $rtgCount++;
                if ($rtgCount === 1) $map['whiteRtg'] = $i;
                else                 $map['blackRtg'] = $i;
            }
            elseif (preg_match('#^Pts\.?$#i', $val)) {
                $ptsCount++;
                // First Pts = white score, second = black score (skip both)
            }
        }

        return $hasResult ? $map : null;
    }

    private function parsePairingRowMapped(DOMXPath $xpath, DOMElement $row, int $roundNum, ?array $colMap): void
    {
        $cells = $this->getCellTexts($xpath, $row);
        if (count($cells) < 4) return;

        // If no column map, try to detect from this row (might be a header)
        if ($colMap === null) {
            $colMap = $this->guessPairingColumns($cells);
            if ($colMap === null) return;
        }

        $boardNo = isset($colMap['board']) ? (int)$cells[$colMap['board']] : 0;

        // Get player numbers — either from No. columns or by name lookup
        $whiteNo = isset($colMap['whiteNo']) ? (int)$cells[$colMap['whiteNo']] : 0;
        $blackNo = isset($colMap['blackNo']) ? (int)$cells[$colMap['blackNo']] : 0;

        if ($whiteNo <= 0 && isset($colMap['whiteName'])) {
            $whiteNo = $this->resolvePlayerByName(trim($cells[$colMap['whiteName']]));
        }
        if ($blackNo <= 0 && isset($colMap['blackName'])) {
            $blackNo = $this->resolvePlayerByName(trim($cells[$colMap['blackName']]));
        }

        $resultStr = isset($colMap['result']) ? trim($cells[$colMap['result']]) : '';
        $result = $this->normalizeResult($resultStr);
        $isBye = false;

        if ($whiteNo <= 0) return; // Not a data row

        // Check for "not paired" — skip entirely, the round hasn't been paired yet
        if ($blackNo <= 0) {
            $blackName = isset($colMap['blackName']) ? trim($cells[$colMap['blackName']]) : '';
            if (preg_match('#not paired|nicht gepaart|no jugado|non accoppiato|pas apparié#i', $blackName)) {
                return; // Not a real pairing — round hasn't been generated yet
            }
            // Check for bye: no black player or keyword in black name
            if ($blackName === '' || preg_match('#bye|spielfrei|free#i', $blackName)) {
                $isBye = true;
            }
        }

        // For byes, the result column is a single value ("1", "½", "0") not "X - Y"
        if ($isBye && $result === null) {
            $result = $this->parseByeResult($resultStr);
        }

        if ($boardNo === 0) {
            $boardNo = count($this->rounds[$roundNum]['pairings']) + 1;
        }

        $this->rounds[$roundNum]['pairings'][] = [
            'board' => $boardNo,
            'whiteNo' => $whiteNo,
            'blackNo' => $blackNo,
            'result' => $isBye ? ($result ?? '1-0') : $result,
            'isBye' => $isBye,
        ];
    }

    /**
     * Parse a single-value bye result ("1", "½", "0") into a standard result string.
     */
    private function parseByeResult(string $val): string
    {
        $val = trim($val);
        $val = str_replace("\xC2\xBD", '½', $val);
        $val = str_replace('&frac12;', '½', $val);

        if ($val === '1') return '1-0';      // Full-point bye
        if ($val === '½' || $val === '0.5' || $val === '1/2') return '½-½'; // Half-point bye
        if ($val === '0') return '0-1';      // Zero-point bye / forfeit
        return '1-0';                        // Default: full bye
    }

    /**
     * Fallback: guess column positions by scanning cell contents.
     */
    private function guessPairingColumns(array $cells): ?array
    {
        $resultIdx = -1;
        foreach ($cells as $i => $val) {
            if ($this->isResult($val)) {
                $resultIdx = $i;
                break;
            }
        }
        if ($resultIdx === -1) return null;

        // Find integer cells before the result — board number, then white No
        $intsBefore = [];
        for ($i = 0; $i < $resultIdx; $i++) {
            if (preg_match('#^\d+$#', trim($cells[$i]))) {
                $intsBefore[] = $i;
            }
        }

        // Find integer cells after the result — black No is the last one
        $intsAfter = [];
        for ($i = $resultIdx + 1; $i < count($cells); $i++) {
            if (preg_match('#^\d+$#', trim($cells[$i]))) {
                $intsAfter[] = $i;
            }
        }

        $map = ['result' => $resultIdx];
        if (count($intsBefore) >= 2) {
            $map['board'] = $intsBefore[0];
            $map['whiteNo'] = $intsBefore[1];
        } elseif (count($intsBefore) === 1) {
            $map['whiteNo'] = $intsBefore[0];
        }
        if (!empty($intsAfter)) {
            $map['blackNo'] = end($intsAfter);
        }

        return isset($map['whiteNo']) ? $map : null;
    }

    // ── Name Resolution ──────────────────────────────────────

    /** @var array<string, int>|null Lazy-built name→startNo index */
    private ?array $nameIndex = null;

    private function resolvePlayerByName(string $name): int
    {
        if ($this->nameIndex === null) {
            $this->nameIndex = [];
            foreach ($this->players as $startNo => $player) {
                // Index the stored name (already title-stripped)
                $key = $this->normalizeNameForLookup($player['name']);
                $this->nameIndex[$key] = $startNo;
            }
        }

        // Strip titles from the pairings name before lookup
        $stripped = preg_replace(
            '#^(GM|IM|FM|CM|WGM|WIM|WFM|WCM|NM|WNM)\s+#i', '', trim($name)
        );
        $key = $this->normalizeNameForLookup($stripped);

        return $this->nameIndex[$key] ?? 0;
    }

    private function normalizeNameForLookup(string $name): string
    {
        // Lowercase, collapse whitespace, trim
        return mb_strtolower(preg_replace('#\s+#u', ' ', trim($name)), 'UTF-8');
    }

    // ── Shared Helpers ─────────────────────────────────────────

    private function getCellTexts(DOMXPath $xpath, DOMElement $row): array
    {
        $cells = $xpath->query('.//td|.//th', $row);
        $vals = [];
        foreach ($cells as $cell) {
            $vals[] = trim($cell->textContent);
        }
        return $vals;
    }

    private function isResult(string $val): bool
    {
        $val = trim($val);
        $val = str_replace("\xC2\xBD", '½', $val);
        $val = str_replace('&frac12;', '½', $val);
        return (bool)preg_match('#^[01½+\-/]+\s*-\s*[01½+\-/]+$#u', $val);
    }

    private function normalizeResult(string $val): ?string
    {
        $val = trim($val);
        $val = str_replace("\xC2\xBD", '½', $val);
        $val = str_replace('&frac12;', '½', $val);
        $val = preg_replace('#\s+#', '', $val);

        if ($val === '1-0') return '1-0';
        if ($val === '0-1') return '0-1';
        if ($val === '½-½' || $val === '1/2-1/2') return '½-½';
        if ($val === '+-−' || $val === '+--') return 'F1-0';
        if ($val === '−-+' || $val === '--+') return 'F0-1';

        return null;
    }

    private function buildPlayerData(): void
    {
        foreach ($this->rounds as $roundNum => $roundData) {
            foreach ($roundData['pairings'] as $pairing) {
                $whiteNo = $pairing['whiteNo'];
                $blackNo = $pairing['blackNo'];

                if ($pairing['isBye']) {
                    if (isset($this->players[$whiteNo])) {
                        $this->players[$whiteNo]['opponents'][$roundNum] = 0;
                        $this->players[$whiteNo]['colors'][$roundNum] = '-';
                        // Store actual result so half-point byes score correctly
                        $this->players[$whiteNo]['results'][$roundNum] = $this->resultForWhite($pairing['result']) ?? '1';
                        $this->players[$whiteNo]['hadBye'] = true;
                        $this->players[$whiteNo]['byeRounds'][] = $roundNum;
                    }
                    continue;
                }

                $forfeit = $this->isForfeit($pairing['result']);

                if (isset($this->players[$whiteNo])) {
                    $this->players[$whiteNo]['opponents'][$roundNum] = $blackNo;
                    $this->players[$whiteNo]['colors'][$roundNum] = $forfeit ? '-' : 'W';
                    $this->players[$whiteNo]['results'][$roundNum] = $this->resultForWhite($pairing['result']);
                }

                if (isset($this->players[$blackNo])) {
                    $this->players[$blackNo]['opponents'][$roundNum] = $whiteNo;
                    $this->players[$blackNo]['colors'][$roundNum] = $forfeit ? '-' : 'B';
                    $this->players[$blackNo]['results'][$roundNum] = $this->resultForBlack($pairing['result']);
                }
            }
        }
    }

    private function resultForWhite(?string $result): ?string
    {
        return match ($result) {
            '1-0', 'F1-0' => '1', '0-1', 'F0-1' => '0', '½-½' => '½', default => null,
        };
    }

    private function resultForBlack(?string $result): ?string
    {
        return match ($result) {
            '1-0', 'F1-0' => '0', '0-1', 'F0-1' => '1', '½-½' => '½', default => null,
        };
    }

    private function isForfeit(?string $result): bool
    {
        return $result === 'F1-0' || $result === 'F0-1';
    }

    private function parseScore(string $val): float
    {
        $val = trim($val);
        $val = str_replace(',', '.', $val);
        $val = str_replace('½', '.5', $val);
        return (float)$val;
    }
}
