/**
 * Test Taking Interface
 * Handles question navigation, progress tracking, and answer saving
 *
 * Keyboard map (ignored while focus is in text/number input, select, textarea,
 * and with Ctrl/Meta/Alt):
 *   1..9, Numpad1..9 -> N-th option of the current question (display order)
 *   0, Numpad0       -> 10th option (Lazarus 1-10 scales)
 *   Backspace / ArrowLeft / Escape -> previous question
 *   ArrowRight / Enter -> next question, only if the current one is answered
 *                         (Enter on the last answered question submits)
 * Dual (Lazarus) cards: a digit fills the first unanswered row (Я, then Партнёр).
 *
 * Time estimate: median of the respondent's own time-per-answer over the last
 * 20 first answers (>= 3 samples, each capped at 90 s); before that the test's
 * declared average (data-estimated-minutes / total questions); else hidden.
 */

(function () {
    'use strict';

    // State
    let currentQuestionIndex = 0;
    let answers = {};
    let questions = [];
    let demographics = {};
    let testStarted = false;
    let formInitialized = false;
    let advanceTimer = null;
    let shownAt = 0;
    let answerDurations = [];
    const HINT_STORAGE_KEY = 'psytest.keyHintDismissed';
    const MAX_SAMPLE_MS = 90000;
    const SAMPLE_WINDOW = 20;
    const MIN_SAMPLES = 3;

    // Initialize on DOM ready
    document.addEventListener('DOMContentLoaded', function () {
        initTestTaking();
    });

    /**
     * Initialize test taking interface
     */
    function initTestTaking() {
        const form = document.getElementById('testForm');
        if (!form) return;

        // Check if demographics section exists
        const demographicsSection = document.getElementById('demographicsSection');
        const startTestBtn = document.getElementById('startTestBtn');

        if (demographicsSection && startTestBtn) {
            // Handle demographics submission
            startTestBtn.addEventListener('click', handleDemographicsSubmit);

            // Allow Enter key to start test
            demographicsSection.addEventListener('keydown', function (e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    handleDemographicsSubmit();
                }
            });

            return; // Don't initialize test taking until demographics are done
        }

        // Initialize test questions (only if no demographics)
        initializeTestQuestions();
    }

    /**
     * Handle demographics form submission
     */
    function handleDemographicsSubmit() {
        if (validateDemographics()) {
            startTest();
        }
    }

    // Auto-advance delay (ms) — gives visual feedback before transitioning
    const AUTO_ADVANCE_DELAY = 300;

    /**
     * Initialize test questions
     */
    function initializeTestQuestions() {
        // Prevent double initialization
        if (formInitialized) return;
        formInitialized = true;

        // Show questions and navigation (needed when no demographics gate)
        const questionsContainer = document.getElementById('questionsContainer');
        if (questionsContainer) {
            questionsContainer.style.display = 'block';
        }
        const testNavigation = document.getElementById('testNavigation');
        if (testNavigation) {
            testNavigation.style.display = 'flex';
        }

        // Get all question cards
        questions = Array.from(document.querySelectorAll('.question-card'));
        if (questions.length === 0) {
            console.error('No questions found!');
            return;
        }

        // Setup navigation buttons
        const prevBtn = document.getElementById('prevBtn');
        const submitBtn = document.getElementById('submitBtn');

        if (prevBtn) {
            prevBtn.addEventListener('click', goToPreviousQuestion);
        }

        // Submit button starts hidden (shown on last question)
        if (submitBtn) {
            submitBtn.style.display = 'none';
        }

        // Show first question
        showQuestion(currentQuestionIndex);

        // Auto-advance on answer change + auto-save
        const form = document.getElementById('testForm');
        if (form) {
            form.addEventListener('change', function (e) {
                if (e.target.name && e.target.name.startsWith('answers[')) {
                    saveAnswer(e.target);
                    scheduleAutoAdvance();
                }
            });
        }

        decorateOptionKeys();
        setupKeyHint();
        updateEstimate();

        // Keyboard navigation (see key map at the top of the file)
        document.addEventListener('keydown', handleKeydown);

        // Form submission
        form?.addEventListener('submit', handleFormSubmit);
    }


    function clearAdvanceTimer() {
        if (advanceTimer !== null) {
            clearTimeout(advanceTimer);
            advanceTimer = null;
        }
    }

    function isTypingTarget(el) {
        if (!el || !el.tagName) return false;
        const tag = el.tagName.toLowerCase();
        if (tag === 'select' || tag === 'textarea') return true;
        if (tag === 'input') {
            const type = (el.getAttribute('type') || 'text').toLowerCase();
            return !['radio', 'checkbox', 'button', 'submit'].includes(type);
        }
        return el.isContentEditable === true;
    }

    function isCurrentQuestionAnswered() {
        const card = questions[currentQuestionIndex];
        if (!card) return false;
        const names = new Set();
        card.querySelectorAll('input[type="radio"]').forEach(function (r) { names.add(r.name); });
        if (names.size === 0) return false;
        let all = true;
        names.forEach(function (name) {
            if (!card.querySelector('input[type="radio"][name="' + name + '"]:checked')) all = false;
        });
        return all;
    }

    /** Radios of the group a digit should act on (first unanswered row, else first row). */
    function keyTargetGroup(card) {
        const names = [];
        card.querySelectorAll('input[type="radio"]').forEach(function (r) {
            if (names.indexOf(r.name) === -1) names.push(r.name);
        });
        const pick = names.find(function (name) {
            return !card.querySelector('input[type="radio"][name="' + name + '"]:checked');
        }) || names[0];
        if (!pick) return [];
        return Array.from(card.querySelectorAll('input[type="radio"]')).filter(function (r) {
            return r.name === pick;
        });
    }

    function digitFromEvent(e) {
        if (/^[0-9]$/.test(e.key)) return parseInt(e.key, 10);
        const m = /^Numpad([0-9])$/.exec(e.code || '');
        return m ? parseInt(m[1], 10) : null;
    }

    function handleKeydown(e) {
        if (e.ctrlKey || e.metaKey || e.altKey) return;
        if (!questions.length || !formInitialized) return;
        if (isTypingTarget(e.target)) return;

        const form = document.getElementById('testForm');
        const digit = digitFromEvent(e);

        if (digit !== null) {
            const card = questions[currentQuestionIndex];
            if (!card) return;
            const radios = keyTargetGroup(card);
            const idx = digit === 0 ? 9 : digit - 1;
            if (radios[idx]) {
                e.preventDefault();
                dismissKeyHint();
                if (!radios[idx].checked) {
                    radios[idx].checked = true;
                    radios[idx].dispatchEvent(new Event('change', { bubbles: true }));
                } else {
                    // Same option pressed again: nothing new to save, just move on.
                    scheduleAutoAdvance();
                }
            }
            return;
        }

        if (e.key === 'Escape' || e.key === 'ArrowLeft' || e.key === 'Backspace') {
            e.preventDefault();
            goToPreviousQuestion();
            return;
        }

        const onControl = e.target && e.target.closest && e.target.closest('button, a');
        if (e.key === 'ArrowRight' && isCurrentQuestionAnswered()) {
            e.preventDefault();
            clearAdvanceTimer();
            goToNextQuestion();
            return;
        }
        if (e.key === 'Enter' && !onControl) {
            if (currentQuestionIndex === questions.length - 1) {
                const submitBtn = document.getElementById('submitBtn');
                if (submitBtn && submitBtn.style.display !== 'none' && isCurrentQuestionAnswered()) {
                    e.preventDefault();
                    form?.requestSubmit();
                }
            } else if (isCurrentQuestionAnswered()) {
                e.preventDefault();
                clearAdvanceTimer();
                goToNextQuestion();
            }
        }
    }

    /** Quiet key badges «1», «2»… on single-choice options (aria-hidden, hidden on touch via CSS). */
    function decorateOptionKeys() {
        questions.forEach(function (card) {
            if (card.classList.contains('question-card--dual')) return;
            card.querySelectorAll('.answer-option').forEach(function (label, i) {
                if (i > 9 || label.querySelector('.answer-option__key')) return;
                const badge = document.createElement('span');
                badge.className = 'answer-option__key';
                badge.setAttribute('aria-hidden', 'true');
                badge.textContent = String(i === 9 ? 0 : i + 1);
                label.appendChild(badge);
            });
        });
    }

    function storageGet(key) {
        try { return window.localStorage.getItem(key); } catch (err) { return null; }
    }
    function storageSet(key, value) {
        try { window.localStorage.setItem(key, value); } catch (err) { /* storage unavailable */ }
    }

    function keyHintText() {
        const card = questions[0];
        const count = card ? (card.classList.contains('question-card--dual')
            ? 10 : card.querySelectorAll('.answer-option').length) : 3;
        let keys;
        if (count <= 4) {
            keys = Array.from({ length: count }, function (_, i) { return String(i + 1); }).join(', ');
        } else {
            keys = count > 9 ? '1–9, 0' : '1–' + count;
        }
        return 'Отвечать можно клавишами ' + keys + ' · назад — Esc';
    }

    let hintDismissedThisPage = false;
    function setupKeyHint() {
        const hint = document.getElementById('keyHint');
        if (!hint || storageGet(HINT_STORAGE_KEY) === '1') return;
        const text = document.createElement('span');
        text.textContent = keyHintText();
        const close = document.createElement('button');
        close.type = 'button';
        close.className = 'test-key-hint__close';
        close.textContent = 'Понятно';
        close.addEventListener('click', dismissKeyHint);
        hint.appendChild(text);
        hint.appendChild(close);
        updateKeyHintVisibility();
    }

    function updateKeyHintVisibility() {
        const hint = document.getElementById('keyHint');
        if (!hint || hint.childNodes.length === 0) return;
        hint.hidden = hintDismissedThisPage || currentQuestionIndex !== 0;
    }

    function dismissKeyHint() {
        const hint = document.getElementById('keyHint');
        if (!hint || hintDismissedThisPage) return;
        hintDismissedThisPage = true;
        hint.hidden = true;
        storageSet(HINT_STORAGE_KEY, '1');
    }

    function median(values) {
        const sorted = values.slice().sort(function (a, b) { return a - b; });
        const mid = Math.floor(sorted.length / 2);
        return sorted.length % 2 ? sorted[mid] : (sorted[mid - 1] + sorted[mid]) / 2;
    }

    /** «Осталось примерно N мин»: updates once per answer, never shows seconds. */
    function updateEstimate() {
        const el = document.getElementById('progressEstimate');
        if (!el || typeof TEST_CONFIG === 'undefined') return;

        const total = TEST_CONFIG.totalQuestions;
        const answered = new Set(Object.keys(answers).map(function (k) { return k.replace(/_.*$/, ''); })).size;
        const remaining = total - answered;
        if (remaining <= 0 || total <= 0) {
            el.hidden = true;
            return;
        }

        let perQuestionMs = null;
        if (answerDurations.length >= MIN_SAMPLES) {
            perQuestionMs = median(answerDurations);
        } else {
            const declared = parseFloat(el.dataset.estimatedMinutes || '0');
            if (declared > 0) perQuestionMs = (declared * 60000) / total;
        }
        if (perQuestionMs === null || !isFinite(perQuestionMs) || perQuestionMs <= 0) {
            el.hidden = true;
            return;
        }

        const minutes = Math.round((remaining * perQuestionMs) / 60000);
        el.textContent = minutes < 1 ? 'Осталось меньше минуты' : 'Осталось примерно ' + minutes + ' мин';
        el.hidden = false;
    }

    /**
     * Validate demographics form
     */
    function validateDemographics() {
        const genderOptions = document.querySelectorAll('input[name="demographics[gender]"]');

        let genderSelected = false;
        let selectedGender = '';
        genderOptions.forEach(option => {
            if (option.checked) {
                genderSelected = true;
                selectedGender = option.value;
            }
        });

        if (!genderSelected) {
            alert('Пожалуйста, выберите ваш пол');
            return false;
        }

        // Save demographics
        demographics = {
            gender: selectedGender,
        };

        // Collect age if present
        const ageInput = document.getElementById('demographicsAge');
        if (ageInput && ageInput.value) {
            const age = parseInt(ageInput.value, 10);
            if (!isNaN(age)) {
                demographics.age = age;
            }
        }

        return true;
    }

    /**
     * Start the test (hide demographics, show questions)
     */
    function startTest() {
        testStarted = true;

        // Hide demographics section
        const demographicsSection = document.getElementById('demographicsSection');
        if (demographicsSection) {
            demographicsSection.style.display = 'none';
        }

        // Update question texts based on gender (if gender variants available)
        if (demographics.gender) {
            updateQuestionTextsForGender(demographics.gender);
        }

        // Show questions container
        const questionsContainer = document.getElementById('questionsContainer');
        if (questionsContainer) {
            questionsContainer.style.display = 'block';
        }

        // Show navigation buttons
        const testNavigation = document.getElementById('testNavigation');
        if (testNavigation) {
            testNavigation.style.display = 'flex';
        }

        // Initialize questions (this will set up event listeners)
        initializeTestQuestions();

        // Scroll to top
        window.scrollTo({ top: 0, behavior: 'smooth' });
    }

    /**
     * Update question texts based on selected gender
     */
    function updateQuestionTextsForGender(gender) {
        const questionCards = document.querySelectorAll('.question-card');

        questionCards.forEach(card => {
            const textElement = card.querySelector('.question-text');
            const questionId = card.dataset.questionId;

            // Get gender-specific text from data attributes
            const maleText = card.dataset.textMale;
            const femaleText = card.dataset.textFemale;

            if (maleText && femaleText) {
                const selectedText = gender === 'male' ? maleText : femaleText;
                textElement.textContent = `${questionId}. ${selectedText}`;
            }
        });

        console.log(`Updated question texts for gender: ${gender}`);
    }

    /**
     * Show a specific question
     */
    function showQuestion(index) {
        if (!questions || questions.length === 0) return;

        questions.forEach((card, i) => {
            card.style.display = i === index ? 'block' : 'none';
        });

        updateNavigation();
        updateProgress();
        shownAt = Date.now();
        updateKeyHintVisibility();

        // Keep the current reading position when moving between questions.
        // On mobile, jumping to the page header made each answer require a
        // second scroll back to the answer scale.
    }

    /**
     * Go to previous question
     */
    function goToPreviousQuestion() {
        clearAdvanceTimer();
        if (currentQuestionIndex > 0 && questions.length > 0) {
            currentQuestionIndex--;
            showQuestion(currentQuestionIndex);
        }
    }

    /**
     * Go to next question
     */
    function goToNextQuestion() {
        if (!questions || questions.length === 0) return;

        // Validate current question has an answer
        const currentCard = questions[currentQuestionIndex];
        if (!currentCard) return;

        const questionId = currentCard.getAttribute('data-question-id');
        const selectedAnswer = document.querySelector(
            `input[name="answers[${questionId}]"]:checked`
        );

        if (!selectedAnswer) {
            // Highlight that an answer is required
            currentCard.classList.add('error');
            setTimeout(() => currentCard.classList.remove('error'), 2000);
            return;
        }

        if (currentQuestionIndex < questions.length - 1) {
            currentQuestionIndex++;
            showQuestion(currentQuestionIndex);
        }
    }

    /**
     * Update navigation buttons visibility
     */
    function updateNavigation() {
        if (!questions || questions.length === 0) return;

        const prevBtn = document.getElementById('prevBtn');
        const submitBtn = document.getElementById('submitBtn');
        const testNavigation = document.getElementById('testNavigation');
        const hasPreviousAction = currentQuestionIndex > 0;
        const hasSubmitAction = currentQuestionIndex >= questions.length - 1;
        const hasVisibleAction = hasPreviousAction || hasSubmitAction;

        if (prevBtn) {
            prevBtn.style.visibility = hasPreviousAction ? 'visible' : 'hidden';
        }

        // Submit button only visible on the last question
        if (submitBtn) {
            submitBtn.style.display = hasSubmitAction ? 'inline-flex' : 'none';
        }

        if (testNavigation) {
            testNavigation.style.display = hasVisibleAction ? 'flex' : 'none';
        }
    }

    /**
     * Update progress bar
     */
    function updateProgress() {
        const progressFill = document.getElementById('progressFill');
        const progressText = document.getElementById('progressText');

        if (!progressFill || !progressText) return;

        // Count unique answered question ids. For dual questions the keys are
        // like "1_self"/"1_partner" — collapse to the numeric question id so
        // a fully-answered dual question counts as 1, not 2.
        const answeredIds = new Set();
        Object.keys(answers).forEach(function (key) {
            const m = key.match(/^(\d+)/);
            if (m) answeredIds.add(m[1]);
        });
        const answeredCount = answeredIds.size;
        const totalQuestions = typeof TEST_CONFIG !== 'undefined' ? TEST_CONFIG.totalQuestions : questions.length;
        const percentage = totalQuestions > 0 ? (answeredCount / totalQuestions) * 100 : 0;

        progressFill.style.width = percentage + '%';
        progressText.textContent = `${answeredCount} / ${totalQuestions}`;
    }

    /**
     * Save an answer
     */
    function saveAnswer(input) {
        // Supports both "answers[1]" (single) and "answers[1_self]" / "answers[1_partner]" (dual)
        const questionId = input.name.match(/answers\[([^\]]+)\]/);
        if (!questionId) return;

        const value = input.value; // Keep as string (0,1,2,3)
        const isFirstAnswer = !Object.prototype.hasOwnProperty.call(answers, questionId[1]);
        answers[questionId[1]] = value;
        if (isFirstAnswer && shownAt > 0) {
            answerDurations.push(Math.min(Date.now() - shownAt, MAX_SAMPLE_MS));
            if (answerDurations.length > SAMPLE_WINDOW) answerDurations.shift();
        }
        updateProgress();
        updateEstimate();

        // Visual feedback
        const card = input.closest('.question-card');
        if (card) {
            card.classList.add('answered');
        }

        // Auto-save to server (debounced)
        debounceSave();
    }

    /**
     * Auto-advance to next question after a short delay.
     * On the last question, show the Submit button instead.
     */
    function scheduleAutoAdvance() {
        if (!questions || questions.length === 0) return;

        // For dual-scale questions (two radio groups: _self + _partner),
        // only advance once ALL required radios in the card are answered.
        const currentCard = questions[currentQuestionIndex];
        if (currentCard && currentCard.classList.contains('question-card--dual')) {
            const requiredRadios = currentCard.querySelectorAll('input[type="radio"][required]');
            const answeredNames = new Set();
            requiredRadios.forEach(function (r) {
                if (r.checked) answeredNames.add(r.name);
            });
            const requiredNames = new Set();
            requiredRadios.forEach(function (r) {
                requiredNames.add(r.name);
            });
            if (answeredNames.size < requiredNames.size) {
                return; // ждём остальные ответы
            }
        }

        if (currentQuestionIndex >= questions.length - 1) {
            // On last question — show submit button
            const submitBtn = document.getElementById('submitBtn');
            if (submitBtn) {
                submitBtn.style.display = 'inline-flex';
                submitBtn.focus();
            }
            return;
        }

        clearAdvanceTimer();
        advanceTimer = setTimeout(function () {
            advanceTimer = null;
            if (currentQuestionIndex < questions.length - 1) {
                currentQuestionIndex++;
                showQuestion(currentQuestionIndex);
            }
        }, AUTO_ADVANCE_DELAY);
    }

    /**
     * Debounced auto-save
     */
    let saveTimeout = null;
    function debounceSave() {
        if (saveTimeout) {
            clearTimeout(saveTimeout);
        }

        saveTimeout = setTimeout(function () {
            saveAnswersToServer();
        }, 1000);
    }

    /**
     * Save answers to server
     */
    async function saveAnswersToServer() {
        const answeredCount = Object.keys(answers).length;
        if (answeredCount === 0) return;

        if (typeof TEST_CONFIG === 'undefined') return;

        const payload = {
            session_token: TEST_CONFIG.sessionToken,
            answers: answers,
        };

        // Include demographics if collected
        if (Object.keys(demographics).length > 0) {
            payload.demographics = demographics;
        }

        try {
            const response = await fetch(`${TEST_CONFIG.basePath}/test/${TEST_CONFIG.slug}/save`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': TEST_CONFIG.csrfToken,
                },
                body: JSON.stringify(payload),
            });

            const result = await response.json();

            if (!result.success) {
                console.warn('Auto-save failed:', result.error);
            }
        } catch (error) {
            console.warn('Auto-save error:', error);
        }
    }

    /**
     * Handle form submission
     */
    async function handleFormSubmit(e) {
        e.preventDefault();

        if (!questions || questions.length === 0) return;

        // Show loading state
        const submitBtn = document.getElementById('submitBtn');
        if (submitBtn) {
            submitBtn.disabled = true;
            submitBtn.textContent = 'Обработка...';
        }

        // Inject demographics as hidden inputs into the form before submission
        injectDemographicsInputs(e.target);

        // Save final answers to server
        await saveAnswersToServer();

        // Small delay to ensure save completes
        await new Promise(resolve => setTimeout(resolve, 100));

        // Submit form
        e.target.removeEventListener('submit', handleFormSubmit);
        e.target.submit();
    }

    /**
     * Inject demographics as hidden inputs into the form
     */
    function injectDemographicsInputs(form) {
        if (Object.keys(demographics).length === 0) return;

        // Remove previously injected inputs (in case of re-submission)
        form.querySelectorAll('input[data-demographic]').forEach(el => el.remove());

        if (demographics.gender) {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'demographics[gender]';
            input.value = demographics.gender;
            input.setAttribute('data-demographic', 'true');
            form.appendChild(input);
        }

        if (demographics.age) {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'demographics[age]';
            input.value = demographics.age;
            input.setAttribute('data-demographic', 'true');
            form.appendChild(input);
        }
    }

})();
