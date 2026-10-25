<?php
session_start();
// Buffer all output so AJAX handlers can return clean JSON even if includes emit warnings
if (function_exists('ob_start')) ob_start();

// Ensure pages are served as UTF-8 so Arabic text renders correctly
header('Content-Type: text/html; charset=utf-8');


require_once __DIR__ . '/../includes/drill_engine.php';
require_once __DIR__ . '/verb_forms_data.php';

$view = $_GET['view'] ?? 'concept';
$allowedViews = ['concept', 'study', 'identify', 'produce'];

if (!in_array($view, $allowedViews, true)) {
    $view = 'concept';
}

$verbData = getVerbFormsData();
$feedback = null;

// Handle small AJAX actions from the Study UI (mark learned / clear learned)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['api_action'])) {
    header('Content-Type: application/json');
    $action = $_POST['api_action'];
    if ($action === 'mark_learned' && !empty($_POST['key'])) {
        if (!isset($_SESSION['vf_learned'])) $_SESSION['vf_learned'] = [];
        $k = (string) $_POST['key'];
        if (!in_array($k, $_SESSION['vf_learned'], true)) {
            $_SESSION['vf_learned'][] = $k;
        }
        echo json_encode(['status' => 'ok', 'learned' => $_SESSION['vf_learned']]);
        exit;
    }
    if ($action === 'clear_learned') {
        $_SESSION['vf_learned'] = [];
        echo json_encode(['status' => 'ok', 'learned' => []]);
        exit;
    }
    echo json_encode(['status' => 'error', 'message' => 'unknown action']);
    exit;
}

// Handle AJAX practice submissions (return JSON) to update the practice panel without full reload
// Only treat as AJAX when ajax flag is present or request header indicates XHR. Do NOT
// return JSON for normal full-page form submissions (prevents navigating to raw JSON).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['ajax']) || (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest'))) {
    // Use output buffering to prevent any incidental output (warnings, notices)
    ob_start();
    // Determine action: prefer explicit practice_action, otherwise infer from payload
    $pa = $_POST['practice_action'] ?? null;
    if ($pa === null) {
        if (isset($_POST['form_choice'])) {
            $pa = 'submit_identify';
        } else {
            // default to new_question when no form_choice provided
            $pa = 'new_question';
        }
    }

    try {
        if ($pa === 'submit_identify') {
            if (!isset($_SESSION['vf_study_current'])) {
                throw new RuntimeException('No active study question');
            }
            $current = $_SESSION['vf_study_current'];
            $user = $_POST['form_choice'] ?? '';
            $isCorrect = ($user === $current['form']);
            recordDrillAnswer('verb_forms_study_identify', $isCorrect);
            $studyFeedback = [ 'correct' => $isCorrect, 'word' => $current['word'], 'correctForm' => $current['form'], 'meaning' => $current['meaning'], 'root' => $current['root'], 'userChoice' => $user ];
            $out = ['status' => 'ok', 'studyFeedback' => $studyFeedback];
        } elseif ($pa === 'new_question') {
            unset($_SESSION['vf_study_current']);
            $out = ['status' => 'ok', 'reload' => true];
        } else {
            $out = ['status' => 'error', 'message' => 'unknown practice action'];
        }
    } catch (Throwable $e) {
        error_log('Practice AJAX error: ' . $e->getMessage());
        $out = ['status' => 'error', 'message' => $e->getMessage()];
    }
    // Clear any buffered output and send clean JSON
    if (ob_get_length() !== false) ob_end_clean();
    header('Content-Type: application/json');
    echo json_encode($out);
    exit;
}

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
                'correct' => $isCorrect,
                'word' => $current['word'],
                'correctForm' => $current['form'],
                'meaning' => $current['meaning'],
                'root' => $current['root']
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

