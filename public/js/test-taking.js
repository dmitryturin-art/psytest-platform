/**
 * Test Taking Interface
 * Handles question navigation, progress tracking, and answer saving
 */

(function() {
    'use strict';

    // State
    let currentQuestionIndex = 0;
    let answers = {};
    let questions = [];
    let demographics = {};
    let questionsPerPage = 1; // Can be increased for batch viewing
    let testStarted = false;

    // Initialize on DOM ready
    document.addEventListener('DOMContentLoaded', function() {
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
            startTestBtn.addEventListener('click', function() {
                if (validateDemographics()) {
                    startTest();
                }
            });
            
            // Allow Enter key to start test
            demographicsSection.addEventListener('keydown', function(e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    if (validateDemographics()) {
                        startTest();
                    }
                }
            });
            
            return; // Don't initialize test taking until demographics are done
        }

        // Get all question cards
        questions = Array.from(document.querySelectorAll('.question-card'));
        if (questions.length === 0) return;

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
        form.addEventListener('change', function(e) {
            if (e.target.name && e.target.name.startsWith('answers[')) {
                saveAnswer(e.target);
            }
        });
        
        // Warn before leaving with unsaved answers
        window.addEventListener('beforeunload', function(e) {
            const answeredCount = Object.keys(answers).length;
            if (answeredCount < questions.length && answeredCount > 0) {
                e.preventDefault();
                e.returnValue = '';
                return '';
            }
        });
        
        // Keyboard navigation
        document.addEventListener('keydown', function(e) {
            if (e.key === 'ArrowRight' || e.key === 'Enter') {
                e.preventDefault();
                goToNextQuestion();
            } else if (e.key === 'ArrowLeft') {
                e.preventDefault();
                goToPreviousQuestion();
            }
        });
    }
    
    /**
     * Show a specific question
     */
    function showQuestion(index) {
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
        if (currentQuestionIndex > 0) {
            currentQuestionIndex--;
            showQuestion(currentQuestionIndex);
        }
    }
    
    /**
     * Go to next question
     */
    function goToNextQuestion() {
        // Validate current question has an answer
        const currentCard = questions[currentQuestionIndex];
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
        const totalQuestions = TEST_CONFIG.totalQuestions;
        const percentage = (answeredCount / totalQuestions) * 100;
        
        progressFill.style.width = percentage + '%';
        progressText.textContent = `${answeredCount} / ${totalQuestions}`;
    }
    
    /**
     * Save an answer
     */
    function saveAnswer(input) {
        const questionId = input.name.match(/answers\[(\d+)\]/)[1];
        const value = input.value === 'true';
        
        answers[questionId] = value;
        
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
        
        saveTimeout = setTimeout(function() {
            saveAnswersToServer();
        }, 1000);
    }
    
    /**
     * Save answers to server
     */
    async function saveAnswersToServer() {
        const answeredCount = Object.keys(answers).length;
        if (answeredCount === 0) return;
        
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
     * Validate demographics form
     */
    function validateDemographics() {
        const genderOptions = document.querySelectorAll('input[name="demographics[gender]"]');
        const ageInput = document.getElementById('demographicsAge');
        
        let genderSelected = false;
        genderOptions.forEach(option => {
            if (option.checked) genderSelected = true;
        });
        
        const age = parseInt(ageInput.value);
        const isValidAge = age >= 14 && age <= 100;
        
        if (!genderSelected) {
            alert('Пожалуйста, выберите ваш пол');
            return false;
        }
        
        if (!isValidAge) {
            if (age < 14) {
                alert('К сожалению, тестирование доступно только с 14 лет');
                return false;
            } else {
                alert('Пожалуйста, укажите корректный возраст (14-100)');
                return false;
            }
        }
        
        // Save demographics
        demographics = {
            gender: document.querySelector('input[name="demographics[gender]"]:checked').value,
            age: age,
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
        
        // Show questions container
        const questionsContainer = document.getElementById('questionsContainer');
        if (questionsContainer) {
            questionsContainer.style.display = 'block';
        }
        
        // Initialize questions
        questions = Array.from(document.querySelectorAll('.question-card'));
        
        // Show first question
        showQuestion(currentQuestionIndex);
        
        // Update progress
        updateProgress();
        
        // Scroll to top
        window.scrollTo({ top: 0, behavior: 'smooth' });
    }

    /**
     * Submit test for scoring
     */
    document.getElementById('testForm')?.addEventListener('submit', async function(e) {
        e.preventDefault();

        const answeredCount = Object.keys(answers).length;
        const totalQuestions = TEST_CONFIG.totalQuestions;

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

        // Save final answers
        await saveAnswersToServer();

        // Submit form normally
        this.submit();
    });
    
})();
