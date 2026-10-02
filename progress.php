<?php

session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

require_once __DIR__ . '/db.php';

$conn = connectToDB();

$userId = $_SESSION['user_id'];

$sql = "
    SELECT
        w.book,
        w.lesson,
        p.mode,
        SUM(p.times_seen) AS seen,
        SUM(p.times_correct) AS correct_count,
        SUM(p.times_incorrect) AS incorrect_count
    FROM user_word_progress p
    JOIN words w
        ON p.word_id = w.id
    WHERE p.user_id = :user_id
    GROUP BY
        w.book,
        w.lesson,
        p.mode
    ORDER BY
        w.book,
        CAST(w.lesson AS DECIMAL(10,2)),
        p.mode
";

$stmt = $conn->prepare($sql);

$stmt->execute([
    ':user_id' => $userId
]);

$progressData = $stmt->fetchAll();

$sql = "
    SELECT
        w.book,
        w.lesson,
        w.english,
        w.arabic_diacritics,
        SUM(p.times_incorrect) AS misses
    FROM user_word_progress p
    JOIN words w
        ON p.word_id = w.id
    WHERE p.user_id = :user_id
    AND p.times_incorrect > 0
    GROUP BY
        w.id
    ORDER BY
        w.book,
        CAST(w.lesson AS DECIMAL(10,2)),
        misses DESC
";

$stmt = $conn->prepare($sql);

$stmt->execute([
    ':user_id' => $userId
]);

$missedWords = $stmt->fetchAll();

$missedByLesson = [];

foreach ($missedWords as $word) {

    $key = $word['book'] . '|' . $word['lesson'];

    $missedByLesson[$key][] = $word;
}