// If study mode is active and there are learned flags, prefer roots/forms not yet learned
if ($view === 'identify' || $view === 'produce') {
    $learned = $_SESSION['vf_learned'] ?? [];
    // If all learned, ignore; otherwise filter verbData for entries with unlearned forms
    $allKeys = [];
    foreach ($verbData as $e) {
        foreach ($e['forms'] as $lab => $f) {
            $allKeys[] = $e['root'] . '|' . $lab;
        }
    }
    $unlearnedKeys = array_values(array_diff($allKeys, $learned));
    if (!empty($learned) && !empty($unlearnedKeys)) {
        // Build a reduced verbData containing only entries with at least one unlearned form
        $reduced = [];
        foreach ($verbData as $e) {
            $keep = false;
            foreach ($e['forms'] as $lab => $f) {
                if (in_array($e['root'] . '|' . $lab, $unlearnedKeys, true)) { $keep = true; break; }
            }
            if ($keep) $reduced[] = $e;
        }
        if (!empty($reduced)) $verbData = $reduced;
    }
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
            'root' => $rootEntry['root'],
            'form' => $form,
            'word' => $formData['word'],
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
    <link rel="stylesheet" href="../style.css?v=10">
</head>
<body>
<?php include '../header.php'; ?>

<div class="games-container">

    <h1>Verb Forms (الأوزان)</h1>

    <nav aria-label="Verb Forms local navigation">
        <a href="?view=concept">Learn the Forms</a>
        &nbsp;|&nbsp;
        <a href="?view=study">Study &amp; Practice</a>
        &nbsp;|&nbsp;
        <a href="build_verb.php">Build the Verb</a>
        &nbsp;|&nbsp;
        <a href="?view=identify">Identify the Form</a>
        &nbsp;|&nbsp;
        <a href="?view=produce">Produce the Conjugation</a>
        &nbsp;|&nbsp;
        <a href="verb_dna.php">Verb DNA</a>
    </nav>

<?php if ($view === 'concept' || $view === 'study'): ?>

        <p>
            Arabic verbs are built from a three-letter root
            (like <span class="inline-arabic">ك-ت-ب</span>, <span class="translation">writing</span>) that gets slotted into one
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

        <div class="study-info fullwidth">

            <?php foreach ($patterns as $formLabel => $patternData): ?>

                <strong>Form <?php echo htmlspecialchars($formLabel, ENT_QUOTES, 'UTF-8'); ?></strong>
                <span class="form-pattern-wrap">
                    ( <span class="arabic-text green-text drill-answer-text"><?php echo htmlspecialchars($patternData['pattern'], ENT_QUOTES, 'UTF-8'); ?></span> )
                </span>:

                <span class="pattern-change">
                    <?php foreach ($patternData['change'] as $segment): ?>
                        <?php if (isset($segment['arabic'])): ?>
                            <span class="arabic-text green-text drill-answer-text" style="margin:0 4px; display:inline-block;">
                                <?php echo htmlspecialchars($segment['arabic'], ENT_QUOTES, 'UTF-8'); ?>
                            </span>
                    <?php else: ?>
                            <span class="translation"><?php echo htmlspecialchars($segment['text'], ENT_QUOTES, 'UTF-8'); ?></span>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </span>

                <br><br>

            <?php endforeach; ?>

        </div>

        <hr>

        <h2>Study Mode (recommended)</h2>
        <p>
            Study Mode will walk you through each Form with examples. After you review a Form
            you can mark it as "learned" and the practice quiz will focus on Forms you haven't
            yet marked learned. This helps reinforce the differences between Forms.
        </p>

        <?php
        // Build a simple study list from available verb data and patterns
        $studyList = [];
        foreach ($verbData as $rootEntry) {
            foreach ($rootEntry['forms'] as $label => $fdata) {
                $studyList[] = [
                    'root' => $rootEntry['root'],
                    'form' => $label,
                    'word' => $fdata['word'],
                    'meaning' => $fdata['meaning']
                ];
            }
        }
        ?>

        <?php
        // Replace guided pager with a two-column Study + Practice dashboard.
        // Left column: list of roots and their forms with learned markers.
        // Right column: practice panel for the selected root (selected via ?selected=ROOT)

        // Handle practice form submissions
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['practice_action'])) {
            $pa = $_POST['practice_action'];
            $sel = $_POST['selected_root'] ?? $_GET['selected'] ?? null;
            if ($pa === 'submit_identify' && isset($_SESSION['vf_study_current']) && $sel) {
                $current = $_SESSION['vf_study_current'];
                $user = $_POST['form_choice'] ?? '';
                $isCorrect = ($user === $current['form']);
                // record drill score for study-practice (separate key)
                recordDrillAnswer('verb_forms_study_identify', $isCorrect);
                // include the user's selected choice so Results can display it
                $studyFeedback = [ 'correct' => $isCorrect, 'word' => $current['word'], 'correctForm' => $current['form'], 'meaning' => $current['meaning'], 'root' => $current['root'], 'userChoice' => $user ];
                // do NOT clear the current question here; wait for the user to click New Question
            }
            if ($pa === 'new_question') {
                unset($_SESSION['vf_study_current']);
            }
        }

        // Selected root from query param
        $selectedRoot = $_GET['selected'] ?? null;
        // If no current question, build one. Use a rotating queue of roots so practice cycles through all roots.
        if (!isset($_SESSION['vf_study_current'])) {
            // Build or refresh a session queue of root identifiers (the $verbData 'root' strings)
            if (empty($_SESSION['vf_study_queue']) || !is_array($_SESSION['vf_study_queue'])) {
                $_SESSION['vf_study_queue'] = array_map(function($e){ return $e['root']; }, $verbData);
                // shuffle so order isn't always the same
                shuffle($_SESSION['vf_study_queue']);
            }

            // If the user has specifically selected a root, try to honor it first
            $rootKey = null;
            if ($selectedRoot) {
                // if selectedRoot exists in queue, remove it and use it
                $pos = array_search($selectedRoot, $_SESSION['vf_study_queue'], true);
                if ($pos !== false) {
                    $rootKey = $_SESSION['vf_study_queue'][$pos];
                    // remove from front so we can push it to the end after use
                    array_splice($_SESSION['vf_study_queue'], $pos, 1);
                }
            }

            // If no selected root forced, pop the next root from the queue
            if ($rootKey === null) {
                $rootKey = array_shift($_SESSION['vf_study_queue']);
            }

            // push the used root to the end of the queue so we cycle through all roots
            if ($rootKey !== null) {
                $_SESSION['vf_study_queue'][] = $rootKey;
            }

            // find matching entry in $verbData
            $rootEntry = null;
            foreach ($verbData as $r) { if ($r['root'] === $rootKey) { $rootEntry = $r; break; } }

            if ($rootEntry) {
                $forms = array_keys($rootEntry['forms']);
                $correctForm = $forms[array_rand($forms)];
                $correctData = $rootEntry['forms'][$correctForm];
                $distractorPool = array_values(array_diff($forms, [$correctForm]));
                $options = buildMultipleChoiceOptions($correctForm, $distractorPool, 4);
                $_SESSION['vf_study_current'] = [ 'root'=>$rootEntry['root'], 'word'=>$correctData['word'], 'meaning'=>$correctData['meaning'], 'form'=>$correctForm, 'options'=>$options ];
            }
        }

        // prepare score for practice panel
        $studyPracticeScore = getDrillScore('verb_forms_study_identify');
        ?>

