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

## Archived

_(Nothing yet)_
