<?php
session_start();
header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title>Build the Verb</title>
	<link rel="stylesheet" href="../style.css?v=10">
	<style>
	/* Minimal page styles specific to this game */
	.build-verb { max-width: 980px; margin: 12px auto; }
	.build-verb { max-width: 980px; margin: 12px auto; text-align: center; }
	.build-verb .mission { font-weight:700; margin:8px auto 12px; }
	.pieces { display:flex; gap:8px; flex-wrap:wrap; margin:12px auto; justify-content: center; }
	.piece { background: #fff; border:1px solid #ddd; padding:10px 14px; border-radius:6px; font-size:36px; cursor:grab; color:#1b7a1b; }
	.slots { display:flex; gap:6px; margin: 18px auto; justify-content: center; }
	.slot { min-width:48px; min-height:48px; border-bottom:2px dashed #ccc; display:flex; align-items:center; justify-content:center; font-size:36px; color:#1b7a1b; }
	.slot.filled { border-bottom:2px solid #333; }
	.controls { margin:12px auto; display:flex; gap:8px; justify-content:center; }
	.results-area { margin:16px auto; text-align:center; }
	.assembly-anim { margin:10px auto; font-size:36px; color:green; display:block; text-align:center; }
	/* animation for assembly line */
	.piece-anim { display:inline-block; margin:0 6px; opacity:0; transform:translateY(-8px); font-size:34px; }
	.final-word { display:inline-block; margin-left:12px; opacity:0; transform:scale(.98); font-size:40px; }
	@keyframes assembleIn { to { opacity:1; transform:none; } }
	@keyframes finalPop { 0% { opacity:0; transform:scale(.98) translateY(-6px);} 60% { transform:scale(1.06) translateY(0);} 100% { opacity:1; transform:scale(1) translateY(0);} }
	.pieces.dragging { opacity: .6; }
	.piece[draggable="true"]:active { opacity:.6; }
	</style>
</head>
<body>
<?php include '../header.php'; ?>

<div class="games-container build-verb">
	<h1>Build the Verb</h1>

	<p>
		<a href="verb_forms.php?view=concept">Learn the Forms</a>
		&nbsp;|&nbsp;
		<a href="verb_forms.php?view=study">Study &amp; Practice</a>
		&nbsp;|&nbsp;
		<a href="build_verb.php">Build the Verb</a>
		&nbsp;|&nbsp;
		<a href="verb_forms.php?view=identify">Identify the Form</a>
		&nbsp;|&nbsp;
		<a href="verb_forms.php?view=produce">Produce the Conjugation</a>
	</p>

	<div id="game-root"></div>

	<div class="mission">Mission: <span id="mission-target"></span></div>

	<div class="pieces" id="pieces" aria-label="Available pieces" role="list"></div>

	<div class="slots" id="slots" aria-label="Construction slots" role="list"></div>

	<div class="controls">
		<button id="checkBtn">Check Answer</button>
		<button id="resetBtn">Reset</button>
		<button id="newBtn">New Challenge</button>
	</div>

	<div class="results-area" id="results"></div>

</div>

<script>
// Small data set mirroring verb_forms_data
const DATA = [
	{ root: 'ك-ت-ب', rootLetters: ['ك','ت','ب'], forms: {
		'III': {word: 'كَاتَبَ', meaning: 'he corresponded with', pattern: 'فَاعَلَ', pieces: ['ك','ا','ت','ب']}
	}},
	{ root: 'ع-ل-م', rootLetters: ['ع','ل','م'], forms: {
		'II': {word: 'عَلَّمَ', meaning: 'he taught', pattern: 'فَعَّلَ', pieces: ['ع','ل','ّ','م']}
	}},
	{ root: 'خ-ر-ج', rootLetters: ['خ','ر','ج'], forms: {
		'IV': {word: 'أَخْرَجَ', meaning: 'he took out / produced', pattern: 'أَفْعَلَ', pieces: ['أ','خ','ر','ج']}
	}},
];

let current = null;
let placed = [];

function shuffle(a){ for(let i=a.length-1;i>0;i--){ const j=Math.floor(Math.random()*(i+1)); [a[i],a[j]]=[a[j],a[i]];} return a; }

function startChallenge(){
	const idx = Math.floor(Math.random()*DATA.length);
	const entry = DATA[idx];
	// pick a form randomly from available
	const formKeys = Object.keys(entry.forms);
	const fk = formKeys[Math.floor(Math.random()*formKeys.length)];
	current = { entry, formKey: fk, spec: entry.forms[fk] };
	document.getElementById('game-root').innerHTML = '<strong>Root:</strong> <span class="arabic-text inline-arabic">'+entry.root+'</span>';
	document.getElementById('mission-target').textContent = 'Build Form ' + fk;

	// Build pieces: include pieces from spec plus extra distractors (some husks)
	let pieces = Array.from(current.spec.pieces);
	// add some distractor diacritics or letters to make it interesting
	const extras = ['است','ت','ّ','ا','َ','ُ'];
	for(let i=0;i<2;i++){ pieces.push(extras[Math.floor(Math.random()*extras.length)]); }
	pieces = shuffle(pieces);

	const piecesDiv = document.getElementById('pieces'); piecesDiv.innerHTML = '';
	pieces.forEach((p, i) => {
		const el = document.createElement('div'); el.className='piece'; el.draggable=true; el.dataset.val=p; el.textContent=p;
		el.addEventListener('dragstart', onDragStart);
		el.addEventListener('click', onPieceClick);
		piecesDiv.appendChild(el);
	});

	const slots = document.getElementById('slots'); slots.innerHTML = '';
	// number of slots equal to spec piece count
	// Create slots arranged right-to-left: highest index on the right visually
	for(let i=0;i<current.spec.pieces.length;i++){
		const s = document.createElement('div'); s.className='slot'; s.dataset.index=i; s.addEventListener('dragover', onDragOver); s.addEventListener('drop', onDrop);
		s.addEventListener('click', onSlotClick);
		slots.appendChild(s);
	}

	placed = new Array(current.spec.pieces.length).fill(null);
	document.getElementById('results').innerHTML = '';
}

function onDragStart(e){ e.dataTransfer.setData('text/plain', e.target.dataset.val); e.dataTransfer.setData('text/id', e.target.dataset.id || ''); setTimeout(()=>e.target.classList.add('dragging'), 10); }
function onDragOver(e){ e.preventDefault(); }
function onDrop(e){ e.preventDefault(); const val = e.dataTransfer.getData('text/plain'); const slot = e.currentTarget; placePieceIntoSlot(val, slot); }

function placePieceIntoSlot(val, slot){ const idx = parseInt(slot.dataset.index,10); // find piece element of this val (first available)
	// For RTL assembly, place the clicked letters from right-to-left: map logical slot index so index 0 is rightmost
	const piecesEls = Array.from(document.querySelectorAll('.piece'));
	const match = piecesEls.find(pe=>pe.dataset.val===val && !pe.classList.contains('placed'));
	if(!match) return; match.classList.add('placed');
	// Compute visual slot index: slots are appended LTR, but Arabic should fill right-to-left
	const slotsEls = Array.from(document.querySelectorAll('.slot'));
	// Find index of the clicked slot in DOM order
	const domIdx = slotsEls.indexOf(slot);
	// Determine target visual index: place into the rightmost empty slot by default
	let targetIdx = domIdx;
	// If caller passed a slot element, use it; otherwise find the rightmost empty slot
	if (slot && slot.classList.contains('filled')) {
		const rightEmpty = slotsEls.slice().reverse().find(s=>!s.classList.contains('filled'));
		if (rightEmpty) targetIdx = slotsEls.indexOf(rightEmpty);
	}
	// Update the visual slot element and logical placed array
	const targetSlot = slotsEls[targetIdx];
	targetSlot.textContent = match.dataset.val; targetSlot.classList.add('filled');
	// Map placed array so index 0 corresponds to leftmost root letter; keep logical order matching spec
	placed[targetIdx] = match.dataset.val;
}

function onPieceClick(e){ // try to place into first empty slot
	const val = e.target.dataset.val;
	const slotsEls = Array.from(document.querySelectorAll('.slot'));
	const firstEmpty = slotsEls.find(s=>!s.classList.contains('filled'));
	if(firstEmpty) placePieceIntoSlot(val, firstEmpty);
}
function onSlotClick(e){ // remove piece from slot
	const slot = e.currentTarget; const idx = parseInt(slot.dataset.index,10);
	if(!slot.classList.contains('filled')) return; const val = slot.textContent;
	slot.textContent=''; slot.classList.remove('filled'); placed[idx]=null;
	// unmark a piece with that val (first placed)
	const el = Array.from(document.querySelectorAll('.piece.placed')).find(p=>p.dataset.val===val);
	if(el) el.classList.remove('placed');
}

function checkAnswer(){
	// Build assembled pieces array by reading slots right-to-left (so first-clicked becomes rightmost)
	const spec = current.spec.pieces;
	const slotsEls = Array.from(document.querySelectorAll('.slot'));
	const assembled = [];
	for (let i = slotsEls.length - 1; i >= 0; i--) {
		assembled.push((slotsEls[i].textContent || '').trim());
	}
	const filledCount = assembled.filter(Boolean).length;
	const results = document.getElementById('results');
	results.innerHTML = '';
	// Compare assembled pieces with spec pieces (piece-level comparison)
	const ok = (filledCount === spec.length) && spec.every((s, i) => assembled[i] === s);
	if (ok) {
		// Show RTL assembly sequence (pieces should appear right-to-left)
		const anim = document.createElement('div');
		anim.className = 'assembly-anim';
		anim.setAttribute('dir', 'rtl');
		anim.textContent = assembled.join(' + ') + ' → ' + current.spec.word;
		results.appendChild(anim);
		const info = document.createElement('div'); info.className='results-final'; info.innerHTML = '<strong>Form '+current.formKey+':</strong> '+current.spec.pattern+'<br><span class="arabic-text">'+current.spec.word+'</span><br><span class="translation">'+current.spec.meaning+'</span>';
		results.appendChild(info);
	} else {
		results.innerHTML = '<div class="translation">Incorrect — try again or click New Challenge.</div>';
	}
}

function resetPlaced(){ document.querySelectorAll('.slot').forEach(s=>{ s.textContent=''; s.classList.remove('filled'); }); document.querySelectorAll('.piece.placed').forEach(p=>p.classList.remove('placed')); placed = placed.map(()=>null); document.getElementById('results').innerHTML=''; }

function newChallenge(){ startChallenge(); }

document.getElementById('checkBtn').addEventListener('click', checkAnswer);
document.getElementById('resetBtn').addEventListener('click', resetPlaced);
document.getElementById('newBtn').addEventListener('click', newChallenge);

startChallenge();
</script>

</body>
</html>