<!-- Full examples (detailed) -->
<div class="lessons-grid">

            <?php foreach ($verbData as $rootEntry): ?>

                <div class="study-info fullwidth">

                    <strong>
                        <span class="arabic-text"><?php echo htmlspecialchars($rootEntry['root'], ENT_QUOTES, 'UTF-8'); ?></span>
                        (<span class="translation"><?php echo htmlspecialchars($rootEntry['meaning'], ENT_QUOTES, 'UTF-8'); ?></span>)
                    </strong>

                    <br><br>

                    <div class="forms-grid">
                    <?php foreach ($rootEntry['forms'] as $formLabel => $formData): ?>

                        <div class="form-block">
                            <strong class="form-label">Form <?php echo htmlspecialchars($formLabel, ENT_QUOTES, 'UTF-8'); ?>:</strong>
                            <span class="arabic-text green-text drill-answer-text form-word">
                                <?php echo htmlspecialchars($formData['word'], ENT_QUOTES, 'UTF-8'); ?>
                            </span>
                            &nbsp;&mdash;&nbsp;
                            <span class="translation form-meaning"><?php echo htmlspecialchars($formData['meaning'], ENT_QUOTES, 'UTF-8'); ?></span>
                        </div>

                    <?php endforeach; ?>
                    </div>

                </div>

            <?php endforeach; ?>

    </div>

