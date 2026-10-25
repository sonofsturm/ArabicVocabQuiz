<?php
session_start();
header('Content-Type: text/html; charset=utf-8');
// A tiny single-page exercise: Verb DNA
// For now use a tiny dataset with one canonical example; extend later.

$DATA = [
	[
		'word' => 'اِسْتَغْفَرَ',
		'root' => ['غ','ف','ر'],
		'form' => 'X',
		'meaning' => 'to seek forgiveness',
		'pieces' => ['است','غ','ف','ر']
	]
];

$example = $DATA[0];

if (!isset($_SESSION['verb_dna_score'])) {
	$_SESSION['verb_dna_score'] = ['root'=>0,'form'=>0,'meaning'=>0,'total'=>0];
}

$feedback = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	$meaningInput = trim($_POST['meaning'] ?? '');
	$rootInput = trim($_POST['root'] ?? '');
	$formInput = trim($_POST['form'] ?? '');
	$patternInput = trim($_POST['pattern'] ?? '');

	// Normalize root: split on non-letter characters and keep first 3
	$rootParts = preg_split('/[^\p{Arabic}]+/u', $rootInput, -1, PREG_SPLIT_NO_EMPTY);
	// sometimes user types with spaces: explode by space
	if (empty($rootParts)) {
		$rootParts = preg_split('/\s+/', $rootInput, -1, PREG_SPLIT_NO_EMPTY);
	}

	// Normalize pattern and meaning simple trimming
	$scoreAdd = ['root'=>0,'form'=>0,'meaning'=>0];

	// root check: compare arrays ignoring surrounding marks
	$expectedRoot = $example['root'];
	$rp = array_values(array_filter(array_map('trim',$rootParts)));
	// If user entered single string with hyphens like غ-ف-ر
	if (count($rp) === 1 && preg_match('/[\p{Arabic}].*[\p{Arabic}]/u', $rp[0])) {
		$rp = preg_split('/[^\p{Arabic}]+/u', $rp[0], -1, PREG_SPLIT_NO_EMPTY);
	}
	$rp = array_values(array_slice($rp,0,3));
	if (count($rp) === 3 && $rp === $expectedRoot) { $scoreAdd['root'] = 100; $_SESSION['verb_dna_score']['root'] += 100; }

	if (strcasecmp($formInput, $example['form']) === 0) { $scoreAdd['form'] = 100; $_SESSION['verb_dna_score']['form'] += 100; }

	// meaning: simple case-insensitive contains
	if (stripos($example['meaning'], $meaningInput) !== false || stripos($meaningInput, $example['meaning']) !== false) { $scoreAdd['meaning'] = 100; $_SESSION['verb_dna_score']['meaning'] += 100; }

	$scoreAdd['total'] = array_sum($scoreAdd);
	$_SESSION['verb_dna_score']['total'] += $scoreAdd['total'];

	$feedback = $scoreAdd;
}

?>
<!doctype html>
<html lang="en">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width,initial-scale=1">
	<title>Verb DNA</title>
	<link rel="stylesheet" href="../style.css?v=10">
	<style>
	.verb-dna { max-width:900px; margin:18px auto; text-align:center; }
	.verb-dna .word { font-size:48px; color:#1b7a1b; margin:10px 0; }
	.verb-dna .field { margin:8px 0; }
	.verb-dna input[type="text"] { font-size:20px; padding:6px 8px; }
	.verb-dna select { font-size:18px; padding:6px 8px; }
	.verb-dna .results { margin-top:16px; }
	.assembly { font-size:36px; color:#1b7a1b; margin-top:12px; }
	.score-row { display:flex; gap:12px; justify-content:center; margin-top:10px; }
	.score-box { background:#fff; border:1px solid #ddd; padding:8px 12px; border-radius:6px; min-width:120px; }
	</style>
</head>
<body>
<?php include '../header.php'; ?>

<div class="games-container verb-dna">
	<h1>Verb DNA</h1>

	<div class="word" dir="rtl"><?php echo htmlspecialchars($example['word'], ENT_QUOTES, 'UTF-8'); ?></div>

	<p>Identify the components (ROOT, FORM, PATTERN, MEANING) of the verb above. Each correct part awards +100 points.</p>
	<!-- no-op context refresh -->

	<form method="post" id="dnaForm">
		<div class="field">
			<label>MEANING (English): <input type="text" name="meaning" placeholder="to seek forgiveness"></label>
		</div>
		<div class="field">
			<label>ROOT (three letters): <input type="text" name="root" value="" autocomplete="off" dir="rtl" aria-label="Root"></label>
		</div>
		<div class="field">
			<label>FORM: <select name="form">
				<option value="">-- pick --</option>
				<?php foreach (['I','II','III','IV','V','VI','VII','VIII','IX','X'] as $f): ?>
					<option value="<?php echo $f; ?>"><?php echo $f; ?></option>
				<?php endforeach; ?>
			</select></label>
		</div>
		<div class="field">
			<label>PATTERN: <input type="text" name="pattern" value="" autocomplete="off" aria-label="Pattern"></label>
		</div>

		<div class="field">
			<button type="submit">Check</button>
			<button type="button" id="showDNA">Show DNA</button>
		</div>
	</form>

	<?php if ($feedback !== null): ?>
		<div class="results">
			<h3>Results</h3>
			<div class="score-row">
				<div class="score-box">Root: <?php echo $feedback['root']; ?> pts</div>
				<div class="score-box">Form: <?php echo $feedback['form']; ?> pts</div>
				<div class="score-box">Meaning: <?php echo $feedback['meaning']; ?> pts</div>
			</div>
			<div class="assembly" id="assemblyArea"></div>
			<div style="margin-top:8px;">Session total: <?php echo (int)$_SESSION['verb_dna_score']['total']; ?> pts</div>
		</div>
	<?php endif; ?>

</div>

<script>
const pieces = <?php echo json_encode($example['pieces'], JSON_UNESCAPED_UNICODE); ?>;
const finalWord = <?php echo json_encode($example['word'], JSON_UNESCAPED_UNICODE); ?>;
document.getElementById('showDNA').addEventListener('click', function(){
	// animate: است + غ ف ر -> word
	const area = document.getElementById('assemblyArea');
	area.innerHTML = '';
	const span = document.createElement('div'); span.style.direction='rtl'; span.style.fontSize='36px'; span.style.color='#1b7a1b';
	span.textContent = pieces.join(' + ') + ' → ' + finalWord;
	area.appendChild(span);
});

// If server returned feedback, auto-show DNA assembly
<?php if ($feedback !== null): ?>
document.addEventListener('DOMContentLoaded', function(){ document.getElementById('showDNA').click(); });
<?php endif; ?>
</script>

<script>
// Clear any browser autofill in the PATTERN field on load and shortly after to ensure it is blank
(function(){
	function clearPattern(){
		var p = document.querySelector('input[name="pattern"]');
		if(!p) return;
		try{ p.value = ''; p.setAttribute('autocomplete','off'); }
		catch(e){}
	}
	document.addEventListener('DOMContentLoaded', function(){ clearPattern(); setTimeout(clearPattern, 150); setTimeout(clearPattern, 800); });
	// Also clear on focus to avoid prefilled value persisting
	var p2 = document.querySelector('input[name="pattern"]');
	if(p2){ p2.addEventListener('focus', function(){ this.value = ''; }); }
})();
</script>

</body>
</html>
