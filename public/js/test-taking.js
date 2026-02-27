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

    /**
     * Initialize test questions
     */
    function initializeTestQuestions() {
        // Prevent double initialization
        if (formInitialized) return;
        formInitialized = true;

        // Get all question cards
        questions = Array.from(document.querySelectorAll('.question-card'));
        if (questions.length === 0) {
            console.error('No questions found!');
            return;
        }

        // Setup navigation buttons
        const prevBtn = document.getElementById('prevBtn');
        const nextBtn = document.getElementById('nextBtn');
        const submitBtn = document.getElementById('submitBtn');

        if (prevBtn) {
            prevBtn.addEventListener('click', goToPreviousQuestion);
        }

        if (nextBtn) {
            nextBtn.addEventListener('click', goToNextQuestion);
        }

        if (submitBtn) {
            submitBtn.style.display = 'none';
        }

        // Show first question
        showQuestion(currentQuestionIndex);

        // Auto-save on answer change
        const form = document.getElementById('testForm');
        if (form) {
            form.addEventListener('change', function (e) {
                if (e.target.name && e.target.name.startsWith('answers[')) {
                    saveAnswer(e.target);
                }
            });
        }

        // Keyboard navigation
        document.addEventListener('keydown', function (e) {
            if (e.key === 'ArrowRight' || e.key === 'Enter') {
                e.preventDefault();
                goToNextQuestion();
            } else if (e.key === 'ArrowLeft') {
                e.preventDefault();
                goToPreviousQuestion();
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
        const nextBtn = document.getElementById('nextBtn');
        const submitBtn = document.getElementById('submitBtn');

        if (prevBtn) {
            prevBtn.style.visibility = currentQuestionIndex > 0 ? 'visible' : 'hidden';
        }

        if (nextBtn) {
            if (currentQuestionIndex >= questions.length - 1) {
                nextBtn.style.display = 'none';
                if (submitBtn) {
                    submitBtn.style.display = 'inline-flex';
                }
            } else {
                nextBtn.style.display = 'inline-flex';
                if (submitBtn) {
                    submitBtn.style.display = 'none';
                }
            }
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

        try {
            const response = await fetch(`${TEST_CONFIG.basePath}/test/${TEST_CONFIG.slug}/save`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    session_token: TEST_CONFIG.sessionToken,
                    answers: answers,
                }),
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

        // Save final answers to server
        await saveAnswersToServer();

        // Small delay to ensure save completes
        await new Promise(resolve => setTimeout(resolve, 100));

        // Submit form
        e.target.removeEventListener('submit', handleFormSubmit);
        e.target.submit();
    }

})();
