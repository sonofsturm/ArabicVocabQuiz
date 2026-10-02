<?php
    header('Content-Type: text/html; charset=UTF-8');
session_start();

$isLoggedIn = isset($_SESSION['user_id']);

// Save mode into session
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mode'])) {
    $_SESSION['mode'] = $_POST['mode'];
}

// Now read from session instead of POST
$book = $_SESSION['book'] ?? null;
$lesson = $_SESSION['lesson'] ?? null;
$mode = $_SESSION['mode'] ?? null;
$wordGroup = $_SESSION['word_group'] ?? 'all';

$userId = $_SESSION['user_id'] ?? null;

$lastWordId = $_SESSION['last_word_id'] ?? 0;

if (!$book || !$lesson || !$mode) {
    die("Missing selection. Go back and try again.");
}

// Example DB connection
include_once('db.php');

try {
    $conn = connectToDB();
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}

$newWordsToday = 0;

if ($userId) {

    $stmtDaily = $conn->prepare("
        SELECT new_words_seen
        FROM user_daily_stats
        WHERE user_id = :user_id
        AND study_date = CURDATE()
    ");

    $stmtDaily->execute([
        ':user_id' => $userId
    ]);

    $newWordsToday =
        $stmtDaily->fetchColumn() ?: 0;
}

$feedback = null;

$sessionComplete = false;

    
// ==========================
// Flashcard flip handling
// ==========================
if (!isset($_SESSION['show_answer'])) {
    $_SESSION['show_answer'] = false;
}

if ($mode === 'flashcards' && isset($_POST['flip'])) {
    $_SESSION['show_answer'] = true;
}

// Reset flip AND direction when moving to next word
if (isset($_POST['next'])) {
    $_SESSION['show_answer'] = false;
    unset($_SESSION['direction']); // important
}

// Track flashcard correct
if (
    $isLoggedIn &&
    isset($_POST['correct']) &&
    isset($_SESSION['current_word'])
) {

    $wordId = $_SESSION['current_word']['id'];

    $stmt = $conn->prepare("
        UPDATE user_word_progress
    	SET
    times_correct = times_correct + 1,

    learning_step = LEAST(learning_step + 1, 3),

    repetitions = CASE
        WHEN learning_step + 1 >= 3
             AND repetitions = 0
        THEN 1
        ELSE repetitions
    END,

    interval_days = CASE
        WHEN learning_step + 1 >= 3
             AND interval_days = 0
        THEN 1
        ELSE interval_days
    END,

    next_review = CASE

    WHEN learning_step < 3
         AND learning_step + 1 >= 3
    THEN DATE_ADD(CURDATE(), INTERVAL 1 DAY)

    WHEN learning_step = 3
    THEN DATE_ADD(CURDATE(), INTERVAL 1 DAY)

    ELSE next_review

END
        WHERE user_id = :user_id
        AND word_id = :word_id
        AND mode = :mode
    ");

    $stmt->execute([
        ':user_id' => $_SESSION['user_id'],
        ':word_id' => $wordId,
        ':mode' => $mode
    ]);
}

// Track flashcard incorrect
if (
    $isLoggedIn &&
    isset($_POST['incorrect']) &&
    isset($_SESSION['current_word'])
) {

    $wordId = $_SESSION['current_word']['id'];

    $stmt = $conn->prepare("
        UPDATE user_word_progress
        SET
            times_incorrect = times_incorrect + 1,
            learning_step = CASE
                WHEN learning_step < 3
                THEN GREATEST(learning_step - 1, 0)
                ELSE 3
            END
        WHERE user_id = :user_id
        AND word_id = :word_id
        AND mode = :mode
    ");

    $stmt->execute([
        ':user_id' => $_SESSION['user_id'],
        ':word_id' => $wordId,
        ':mode' => $mode
    ]);
}


// FIRST: Handle Next Word
if (
    !$sessionComplete &&
    (
        !isset($_SESSION['current_word']) ||
        isset($_POST['next']) ||
        isset($_POST['correct']) ||
        isset($_POST['incorrect'])
    )
) {

$userId = $_SESSION['user_id'] ?? null;
    
    if ($wordGroup === 'all') {

    $sql = "
        SELECT
            w.english,
            w.arabic_diacritics,
            w.arabic_shadda,
            w.book,
            w.lesson,
            w.id,

            COALESCE(p.learning_step, -1) AS learning_step,

            CASE
                WHEN p.learning_step IS NULL THEN 1
                ELSE 0
            END AS is_new

    FROM words w

    LEFT JOIN user_word_progress p
        ON p.word_id = w.id
        AND p.user_id = :user_id
        AND p.mode = :mode
        WHERE w.book = :book
            AND w.lesson = :lesson
           
            AND (
                p.word_id IS NULL
                OR p.learning_step BETWEEN 0 AND 2
                OR (
                    p.learning_step = 3
                    AND p.next_review IS NOT NULL
                    AND p.next_review <= NOW()
                )
            )
        ORDER BY

		CASE

                    WHEN p.learning_step = 3
                         AND p.next_review IS NOT NULL
                         AND p.next_review <= NOW()
                        THEN 1

                    WHEN p.learning_step IS NULL
                        THEN 1

                    WHEN p.learning_step BETWEEN 0 AND 2
                        THEN 1

                    ELSE 2

                END,

        RAND()

        LIMIT 1
    ";

    if ($newWordsToday >= 20) {

    $sql = str_replace(
        "ORDER BY",
        "AND p.learning_step IS NOT NULL ORDER BY",
        $sql
    );
}

    $stmt = $conn->prepare($sql);

    $stmt->execute([
    ':user_id' => $userId,
    ':mode' => $mode,
    ':book' => $book,
    ':lesson' => $lesson
            

]);

} else {

    $sql = "
        SELECT
            w.english,
            w.arabic_diacritics,
            w.arabic_shadda,
            w.book,
            w.lesson,
            w.id,

            COALESCE(p.learning_step, -1) AS learning_step,

            CASE
                WHEN p.learning_step IS NULL THEN 1
                ELSE 0
            END AS is_new

        FROM words w

        LEFT JOIN user_word_progress p
            ON p.word_id = w.id
            AND p.user_id = :user_id
            AND p.mode = :mode

        WHERE w.book = :book
        AND w.lesson = :lesson
        AND w.word_group = :word_group
        
        AND (
            p.word_id IS NULL
            OR p.learning_step BETWEEN 0 AND 2
            OR (
                p.learning_step = 3
                AND p.next_review IS NOT NULL
                AND p.next_review <= NOW()
            )
        )

        ORDER BY

            CASE

                WHEN p.learning_step = 3
                     AND p.next_review IS NOT NULL
                     AND p.next_review <= NOW()
                    THEN 1

                WHEN p.learning_step IS NULL
                    THEN 1

                WHEN p.learning_step BETWEEN 0 AND 2
                    THEN 1

                ELSE 2

            END,

        RAND()

        LIMIT 1
    ";

    $stmt = $conn->prepare($sql);

    $stmt->execute([
        ':user_id' => $userId,
        ':mode' => $mode,
        ':book' => $book,
        ':lesson' => $lesson,
        ':word_group' => $wordGroup

    ]);
}

$_SESSION['current_word'] = $stmt->fetch();

if ($_SESSION['current_word'] === false) {
    $_SESSION['current_word'] = null;
}

if (
    $userId &&
    $_SESSION['current_word'] &&
    $_SESSION['current_word']['learning_step'] == -1
) {

    $stmtDaily = $conn->prepare("
        INSERT INTO user_daily_stats
        (
            user_id,
            study_date,
            new_words_seen
        )
        VALUES
        (
            :user_id,
            CURDATE(),
            1
        )
        ON DUPLICATE KEY UPDATE
        new_words_seen = new_words_seen + 1
    ");

    $stmtDaily->execute([
        ':user_id' => $userId
    ]);

    $newWordsToday++;
}

if ($_SESSION['current_word']) {

    $_SESSION['last_word_id'] =
        $_SESSION['current_word']['id'];
}
    
    if ($userId && $_SESSION['current_word']) {

    $wordId = $_SESSION['current_word']['id'];

    $stmt = $conn->prepare("
        INSERT INTO user_word_progress
        (
            user_id,
            word_id,
            arabic_word,
            mode,
            times_seen,
            last_seen
        )
        VALUES
        (
            :user_id,
            :word_id,
            :arabic_word,
            :mode,
            1,
            NOW()
        )
        ON DUPLICATE KEY UPDATE
            times_seen = times_seen + 1,
            last_seen = NOW()
    ");

  $stmt->execute([
    ':user_id' => $userId,
    ':word_id' => $wordId,
    ':arabic_word' => $_SESSION['current_word']['arabic_diacritics'],
    ':mode' => $mode
]);
}
 // ======================================
 // RESET FLASHCARD STATE
 // Applies to both logged-in users
 // and guest users when a new card is loaded.
// ======================================

    $_SESSION['show_answer'] = false;
    unset($_SESSION['direction']);
}

// Always use session word
$word = $_SESSION['current_word'] ?? null;

if ($mode === 'flashcards' && !isset($_SESSION['direction'])) {
    $_SESSION['direction'] = rand(0, 1) ? 'arabic_to_english' : 'english_to_arabic';
}

// Recompute question/answer AFTER word is set
// Recompute question/answer AND question class (ONE source of truth)
$direction = $_SESSION['direction'] ?? 'english_to_arabic';

if ($word) {

    switch ($mode) {

        case 'shadda':
            $question = $word['english'];
            $answer   = $word['arabic_shadda'];
            $question_class = 'english-text red-text';
            break;

        case 'diacritics':
            $question = $word['english'];
            $answer   = $word['arabic_diacritics'];
            $question_class = 'english-text red-text';
            break;

        case 'flashcards':
            if ($direction === 'arabic_to_english') {
                $question = $word['arabic_diacritics'];
                $answer   = $word['english'];
                $question_class = 'arabic-text green-text';
            } else {
                $question = $word['english'];
                $answer   = $word['arabic_diacritics'];
                $question_class = 'english-text red-text';
            }
            break;
    }

}

// SECOND: Handle answer submission (AFTER answer is defined)
if (isset($_POST['submit_answer'])) {
    $user_answer = trim($_POST['answer']);

    if (trim($user_answer) === trim($answer)) {

    $feedback = "Correct!";

    if ($isLoggedIn) {

        $stmt = $conn->prepare("
            UPDATE user_word_progress
           SET
    times_correct = times_correct + 1,

    learning_step = LEAST(learning_step + 1, 3),

    repetitions = CASE
        WHEN learning_step + 1 >= 3
             AND repetitions = 0
        THEN 1
        ELSE repetitions
    END,

    interval_days = CASE
        WHEN learning_step + 1 >= 3
             AND interval_days = 0
        THEN 1
        ELSE interval_days
    END,

next_review = CASE

    WHEN learning_step < 3
         AND learning_step + 1 >= 3
    THEN DATE_ADD(CURDATE(), INTERVAL 1 DAY)

    WHEN learning_step = 3
    THEN DATE_ADD(CURDATE(), INTERVAL 1 DAY)

    ELSE next_review

END
            WHERE user_id = :user_id
            AND word_id = :word_id
            AND mode = :mode
        ");

        $stmt->execute([
            ':user_id' => $_SESSION['user_id'],
            ':word_id' => $word['id'],
            ':mode' => $mode
        ]);
    }

} else {

    $feedback = "Incorrect";

    if ($isLoggedIn) {

        $stmt = $conn->prepare("
            UPDATE user_word_progress
            SET
    			times_incorrect = times_incorrect + 1,
    			learning_step = CASE
                    WHEN learning_step < 3
                    THEN GREATEST(learning_step - 1, 0)
                    ELSE 3
                END
            WHERE user_id = :user_id
            AND word_id = :word_id
            AND mode = :mode
        ");

        $stmt->execute([
            ':user_id' => $_SESSION['user_id'],
            ':word_id' => $word['id'],
            ':mode' => $mode
        ]);
    }
}
}

// ======================================
// STUDY QUEUE COUNTERS
// ======================================
// Counts are limited to the currently
// selected Book, Lesson and Word Group.
//
// Reviews Due:
//     learning_step = 3
//     next_review <= NOW()
//
// Learning Words:
//     learning_step 0-2
//
// New Words:
//     no progress record yet
// ======================================

$reviewCount = 0;
$learningCount = 0;
$newCount = 0;

if ($userId) {

    // ======================================
    // COUNT REVIEW WORDS DUE
    // ======================================

    $reviewSql = "
        SELECT COUNT(*)
        FROM user_word_progress p
        INNER JOIN words w
            ON w.id = p.word_id
        WHERE p.user_id = :user_id
        AND p.mode = :mode
        AND w.book = :book
        AND w.lesson = :lesson
        AND p.learning_step = 3
        AND p.next_review IS NOT NULL
        AND p.next_review <= NOW()
    ";

    if ($wordGroup !== 'all') {
        $reviewSql .= " AND w.word_group = :word_group";
    }

    $stmt = $conn->prepare($reviewSql);

    $params = [
        ':user_id' => $userId,
        ':mode'    => $mode,
        ':book'    => $book,
        ':lesson'  => $lesson
    ];

    if ($wordGroup !== 'all') {
        $params[':word_group'] = $wordGroup;
    }

    $stmt->execute($params);
    $reviewCount = (int)$stmt->fetchColumn();

    // ======================================
    // COUNT LEARNING WORDS
    // ======================================

    $learningSql = "
        SELECT COUNT(*)
        FROM user_word_progress p
        INNER JOIN words w
            ON w.id = p.word_id
        WHERE p.user_id = :user_id
        AND p.mode = :mode
        AND w.book = :book
        AND w.lesson = :lesson
        AND p.learning_step BETWEEN 0 AND 2
    ";

    if ($wordGroup !== 'all') {
        $learningSql .= " AND w.word_group = :word_group";
    }

    $stmt = $conn->prepare($learningSql);

    $params = [
        ':user_id' => $userId,
        ':mode'    => $mode,
        ':book'    => $book,
        ':lesson'  => $lesson
    ];

    if ($wordGroup !== 'all') {
        $params[':word_group'] = $wordGroup;
    }

    $stmt->execute($params);
    $learningCount = (int)$stmt->fetchColumn();

    // ======================================
    // COUNT NEW WORDS
    // ======================================

    $newSql = "
        SELECT COUNT(*)
        FROM words w
        LEFT JOIN user_word_progress p
            ON p.word_id = w.id
            AND p.user_id = :user_id
            AND p.mode = :mode
        WHERE w.book = :book
        AND w.lesson = :lesson
        AND p.word_id IS NULL
    ";

    if ($wordGroup !== 'all') {
        $newSql .= " AND w.word_group = :word_group";
    }

    $stmt = $conn->prepare($newSql);

    $params = [
        ':user_id' => $userId,
        ':mode'    => $mode,
        ':book'    => $book,
        ':lesson'  => $lesson
    ];

    if ($wordGroup !== 'all') {
        $params[':word_group'] = $wordGroup;
    }

    $stmt->execute($params);
$newCount = (int)$stmt->fetchColumn();
}

// ======================================
// SESSION COMPLETE?
// ======================================

$sessionComplete = $userId
    ? (
        $reviewCount === 0 &&
        $learningCount === 0 &&
        $newCount === 0
    )
    : !$_SESSION['current_word'];
    
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Quiz</title>
    <link rel="stylesheet" href="style.css">

</head>
<body>
    <?php include 'header.php'; ?>

<div class="quiz-container">

    <div class="study-info">

        <strong>Book:</strong>
        <?php echo htmlspecialchars($book, ENT_QUOTES, 'UTF-8'); ?>

        <br>

        <strong>Lesson:</strong>
        <?php echo htmlspecialchars($lesson, ENT_QUOTES, 'UTF-8'); ?>

        <br>

        <strong>Group:</strong>
        <?php echo htmlspecialchars($wordGroup, ENT_QUOTES, 'UTF-8'); ?>

        <br>

        <strong>Mode:</strong>
        <?php echo htmlspecialchars($mode, ENT_QUOTES, 'UTF-8'); ?>

    </div>

    <!-- =====================================
     TODAY'S STUDY QUEUE
====================================== -->

<!-- =====================================
     SIDEBAR STUDY QUEUE
====================================== -->

<div class="study-queue-card">

    <h3>Today's Queue</h3>

    <div class="queue-row">
        <span>Reviews Due</span>
        <strong><?php echo $reviewCount; ?></strong>
    </div>

    <div class="queue-row">
        <span>Learning Words</span>
        <strong><?php echo $learningCount; ?></strong>
    </div>

    <div class="queue-row">
        <span>New Words</span>
        <strong><?php echo $newCount; ?></strong>
    </div>

</div>
    
    <br>
      
      <?php if ($sessionComplete): ?>

        <div class="session-complete">

            <h2>🎉 Study Session Complete!</h2>

            <p>
                You've completed all reviews, learning words,
                and new words for this mode.
            </p>

            <p>
                Great work! Come back tomorrow for more reviews or choose a different mode, lesson, or word group to continue studying.
            </p>

        </div>

   <?php elseif (!$sessionComplete && $mode === 'flashcards'): ?>

    <!-- ==========================
         FLASHCARD MODE
    ========================== -->

    <?php if (($_SESSION['direction'] ?? '') === 'arabic_to_english'): ?>
        <p>Translate to <span class="red-text">English</span>:</p>
    <?php else: ?>
        <p>Translate to <span class="green-text">Arabic</span>:</p>
    <?php endif; ?>

    <?php
    $direction = $_SESSION['direction'] ?? 'english_to_arabic';

    if ($direction === 'arabic_to_english') {
        $question_class = 'arabic-text green-text';
    } else {
        $question_class = 'english-text red-text';
    }
    ?>
        <?php if ($direction === 'arabic_to_english'): ?>
            <h2 class="green-text">
                <span class="arabic-text">
                    <?php echo htmlspecialchars($question, ENT_QUOTES, 'UTF-8'); ?>
                </span>
            </h2>
        <?php else: ?>
            <h2 class="red-text english-text">
                <?php echo htmlspecialchars($question, ENT_QUOTES, 'UTF-8'); ?>
            </h2>
        <?php endif; ?>

        <?php if ($_SESSION['show_answer']): ?>
            <?php
                $answer_class = (($_SESSION['direction'] ?? '') === 'arabic_to_english')
                    ? 'english-text red-text'
                    : 'arabic-text green-text';
            ?>

            <?php if ($direction === 'arabic_to_english'): ?>
                <div class="answer-box red-text english-text">
                    <?php echo htmlspecialchars($answer, ENT_QUOTES, 'UTF-8'); ?>
                </div>
			<?php else: ?>
                <div class="answer-box green-text">
                    <span class="arabic-text">
                        <?php echo htmlspecialchars($answer, ENT_QUOTES, 'UTF-8'); ?>
                    </span>
                </div>
			<?php endif; ?>
        <?php endif; ?>

    <form method="POST">

        <?php if (!$_SESSION['show_answer']): ?>

            <button type="submit" name="flip">Show Answer</button>

        <?php else: ?>

            <button type="submit" name="correct">Correct</button>
            <button type="submit" name="incorrect">Incorrect</button>

        <?php endif; ?>

    </form>
        
<?php elseif (!$sessionComplete): ?>

    <!-- ==========================
         QUIZ MODES (UNCHANGED)
    ========================== -->

    <?php if ($mode === 'shadda'): ?>
    <p>
    Please type the Arabic spelling of the word <strong>without full diacritics</strong>, 
    but <strong>include the shadda where appropriate</strong>.
    </p>

<?php elseif ($mode === 'diacritics'): ?>
    <p>
    Please type the Arabic spelling of the word <strong>with full diacritics</strong>.
    </p>
<?php endif; ?>

    <?php $direction = $_SESSION['direction'] ?? 'english_to_arabic';?>

    <?php
        // QUESTION CLASS — DECIDE BY LANGUAGE, NOT DIRECTION
        $question_class = (
            $mode === 'flashcards' && $direction === 'arabic_to_english'
        )
            ? 'arabic-text green-text'
            : 'english-text red-text';
    ?>

      <h2 class="red-text english-text">
        <?php echo htmlspecialchars($question, ENT_QUOTES, 'UTF-8'); ?>
      </h2>

    
    <form method="POST">
        <input type="text" name="answer" required
            class="arabic-text"
            value="<?php echo isset($user_answer) ? htmlspecialchars($user_answer, ENT_QUOTES, 'UTF-8') : ''; ?>">

        <button type="submit" name="submit_answer">Submit</button>
        <button type="submit" name="next" formnovalidate>Next Word</button>
    </form>

    <?php if ($feedback !== null): ?>
        <div class="feedback">
            <p><strong><?php echo $feedback; ?></strong></p>

            <p>Correct spelling:</p>
            <?php $direction = $_SESSION['direction'] ?? 'english_to_arabic'; ?>

               <?php
                    $answer_class = ($direction === 'arabic_to_english')
                        ? 'english-text red-text'
                        : 'arabic-text green-text';
                ?>

                <?php if ($direction === 'arabic_to_english'): ?>
                    <div class="answer-box red-text english-text">
                        <?php echo htmlspecialchars($answer, ENT_QUOTES, 'UTF-8'); ?>
                    </div>
                <?php else: ?>
                    <div class="answer-box green-text">
                        <span class="arabic-text">
                            <?php echo htmlspecialchars($answer, ENT_QUOTES, 'UTF-8'); ?>
                        </span>
                    </div>
                <?php endif; ?>
        </div>
    <?php endif; ?>

<?php endif; ?>
      <a href="index.php?reset=1">← Back to Home</a>  
    </div>
</body>

</html>