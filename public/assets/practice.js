/**
 * Practice dictation — "listen-then-dictate" với progress tracking.
 *
 * State machine mỗi câu: idle → listening → dictating → completed.
 * Progress (câu đã hoàn thành + score) lưu localStorage để reload không mất.
 * Transcript view đánh dấu ✓ câu xong, highlight câu hiện tại.
 */
(function () {
    const app      = document.getElementById('app');
    const lessonId = +app.dataset.lessonId;
    const STORAGE_KEY = `dictation_progress_${lessonId}`;

    const $ = id => document.getElementById(id);
    const audio       = $('audio');
    const display     = $('display');
    const speakerTag  = $('speaker-tag');
    const input       = $('user-input');
    const result      = $('result');
    const fullT       = $('full-transcript');
    const curIdxEl    = $('cur-idx');
    const totalIdxEl  = $('total-idx');
    const totalIdx2El = $('total-idx-2');
    const scoreEl     = $('score');
    const modeSel     = $('dict-mode');
    const speedSel    = $('speed');
    const btnRelisten = $('btn-relisten');
    const btnShow     = $('btn-show');
    const btnCheck    = $('btn-check');
    const btnPrev     = $('btn-prev');
    const btnNext     = $('btn-next');
    const nextLabel   = $('next-label');

    const PHASE = { IDLE: 'idle', LISTENING: 'listening', DICTATING: 'dictating', COMPLETED: 'completed' };
    const WORD_RE = /[A-Za-zÀ-ỹĐđ0-9'']/;
    const isWord = tok => WORD_RE.test(tok);
    const MIN_WORD_LEN = 3;
    const isPracticeWord = tok => isWord(tok) && tok.length >= MIN_WORD_LEN;

    const state = {
        lines: [],
        cur: 0,
        wordIdx: 0,
        score: 0,
        completed: new Set(),   // indexes of completed lines
        revealed: new Set(),    // "lineIdx:tokIdx" đã reveal qua Đáp án
        stopAt: null,
        phase: PHASE.IDLE,
        mode: 'word',
    };

    /* =========== localStorage persistence =========== */
    function saveProgress() {
        try {
            localStorage.setItem(STORAGE_KEY, JSON.stringify({
                completed: [...state.completed],
                score: state.score,
                cur: state.cur,
            }));
        } catch (e) {}
    }

    function loadProgress() {
        try {
            const raw = localStorage.getItem(STORAGE_KEY);
            if (!raw) return;
            const d = JSON.parse(raw);
            if (d.completed) d.completed.forEach(i => state.completed.add(i));
            if (typeof d.score === 'number') state.score = d.score;
            if (typeof d.cur === 'number') state.cur = d.cur;
        } catch (e) {}
    }

    function resetProgress() {
        state.completed.clear();
        state.revealed.clear();
        state.score = 0;
        state.wordIdx = 0;
        localStorage.removeItem(STORAGE_KEY);
        scoreEl.textContent = '0';
        setCurrent(0);
        renderProgressBar();
        renderTranscriptMarks();
    }

    /* =========== Load data =========== */
    fetch(`api/transcript.php?id=${lessonId}`)
        .then(r => r.json())
        .then(data => {
            state.lines = data.lines.map(l => {
                const wordsOnly = [];
                l.words.forEach((w, i) => {
                    if (isPracticeWord(w)) wordsOnly.push({ tok: w, tokIdx: i });
                });
                return { ...l, wordsOnly };
            });
            totalIdxEl.textContent  = state.lines.length;
            totalIdx2El.textContent = state.lines.length;

            // Restore progress trước khi render
            loadProgress();
            scoreEl.textContent = state.score;

            renderTranscript();
            renderProgressBar();
            setCurrent(Math.min(state.cur, state.lines.length - 1));
        })
        .catch(err => { result.textContent = 'Lỗi tải transcript: ' + err; });

    /* =========== Set current sentence =========== */
    function setCurrent(i) {
        i = Math.max(0, Math.min(i, state.lines.length - 1));
        state.cur = i;
        state.wordIdx = 0;
        state.stopAt = null;

        // Câu đã completed → hiện lại trạng thái completed, không reset IDLE
        state.phase = state.completed.has(i) ? PHASE.COMPLETED : PHASE.IDLE;

        const line = state.lines[i];
        curIdxEl.textContent = i + 1;
        if (line.speaker) {
            speakerTag.textContent = line.speaker;
            speakerTag.classList.add('show');
        } else {
            speakerTag.classList.remove('show');
        }
        result.className = 'result';
        result.textContent = '';
        input.value = '';
        renderDisplay();
        highlightTranscript();
        updateButtonLabels();
    }

    /* =========== Phase transitions =========== */
    function startListening(replayOnly = false) {
        const line = state.lines[state.cur];
        if (!line) return;
        state._returnPhase = replayOnly ? state.phase : null;
        state.phase = PHASE.LISTENING;
        state.stopAt = line.start_sec + (line.duration_sec || 3) + 0.15;
        try {
            audio.currentTime = line.start_sec;
            audio.playbackRate = parseFloat(speedSel.value);
            audio.play().catch(() => {});
        } catch (e) {}
        renderDisplay();
        updateButtonLabels();
    }

    function enterDictating() {
        const line = state.lines[state.cur];
        if (state.mode === 'word' && line.wordsOnly.length === 0) {
            completeLine();
            return;
        }
        state.phase = PHASE.DICTATING;
        renderDisplay();
        input.disabled = false;
        input.focus();
        updateButtonLabels();
    }

    function completeLine() {
        if (!state.completed.has(state.cur)) {
            state.completed.add(state.cur);
            state.score++;
            scoreEl.textContent = state.score;
        }
        state.phase = PHASE.COMPLETED;
        renderDisplay();
        updateButtonLabels();
        renderProgressBar();
        renderTranscriptMarks();
        saveProgress();
    }

    audio.addEventListener('timeupdate', () => {
        if (state.stopAt !== null && audio.currentTime >= state.stopAt) {
            audio.pause();
            state.stopAt = null;
            if (state.phase === PHASE.LISTENING) {
                if (state._returnPhase === PHASE.COMPLETED || state._returnPhase === PHASE.DICTATING) {
                    state.phase = state._returnPhase;
                    state._returnPhase = null;
                    renderDisplay();
                    updateButtonLabels();
                    if (state.phase === PHASE.DICTATING) input.focus();
                } else {
                    enterDictating();
                }
            }
        }
    });

    /* =========== Smart play / nav =========== */
    function smartPlayNext() {
        const isLast = state.cur >= state.lines.length - 1;
        if (state.phase === PHASE.COMPLETED) {
            if (isLast) return;
            setCurrent(state.cur + 1);
            startListening();
        } else if (state.phase === PHASE.IDLE) {
            startListening();
        } else if (state.phase === PHASE.DICTATING) {
            if (!isLast) { setCurrent(state.cur + 1); startListening(); }
        }
    }

    function relisten() {
        if (state.phase === PHASE.LISTENING) return;
        startListening(true);
    }

    /* =========== UI labels =========== */
    function updateButtonLabels() {
        const isLast = state.cur >= state.lines.length - 1;
        const allDone = state.completed.size >= state.lines.length;
        const lbl = (() => {
            if (allDone)                              return '🎉 Hoàn thành bài';
            if (state.phase === PHASE.IDLE)           return state.cur === 0 ? 'Bắt đầu' : 'Phát câu';
            if (state.phase === PHASE.LISTENING)      return 'Đang phát…';
            if (state.phase === PHASE.DICTATING)      return isLast ? 'Bỏ qua' : 'Câu sau ▶';
            if (state.phase === PHASE.COMPLETED)      return isLast ? '🎉 Xong!' : 'Câu sau ▶';
            return 'Phát';
        })();
        nextLabel.textContent = lbl;
        btnNext.disabled = allDone || state.phase === PHASE.LISTENING ||
                           (state.phase === PHASE.COMPLETED && isLast);

        input.disabled    = state.phase !== PHASE.DICTATING;
        btnCheck.disabled = state.phase !== PHASE.DICTATING;
        btnShow.disabled  = state.phase !== PHASE.DICTATING;
    }

    /* =========== Render display =========== */
    function maskOf(w) {
        const len = w.length;
        return `[${'-'.repeat(Math.max(1, len))}](${len})`;
    }

    function renderDisplay() {
        const line = state.lines[state.cur];
        if (!line) return;

        if (state.phase === PHASE.IDLE) {
            display.innerHTML =
                `<div class="phase-msg phase-idle">
                    <div class="big-icon">🎧</div>
                    <p>Nhấn <kbd>▶ ${state.cur === 0 ? 'Bắt đầu' : 'Phát câu'}</kbd>
                       hoặc phím <kbd>Space</kbd> để nghe.</p>
                </div>`;
            return;
        }
        if (state.phase === PHASE.LISTENING) {
            display.innerHTML =
                `<div class="phase-msg phase-listening">
                    <div class="big-icon spinning">🎧</div>
                    <p>Đang phát… tập trung nghe nhé.</p>
                </div>`;
            return;
        }
        if (state.phase === PHASE.COMPLETED) {
            const html = line.words.map(t =>
                isWord(t)
                    ? `<span class="tok tok-correct">${escapeHtml(t)}</span>`
                    : `<span class="tok tok-revealed">${escapeHtml(t)}</span>`
            );
            let buf = '';
            for (let i = 0; i < line.words.length; i++) {
                if (i > 0 && isWord(line.words[i])) buf += ' ';
                buf += html[i];
            }
            const isLast = state.cur >= state.lines.length - 1;
            const allDone = state.completed.size >= state.lines.length;
            let tip;
            if (allDone) {
                tip = `<div class="phase-tip">🎉 Tuyệt vời! Hoàn thành ${state.lines.length}/${state.lines.length} câu.</div>`;
            } else if (isLast) {
                tip = '<div class="phase-tip">Câu cuối xong. Dùng Transcript bên dưới để chọn câu chưa hoàn thành.</div>';
            } else {
                tip = '<div class="phase-tip">✅ Hoàn thành! Nhấn <kbd>▶ Câu sau</kbd> để tiếp.</div>';
            }
            tip += `<button class="retry-btn" data-action="retry">↺ Làm lại câu này</button>`;
            display.innerHTML = `<div class="full-line">${buf}</div>${tip}`;
            return;
        }

        // DICTATING
        const wordsOnly = line.wordsOnly;
        const curTokIdx = state.mode === 'word' && wordsOnly[state.wordIdx]
            ? wordsOnly[state.wordIdx].tokIdx : -2;

        const parts = line.words.map((tok, i) => {
            if (!isWord(tok)) return `<span class="tok tok-revealed">${escapeHtml(tok)}</span>`;
            const wasRevealed = state.revealed.has(`${state.cur}:${i}`);
            if (state.mode === 'word') {
                if (i === curTokIdx) return `<span class="tok tok-blank current">${maskOf(tok)}</span>`;
                return `<span class="tok tok-revealed">${escapeHtml(tok)}</span>`;
            } else {
                if (wasRevealed) return `<span class="tok tok-revealed">${escapeHtml(tok)}</span>`;
                return `<span class="tok tok-blank">${maskOf(tok)}</span>`;
            }
        });

        let buf = '';
        for (let i = 0; i < line.words.length; i++) {
            if (i > 0 && isWord(line.words[i])) buf += ' ';
            buf += parts[i];
        }
        display.innerHTML = buf;
    }

    /* =========== Progress bar =========== */
    function renderProgressBar() {
        let bar = $('progress-bar');
        if (!bar) {
            // Tạo progress bar nếu chưa có
            const container = document.createElement('div');
            container.className = 'progress-wrap';
            container.innerHTML =
                `<div class="progress-track"><div class="progress-fill" id="progress-bar"></div></div>
                 <span class="progress-text" id="progress-text"></span>
                 <button class="btn-reset" id="btn-reset" title="Xóa tiến độ, làm lại từ đầu">↺ Làm lại</button>`;
            // Insert sau counter-row
            const counterRow = document.querySelector('.counter-row');
            if (counterRow) counterRow.after(container);
            bar = $('progress-bar');
            $('btn-reset')?.addEventListener('click', () => {
                if (confirm('Xóa hết tiến độ bài này và làm lại từ đầu?')) resetProgress();
            });
        }
        const total   = state.lines.length || 1;
        const done    = state.completed.size;
        const pct     = Math.round((done / total) * 100);
        bar.style.width = pct + '%';
        const textEl = $('progress-text');
        if (textEl) textEl.textContent = `${done}/${total} câu (${pct}%)`;
    }

    /* =========== Transcript view =========== */
    function renderTranscript() {
        fullT.innerHTML = state.lines.map((l, i) =>
            `<div class="ft-line" data-i="${i}">
                <span class="ft-status" data-i="${i}"></span>
                ${l.speaker ? `<span class="ft-speaker">${escapeHtml(l.speaker)}</span>` : ''}
                <span class="ft-text">${escapeHtml(l.text)}</span>
            </div>`).join('');
        fullT.addEventListener('click', e => {
            const el = e.target.closest('.ft-line');
            if (el) setCurrent(+el.dataset.i);
        });
        renderTranscriptMarks();
    }

    function renderTranscriptMarks() {
        fullT.querySelectorAll('.ft-status').forEach(el => {
            const i = +el.dataset.i;
            if (state.completed.has(i)) {
                el.textContent = '✅';
                el.closest('.ft-line')?.classList.add('ft-done');
            } else {
                el.textContent = '';
                el.closest('.ft-line')?.classList.remove('ft-done');
            }
        });
    }

    function highlightTranscript() {
        fullT.querySelectorAll('.ft-line').forEach((el, i) =>
            el.classList.toggle('active', i === state.cur));
    }

    /* =========== Check / Compare =========== */
    function normalize(s) {
        return s.toLowerCase().replace(/['']/g, "'").replace(/[^a-z0-9'à-ỹđ]/gi, '');
    }

    function checkAnswer() {
        if (state.phase !== PHASE.DICTATING) return;
        const line = state.lines[state.cur];
        if (state.mode === 'word') {
            if (line.wordsOnly.length === 0) { completeLine(); return; }
            const target = line.wordsOnly[state.wordIdx];
            if (!target) { completeLine(); return; }
            const got = normalize(input.value);
            const exp = normalize(target.tok);
            if (got === exp) {
                state.wordIdx++;
                input.value = '';
                if (state.wordIdx >= line.wordsOnly.length) {
                    flashResult('🎉 Hoàn thành câu!', 'ok');
                    completeLine();
                } else {
                    renderDisplay();
                    flashResult(`✓ Đúng! Tiếp: ${line.wordsOnly.length - state.wordIdx} từ còn lại`, 'ok');
                }
            } else if (got === '') {
                flashResult('Gõ từ rồi nhấn Enter.', '');
            } else {
                flashResult(`✘ Sai — từ này ${target.tok.length} chữ, thử lại`, 'fail');
            }
        } else {
            const expectedTokens = line.wordsOnly.map(x => normalize(x.tok)).filter(Boolean);
            const gotTokens      = input.value.split(/\s+/).map(normalize).filter(Boolean);
            const diff = diffLCS(expectedTokens, gotTokens);
            const allOk = diff.every(d => d.kind === 'ok');
            const html = diff.map(d => {
                if (d.kind === 'ok')      return `<span class="tok-correct">${escapeHtml(d.expected)}</span>`;
                if (d.kind === 'wrong')   return `<span class="tok-wrong">${escapeHtml(d.got)}</span> <span class="tok-blank">[${d.expected}]</span>`;
                if (d.kind === 'missing') return `<span class="tok-blank">[${d.expected}]</span>`;
                if (d.kind === 'extra')   return `<span class="tok-wrong">${escapeHtml(d.got)}</span>`;
                return '';
            }).join(' ');
            result.className = 'result ' + (allOk ? 'ok' : 'fail');
            result.innerHTML = (allOk ? '✓ Hoàn hảo! ' : '✘ Có lỗi: ') + html;
            if (allOk) completeLine();
        }
    }

    function flashResult(text, cls) {
        result.className = 'result ' + cls;
        result.textContent = text;
    }

    /**
     * Đáp án = "peek" (gợi ý): hiện từ đáp án tại chỗ trên display 3 giây rồi ẩn lại.
     * KHÔNG advance sang từ kế tiếp — user vẫn phải tự gõ đúng từ đó.
     * Bấm nhiều lần → chỉ hiện lại cùng 1 từ (vì wordIdx không đổi).
     */
    function showAnswer() {
        if (state.phase !== PHASE.DICTATING) return;
        const line = state.lines[state.cur];

        if (state.mode === 'word') {
            const target = line.wordsOnly[state.wordIdx];
            if (!target) return;

            // Hiện từ đáp án tại chỗ blank (highlight cam) — 3 giây rồi ẩn lại
            if (state._peekTimer) clearTimeout(state._peekTimer);
            renderDisplayPeek(target.tokIdx, target.tok);
            flashResult(`Gợi ý: "${target.tok}" — hãy gõ lại từ này`, 'fail');
            input.focus();

            state._peekTimer = setTimeout(() => {
                state._peekTimer = null;
                renderDisplay(); // ẩn lại thành [----](N)
            }, 3000);
        } else {
            // Whole mode: hiện toàn bộ trên display
            line.wordsOnly.forEach(x => state.revealed.add(`${state.cur}:${x.tokIdx}`));
            renderDisplay();
            flashResult('Xem đáp án trên display — hãy gõ lại cả câu', 'fail');
        }
    }

    /** Render display với 1 từ peek (highlight cam) tại vị trí tokIdx, các từ khác giữ nguyên. */
    function renderDisplayPeek(peekTokIdx, peekText) {
        const line = state.lines[state.cur];
        const parts = line.words.map((tok, i) => {
            if (!isWord(tok)) return `<span class="tok tok-revealed">${escapeHtml(tok)}</span>`;
            if (i === peekTokIdx) {
                return `<span class="tok tok-answer">${escapeHtml(peekText)}</span>`;
            }
            return `<span class="tok tok-revealed">${escapeHtml(tok)}</span>`;
        });
        let buf = '';
        for (let i = 0; i < line.words.length; i++) {
            if (i > 0 && isWord(line.words[i])) buf += ' ';
            buf += parts[i];
        }
        display.innerHTML = buf;
    }

    /* =========== Diff (LCS) =========== */
    function diffLCS(exp, got) {
        const m = exp.length, n = got.length;
        const dp = Array.from({ length: m + 1 }, () => new Array(n + 1).fill(0));
        for (let i = 1; i <= m; i++)
            for (let j = 1; j <= n; j++)
                dp[i][j] = exp[i-1] === got[j-1] ? dp[i-1][j-1] + 1 : Math.max(dp[i-1][j], dp[i][j-1]);
        const out = [];
        let i = m, j = n;
        while (i > 0 && j > 0) {
            if (exp[i-1] === got[j-1])      { out.push({ kind: 'ok', expected: exp[--i] }); j--; }
            else if (dp[i-1][j] >= dp[i][j-1]) out.push({ kind: 'missing', expected: exp[--i] });
            else                               out.push({ kind: 'extra',   got:      got[--j] });
        }
        while (i > 0) out.push({ kind: 'missing', expected: exp[--i] });
        while (j > 0) out.push({ kind: 'extra',   got:      got[--j] });
        out.reverse();
        const merged = [];
        for (let k = 0; k < out.length; k++) {
            const cur = out[k], nxt = out[k + 1];
            if (cur && nxt && cur.kind === 'extra' && nxt.kind === 'missing') {
                merged.push({ kind: 'wrong', got: cur.got, expected: nxt.expected }); k++;
            } else if (cur && nxt && cur.kind === 'missing' && nxt.kind === 'extra') {
                merged.push({ kind: 'wrong', got: nxt.got, expected: cur.expected }); k++;
            } else merged.push(cur);
        }
        return merged;
    }

    /* =========== Retry (làm lại câu đã completed) =========== */
    function retryLine(i) {
        state.completed.delete(i);
        state.score = Math.max(0, state.score - 1);
        state.wordIdx = 0;
        // Xóa revealed hints cho câu này
        for (const key of [...state.revealed]) {
            if (key.startsWith(i + ':')) state.revealed.delete(key);
        }
        scoreEl.textContent = state.score;
        state.phase = PHASE.IDLE;
        renderDisplay();
        renderProgressBar();
        renderTranscriptMarks();
        updateButtonLabels();
        saveProgress();
        flashResult('Đã reset câu — nhấn ▶ Phát để nghe lại.', '');
    }

    // Event delegation cho nút "Làm lại" bên trong display
    display.addEventListener('click', e => {
        if (e.target.closest('[data-action="retry"]')) {
            retryLine(state.cur);
        }
    });

    /* =========== Wiring =========== */
    btnRelisten.addEventListener('click', relisten);
    btnShow    .addEventListener('click', showAnswer);
    btnCheck   .addEventListener('click', checkAnswer);
    btnPrev    .addEventListener('click', () => setCurrent(state.cur - 1));
    btnNext    .addEventListener('click', smartPlayNext);
    speedSel   .addEventListener('change', () => audio.playbackRate = parseFloat(speedSel.value));
    modeSel    .addEventListener('change', () => {
        state.mode = modeSel.value;
        state.wordIdx = 0;
        state.completed.delete(state.cur);
        if (state.phase === PHASE.COMPLETED) state.phase = PHASE.DICTATING;
        renderDisplay();
        updateButtonLabels();
        if (state.phase === PHASE.DICTATING) input.focus();
    });
    input.addEventListener('keydown', e => {
        if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); checkAnswer(); }
    });
    document.addEventListener('keydown', e => {
        if (e.target === input) return;
        if (e.code === 'Space')                       { e.preventDefault(); smartPlayNext(); }
        else if (e.shiftKey && e.key === 'R')         { e.preventDefault(); relisten(); }
        else if (e.shiftKey && e.key === 'H')         { e.preventDefault(); showAnswer(); }
        else if (e.shiftKey && e.key === 'ArrowLeft') { e.preventDefault(); setCurrent(state.cur - 1); }
        else if (e.shiftKey && e.key === 'ArrowRight'){ e.preventDefault(); setCurrent(state.cur + 1); }
    });

    function escapeHtml(s) {
        return String(s ?? '').replace(/[&<>"']/g, c =>
            ({ '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#39;' }[c]));
    }
})();
