<?php
session_start();

require_once __DIR__ . '/../includes/drill_engine.php';
require_once __DIR__ . '/verb_forms_data.php';

$view = $_GET['view'] ?? 'concept';
$allowedViews = ['concept', 'identify', 'produce'];

if (!in_array($view, $allowedViews, true)) {
    $view = 'concept';
}

$verbData = getVerbFormsData();
$feedback = null;

// ======================================
// IDENTIFY THE FORM (multiple choice)
// Show a conjugated word, pick which Form
// it is. Distractors come from this SAME
// root's other attested Forms only.
// ======================================
if ($view === 'identify') {

    $drillKey = 'verb_forms_identify';
    initDrillScore($drillKey);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {

        if (
            isset($_POST['submit_answer']) &&
            isset($_SESSION['verb_forms_identify_current'])
        ) {

            $current = $_SESSION['verb_forms_identify_current'];
            $userChoice = $_POST['form_choice'] ?? '';
            $isCorrect = ($userChoice === $current['form']);

            recordDrillAnswer($drillKey, $isCorrect);

            $feedback = [
                'correct'     => $isCorrect,
                'word'        => $current['word'],
                'correctForm' => $current['form'],
                'meaning'     => $current['meaning'],
                'root'        => $current['root']
            ];
        }

        if (isset($_POST['next'])) {
            unset($_SESSION['verb_forms_identify_current']);
        }
    }

    if (!isset($_SESSION['verb_forms_identify_current'])) {

        // Only roots with 2+ attested Forms can offer a
        // real multiple-choice distractor.
        $eligibleRoots = array_values(array_filter(
            $verbData,
            function ($r) {
                return count($r['forms']) >= 2;
            }
        ));

        $rootEntry = $eligibleRoots[array_rand($eligibleRoots)];
        $formLabels = array_keys($rootEntry['forms']);
        $correctForm = $formLabels[array_rand($formLabels)];
        $correctData = $rootEntry['forms'][$correctForm];

        $distractorPool = array_values(array_diff(
            $formLabels,
            [$correctForm]
        ));

        $options = buildMultipleChoiceOptions(
            $correctForm,
            $distractorPool,
            4
        );

        $_SESSION['verb_forms_identify_current'] = [
            'root'    => $rootEntry['root'],
            'word'    => $correctData['word'],
            'meaning' => $correctData['meaning'],
            'form'    => $correctForm,
            'options' => $options
        ];
    }

    $current = $_SESSION['verb_forms_identify_current'];
    $score = getDrillScore($drillKey);
}

