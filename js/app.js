(function () {
    'use strict';

    const $ = (sel) => document.querySelector(sel);
    const $$ = (sel) => document.querySelectorAll(sel);

    const urlInput = $('#tournament-url');
    const analyzeBtn = $('#analyze-btn');
    const errorBanner = $('#error-banner');
    const loadingSection = $('#loading');
    const loadingText = $('#loading-text');
    const resultsSection = $('#results');
    const roundPicker = $('#round-picker');

    // State: cache scraped data URL so round switches don't re-prompt
    let currentTournamentUrl = '';

    // Check URL params on load
    const params = new URLSearchParams(window.location.search);
    if (params.get('url')) {
        urlInput.value = params.get('url');
        const autoRound = params.get('round') ? parseInt(params.get('round'), 10) : 0;
        setTimeout(() => analyze(autoRound), 300);
    }

    // Event listeners
    analyzeBtn.addEventListener('click', () => analyze());
    urlInput.addEventListener('keydown', (e) => {
        if (e.key === 'Enter') analyze();
    });

    // Tab switching
    $$('.tab').forEach((tab) => {
        tab.addEventListener('click', () => {
            $$('.tab').forEach((t) => t.classList.remove('active'));
            tab.classList.add('active');
            $$('.tab-content').forEach((c) => c.classList.add('hidden'));
            $(`#tab-${tab.dataset.tab}`).classList.remove('hidden');
        });
    });

    function validateUrl(url) {
        return /chess-results\.com\/tnr\d+/i.test(url);
    }

    function showError(msg) {
        errorBanner.textContent = msg;
        errorBanner.classList.remove('hidden');
        setTimeout(() => errorBanner.classList.add('hidden'), 8000);
    }

    function hideError() {
        errorBanner.classList.add('hidden');
    }

    function setLoading(show, text) {
        if (show) {
            loadingSection.classList.remove('hidden');
            resultsSection.classList.add('hidden');
            roundPicker.classList.add('hidden');
            loadingText.textContent = text || 'Fetching tournament data...';
        } else {
            loadingSection.classList.add('hidden');
        }
    }

    async function analyze(targetRound) {
        const url = urlInput.value.trim();
        if (!url) {
            showError('Please enter a tournament URL.');
            return;
        }
        if (!validateUrl(url)) {
            showError('Invalid URL. Please use a chess-results.com tournament link (e.g. https://chess-results.com/tnr123456.aspx?lan=1)');
            return;
        }

        hideError();
        setLoading(true, 'Fetching tournament data...');
        analyzeBtn.disabled = true;
        currentTournamentUrl = url;

        // Update URL bar for shareability
        let shareUrl = `${window.location.pathname}?url=${encodeURIComponent(url)}`;
        if (targetRound) shareUrl += `&round=${targetRound}`;
        window.history.replaceState(null, '', shareUrl);

        // Progress messages
        const messages = [
            'Scraping standings...',
            'Parsing round pairings...',
            'Running Swiss pairing algorithm...',
            'Assigning colors and boards...',
        ];
        let msgIdx = 0;
        const progressTimer = setInterval(() => {
            msgIdx++;
            if (msgIdx < messages.length) {
                loadingText.textContent = messages[msgIdx];
            }
        }, 1500);

        try {
            let apiUrl = `api/tournament.php?action=analyze&url=${encodeURIComponent(url)}`;
            if (targetRound) apiUrl += `&round=${targetRound}`;

            const resp = await fetch(apiUrl);
            const data = await resp.json();

            clearInterval(progressTimer);

            if (!data.success) {
                throw new Error(data.error || 'Unknown error');
            }

            if (data.mode === 'completed') {
                // Tournament is finished — show round picker
                setLoading(false);
                showRoundPicker(data);
            } else {
                renderResults(data);
            }
        } catch (err) {
            clearInterval(progressTimer);
            setLoading(false);
            showError(err.message || 'Failed to analyze tournament.');
        } finally {
            analyzeBtn.disabled = false;
        }
    }

    function showRoundPicker(data) {
        const t = data.tournament;
        resultsSection.classList.add('hidden');
        roundPicker.classList.remove('hidden');

        let optionsHtml = '';
        for (let r = 1; r <= t.totalRounds; r++) {
            optionsHtml += `<option value="${r}">Round ${r}</option>`;
        }

        roundPicker.innerHTML = `
            <div class="round-picker-card">
                <h2>${esc(t.name)}</h2>
                <p class="round-picker-meta">${t.totalRounds} rounds &middot; ${data.playerCount} players &middot; Tournament completed</p>
                <p class="round-picker-prompt">This tournament is complete. Which round would you like to predict pairings for?</p>
                <div class="round-picker-row">
                    <select id="round-select">${optionsHtml}</select>
                    <button id="round-go-btn" type="button">Predict</button>
                </div>
                <p class="input-hint">Compare the prediction against the actual pairings on chess-results.com</p>
            </div>
        `;

        $('#round-go-btn').addEventListener('click', () => {
            const round = parseInt($('#round-select').value, 10);
            analyze(round);
        });

        // Also allow Enter key in the select
        $('#round-select').addEventListener('keydown', (e) => {
            if (e.key === 'Enter') {
                const round = parseInt($('#round-select').value, 10);
                analyze(round);
            }
        });
    }

    function renderResults(data) {
        setLoading(false);
        roundPicker.classList.add('hidden');
        resultsSection.classList.remove('hidden');

        const t = data.tournament;
        const predictingRound = data.predictions.nextRound;
        const isCompleted = data.isCompleted;

        // Tournament header — show round switcher if completed
        let headerHtml = `<h2>${esc(t.name)}</h2>`;

        if (isCompleted) {
            // Build round switcher
            let optionsHtml = '';
            for (let r = 1; r <= t.totalRounds; r++) {
                const selected = r === predictingRound ? 'selected' : '';
                optionsHtml += `<option value="${r}" ${selected}>Round ${r}</option>`;
            }
            const poolNote = data.actualPoolSize
                ? ` &middot; ${data.actualPoolSize} of ${data.totalPlayers} players paired`
                : ` &middot; ${data.standings.length} players`;
            headerHtml += `
                <div class="meta">
                    ${t.totalRounds} rounds${poolNote} &middot; Tournament completed
                </div>
                <div class="round-switcher">
                    <span>Predicting pairings for</span>
                    <select id="header-round-select">${optionsHtml}</select>
                </div>
            `;
        } else if (data.isNotStarted) {
            const totalInfo = t.totalRounds > 0 ? `${t.totalRounds} rounds &middot; ` : '';
            headerHtml += `
                <div class="meta">
                    ${totalInfo}${data.standings.length} players
                    &middot; Tournament not yet started
                    &middot; Predicting Round 1
                </div>
            `;
        } else {
            headerHtml += `
                <div class="meta">
                    Round ${t.completedRounds} of ${t.totalRounds} completed
                    &middot; ${data.standings.length} players
                    &middot; Predicting Round ${predictingRound}
                </div>
            `;
        }

        $('#tournament-header').innerHTML = headerHtml;

        // Bind round switcher if present
        const roundSelect = $('#header-round-select');
        if (roundSelect) {
            roundSelect.addEventListener('change', () => {
                analyze(parseInt(roundSelect.value, 10));
            });
        }

        renderPairings(data.predictions);
        renderStandings(data.standings);
        renderHistory(data.playerDetails, predictingRound - 1);

        // Reset to pairings tab
        $$('.tab').forEach((t) => t.classList.remove('active'));
        $('.tab[data-tab="pairings"]').classList.add('active');
        $$('.tab-content').forEach((c) => c.classList.add('hidden'));
        $('#tab-pairings').classList.remove('hidden');
    }

    function renderPairings(predictions) {
        const pairings = predictions.pairings;

        let html = `<table class="data-table">
            <thead>
                <tr>
                    <th class="text-center" style="width:50px">Bd</th>
                    <th class="text-right">White</th>
                    <th class="text-center" style="width:40px"></th>
                    <th>Black</th>
                </tr>
            </thead>
            <tbody>`;

        for (const p of pairings) {
            html += `
                <tr>
                    <td class="num text-center">${p.board}</td>
                    <td class="text-right">
                        <div class="player-cell align-right">
                            <div>
                                <div class="player-name">${esc(p.white.name)}</div>
                                <div class="player-meta">${p.white.rating} &middot; ${p.white.score}pts</div>
                            </div>
                            <span class="color-indicator white"></span>
                        </div>
                    </td>
                    <td class="vs-cell">vs</td>
                    <td>
                        <div class="player-cell">
                            <span class="color-indicator black"></span>
                            <div>
                                <div class="player-name">${esc(p.black.name)}</div>
                                <div class="player-meta">${p.black.rating} &middot; ${p.black.score}pts</div>
                            </div>
                        </div>
                    </td>
                </tr>`;
        }

        html += '</tbody></table>';

        if (predictions.bye) {
            const b = predictions.bye;
            html += `<div class="bye-card">Bye: ${esc(b.playerName)} (${b.playerRating})</div>`;
        }

        $('#tab-pairings').innerHTML = html;
    }

    function renderStandings(standings) {
        let html = `<table class="data-table">
            <thead>
                <tr>
                    <th class="text-center" style="width:40px">Rk</th>
                    <th style="width:40px" class="text-center">No</th>
                    <th>Name</th>
                    <th class="text-center">Rtg</th>
                    <th class="text-center">Fed</th>
                    <th class="text-center">Pts</th>
                </tr>
            </thead>
            <tbody>`;

        for (const p of standings) {
            html += `
                <tr>
                    <td class="num text-center">${p.rank || '-'}</td>
                    <td class="num text-center">${p.startNo}</td>
                    <td>${esc(p.name)}</td>
                    <td class="num text-center">${p.rating || '-'}</td>
                    <td class="text-center">${esc(p.federation || '')}</td>
                    <td class="num text-center">${p.currentScore}</td>
                </tr>`;
        }

        html += '</tbody></table>';
        $('#tab-standings').innerHTML = html;
    }

    function renderHistory(playerDetails, completedRounds) {
        let html = `<table class="data-table">
            <thead>
                <tr>
                    <th>Name</th>
                    <th class="text-center">Rtg</th>
                    <th class="text-center">Pts</th>
                    <th>Color History</th>
                    <th>Opponents</th>
                </tr>
            </thead>
            <tbody>`;

        // Sort by score desc then rating desc
        playerDetails.sort((a, b) => {
            if (b.currentScore !== a.currentScore) return b.currentScore - a.currentScore;
            return b.rating - a.rating;
        });

        for (const p of playerDetails) {
            // Color strip
            let strip = '<div class="color-strip">';
            for (const h of p.history) {
                const isBye = h.opponentNo === 0 || h.opponentNo === null;
                if (isBye) {
                    const byeLabel = h.result === '½' ? '½' : h.result === '0' ? '0' : 'B';
                    strip += `<div class="square bye" title="R${h.round}: Bye (${h.result})">${byeLabel}</div>`;
                } else if (h.color === 'W') {
                    const cls = resultClass(h.result);
                    strip += `<div class="square w ${cls}" title="R${h.round}: ${esc(h.opponentName)} (${h.color}) ${h.result}">${displayResult(h.result)}</div>`;
                } else if (h.color === 'B') {
                    const cls = resultClass(h.result);
                    strip += `<div class="square b ${cls}" title="R${h.round}: ${esc(h.opponentName)} (${h.color}) ${h.result}">${displayResult(h.result)}</div>`;
                } else {
                    strip += `<div class="square" title="R${h.round}: -">-</div>`;
                }
            }
            strip += '</div>';

            // Opponents list
            let opps = p.history
                .map((h) => {
                    const isBye = h.opponentNo === 0 || h.opponentNo === null;
                    if (isBye) return '<span style="color:var(--bye-color)">bye</span>';
                    if (!h.opponentName || h.opponentName === '-') return '-';
                    return esc(h.opponentName);
                })
                .join(', ');

            html += `
                <tr>
                    <td>${esc(p.name)}</td>
                    <td class="num text-center">${p.rating || '-'}</td>
                    <td class="num text-center">${p.currentScore}</td>
                    <td>${strip}</td>
                    <td style="font-size:0.75rem;color:var(--text-secondary)">${opps}</td>
                </tr>`;
        }

        html += '</tbody></table>';
        $('#tab-history').innerHTML = html;
    }

    function resultClass(result) {
        if (result === '1') return 'result-win';
        if (result === '½') return 'result-draw';
        if (result === '0') return 'result-loss';
        return '';
    }

    function displayResult(result) {
        if (result === '1') return 'W';
        if (result === '½') return 'D';
        if (result === '0') return 'L';
        return '?';
    }

    function esc(str) {
        if (!str) return '';
        const div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }
})();
