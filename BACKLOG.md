# ChessPairings — Features Backlog

Ideas for future enhancement. Move items to "Archived" if decided against.

## Active Ideas

### Shareable links with rich previews
Add Open Graph meta tags so shared links show the tournament name and predicted round in social/chat previews. The URL-param support already exists (`?url=...&round=...`) — make the share link more prominent in the UI and generate proper `og:title` / `og:description` from the API response.

### Tournament organiser / arbiter mode
Position the manual bye selector as a planning tool for arbiters: "what happens if player X takes a bye?" Organisers need this before pairings are official. Could expand to include withdrawals, late entries, and what-if scenarios. This is a different audience (arbiters, not players) and one more likely to pay.

### Chess Club Manager integration
Shared pairing engine as a library or microservice that both ChessPairings and Chess Club Manager can use. Avoids duplicating JaVaFo wrapper logic across projects.

### Notifications
Optional push notifications or email alerts: "your next round pairing is ready" or "notify me when round N is paired." Turns a tool people visit occasionally into one they rely on. Even a simple email signup per tournament would work.

### Broader pairing system support
JaVaFo implements FIDE Dutch, but many tournaments use Burstein or Dubov via Swiss-Manager. Allow users to specify the system, or attempt to detect it from the chess-results page. Improved accuracy = clear differentiator.

### Browser extension
A lightweight browser extension that adds predicted pairings directly onto chess-results.com pages. Very low friction for users already browsing tournaments.

### Caching & performance tier
For high-traffic tournaments (Olympiad, national championships), consider a short-lived server-side cache with push-based refresh — scrape on a timer rather than on-demand. Could also add a simple queue to avoid concurrent JaVaFo processes.

### Monetisation (if desired)
Freemium model: basic predictions free, organiser features (bulk analysis, API access, notification alerts, embed widget) behind a small subscription. The user base is niche but dedicated.

## Technical Debt

Codebase assessment, 2026-09-26. Ordered by leverage — item 1 changes the
economics of everything below it, so do it first. Origin: the Hull 4NCL
`h3.CRmsg` round-misdetection bug (fixed in `bced33f`), which a single
offline fixture test would have caught the day it was introduced.

### 1. Split fetching from parsing, then add fixtures

`ChessResultsScraper` welds `fetchPage()` (network) to `parseStandings()` /
`parsePairings()` (pure functions) inside one class, so the parser cannot be
exercised without the internet and a live tournament whose data shifts
underneath you. Split into a `Fetcher` that returns HTML and a `Parser` that
turns HTML into structures, then save ~10 real pages as fixtures — include
`tnr1483594` (announcement banner mentioning rounds), a tournament with
150+ players, one with forfeits, and one mid-round with unpublished pairings.

This is roughly 80% of the value on this list. Everything else is cheaper
once the parser can be tested in 50ms offline.

### 2. Delete the dead pairing engine and the scratch scripts

`lib/SwissPairing.php` is 1012 lines, of which production uses only the
68-line static `rewindToRound()`. The rest — `predict()`, bracket pairing,
downfloat selection, colour allocation, permutation search — is an abandoned
hand-rolled Dutch implementation superseded by JaVaFo, reachable only from
the root `test_*.php` scripts. CLAUDE.md has already drifted as a result,
describing the file as a rewind utility (true of 7% of it). Dead code that
looks authoritative is a trap for the next reader.

Also delete: the nine root `test_*.php` / `debug_withdrawals.php` scripts
(zero assertions between them, all hitting the live network — debugging
transcripts, not tests), `test-cache.html`, and `contact.php.bak`.

Two deployment consequences, since htdocs is the repo root:
- Those scripts are publicly reachable and each fires live scrapes on load
  — an unauthenticated way for anyone to make the server hammer
  chess-results. Worth confirming on the live box.
- `.bak` is not parsed by PHP, so `contact.php.bak` is served as plain-text
  source. It leaks no credentials (only `HCAPTCHA_SITE_KEY`, public by
  design) and differs from `contact.php` by one line, but delete it anyway.

### 3. Golden-file tests for TRF generation

TRF generation is the other place a silent bug ruins every prediction, and
the invariants are already written down in CLAUDE.md — 10-char round blocks
with no trailing space, `F` not `+` for full-point byes, `mb_*` padding for
multibyte names. Assert them against known-good TRF files.

### 4. One canary test against a real tournament

Fixtures catch regressions; only a live check catches chess-results changing
its HTML. Hit one real completed tournament and assert a known-good result,
so upstream drift surfaces as a test failure rather than as a quietly wrong
prediction.

### 5. Cheap wins

- **Parallelise fetches.** `analyze()` does 2 + N *sequential* requests at up
  to 30s timeout each; a 9-round tournament is ~11 serial round-trips.
  `curl_multi` collapses the per-round ones. `TournamentCache` hides this on
  repeat views but not on cold loads.
- **Fix the rate-limiter race.** `lib/RateLimiter.php` reads unlocked (line
  49) then writes with `LOCK_EX` (line 64). `LOCK_EX` serialises the write
  but does nothing about the lost update between read and write, so
  concurrent requests both read the same count and the limit under-counts.
  Hold one `flock` across read and write, or use `fopen('c+')`.
- **Thin `api/tournament.php`.** 280 lines of scrape, cache, rate-limit,
  rewind, pair, sort and reshape in a single `try`. It also sets
  `Content-Type` twice (lines 5 and 8) after the caching work landed —
  harmless, but a symptom.

## Archived

_(Nothing yet)_
