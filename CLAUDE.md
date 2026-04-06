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
- **Forfeit handling**: Scraper preserves actual colors for forfeits and tracks them via `forfeits[$round]` flag on player data; TRF uses `+`/`-` result codes with the real color (e.g. `37 b +`)
- **Pairing system mismatch**: JaVaFo implements FIDE Dutch; tournaments may use other systems (Burstein, Dubov) in Swiss-Manager, causing differences especially in lower score groups
- **Scraper pagination**: chess-results.com `&art=2` only shows last ~3 rounds; `fetchMissingRounds()` fetches earlier rounds via `&art=2&rd=N`
## Contact Form

Added 2025-04-06. Users can report bugs/contact via `/contact.php`.

### Files
- **`contact.php`** — Dark-theme contact form (name, email, tournament URL, description). JS intercepts form submit and POSTs to `/api/send-contact.php`.
- **`api/send-contact.php`** — Validates input, checks rate limit, verifies hCaptcha, sends email via PHPMailer + Amazon SES SMTP. Returns JSON `{success: true/false, error: "..."}`.
- **`vendor/phpmailer/`** — PHPMailer library (manual install, not composer).
- **`config.php`** — SMTP credentials and site settings. **NEVER committed to git.** See `config.php.example` for template.

### Email Delivery
- Sent from: `noreply@chess-pairings.com` via SES SMTP (eu-west-1)
- Delivered to: `alan@ampdigital.com`
- Reply-to: submitter's email address
- Rate limit: defined by `CONTACT_RATE_LIMIT` in config.php (default 3 per IP per hour, stored in `.rate_limit/` directory)

### Configuration
```php
// config.php (not in git)
SMTP_HOST, SMTP_PORT, SMTP_USERNAME, SMTP_PASSWORD
MAIL_FROM, MAIL_TO, SITE_NAME, SITE_URL
HCAPTCHA_SITE_KEY, HCAPTCHA_SECRET_KEY, HCAPTCHA_ENABLED
CONTACT_RATE_LIMIT
```

### hCaptcha
- Placeholder in contact.php — enable by filling `HCAPTCHA_*` keys in config.php and setting `HCAPTCHA_ENABLED = true`.


## Deployment
- Live instance: ChessPairings on Lightsail (54.195.64.176)
- Deploy: `git pull` on the server
- `config.php` must be manually uploaded via browser terminal (cat heredoc)
- PHP 8.5 — deprecation warnings suppressed in `api/tournament.php` (curl_close deprecated)
- Java required for JaVaFo (OpenJDK 25.0.2)

## Development

- Runs on XAMPP (Apache + PHP)
- Java must be installed for JaVaFo (`java` on PATH or at standard Oracle install path)
- No build step, no package manager — plain PHP + vanilla JS
- Frontend uses no frameworks; single IIFE in `js/app.js`
