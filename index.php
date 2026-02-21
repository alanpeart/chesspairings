<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ChessPairings — Predict Next Round</title>
    <meta name="description" content="Predict Swiss-system pairings for chess tournaments on chess-results.com. Powered by JaVaFo, the FIDE-endorsed Dutch pairing engine.">
    <link rel="icon" href="favicon.svg" type="image/svg+xml">

    <!-- Open Graph -->
    <meta property="og:type" content="website">
    <meta property="og:url" content="https://chess-pairings.com/">
    <meta property="og:title" content="ChessPairings — Predict Next Round">
    <meta property="og:description" content="Predict Swiss-system pairings for chess tournaments on chess-results.com. Powered by JaVaFo, the FIDE-endorsed Dutch pairing engine.">
    <meta property="og:image" content="https://chess-pairings.com/og-image.png">

    <!-- Twitter Card -->
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="ChessPairings — Predict Next Round">
    <meta name="twitter:description" content="Predict Swiss-system pairings for chess tournaments on chess-results.com.">
    <meta name="twitter:image" content="https://chess-pairings.com/og-image.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/style.css">
</head>
<body>
    <header class="header">
        <div class="header-inner">
            <div class="logo">
                <span class="logo-icon">♔</span>
                <div>
                    <h1>ChessPairings <span class="alpha-badge">alpha</span></h1>
                    <p class="tagline">Predict the next round from chess-results.com</p>
                </div>
            </div>
        </div>
    </header>

    <main class="main">
        <section class="intro">
            <p>An unofficial tool that predicts Swiss-system pairings for tournaments on chess-results.com. Powered by <a href="https://github.com/JaVaFo" target="_blank" rel="noopener">JaVaFo</a>, the FIDE-endorsed Dutch pairing engine. Paste a tournament link below to see predicted pairings for any round &mdash; useful for scouting your likely next opponent or just satisfying your curiosity!</p>
            <p class="disclaimer">Note: predictions use the FIDE Dutch pairing algorithm. Tournament organisers may choose a different pairing system (e.g. Burstein, Dubov) in Swiss-Manager, which can produce different pairings &mdash; especially in lower score groups. Pairings may also differ if a player withdraws, takes a bye, or if the arbiter makes manual adjustments.</p>
        </section>

        <section class="input-section">
            <div class="input-card">
                <label for="tournament-url">Tournament URL</label>
                <div class="input-row">
                    <input type="url" id="tournament-url"
                           placeholder="https://chess-results.com/tnr123456.aspx?lan=1"
                           spellcheck="false" autocomplete="off">
                    <button id="analyze-btn" type="button">Predict Pairings</button>
                </div>
                <p class="input-hint">Paste any chess-results.com tournament link</p>
            </div>
        </section>

        <div id="error-banner" class="error-banner hidden"></div>

        <section id="loading" class="loading hidden">
            <div class="spinner"></div>
            <p id="loading-text">Fetching tournament data...</p>
        </section>

        <section id="round-picker" class="round-picker hidden"></section>

        <section id="results" class="results hidden">
            <div class="tournament-header" id="tournament-header"></div>

            <div id="bye-selector" class="bye-selector hidden"></div>

            <div class="tabs">
                <button class="tab active" data-tab="pairings">Predicted Pairings</button>
                <button class="tab" data-tab="standings">Current Standings</button>
                <button class="tab" data-tab="history">Player History</button>
                <button class="tab hidden" data-tab="final-standings">Final Standings</button>
            </div>

            <div class="tab-content" id="tab-pairings"></div>
            <div class="tab-content hidden" id="tab-standings"></div>
            <div class="tab-content hidden" id="tab-history"></div>
            <div class="tab-content hidden" id="tab-final-standings"></div>
        </section>
    </main>

    <footer class="footer">
        <p>Data sourced from <a href="https://chess-results.com" target="_blank" rel="noopener">chess-results.com</a>. Predictions use the FIDE Dutch pairing algorithm &mdash; actual pairings may differ if the tournament uses a different system, or due to withdrawals, byes, or arbiter adjustments.</p>
    </footer>

    <script src="js/app.js"></script>
</body>
</html>
