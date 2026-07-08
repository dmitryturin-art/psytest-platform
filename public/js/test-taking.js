/**
 * Test Taking Interface
 * Handles question navigation, progress tracking, and answer saving
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

        // Keyboard navigation: Escape = back, Enter on last = submit
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' || e.key === 'ArrowLeft') {
                e.preventDefault();
                goToPreviousQuestion();
            }
            // Enter only triggers submit on the last question
            if (e.key === 'Enter' && currentQuestionIndex === questions.length - 1) {
                const submitBtn = document.getElementById('submitBtn');
                if (submitBtn && submitBtn.style.display !== 'none') {
                    e.preventDefault();
                    form?.requestSubmit();
                }
            }
        });

        // Form submission
        form?.addEventListener('submit', handleFormSubmit);
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

        // Scroll to top of question
        window.scrollTo({ top: 0, behavior: 'smooth' });
    }

    /**
     * Go to previous question
     */
    function goToPreviousQuestion() {
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

        if (prevBtn) {
            prevBtn.style.visibility = currentQuestionIndex > 0 ? 'visible' : 'hidden';
        }

        // Submit button only visible on the last question
        if (submitBtn) {
            submitBtn.style.display = currentQuestionIndex >= questions.length - 1 ? 'inline-flex' : 'none';
        }
    }

    /**
     * Update progress bar
     */
    function updateProgress() {
        const progressFill = document.getElementById('progressFill');
        const progressText = document.getElementById('progressText');

        if (!progressFill || !progressText) return;

        const answeredCount = Object.keys(answers).length;
        const totalQuestions = typeof TEST_CONFIG !== 'undefined' ? TEST_CONFIG.totalQuestions : questions.length;
        const percentage = totalQuestions > 0 ? (answeredCount / totalQuestions) * 100 : 0;

        progressFill.style.width = percentage + '%';
        progressText.textContent = `${answeredCount} / ${totalQuestions}`;
    }

    /**
     * Save an answer
     */
    function saveAnswer(input) {
        const questionId = input.name.match(/answers\[(\d+)\]/);
        if (!questionId) return;

        const value = input.value; // Keep as string (0,1,2,3)
        answers[questionId[1]] = value;

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

        setTimeout(function () {
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

        const totalQuestions = questions.length;
        const answeredCount = Object.keys(answers).length;

        if (answeredCount < totalQuestions) {
            const confirmed = confirm(
                `Вы ответили на ${answeredCount} из ${totalQuestions} вопросов. ` +
                'Завершить тестирование?'
            );

            if (!confirmed) return;
        }

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
