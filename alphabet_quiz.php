<?php
session_start();
?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title>Alphabet Quiz</title>
	<link rel="stylesheet" href="style.css">
	<link rel="icon" href="img/arabic_icon.png" type="image/png">
	<link rel="apple-touch-icon" href="img/arabic_icon.png">
	<link rel="manifest" href="manifest.json">
	<meta name="theme-color" content="#ffffff">
</head>
<body>

<?php include 'header.php'; ?>

<div class="quiz-container alphabet-page">
	<h1 class="arabic-text">Alphabet Quiz</h1>

	<div class="quiz-controls">
		<p>Practice recognizing letters. Choose the correct transliteration for the shown Arabic letter.</p>
		<button id="startQuiz">Start Quiz</button>

		<div id="quizArea" style="display:none; margin-top:12px;">
			<div id="quizQuestion" class="alpha-letter arabic-text" aria-live="polite"></div>
			<div id="quizOptions" class="quiz-options"></div>
			<div id="quizFeedback" class="quiz-feedback" role="status" aria-live="polite"></div>
			<div id="quizProgress" class="quiz-progress"></div>
			<button id="nextBtn" style="display:none; margin-top:8px;">Next</button>
		</div>
	</div>

</div>

<script>
// Quiz data (generated from PHP letters)
const ALPHABET = [
<?php
$letters = [
	['ا','a','alif','ألف'],
	['ب','b','ba','باء'],
	['ت','t','ta','تاء'],
	['ث','th','tha','ثاء'],
	['ج','j','jeem','جيم'],
	['ح','ḥ','ha (emphatic)','حاء'],
	['خ','kh','kha','خاء'],
	['د','d','dal','دال'],
	['ذ','dh','dhal','ذال'],
	['ر','r','ra','راء'],
	['ز','z','zay','زاي'],
	['س','s','seen','سين'],
	['ش','sh','sheen','شين'],
	['ص','ṣ','sad','صاد'],
	['ض','ḍ','dad','ضاد'],
	['ط','ṭ','ta (emphatic)','طاء'],
	['ظ','ẓ','za (emphatic)','ظاء'],
	['ع','ʿ','ayin','عين'],
	['غ','gh','ghayn','غين'],
	['ف','f','fa','فاء'],
	['ق','q','qaf','قاف'],
	['ك','k','kaf','كاف'],
	['ل','l','lam','لام'],
	['م','m','meem','ميم'],
	['ن','n','noon','نون'],
	['ه','h','ha','هاء'],
	['و','w','waw','واو'],
	['ي','y','ya','ياء'],
	['ء','ʼ','hamza','همزة']
];

foreach ($letters as $row) {
	$ch = $row[0];
	$trans = $row[1];
	$name = $row[2];
	$arabicName = $row[3] ?? '';
	echo "{ch: '" . addslashes($ch) . "', trans: '" . addslashes($trans) . "', name: '" . addslashes($name) . "', ar: '" . addslashes($arabicName) . "'},\n";
}
?>];

function shuffle(arr){
	for(let i=arr.length-1;i>0;i--){
		const j = Math.floor(Math.random()*(i+1));
		[arr[i],arr[j]]=[arr[j],arr[i]];
	}
}

const startBtn = document.getElementById('startQuiz');
const quizArea = document.getElementById('quizArea');
const questionEl = document.getElementById('quizQuestion');
const optionsEl = document.getElementById('quizOptions');
const feedbackEl = document.getElementById('quizFeedback');
const progressEl = document.getElementById('quizProgress');
const nextBtn = document.getElementById('nextBtn');

let pool = [];
let current = null;
let score = 0;
let total = 0;

function pickQuestion(){
	if(pool.length===0) return null;
	return pool.pop();
}

function renderQuestion(){
	feedbackEl.textContent = '';
	nextBtn.style.display = 'none';
	current = pickQuestion();
	if(!current){
		questionEl.textContent = '';
		optionsEl.innerHTML = '';
		progressEl.textContent = `Finished — Score: ${score}/${total}`;
		startBtn.textContent = 'Restart Quiz';
		return;
	}
	questionEl.textContent = current.ch;
	// build options: correct + 3 random others
	const choices = [current.trans];
	const others = ALPHABET.filter(a=>a.trans!==current.trans).map(a=>a.trans);
	shuffle(others);
	while(choices.length<4 && others.length) choices.push(others.shift());
	shuffle(choices);
	optionsEl.innerHTML = '';
	choices.forEach(opt=>{
		const btn = document.createElement('button');
		btn.className = 'option-btn';
		btn.textContent = opt;
		btn.addEventListener('pointerdown', function(e){
			// immediate response on pointerdown for fast feedback
			handleAnswer(opt, btn);
		}, {passive:true});
		optionsEl.appendChild(btn);
	});
	progressEl.textContent = `Progress: ${total - pool.length}/${total}`;
}

function handleAnswer(choice, btn){
	// disable options
	Array.from(optionsEl.children).forEach(b=>b.disabled=true);
	if(choice === current.trans){
		btn.style.background = '#e6ffed';
		btn.style.borderColor = '#2ecc71';
		feedbackEl.textContent = 'Correct!';
		score++;
	} else {
		btn.style.background = '#fff5f5';
		btn.style.borderColor = '#e74c3c';
		feedbackEl.textContent = 'Incorrect — correct: ' + current.trans;
	}
	nextBtn.style.display = 'inline-block';
}

startBtn.addEventListener('click', function(){
	// prepare pool (clone and shuffle)
	pool = ALPHABET.slice();
	shuffle(pool);
	score = 0;
	total = pool.length;
	quizArea.style.display = 'block';
	startBtn.textContent = 'Restart Quiz';
	renderQuestion();
});

nextBtn.addEventListener('click', function(){
	renderQuestion();
});

</script>

</body>
</html>
<?php include 'footer.php'; ?>