// ======================================
// PRODUCE THE CONJUGATION (type-in)
// Show a root + target Form, type the
// correct conjugated word.
// ======================================
if ($view === 'produce') {

    $drillKey = 'verb_forms_produce';
    initDrillScore($drillKey);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {

        if (
            isset($_POST['submit_answer']) &&
            isset($_SESSION['verb_forms_produce_current'])
        ) {

            $current = $_SESSION['verb_forms_produce_current'];
            $userTyped = trim($_POST['typed_answer'] ?? '');
            $isCorrect = checkTypedAnswer($userTyped, $current['word']);

            recordDrillAnswer($drillKey, $isCorrect);

            $feedback = [
                'correct'     => $isCorrect,
                'userTyped'   => $userTyped,
                'correctWord' => $current['word'],
                'meaning'     => $current['meaning'],
                'root'        => $current['root'],
                'form'        => $current['form']
            ];
        }

        if (isset($_POST['next'])) {
            unset($_SESSION['verb_forms_produce_current']);
        }
    }

    if (!isset($_SESSION['verb_forms_produce_current'])) {

        $rootEntry = $verbData[array_rand($verbData)];
        $formLabels = array_keys($rootEntry['forms']);
        $form = $formLabels[array_rand($formLabels)];
        $formData = $rootEntry['forms'][$form];

        $_SESSION['verb_forms_produce_current'] = [
            'root'    => $rootEntry['root'],
            'form'    => $form,
            'word'    => $formData['word'],
            'meaning' => $formData['meaning']
        ];
    }

    $current = $_SESSION['verb_forms_produce_current'];
    $score = getDrillScore($drillKey);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Verb Forms</title>
    <link rel="stylesheet" href="../style.css?v=9">
</head>
<body>
<?php include '../header.php'; ?>

<div class="games-container">

    <h1>Verb Forms (الأوزان)</h1>

    <p>
        <a href="?view=concept">Learn the Forms</a>
        &nbsp;|&nbsp;
        <a href="?view=identify">Identify the Form</a>
        &nbsp;|&nbsp;
        <a href="?view=produce">Produce the Conjugation</a>
    </p>

    <?php if ($view === 'concept'): ?>

        <p>
            Arabic verbs are built from a three-letter root
            (like ك-ت-ب, "writing") that gets slotted into one
            of several patterns, called <strong>Forms</strong>.
            Each Form applies a consistent shape to the root and
            often shifts the meaning in a predictable direction.
        </p>

        <p>
            Not every root has an attested word in every Form -
            that is normal, not a gap in this page. The table
            below only shows real, commonly used words.
        </p>

        <h2>What Actually Changes</h2>

        <p>
            Every Form starts from the same three root letters.
            Here is exactly what gets added or changed to build
            each one, before you look at real examples below.
        </p>

        <?php $patterns = getVerbFormPatterns(); ?>

        <div class="study-info">

            <?php foreach ($patterns as $formLabel => $patternData): ?>

                <strong>Form <?php echo htmlspecialchars($formLabel, ENT_QUOTES, 'UTF-8'); ?></strong>
                <span class="form-pattern-wrap">
                    ( <span class="arabic-text green-text drill-answer-text"><?php echo htmlspecialchars($patternData['pattern'], ENT_QUOTES, 'UTF-8'); ?></span> )
                </span>:

                <?php foreach ($patternData['change'] as $segment): ?>
                    <?php if (isset($segment['arabic'])): ?>
                        <span class="arabic-text green-text drill-answer-text">
                            <?php echo htmlspecialchars($segment['arabic'], ENT_QUOTES, 'UTF-8'); ?>
                        </span>
                    <?php else: ?>
                        <?php echo htmlspecialchars($segment['text'], ENT_QUOTES, 'UTF-8'); ?>
                    <?php endif; ?>
                <?php endforeach; ?>

                <br><br>

            <?php endforeach; ?>

        </div>

        <br>

        <div class="lessons-grid">

        <?php foreach ($verbData as $rootEntry): ?>

            <div class="study-info">

                <strong>
                    <?php echo htmlspecialchars($rootEntry['root'], ENT_QUOTES, 'UTF-8'); ?>
                    (<?php echo htmlspecialchars($rootEntry['meaning'], ENT_QUOTES, 'UTF-8'); ?>)
                </strong>

                <br><br>

                <?php foreach ($rootEntry['forms'] as $formLabel => $formData): ?>

                    <strong>Form <?php echo htmlspecialchars($formLabel, ENT_QUOTES, 'UTF-8'); ?>:</strong>
                    <span class="arabic-text green-text drill-answer-text">
                        <?php echo htmlspecialchars($formData['word'], ENT_QUOTES, 'UTF-8'); ?>
                    </span>
                    &mdash;
                    <?php echo htmlspecialchars($formData['meaning'], ENT_QUOTES, 'UTF-8'); ?>
                    <br>

                <?php endforeach; ?>

            </div>

        <?php endforeach; ?>

    <?php elseif ($view === 'identify'): ?>

        <div class="study-info">
            Score:
            <?php echo (int)$score['correct']; ?>
            /
            <?php echo (int)$score['total']; ?>
        </div>

        <br>

        <?php if ($feedback): ?>

            <div class="study-info">

                <?php if ($feedback['correct']): ?>
                    <strong class="green-text">Correct!</strong>
                <?php else: ?>
                    <strong class="red-text">Incorrect.</strong>
                <?php endif; ?>

                <br><br>

                <span class="arabic-text drill-answer-text">
                    <?php echo htmlspecialchars($feedback['word'], ENT_QUOTES, 'UTF-8'); ?>
                </span>
                is Form
                <strong><?php echo htmlspecialchars($feedback['correctForm'], ENT_QUOTES, 'UTF-8'); ?></strong>
                (<?php echo htmlspecialchars($feedback['meaning'], ENT_QUOTES, 'UTF-8'); ?>)
                from root
                <?php echo htmlspecialchars($feedback['root'], ENT_QUOTES, 'UTF-8'); ?>

            </div>

            <br>

            <form method="POST">
                <button type="submit" name="next">Next Question</button>
            </form>

        <?php else: ?>

            <p>Which Form is this verb?</p>

            <h2 class="green-text">
                <span class="arabic-text drill-answer-text">
                    <?php echo htmlspecialchars($current['word'], ENT_QUOTES, 'UTF-8'); ?>
                </span>
            </h2>

            <form method="POST">

                <?php foreach ($current['options'] as $option): ?>
                    <label>
                        <input
                            type="radio"
                            name="form_choice"
                            value="<?php echo htmlspecialchars($option, ENT_QUOTES, 'UTF-8'); ?>"
                            required
                        >
                        Form <?php echo htmlspecialchars($option, ENT_QUOTES, 'UTF-8'); ?>
                    </label>
                    <br>
                <?php endforeach; ?>

                <br>

                <button type="submit" name="submit_answer">Submit</button>

            </form>

        <?php endif; ?>

    <?php elseif ($view === 'produce'): ?>

        <div class="study-info">
            Score:
            <?php echo (int)$score['correct']; ?>
            /
            <?php echo (int)$score['total']; ?>
        </div>

        <br>

        <?php if ($feedback): ?>

            <div class="study-info">

                <?php if ($feedback['correct']): ?>
                    <strong class="green-text">Correct!</strong>
                <?php else: ?>
                    <strong class="red-text">Incorrect.</strong>
                <?php endif; ?>

                <br><br>

                You typed:
                <span class="arabic-text drill-answer-text">
                    <?php echo htmlspecialchars($feedback['userTyped'], ENT_QUOTES, 'UTF-8'); ?>
                </span>

                <br>

                Correct answer:
                <span class="arabic-text green-text drill-answer-text">
                    <?php echo htmlspecialchars($feedback['correctWord'], ENT_QUOTES, 'UTF-8'); ?>
                </span>

                <br><br>

                Form <?php echo htmlspecialchars($feedback['form'], ENT_QUOTES, 'UTF-8'); ?>
                of root
                <?php echo htmlspecialchars($feedback['root'], ENT_QUOTES, 'UTF-8'); ?>
                &mdash;
                <?php echo htmlspecialchars($feedback['meaning'], ENT_QUOTES, 'UTF-8'); ?>

            </div>

            <br>

            <form method="POST">
                <button type="submit" name="next">Next Question</button>
            </form>

        <?php else: ?>

            <p>
                Conjugate root
                <strong><?php echo htmlspecialchars($current['root'], ENT_QUOTES, 'UTF-8'); ?></strong>
                in Form
                <strong><?php echo htmlspecialchars($current['form'], ENT_QUOTES, 'UTF-8'); ?></strong>
            </p>

            <form method="POST">

                <input
                    type="text"
                    name="typed_answer"
                    class="arabic-text"
                    dir="rtl"
                    required
                >

                <br><br>

                <button type="submit" name="submit_answer">Submit</button>

            </form>

        <?php endif; ?>

    <?php endif; ?>

    <br><br>

    <a href="../index.php">
        &larr; Back to Home
    </a>

</div>
</body>
</html>