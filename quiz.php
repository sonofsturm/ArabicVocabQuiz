<?php
    header('Content-Type: text/html; charset=UTF-8');
session_start();

set_exception_handler(function ($e) {
    logAppError(
        get_class($e) . ': ' . $e->getMessage()
        . ' in ' . $e->getFile() . ':' . $e->getLine()
    );
    http_response_code(500);
    echo 'Something went wrong. Please try again in a moment.';
    exit;
});

$isLoggedIn = isset($_SESSION['user_id']);

// Save mode into session
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mode'])) {
    $_SESSION['mode'] = $_POST['mode'];
}

// Now read from session instead of POST
$book = $_SESSION['book'] ?? null;
$lesson = $_SESSION['lesson'] ?? null;
$mode = $_SESSION['mode'] ?? null;
$trackingMode = $mode;
$wordGroup = $_SESSION['word_group'] ?? 'all';

$userId = $_SESSION['user_id'] ?? null;
$owedCard = null;
$recentIds = [];

$lastWordId = $_SESSION['last_word_id'] ?? 0;

if (!$book || !$lesson || !$mode) {
    die("Missing selection. Go back and try again.");
}

// ======================================
// FLASHCARD DIRECTIONAL SRS TRACKING
//
// Users see one "Flashcards" mode,
// but internally we track:
//
//   flashcards_en_ar
//   flashcards_ar_en
//
// as separate SRS queues.
// ======================================

if ($mode === 'flashcards') {

    // Create a direction if one doesn't exist yet
    if (!isset($_SESSION['direction'])) {

        $_SESSION['direction'] =
            rand(0, 1)
                ? 'arabic_to_english'
                : 'english_to_arabic';
    }

    $trackingMode =
        $_SESSION['direction'] === 'arabic_to_english'
            ? 'flashcards_ar_en'
            : 'flashcards_en_ar';
}

// Example DB connection
include_once('db.php');
require_once 'includes/flashcard_stats.php';
require_once 'includes/queue_stats.php';
require_once 'includes/daily_stats.php';
require_once 'includes/review_updates.php';
require_once 'includes/user_progress.php';
require_once 'includes/word_selector.php';
require_once 'includes/rebalance.php';
require_once 'includes/simple_log.php';

try {
    $conn = connectToDB();
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}

$stmtToday = $conn->query("SELECT CURDATE()");
$today = $stmtToday->fetchColumn();

$availableNewCards = 20;
$introducedDirections = 0;
$needsRebalance = false;
$completeWords = 0;
$partialWords = 0;

// Cycle state for Rules 19-23 (rebalancing).
// NULL new-directions-remaining means "no rebalance has happened yet",
// which is treated as unrestricted new-word introduction (Rule 12: the
// constraint only exists after 10 directions have been introduced).
$cycleOwedRemaining = 0;
$cycleNewDirRemaining = null;

// IDs of words that were newly started (first direction introduced)
// during the CURRENT cycle. Needed to correctly classify a word's
// second direction later: per Rule 19c, both directions of a newly
// allowed word belong to the cycle that introduced it - completing
// it must draw on cycle_new_directions_remaining, not the separate
// backlog bucket (cycle_owed_remaining), even though both cases
// look identical to the SQL that finds "an owed direction".
$cycleNewWordIds = [];

