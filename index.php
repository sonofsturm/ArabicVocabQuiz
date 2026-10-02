<?php
/*
=================================================
FUTURE FEATURES ROADMAP

2. Admin Features
   - User management
   - Password resets
   - Site statistics

3. Games

4. Teacher Mode
   - Optional class participation
   - Students may join class with class code
   - Joining a class is NOT required
   - Teachers may view student progress
   - Teachers may view class statistics
   - Teachers may view most-missed words



=================================================
*/
     
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();


$isLoggedIn = isset($_SESSION['user_id']);


if (isset($_GET['reset'])) {
    unset(
        $_SESSION['book'],
        $_SESSION['lesson'],
        $_SESSION['word_group'],
        $_SESSION['current_word']
    );

    // Redirect to a clean URL so ?reset=1 can never linger in the
    // address bar across later form submissions on this page - the
    // <form> below has no explicit action, so it resubmits to
    // whatever URL is currently shown, including a stale reset flag
    // left over from arriving here via quiz.php's "Back to Home"
    // link. Without this, every subsequent submission would re-fire
    // the reset above BEFORE the POST-handling block runs, wiping
    // the book selection right as it's being re-saved.
    header('Location: index.php');
    exit;
}

// Save selected book when user changes it
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $previousBook = $_SESSION['book'] ?? null;
    $previousLesson = $_SESSION['lesson'] ?? null;

    // Save selected book
    if (!empty($_POST['book'])) {
        $_SESSION['book'] = $_POST['book'];
        unset($_SESSION['current_word']);
    } else {
        unset($_SESSION['book'], $_SESSION['lesson'], $_SESSION['word_group'], $_SESSION['current_word']);
    }

    $bookChanged = ($_SESSION['book'] ?? null) !== $previousBook;

    // A different book invalidates any previously selected lesson -
    // a posted lesson value here reflects the OLD book's dropdown
    // and may not even exist under the newly selected book.
    if ($bookChanged) {
        unset($_SESSION['lesson']);
    } elseif (!empty($_POST['lesson'])) {
        $_SESSION['lesson'] = $_POST['lesson'];
        unset($_SESSION['current_word']);
    }

    $lessonChanged = ($_SESSION['lesson'] ?? null) !== $previousLesson;

    // Same reasoning one level down - word groups are scoped to a
    // specific book+lesson combination.
    if ($bookChanged || $lessonChanged) {
        unset($_SESSION['word_group']);
    } elseif (isset($_POST['word_group'])) {
        $_SESSION['word_group'] = $_POST['word_group'];
    }
}

// DB connection
require_once __DIR__ . '/db.php';
$conn = connectToDB();

// Fetch all books
$sql = "
    SELECT DISTINCT book
    FROM words
    ORDER BY book ASC
";

$stmt = $conn->prepare($sql);
$stmt->execute();

$books = $stmt->fetchAll();

// Fetch lessons ONLY for selected book
$lessons = [];

if (isset($_SESSION['book'])) {

       $sql = "
    SELECT DISTINCT lesson
    FROM words
    WHERE book = :book
    ORDER BY CAST(lesson AS DECIMAL(10,2)) ASC
";

        $stmt = $conn->prepare($sql);

        $stmt->execute([
            ':book' => $_SESSION['book']
        ]);

        $lessons = $stmt->fetchAll();

}

// Fetch word groups for selected book and lesson

$wordGroups = [];

if (
    !empty($_SESSION['book']) &&
    !empty($_SESSION['lesson'])
) {

    $sql = "
        SELECT DISTINCT word_group
        FROM words
        WHERE book = :book
        AND lesson = :lesson
        AND word_group IS NOT NULL
        AND word_group <> ''
        ORDER BY word_group
    ";

    $stmt = $conn->prepare($sql);

    $stmt->execute([
        ':book' => $_SESSION['book'],
        ':lesson' => $_SESSION['lesson']
    ]);

    $wordGroups = $stmt->fetchAll();

}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Select Book</title>
    <link rel="stylesheet" href="style.css">
    <link rel="icon" href="img/arabic_icon.png" type="image/png">
    <link rel="apple-touch-icon" href="img/arabic_icon.png">
    <link rel="manifest" href="manifest.json">
    <meta name="theme-color" content="#ffffff">
</head>
<body>

<?php include 'header.php'; ?>    

<div class="quiz-container">
<h1>Select a Book</h1>

<form method="POST">
<select name="book" onchange="this.form.submit()">

    <option value=""
        <?php if (empty($_SESSION['book'])) echo 'selected'; ?>>
        -- Choose a Book --
    </option>

    <?php foreach ($books as $row): ?>

        <option
            value="<?php echo htmlspecialchars($row['book'], ENT_QUOTES, 'UTF-8'); ?>"
            <?php
            if (($_SESSION['book'] ?? '') === $row['book']) {
                echo 'selected';
            }
            ?>
        >
            <?php echo htmlspecialchars($row['book'], ENT_QUOTES, 'UTF-8'); ?>
        </option>

    <?php endforeach; ?>

</select> 
    <br>
    <h1>Select a Lesson:</h1>
    <br>
        <select name="lesson" onchange="this.form.submit()" required>
            <option value="">-- Choose a Lesson --</option>

               <?php if (!empty($lessons)): ?>
                   <?php foreach ($lessons as $row): ?>

    <option
        value="<?php echo htmlspecialchars($row['lesson'], ENT_QUOTES, 'UTF-8'); ?>"
        <?php
        if (($_SESSION['lesson'] ?? '') == $row['lesson']) {
            echo 'selected';
        }
        ?>
    >
        <?php echo htmlspecialchars($row['lesson'], ENT_QUOTES, 'UTF-8'); ?>
    </option>

<?php endforeach; ?>
                <?php endif; ?>
       </select>

<br><br>

<h1>Select a Word Group:</h1>

<select name="word_group" onchange="this.form.submit()">

    <option
        value="all"
        <?php
        if (($_SESSION['word_group'] ?? 'all') === 'all') {
            echo 'selected';
        }
        ?>
    >
        All Words
    </option>

   <?php foreach ($wordGroups as $row): ?>

    <option
        value="<?php echo htmlspecialchars($row['word_group'], ENT_QUOTES, 'UTF-8'); ?>"
        <?php
        if (
            ($_SESSION['word_group'] ?? '') === $row['word_group']
        ) {
            echo 'selected';
        }
        ?>
    >
            <?php echo htmlspecialchars($row['word_group'], ENT_QUOTES, 'UTF-8'); ?>
        </option>

    <?php endforeach; ?>

</select>

<br><br>

<button type="submit" formaction="mode.php">Next</button>
</form>
<hr>

<p>
    New to Arabic?
    Learn the Arabic Alphabet before starting vocabulary study:
</p>

<p>
    <a href="https://alkitaabtextbook.com/alifbaa/2e/"
        target="_blank"
        rel="noopener noreferrer">
        Learn the Arabic Alphabet (Alif Baa)
    </a>
</p>
<p>
    Or review it in this site:
    <a href="alphabet.php">Interactive Arabic Alphabet</a>
</p>
</div>
<?php include 'footer.php'; ?>
</body>
</html>