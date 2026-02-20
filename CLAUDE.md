# ChessPairings

PHP web app that scrapes chess-results.com tournaments and predicts Swiss-system pairings using JaVaFo, the FIDE-endorsed Dutch pairing engine.

## Architecture

- **`lib/ChessResultsScraper.php`** — Scrapes tournament data (standings, round pairings, player info) from chess-results.com
- **`lib/SwissPairing.php`** — Utility for rewinding tournament state to a specific round
- **`lib/JaVaFoPairing.php`** — Core pairing engine wrapper: generates TRF files, shells out to JaVaFo, parses output
- **`api/tournament.php`** — API endpoint: scrapes, rewinds, pairs, returns JSON
- **`js/app.js`** — Single-file frontend (vanilla JS, IIFE pattern)
- **`css/style.css`** — Dark theme with CSS custom properties
- **`vendor/javafo.jar`** — JaVaFo v2.2 binary (do not modify)

## JaVaFo Integration

JaVaFoPairing wraps JaVaFo via: TRF file generation -> `java -jar vendor/javafo.jar <file> -p` -> parse output.

- Receives **full** tournament data (not rewound) with `$targetRound` parameter
- Infers half-point byes from later-round participation (scraper can't see bye entries)
- Supports manual half-point byes via `$manualByes` constructor param
- Supports `$actualPool` to restrict which players are eligible for pairing

### TRF Format (critical details)

- Round blocks: exactly 10 chars each (`  %4d %s %s` — no trailing space!)
- Multibyte names: use `mb_substr`/`mb_strlen` for padding
- Result codes: `1`/`0`/`=` (game), `+`/`-` (forfeit with real opponent), `F` (full-point bye), `H` (half-point bye)
- **Full-point byes (opp=0) MUST use `F`, not `+`**. JaVaFo throws NullPointerException if `+` is used with opponent 0.
- Player lines use startNo for both startRank and rank fields

### JaVaFo Output

- First line = number of pairs
- Subsequent lines = `white_startNo black_startNo`
- Bye line = `startNo 0` (not counted as a board)

## Known Limitations

- **R1 color convention**: JaVaFo default R1 color may differ from actual (lot-based)
- **Scraper missing byes**: Half-point bye entries not scraped from chess-results.com; inferred from later-round participation
- **Forfeit handling**: Forfeits use `-` color and `+`/`-` TRF results; scraper preserves `F1-0`/`F0-1` notation
- **Scraper pagination**: chess-results.com `&art=2` only shows last ~3 rounds; `fetchMissingRounds()` fetches earlier rounds via `&art=2&rd=N`

## Development

- Runs on XAMPP (Apache + PHP)
- Java must be installed for JaVaFo (`java` on PATH or at standard Oracle install path)
- No build step, no package manager — plain PHP + vanilla JS
- Frontend uses no frameworks; single IIFE in `js/app.js`