if ($userId) {

    $dailyStats = loadDailyStats(
    $conn,
    $userId,
    $book,
    $lesson,
    $wordGroup
);

$availableNewCards =
    $dailyStats['availableNewCards'];


$introducedDirections =
    $dailyStats['introducedDirections'];

$lastReplenished =
    $dailyStats['lastReplenished'];

    if (isset($lastReplenished)) {

$availableNewCards = replenishDailyAllowance(
    $conn,
    $userId,
    $book,
    $lesson,
    $wordGroup,
    $availableNewCards,
    $lastReplenished,
    $today
);
    }
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

if (
    $mode === 'flashcards' &&
    isset($_POST['flip']) &&
    $userId &&
    isset($_SESSION['current_word'])
) {

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

  $isNewWord =
    $_SESSION['current_word']
    && $_SESSION['current_word']['learning_step'] == -1;

if ($isNewWord) {

    $stmtDaily = $conn->prepare("
        UPDATE user_daily_stats
        SET
            available_new_cards =
                GREATEST(available_new_cards - 1, 0),
            introduced_directions_since_eval =
                introduced_directions_since_eval + 1
        WHERE user_id = :user_id
        AND book = :book
        AND lesson = :lesson
        AND word_group = :word_group
    ");

    $stmtDaily->execute([
        ':user_id'    => $userId,
        ':book'       => $book,
        ':lesson'     => $lesson,
        ':word_group' => $wordGroup
    ]);

    $availableNewCards = max(0, $availableNewCards - 1);
    $introducedDirections++;
}

$stmt->execute([
    ':user_id' => $userId,
    ':word_id' => $wordId,
    ':arabic_word' =>
        $_SESSION['current_word']['arabic_diacritics'],
    ':mode' => $trackingMode
]);
}
// Track flashcard correct
if (
    $isLoggedIn &&
    isset($_POST['correct']) &&
    is_array($_SESSION['current_word'] ?? null)
) {

    $wordId = $_SESSION['current_word']['id'];

    applyCorrectAnswer(
        $conn,
        $_SESSION['user_id'],
        $wordId,
        $trackingMode
    );
}

// Track flashcard incorrect
if (
    $isLoggedIn &&
    isset($_POST['incorrect']) &&
    is_array($_SESSION['current_word'] ?? null)
) {

    $wordId = $_SESSION['current_word']['id'];

    applyIncorrectAnswer(
        $conn,
        $_SESSION['user_id'],
        $wordId,
        $trackingMode
    );
}


// FIRST: Handle Next Word
if (
    !$sessionComplete &&
    shouldLoadNewWord()
) {

    // Randomize flashcard direction for the next card

if ($mode === 'flashcards') {

    $_SESSION['direction'] =
        rand(0, 1)
            ? 'arabic_to_english'
            : 'english_to_arabic';

    $trackingMode =
        $_SESSION['direction'] === 'arabic_to_english'
            ? 'flashcards_ar_en'
            : 'flashcards_en_ar';
}

$userId = $_SESSION['user_id'] ?? null;

if ($mode === 'flashcards' && $userId) {

    $recentIds = getRecentIdsForSpacing();

    $owedCard = getOwedCard(
        $conn,
        $userId,
        $book,
        $lesson,
        $wordGroup,
        $recentIds
    );
    }
    
// --------------------------------------------------------
// RULE 12: the first 10 newly introduced directions carry no
// capacity evaluation - owed and new are both freely eligible.
//
// RULE 12c: from the 10th introduced direction onward, every
// subsequent card re-evaluates live. A new word may only be
// introduced when doing so still leaves enough of today's
// budget to finish paying off every direction currently owed
// (Rule 17) - this is what makes 0 owed directions by the time
// today's budget is exhausted a guarantee, not a probability.
// --------------------------------------------------------

$continuousModeActive =
    hasContinuousRebalanceStarted($introducedDirections);

$owedCount = 0;

if ($mode === 'flashcards' && $userId) {

    $stats = getFlashcardStats(
        $conn,
        $userId,
        $book,
        $lesson,
        $wordGroup
    );

    // Each Partial Word is exactly one Owed Direction (Rule 14).
    $owedCount = $stats['partial'];
}

$newWordIntroductionSafe = isNewWordIntroductionSafe(
    $availableNewCards,
    $owedCount
);

$showOwed = shouldShowOwedCard(
    $owedCard,
    $mode,
    $userId,
    $availableNewCards,
    $continuousModeActive,
    $newWordIntroductionSafe
);

if ($showOwed) {

    $trackingMode = assignOwedCard($owedCard);

} else {

    $_SESSION['current_word'] = getNextStudyCard(
        $conn,
        $userId,
        $trackingMode,
        $book,
        $lesson,
        $wordGroup,
        $availableNewCards,
        $continuousModeActive,
        $newWordIntroductionSafe,
        $recentIds
    );
}

if (
    isset($_SESSION['current_word']) &&
    $_SESSION['current_word'] === false &&
    $mode === 'flashcards'
) {

    // Try the opposite direction before giving up - a card may
    // simply not exist in the direction we happened to roll,
    // while the other direction still has something to show.
    $_SESSION['direction'] =
        $_SESSION['direction'] === 'arabic_to_english'
            ? 'english_to_arabic'
            : 'arabic_to_english';

    $trackingMode =
        $_SESSION['direction'] === 'arabic_to_english'
            ? 'flashcards_ar_en'
            : 'flashcards_en_ar';

    $_SESSION['current_word'] = getNextStudyCard(
        $conn,
        $userId,
        $trackingMode,
        $book,
        $lesson,
        $wordGroup,
        $availableNewCards,
        $continuousModeActive,
        $newWordIntroductionSafe,
        $recentIds
    );
}

if ($_SESSION['current_word'] === false) {
    // Nothing left to show right now - today's budget and/or this
    // cycle's allowances are exhausted, and no reviews or learning
    // cards are currently due. That's a legitimate "nothing more
    // today" state (Rule 36), not an error.
    $_SESSION['current_word'] = null;
}


if ($_SESSION['current_word'] === false) {
    $_SESSION['current_word'] = null;
}

if ($_SESSION['current_word']) {

    $_SESSION['last_word_id'] =
        $_SESSION['current_word']['id'];

    if (!isset($_SESSION['recent_word_ids']) || !is_array($_SESSION['recent_word_ids'])) {
        $_SESSION['recent_word_ids'] = [];
    }

    $_SESSION['recent_word_ids'][] = $_SESSION['current_word']['id'];

    // Keep only a short trailing window - this is just a spacing
    // preference, not persisted state the Constitution defines.
    $_SESSION['recent_word_ids'] =
        array_slice($_SESSION['recent_word_ids'], -10);
}

$isNewWord =
    !empty($_SESSION['current_word']) &&
    $_SESSION['current_word']['learning_step'] == -1;

 // ======================================
 // RESET FLASHCARD STATE
 // Applies to both logged-in users
 // and guest users when a new card is loaded.
// ======================================

    if ($mode === 'flashcards') {
        $_SESSION['show_answer'] = false;
    }
}

// Always use session word
$word = $_SESSION['current_word'] ?? null;

// Recompute question/answer AFTER word is set
// Recompute question/answer AND question class (ONE source of truth)
$direction = $_SESSION['direction'] ?? 'english_to_arabic';

$question = '';
$answer = '';

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

            ensureProgressRowExists(
                $conn,
                $_SESSION['user_id'],
                $word['id'],
                $word['arabic_diacritics'],
                $trackingMode
            );

            applyCorrectAnswer(
                $conn,
                $_SESSION['user_id'],
                $word['id'],
                $trackingMode
            );
        }

        } else {

        $feedback = "Incorrect";

        if ($isLoggedIn) {

            ensureProgressRowExists(
                $conn,
                $_SESSION['user_id'],
                $word['id'],
                $word['arabic_diacritics'],
                $trackingMode
            );

            applyIncorrectAnswer(
                $conn,
                $_SESSION['user_id'],
                $word['id'],
                $trackingMode
            );
        }
    }
}

// Rule 12c: recomputed fresh here (not reused from the "Handle Next
// Word" block above, which only runs conditionally) so the queue
// display always reflects live state on every request.
$continuousModeActive =
    hasContinuousRebalanceStarted($introducedDirections);

$owedCount = 0;

if ($mode === 'flashcards' && $userId) {

    $stats = getFlashcardStats(
        $conn,
        $userId,
        $book,
        $lesson,
        $wordGroup
    );

    $owedCount = $stats['partial'];
}

$queueStats = getQueueStats(
    $conn,
    $userId,
    $book,
    $lesson,
    $wordGroup,
    $mode,
    $availableNewCards,
    $continuousModeActive,
    $owedCount
);

$reviewCount = $queueStats['reviews'];
$learningCount = $queueStats['learning'];
$newCount = $queueStats['new'];

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
    <meta name="viewport" content="width=device-width, initial-scale=1">
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
        <div id="feedback" class="feedback">
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

    <?php if ($feedback !== null): ?>
        <script>
        window.onload = function () {
            document.getElementById('feedback').scrollIntoView({
                behavior: 'smooth',
                block: 'center'
            });
        };
        </script>
        <?php endif; ?>
</body>

</html>