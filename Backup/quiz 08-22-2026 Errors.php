<?php
    header('Content-Type: text/html; charset=UTF-8');
session_start();

set_exception_handler(function ($e) {
    echo '<pre>';
    echo get_class($e) . "\n\n";
    echo $e->getMessage() . "\n\n";
    echo 'File: ' . $e->getFile() . "\n";
    echo 'Line: ' . $e->getLine() . "\n";
    echo '</pre>';
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

try {
    $conn = connectToDB();
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}

$availableNewCards = 20;
$introducedDirections = 0;
$needsRebalance = false;
$completeWords = 0;
$partialWords = 0;

if ($userId) {

    $stmtDaily = $conn->prepare("
        SELECT
            available_new_cards,
            last_replenished,
            introduced_directions_since_eval
        FROM user_daily_stats
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

    $dailyRow = $stmtDaily->fetch(PDO::FETCH_ASSOC);
    
    if (!$dailyRow) {

        $availableNewCards = 20;

        $stmtInit = $conn->prepare("
            INSERT INTO user_daily_stats
            (
                user_id,
                book,
                lesson,
                word_group,
                study_date,
                available_new_cards,
                last_replenished,
                introduced_directions_since_eval
            )
            VALUES
            (
                :user_id,
                :book,
                :lesson,
                :word_group,
                CURDATE(),
                20,
                CURDATE(),
                0
            )
        ");

        $stmtInit->execute([
            ':user_id'    => $userId,
            ':book'       => $book,
            ':lesson'     => $lesson,
            ':word_group' => $wordGroup
        ]);

    } else {

        $availableNewCards =
            (int)$dailyRow['available_new_cards'];
        
        $introducedDirections =
            (int)$dailyRow['introduced_directions_since_eval'];

        $lastReplenished =
            $dailyRow['last_replenished'];

    }
        
    $needsRebalance =
        $introducedDirections >= 10;
    
    if (
        $needsRebalance
        && $mode === 'flashcards'
        && $userId
    ) {

        $stmtRebalance = $conn->prepare("
            SELECT

                SUM(
                    CASE
                        WHEN en.word_id IS NOT NULL
                         AND ar.word_id IS NOT NULL
                        THEN 1
                        ELSE 0
                    END
                ) AS complete_words,

                SUM(
                    CASE
                        WHEN (
                            en.word_id IS NOT NULL
                            AND ar.word_id IS NULL
                        )
                        OR (
                            en.word_id IS NULL
                            AND ar.word_id IS NOT NULL
                        )
                        THEN 1
                        ELSE 0
                    END
                ) AS partial_words

            FROM words w

            LEFT JOIN user_word_progress en
                ON en.word_id = w.id
                AND en.user_id = :user_id_en
                AND en.mode = 'flashcards_en_ar'

            LEFT JOIN user_word_progress ar
                ON ar.word_id = w.id
                AND ar.user_id = :user_id_ar
                AND ar.mode = 'flashcards_ar_en'

            WHERE w.book = :book
            AND w.lesson = :lesson
            AND (
                :word_group1 = 'all'
                OR w.word_group = :word_group2
            )
            ");

        $stmtRebalance->execute([
            ':user_id_en'    => $userId,
            ':user_id_ar'    => $userId,
            ':book'       => $book,
            ':lesson'     => $lesson,
            ':word_group1' => $wordGroup,
            ':word_group2' => $wordGroup
        ]);

    $rebalanceRow =
        $stmtRebalance->fetch(PDO::FETCH_ASSOC);

    $completeWords =
        (int)$rebalanceRow['complete_words'];

    $partialWords =
        (int)$rebalanceRow['partial_words'];
}
    
    if (
        isset($lastReplenished)
        && $lastReplenished !== date('Y-m-d')
    ) {

        $availableNewCards =
            min(
                32,
                $availableNewCards + 20
            );

        $stmtUpdate = $conn->prepare("
            UPDATE user_daily_stats
            SET
                available_new_cards = :available,
                last_replenished = CURDATE()
            WHERE user_id = :user_id
            AND book = :book
            AND lesson = :lesson
            AND word_group = :word_group
        ");

        $stmtUpdate->execute([
            ':available'  => $availableNewCards,
            ':user_id'    => $userId,
            ':book'       => $book,
            ':lesson'     => $lesson,
            ':word_group' => $wordGroup
        ]);
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
            GREATEST(
                available_new_cards - 1,
                0
            ),

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

$availableNewCards = max(
    0,
    $availableNewCards - 1
);

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
    isset($_SESSION['current_word'])
) {

    $wordId = $_SESSION['current_word']['id'];

    $stmt = $conn->prepare("
        UPDATE user_word_progress
    	SET
    times_correct = times_correct + 1,

    learning_step = LEAST(learning_step + 1, 9),

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

        WHEN learning_step + 1 = 3
        THEN DATE_ADD(CURDATE(), INTERVAL 1 DAY)

        WHEN learning_step + 1 = 4
        THEN DATE_ADD(CURDATE(), INTERVAL 3 DAY)

        WHEN learning_step + 1 = 5
        THEN DATE_ADD(CURDATE(), INTERVAL 7 DAY)

        WHEN learning_step + 1 = 6
        THEN DATE_ADD(CURDATE(), INTERVAL 14 DAY)

        WHEN learning_step + 1 = 7
        THEN DATE_ADD(CURDATE(), INTERVAL 30 DAY)

        WHEN learning_step + 1 = 8
        THEN DATE_ADD(CURDATE(), INTERVAL 60 DAY)

        WHEN learning_step + 1 = 9
        THEN DATE_ADD(CURDATE(), INTERVAL 120 DAY)

        ELSE next_review

    END
        WHERE user_id = :user_id
        AND word_id = :word_id
        AND mode = :mode
    ");

    $stmt->execute([
        ':user_id' => $_SESSION['user_id'],
        ':word_id' => $wordId,
        ':mode' => $trackingMode
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
                WHEN learning_step <= 1 THEN 0
                ELSE learning_step - 1
            END
        WHERE user_id = :user_id
        AND word_id = :word_id
        AND mode = :mode
    ");

    $stmt->execute([
        ':user_id' => $_SESSION['user_id'],
        ':word_id' => $wordId,
        ':mode' => $trackingMode
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

    $stmtOwed = $conn->prepare("
        SELECT
            w.english,
            w.arabic_diacritics,
            w.arabic_shadda,
            w.book,
            w.lesson,
            w.id,

            CASE
                WHEN ar.word_id IS NOT NULL
                     AND en.word_id IS NULL
                THEN 'flashcards_en_ar'

                WHEN en.word_id IS NOT NULL
                     AND ar.word_id IS NULL
                THEN 'flashcards_ar_en'
            END AS missing_mode

        FROM words w

        LEFT JOIN user_word_progress ar
            ON ar.word_id = w.id
            AND ar.user_id = :user_id_ar
            AND ar.mode = 'flashcards_ar_en'

        LEFT JOIN user_word_progress en
            ON en.word_id = w.id
            AND en.user_id = :user_id_en
            AND en.mode = 'flashcards_en_ar'

        WHERE w.book = :book
        AND w.lesson = :lesson
        AND (
            :word_group1 = 'all'
            OR w.word_group = :word_group2
        )
        AND (
            (ar.word_id IS NOT NULL AND en.word_id IS NULL)
            OR
            (en.word_id IS NOT NULL AND ar.word_id IS NULL)
        )

        ORDER BY RAND()
        LIMIT 1
    ");

    $stmtOwed->execute([
        ':user_id_ar'    => $userId,
        ':user_id_en'    => $userId,
        ':book'       => $book,
        ':lesson'     => $lesson,
        ':word_group1' => $wordGroup,
        ':word_group2' => $wordGroup
    ]);

    $owedCard = $stmtOwed->fetch(PDO::FETCH_ASSOC);
}
 
if (
    $mode === 'flashcards'
    && !empty($owedCard)
    && rand(1, 2) === 1
) {

    $_SESSION['current_word'] = $owedCard;

    $_SESSION['current_word']['learning_step'] = -1;

    $trackingMode =
        $owedCard['missing_mode'];

    $_SESSION['direction'] =
        $trackingMode === 'flashcards_ar_en'
            ? 'arabic_to_english'
            : 'english_to_arabic';

}
else
{
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
                    p.learning_step >= 3
                    AND p.next_review IS NOT NULL
                    AND p.next_review <= NOW()
                )
            )
        ORDER BY

		CASE

                    WHEN p.learning_step >= 3
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

    if ($availableNewCards <= 0) {

    $sql = str_replace(
        "ORDER BY",
        "AND p.learning_step IS NOT NULL ORDER BY",
        $sql
    );
}
$stmt = $conn->prepare($sql);

$stmt->execute([
    ':user_id' => $userId,
    ':mode' => $trackingMode,
    ':book' => $book,
    ':lesson' => $lesson
]);

$_SESSION['current_word'] = $stmt->fetch();

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
                p.learning_step >= 3
                AND p.next_review IS NOT NULL
                AND p.next_review <= NOW()
            )
        )

        ORDER BY

            CASE

                WHEN p.learning_step >= 3
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
        ':mode' => $trackingMode,
        ':book' => $book,
        ':lesson' => $lesson,
        ':word_group' => $wordGroup

    ]);
    
    $_SESSION['current_word'] = $stmt->fetch();
}
}

if (
    isset($_SESSION['current_word']) &&
    $_SESSION['current_word'] === false &&
    $mode === 'flashcards'
) {

    // Try the opposite direction

    $_SESSION['direction'] =
        $_SESSION['direction'] === 'arabic_to_english'
            ? 'english_to_arabic'
            : 'arabic_to_english';

    $trackingMode =
        $_SESSION['direction'] === 'arabic_to_english'
            ? 'flashcards_ar_en'
            : 'flashcards_en_ar';

    $params = [
    ':user_id' => $userId,
    ':mode' => $trackingMode,
    ':book' => $book,
    ':lesson' => $lesson
    ];

    if ($wordGroup !== 'all') {
        $params[':word_group'] = $wordGroup;
    }

    $stmt->execute($params);

    $_SESSION['current_word'] = $stmt->fetch();
}

if ($_SESSION['current_word'] === false) {
    $_SESSION['current_word'] = null;
}

if ($_SESSION['current_word']) {

    $_SESSION['last_word_id'] =
        $_SESSION['current_word']['id'];
}
    
$isNewWord =
    !empty($_SESSION['current_word']) &&
    $_SESSION['current_word']['learning_step'] == -1;

    
 // ======================================
 // RESET FLASHCARD STATE
 // Applies to both logged-in users
 // and guest users when a new card is loaded.
// ======================================

    $_SESSION['show_answer'] = false;
    if ($mode === 'flashcards') {
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

        $stmt = $conn->prepare("
            UPDATE user_word_progress
           SET
    times_correct = times_correct + 1,

    learning_step = LEAST(learning_step + 1, 9),

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

    WHEN learning_step + 1 = 3
    THEN DATE_ADD(CURDATE(), INTERVAL 1 DAY)

    WHEN learning_step + 1 = 4
    THEN DATE_ADD(CURDATE(), INTERVAL 3 DAY)

    WHEN learning_step + 1 = 5
    THEN DATE_ADD(CURDATE(), INTERVAL 7 DAY)

    WHEN learning_step + 1 = 6
    THEN DATE_ADD(CURDATE(), INTERVAL 14 DAY)

    WHEN learning_step + 1 = 7
    THEN DATE_ADD(CURDATE(), INTERVAL 30 DAY)

    WHEN learning_step + 1 = 8
    THEN DATE_ADD(CURDATE(), INTERVAL 60 DAY)

    WHEN learning_step + 1 = 9
    THEN DATE_ADD(CURDATE(), INTERVAL 120 DAY)

    ELSE next_review

END
            WHERE user_id = :user_id
            AND word_id = :word_id
            AND mode = :mode
        ");

        $stmt->execute([
            ':user_id' => $_SESSION['user_id'],
            ':word_id' => $word['id'],
            ':mode' => $trackingMode
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
                    WHEN learning_step <= 1 THEN 0
                    ELSE learning_step - 1
                END
            WHERE user_id = :user_id
            AND word_id = :word_id
            AND mode = :mode
        ");

        $stmt->execute([
            ':user_id' => $_SESSION['user_id'],
            ':word_id' => $word['id'],
            ':mode' => $trackingMode
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
//     learning_step >= 3
//     next_review <= NOW()
//
// Learning Words:
//     learning_step 0-2
//
// New Words:
//     no progress record yet
// ======================================

if ($mode === 'flashcards') {

    $modeCondition = "
        (
            p.mode = 'flashcards_en_ar'
            OR p.mode = 'flashcards_ar_en'
        )
    ";

} else {

    $modeCondition = "p.mode = :mode";
}

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
        AND $modeCondition
        AND w.book = :book
        AND w.lesson = :lesson
        AND p.learning_step >= 3
        AND p.next_review IS NOT NULL
        AND p.next_review <= NOW()
    ";

    if ($wordGroup !== 'all') {
        $reviewSql .= " AND w.word_group = :word_group";
    }

    $stmt = $conn->prepare($reviewSql);

    $params = [
        ':user_id' => $userId,
        ':book'    => $book,
        ':lesson'  => $lesson
    ];

    if ($mode !== 'flashcards') {
        $params[':mode'] = $mode;
    }

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
        AND $modeCondition
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
        ':book'    => $book,
        ':lesson'  => $lesson
    ];

    if ($mode !== 'flashcards') {
        $params[':mode'] = $mode;
    }

    if ($wordGroup !== 'all') {
        $params[':word_group'] = $wordGroup;
    }

    $stmt->execute($params);
    $learningCount = (int)$stmt->fetchColumn();

// ======================================
// COUNT NEW WORDS
// ======================================

if ($mode === 'flashcards') {

    $newSql = "
        SELECT
        (
            COUNT(*) * 2
        )
        -
        (
            SELECT COUNT(*)
            FROM user_word_progress p
            INNER JOIN words w2
                ON w2.id = p.word_id
            WHERE p.user_id = :user_id_sub
            AND (
                p.mode = 'flashcards_en_ar'
                OR p.mode = 'flashcards_ar_en'
            )
            AND w2.book = :book_sub
            AND w2.lesson = :lesson_sub
    ";

    if ($wordGroup !== 'all') {
        $newSql .= "
            AND w2.word_group = :word_group_sub
        ";
    }

    $newSql .= "
        )
        FROM words w
        WHERE w.book = :book
        AND w.lesson = :lesson
    ";

    if ($wordGroup !== 'all') {
        $newSql .= "
            AND w.word_group = :word_group
        ";
    }

} else {

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
}

$stmt = $conn->prepare($newSql);

$params = [
    ':user_id_sub' => $userId,

    ':book'        => $book,
    ':book_sub'    => $book,

    ':lesson'      => $lesson,
    ':lesson_sub'  => $lesson
];

if ($mode !== 'flashcards') {
    $params[':mode'] = $mode;
}

if ($wordGroup !== 'all') {
    $params[':word_group'] = $wordGroup;

    if ($mode === 'flashcards') {
        $params[':word_group_sub'] = $wordGroup;
    }
}

$stmt->execute($params);


$newCount = (int)$stmt->fetchColumn();
echo "<pre>";
echo "reviewCount = $reviewCount\n";
echo "learningCount = $learningCount\n";
echo "newCount = $newCount\n";
echo "availableNewCards = $availableNewCards\n";
echo "introducedDirections = $introducedDirections\n";
echo "needsRebalance = "
    . ($needsRebalance ? 'YES' : 'NO')
    . "\n";
echo "completeWords = $completeWords\n";
echo "partialWords = $partialWords\n";
echo "</pre>";
$newCount = min(
    $newCount,
    $availableNewCards
);
}
echo "<pre>";
echo "Reviews: $reviewCount\n";
echo "Learning: $learningCount\n";
echo "New: $newCount\n";
echo "Available: $availableNewCards\n";
echo "</pre>";
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