<!-- Practice panel comes after the examples list -->
<div class="study-practice" id="study-practice">
                <h3>Practice</h3>
                <div class="study-instruction" style="margin-bottom:8px;color:#333;font-weight:600;">Practice rotates through the available roots. Click New Question to get the next mixed-root practice item.</div>
                <div class="study-practice-meta">Score: <?php echo (int)$studyPracticeScore['correct']; ?> / <?php echo (int)$studyPracticeScore['total']; ?></div>
                <div class="practice-grid" id="practice-panel">
                    <div class="practice-main">
                        <div class="practice-box">
                        <?php if ($selectedRoot || isset($_SESSION['vf_study_current'])): ?>
                            <?php if (isset($_SESSION['vf_study_current'])): $cur = $_SESSION['vf_study_current']; ?>
                                <form method="POST">
                                    <input type="hidden" name="selected_root" value="<?php echo htmlspecialchars($selectedRoot, ENT_QUOTES, 'UTF-8'); ?>">
                                    <h2 class="arabic-text" dir="rtl"><?php echo htmlspecialchars($cur['word'], ENT_QUOTES, 'UTF-8'); ?></h2>
                                    <div class="question-prompt">Which Form is this?</div>
                                    <?php foreach ($cur['options'] as $opt): ?>
                                        <label><input type="radio" name="form_choice" value="<?php echo htmlspecialchars($opt, ENT_QUOTES, 'UTF-8'); ?>" required> Form <?php echo htmlspecialchars($opt); ?></label>
                                    <?php endforeach; ?>
                                    <div class="quiz-controls">
                                        <button type="submit" name="practice_action" value="submit_identify" id="submit-identify">Submit Answer</button>
                                        <button type="submit" name="practice_action" value="new_question">New Question</button>
                                    </div>
                                </form>
                            <?php else: ?>
                                <p><em>No question ready — click New Question to generate one.</em></p>
                                <form method="POST"><input type="hidden" name="selected_root" value="<?php echo htmlspecialchars($selectedRoot, ENT_QUOTES, 'UTF-8'); ?>"><button type="submit" name="practice_action" value="new_question">New Question</button></form>
                            <?php endif; ?>
                        <?php else: ?>
                            <p>Select a root from the list above to begin practicing. You can mark learned Forms in Study Mode.</p>
                        <?php endif; ?>
                        </div>
                    </div>

                    <aside class="practice-feedback-col">
                        <?php if (isset($studyFeedback)): ?>
                            <div class="results-wrap">
                                <h4 class="results-title">Results</h4>
                                <div class="study-feedback">
                                    <?php if ($studyFeedback['correct']): ?>
                                        <strong class="green-text">Correct!</strong>
                                    <?php else: ?>
                                        <strong class="red-text">Incorrect</strong>
                                    <?php endif; ?>
                                    <div class="arabic-text" dir="rtl"><?php echo htmlspecialchars($studyFeedback['word'], ENT_QUOTES, 'UTF-8'); ?></div>
                                    <div class="results-details">
                                        <div><strong>Your answer:</strong> <?php echo htmlspecialchars($studyFeedback['userChoice'] ?? '-', ENT_QUOTES, 'UTF-8'); ?></div>
                                        <div>Correct: Form <?php echo htmlspecialchars($studyFeedback['correctForm']); ?> — <span class="translation"><?php echo htmlspecialchars($studyFeedback['meaning'], ENT_QUOTES, 'UTF-8'); ?></span></div>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>
                    </aside>
                </div>
        </div>

        <script>
        // Mark learned via AJAX to persist in the PHP session so drills can read it server-side
        (function(){
            function postAction(action, key){
                var fd = new FormData();
                fd.append('api_action', action);
                if (key) fd.append('key', key);
                fetch(location.pathname + location.search, { method: 'POST', body: fd }).then(function(res){ return res.json(); }).then(function(json){
                    if (json && json.learned) {
                        document.querySelectorAll('.study-row').forEach(function(row){
                            if (json.learned.indexOf(row.dataset.key) !== -1) row.classList.add('learned');
                            else row.classList.remove('learned');
                        });
                    }
                }).catch(function(){ alert('Network error'); });
            }
            document.querySelectorAll('.mark-learned').forEach(function(btn){
                btn.addEventListener('click', function(){
                    var key = this.dataset.key;
                    postAction('mark_learned', key);
                });
            });
        })();
        // Prevent page jump on practice submit by catching the form submit and posting via fetch
        (function(){
            var panel = document.getElementById('practice-panel');
            if(!panel) return;
            var form = panel.querySelector('form');
            if(!form) return;
            // Ensure clicked submit button value is recorded reliably across browsers by
            // setting a hidden input when any submit button is clicked.
            form.querySelectorAll('button[type="submit"]').forEach(function(btn){
                btn.addEventListener('click', function(ev){
                    var existing = form.querySelector('input[name="practice_action"]').cloneNode ? form.querySelector('input[name="practice_action"]') : form.querySelector('input[name="practice_action"]');
                    if (!existing) {
                        existing = document.createElement('input'); existing.type='hidden'; existing.name='practice_action';
                        form.appendChild(existing);
                    }
                    existing.value = btn.value || '';
                });
            });

            // Intercept the New Question button and perform it via AJAX so the panel updates in-place
            var newBtn = form.querySelector('button[name="practice_action"][value="new_question"]');
            if (newBtn) {
                newBtn.addEventListener('click', function(ev){
                    ev.preventDefault();
                    // send ajax-only request to instruct server to clear current question
                    var fd = new FormData();
                    fd.append('practice_action','new_question');
                    fd.append('ajax','1');
                    fetch(location.pathname + location.search, { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' } })
                        .then(function(res){ if(!res.ok) throw new Error('HTTP ' + res.status); return res.json(); })
                        .then(function(json){
                            if(json && json.status === 'ok'){
                                // fetch updated page and replace practice panel HTML
                                fetch(location.pathname + location.search, { credentials: 'same-origin' }).then(function(r){ return r.text(); }).then(function(html){
                                    try{
                                        var tmp = document.createElement('div'); tmp.innerHTML = html;
                                        var newPanel = tmp.querySelector('#practice-panel');
                                        var oldPanel = document.getElementById('practice-panel');
                                        if(newPanel && oldPanel) oldPanel.parentNode.replaceChild(newPanel, oldPanel);
                                    }catch(e){ console.error(e); location.reload(); }
                                }).catch(function(){ location.reload(); });
                            }
                        }).catch(function(err){ console.error('New Question error', err); alert('Network error — see console'); });
                });
            }

            form.addEventListener('submit', function(e){
                // If the submit came from the New Question button, allow default behavior to reload
                var btn = document.activeElement;
                if(btn && btn.name === 'practice_action' && btn.value === 'new_question') return;
                // otherwise intercept and post via fetch to keep user on the same spot
                e.preventDefault();
                var fd = new FormData(form);
                // Ensure ajax flag present
                fd.append('ajax','1');
                fetch(location.pathname + location.search, { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' } })
                    .then(function(res){
                        if(!res.ok) throw new Error('HTTP ' + res.status);
                        return res.text();
                    })
                    .then(function(text){
                        // Try to parse JSON; if it fails, show server response in console for debugging
                        try{
                            var json = JSON.parse(text);
                        }catch(e){
                            console.error('Server response was not JSON:', text);
                            var resultsCol = document.querySelector('.practice-feedback-col');
                            if(resultsCol){
                                // Show server response for debugging (escaped)
                                var esc = text.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
                                resultsCol.innerHTML = '<div class="results-wrap"><h4 class="results-title">Error</h4><div class="study-feedback"><div class="translation">Server returned an unexpected response. See console for details.</div><pre style="white-space:pre-wrap;background:#fff;border:1px solid #eee;padding:8px;margin-top:8px;">'+esc+'</pre></div></div>';
                            }
                            return;
                        }
                        if(!json) return;
                        if(json.status === 'ok' && json.studyFeedback){
                            // render results in the existing results column
                            var resultsCol = document.querySelector('.practice-feedback-col');
                            if(resultsCol){
                                resultsCol.innerHTML = '<div class="results-wrap"><h4 class="results-title">Results</h4><div class="study-feedback">' + (json.studyFeedback.correct ? '<strong class="green-text">Correct!</strong>' : '<strong class="red-text">Incorrect</strong>') + '<div class="arabic-text" dir="rtl">' + json.studyFeedback.word + '</div><div class="results-details"><div><strong>Your answer:</strong> ' + (json.studyFeedback.userChoice||'-') + '</div><div>Correct: Form ' + json.studyFeedback.correctForm + ' — <span class="translation">' + json.studyFeedback.meaning + '</span></div></div></div></div>';
                            }
                        } else if(json.reload){
                            location.reload();
                        }
                    })
                    .catch(function(err){
                        console.error('Practice submit error:', err);
                        alert('Network error — see console for details');
                    });
            });
        })();
        </script>

        <br>

        <!-- End concept/study section. Practice panels follow. -->
        <?php endif; ?>

        <!-- Practice panel comes after the examples list -->
        <?php if ($view === 'identify'): ?>

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
                (<span class="translation"><?php echo htmlspecialchars($feedback['meaning'], ENT_QUOTES, 'UTF-8'); ?></span>)
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
                <span class="translation"><?php echo htmlspecialchars($feedback['meaning'], ENT_QUOTES, 'UTF-8'); ?></span>

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