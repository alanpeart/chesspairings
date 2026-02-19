<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ChessPairings — Predict Next Round</title>
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
                    <h1>ChessPairings</h1>
                    <p class="tagline">Predict the next round from chess-results.com</p>
                </div>
            </div>
        </div>
    </header>

    <main class="main">
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

            <div class="tabs">
                <button class="tab active" data-tab="pairings">Predicted Pairings</button>
                <button class="tab" data-tab="standings">Current Standings</button>
                <button class="tab" data-tab="history">Player History</button>
            </div>

            <div class="tab-content" id="tab-pairings"></div>
            <div class="tab-content hidden" id="tab-standings"></div>
            <div class="tab-content hidden" id="tab-history"></div>
        </section>
    </main>

    <footer class="footer">
        <p>Data sourced from <a href="https://chess-results.com" target="_blank" rel="noopener">chess-results.com</a>. Pairings are predictions based on the Dutch Swiss algorithm — actual pairings may differ.</p>
    </footer>

    <script src="js/app.js"></script>
</body>
</html>