function loadModeStats(PDO $conn, int $userId, string $mode): array
{
    $stmt = $conn->prepare("
        SELECT
            w.book,
            w.lesson,
            COUNT(*) AS total_words,
            SUM(
                CASE
                    WHEN p.learning_step >= 3
                    THEN 1
                    ELSE 0
                END
            ) AS graduated,
            SUM(
                CASE
                    WHEN p.learning_step BETWEEN 0 AND 2
                    THEN 1
                    ELSE 0
                END
            ) AS learning
        FROM words w
        LEFT JOIN user_word_progress p
            ON p.word_id = w.id
            AND p.user_id = :user_id
            AND p.mode = :mode
        GROUP BY
            w.book,
            w.lesson
    ");

    $stmt->execute([
        ':user_id' => $userId,
        ':mode' => $mode
    ]);

    $stats = [];

    foreach ($stmt->fetchAll() as $row) {

        $key = $row['book'] . '|' . $row['lesson'];

        $graduated = (int)$row['graduated'];
        $learning = (int)$row['learning'];
        $total = (int)$row['total_words'];

        $stats[$key] = [
            'graduated' => $graduated,
            'learning' => $learning,
            'not_started' => max(
                0,
                $total - $graduated - $learning
            )
        ];
    }

    return $stats;
}

$shaddaStats = loadModeStats($conn, $userId, 'shadda');
$diacriticsStats = loadModeStats($conn, $userId, 'diacritics');

$sql = "
    SELECT
        w.book,
        w.lesson,
        COUNT(*) AS total_words,
        SUM(
            CASE
                WHEN en.learning_step >= 3
                 AND ar.learning_step >= 3
                THEN 1
                ELSE 0
            END
        ) AS graduated,
        SUM(
            CASE
                WHEN (en.word_id IS NOT NULL OR ar.word_id IS NOT NULL)
                 AND NOT (
                     en.learning_step >= 3
                     AND ar.learning_step >= 3
                 )
                THEN 1
                ELSE 0
            END
        ) AS learning
    FROM words w
    LEFT JOIN user_word_progress en
        ON en.word_id = w.id
        AND en.user_id = :user_id_en
        AND en.mode = 'flashcards_en_ar'
    LEFT JOIN user_word_progress ar
        ON ar.word_id = w.id
        AND ar.user_id = :user_id_ar
        AND ar.mode = 'flashcards_ar_en'
    GROUP BY
        w.book,
        w.lesson
";

$stmt = $conn->prepare($sql);

$stmt->execute([
    ':user_id_en' => $userId,
    ':user_id_ar' => $userId
]);

$flashcardStats = [];

foreach ($stmt->fetchAll() as $row) {

    $key = $row['book'] . '|' . $row['lesson'];

    $graduated = (int)$row['graduated'];
    $learning = (int)$row['learning'];
    $total = (int)$row['total_words'];

    $flashcardStats[$key] = [
        'graduated' => $graduated,
        'learning' => $learning,
        'not_started' => max(
            0,
            $total - $graduated - $learning
        )
    ];
}

$sql = "
    SELECT COUNT(*) AS due_count
    FROM user_word_progress
    WHERE user_id = :user_id
    AND learning_step >= 3
    AND next_review IS NOT NULL
    AND next_review <= CURDATE()
";
$stmt = $conn->prepare($sql);

$stmt->execute([
    ':user_id' => $userId
]);

$reviewsDue = (int)$stmt->fetch()['due_count'];

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>My Progress</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>

<?php include 'header.php'; ?>

<div class="quiz-container">

    <h1>My Progress</h1>

<div class="study-info">

    <strong>Reviews Due Today:</strong>

    <?php echo $reviewsDue; ?>

</div>

<br>
    <?php if (empty($progressData)): ?>

    <p>No progress recorded yet.</p>

<?php else: ?>

    <?php
    $currentBook = '';
    $currentLesson = '';
    $currentLessonKey = '';
    ?>

    <?php foreach ($progressData as $row): ?>

        <?php if ($currentBook !== $row['book']): ?>

            <?php $currentBook = $row['book']; ?>

            <h2>
                <?php echo htmlspecialchars($currentBook); ?>
            </h2>

        <?php endif; ?>

        <?php $rowLessonKey = $row['book'] . '|' . $row['lesson']; ?>
        <?php if ($currentLessonKey !== $rowLessonKey): ?>
            <?php
            $currentLessonKey = $rowLessonKey;
            $currentLesson = $row['lesson'];
            ?>
        
    <h3>
        Lesson <?php echo htmlspecialchars($currentLesson); ?>
    </h3>
    
        <?php
        $defaultStats = [
            'not_started' => 0,
            'learning' => 0,
            'graduated' => 0
        ];

        $flashcardLessonStats =
            $flashcardStats[$currentLessonKey] ?? $defaultStats;

        $shaddaLessonStats =
            $shaddaStats[$currentLessonKey] ?? $defaultStats;

        $diacriticsLessonStats =
            $diacriticsStats[$currentLessonKey] ?? $defaultStats;
    ?>

<div class="study-info">

    <strong>Learning Status</strong>

    <br><br>

    <strong>Flashcards:</strong>
    Not Started <?php echo $flashcardLessonStats['not_started']; ?>,
    Learning <?php echo $flashcardLessonStats['learning']; ?>,
    Graduated <?php echo $flashcardLessonStats['graduated']; ?>

    <br>

    <strong>Shadda:</strong>
    Not Started <?php echo $shaddaLessonStats['not_started']; ?>,
    Learning <?php echo $shaddaLessonStats['learning']; ?>,
    Graduated <?php echo $shaddaLessonStats['graduated']; ?>

    <br>

    <strong>Diacritics:</strong>
    Not Started <?php echo $diacriticsLessonStats['not_started']; ?>,
    Learning <?php echo $diacriticsLessonStats['learning']; ?>,
    Graduated <?php echo $diacriticsLessonStats['graduated']; ?>

</div>

<br>
   

    <?php if (!empty($missedByLesson[$currentLessonKey])): ?>

        <div class="study-info">

            <strong>Most Missed Words</strong>

            <br><br>

            <?php
            $topWords = array_slice(
                $missedByLesson[$currentLessonKey],
                0,
                5
            );

            foreach ($topWords as $word):
            ?>

                <span class="arabic-text green-text">
    <?php echo htmlspecialchars(
        $word['arabic_diacritics'],
        ENT_QUOTES,
        'UTF-8'
    ); ?>
</span>

—

<span class="red-text">
    <?php echo htmlspecialchars(
        $word['english'],
        ENT_QUOTES,
        'UTF-8'
    ); ?>
</span>

(<?php echo $word['misses']; ?> misses)

<br>

            <?php endforeach; ?>

        </div>

        <br>

    <?php endif; ?>

<?php endif; ?>

        <?php
        $seen = (int)$row['seen'];
        $correct = (int)$row['correct_count'];
        $incorrect = (int)$row['incorrect_count'];

        $accuracy = 0;

        if (($correct + $incorrect) > 0) {
            $accuracy = round(
                ($correct / ($correct + $incorrect)) * 100,
                1
            );
        }
        ?>

        <div class="study-info">

            <strong>
                <?php echo ucfirst($row['mode']); ?>
            </strong>

            <br>

            Seen:
            <?php echo $seen; ?>

            <br>

            Correct:
            <?php echo $correct; ?>

            <br>

            Incorrect:
            <?php echo $incorrect; ?>

            <br>

            Accuracy:
            <?php echo $accuracy; ?>%

        </div>

        <br>
    
    <?php endforeach; ?>

<?php endif; ?>
    
    <br><br>

    <a href="index.php">
        ← Back to Home
    </a>

</div>

</body>
</html>