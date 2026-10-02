<?php
session_start();
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title>Arabic Alphabet (Alif Baa)</title>
	<link rel="stylesheet" href="style.css">
	<link rel="icon" href="img/arabic_icon.png" type="image/png">
	<link rel="apple-touch-icon" href="img/arabic_icon.png">
	<link rel="manifest" href="manifest.json">
	<meta name="theme-color" content="#ffffff">
</head>
<body>

<?php include 'header.php'; ?>

<div class="quiz-container alphabet-page">
	<h1 class="arabic-text">الحروف العربية — The Arabic Alphabet</h1>

	<p class="study-info" style="direction:ltr;text-align:left;max-width:700px;">This page shows each Arabic letter with its typical shapes: isolated, beginning (initial), middle (medial), and end (final). The Arabic script connects letters; the forms below use the tatweel (ـ) to force joining so you can see the different glyphs. </p>

	<div class="alphabet-grid">
		<!-- Each card: letter, transliteration, forms -->
		<?php
		$letters = [
			// ch, transliteration, english name, arabic name
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

			// Use tatweel (\u0640) to create joining contexts for initial/medial/final
			$tatweel = '\u0640';
			// initial: letter + tatweel, medial: tatweel + letter + tatweel, final: tatweel + letter
			$initial = $ch . html_entity_decode('ـ', ENT_QUOTES, 'UTF-8');
			$medial  = html_entity_decode('ـ', ENT_QUOTES, 'UTF-8') . $ch . html_entity_decode('ـ', ENT_QUOTES, 'UTF-8');
			$final   = html_entity_decode('ـ', ENT_QUOTES, 'UTF-8') . $ch;

			// include Arabic name in data-ar for future TTS; do not advertise audio now
			echo '<div class="alpha-card" tabindex="0" data-ar="' . htmlspecialchars($row[3]) . '" title="View letter forms">';
			echo '<div class="alpha-letter arabic-text">' . htmlspecialchars($ch) . '</div>';
			echo '<div class="alpha-name">' . htmlspecialchars($name) . ' <span class="translit">(' . htmlspecialchars($trans) . ')</span></div>';
			echo '<div class="alpha-forms">';
			echo '<div><strong>Isolated</strong><div class="form">' . htmlspecialchars($ch) . '</div></div>';
			echo '<div><strong>Initial</strong><div class="form">' . $initial . '</div></div>';
			echo '<div><strong>Medial</strong><div class="form">' . $medial . '</div></div>';
			echo '<div><strong>Final</strong><div class="form">' . $final . '</div></div>';
			echo '</div>';
			echo '</div>';
		}
		?>
	</div>

	<p class="study-info" style="direction:rtl;">Tip: When letters connect they change shape depending on position. Letters like ا د ذ ر ز و only connect on the left and therefore have only two shapes instead of four.</p>

	<hr>

	<p style="text-align:center; margin-top:18px;">
	<a href="alphabet_quiz.php" class="button-link">Take the Alphabet Quiz</a>
</p>

</div>

<script>
// Optional: simple click-to-speak using SpeechSynthesis (browser support required)
(function(){
	if (!('speechSynthesis' in window)) {
		console.log('SpeechSynthesis API not supported in this browser.');
		return;
	}

	let arabicVoice = null;

	function loadVoices() {
		const voices = window.speechSynthesis.getVoices() || [];
		// prefer a voice where the lang starts with 'ar'
		arabicVoice = voices.find(v => v.lang && v.lang.toLowerCase().startsWith('ar')) || voices.find(v => /arabic/i.test(v.name));
		console.log('TTS voices loaded, selected voice:', arabicVoice);
	}

	loadVoices();
	// some browsers populate voices asynchronously
	window.speechSynthesis.onvoiceschanged = loadVoices;

	// Helper: try to play a pre-recorded audio file for slug, otherwise fallback to TTS
	function playOrSpeak(slug, text, cardEl){
		if(!slug) {
			// fallback to TTS directly
			speakText(text, cardEl);
			return;
		}

		var url = 'audio/' + encodeURIComponent(slug) + '.mp3';

		// First check whether the audio file exists to avoid a 404 in the network tab.
		// Use a HEAD request; if it succeeds, play the audio, otherwise fallback to TTS.
		fetch(url, { method: 'HEAD' }).then(function(resp){
			if (resp && resp.ok) {
				var a = new Audio(url);
				a.preload = 'auto';
				a.play().catch(function(e){
					// play failed (autoplay policy or other) -> fallback to TTS
					console.warn('Audio play failed, falling back to TTS', e);
					speakText(text, cardEl);
				});
				// add speaking class while audio is playing
				a.addEventListener('play', function(){ if(cardEl) cardEl.classList.add('speaking'); });
				a.addEventListener('ended', function(){ if(cardEl) cardEl.classList.remove('speaking'); });
				a.addEventListener('error', function(){ if(cardEl) cardEl.classList.remove('speaking'); speakText(text, cardEl); });
			} else {
				// file not found or HEAD failed -> fallback to TTS
				speakText(text, cardEl);
			}
		}).catch(function(){
			// network/permission error -> fallback to TTS
			speakText(text, cardEl);
		});
	}

	function speakText(toSpeak, cardEl){
		if (!toSpeak) return;
		var utter = new SpeechSynthesisUtterance(toSpeak);
		utter.lang = arabicVoice && arabicVoice.lang ? arabicVoice.lang : 'ar';
		if (arabicVoice) utter.voice = arabicVoice;
		utter.rate = 0.9; utter.pitch = 1;

		var started = false;
		utter.onstart = function(){ started = true; if(cardEl) cardEl.classList.add('speaking'); };
		utter.onend = function(){ started = true; if(cardEl) cardEl.classList.remove('speaking'); };
		utter.onerror = function(){ started = true; if(cardEl) cardEl.classList.remove('speaking'); };

		try{
			window.speechSynthesis.cancel();
			window.speechSynthesis.speak(utter);
		} catch(e){ console.error(e); }

		// If speech doesn't start within ~300ms, assume TTS failed (no voice) and play a short beep pattern as fallback.
		setTimeout(function(){
			if (!started) {
				try {
					window.speechSynthesis.cancel();
				} catch(e){}
				// play a short beep pattern based on the text to give immediate auditory feedback
				playBeepPattern(toSpeak, cardEl);
			}
		}, 300);
	}

	// Play a short beep pattern derived from the input text (deterministic) using WebAudio
	function playBeepPattern(seed, cardEl){
		try{
			var AudioCtx = window.AudioContext || window.webkitAudioContext;
			if (!AudioCtx) return;
			var ctx = new AudioCtx();
			// simple deterministic hash to get base frequency
			var h = 0;
			for(var i=0;i<seed.length;i++){ h = ((h<<5)-h) + seed.charCodeAt(i); h = h & h; }
			var base = 440 + (Math.abs(h) % 300); // 440-740Hz
			var pattern = [0, 0.12, 0.12];
			var now = ctx.currentTime;
			pattern.forEach(function(dur, idx){
				var osc = ctx.createOscillator();
				var gain = ctx.createGain();
				var freq = base * (1 + (idx*0.08));
				osc.type = 'sine';
				osc.frequency.value = freq;
				gain.gain.setValueAtTime(0.0001, now + idx*0.18);
				gain.gain.exponentialRampToValueAtTime(0.2, now + idx*0.18 + 0.01);
				gain.gain.exponentialRampToValueAtTime(0.0001, now + idx*0.18 + 0.18);
				osc.connect(gain);
				gain.connect(ctx.destination);
				osc.start(now + idx*0.18);
				osc.stop(now + idx*0.18 + 0.18);
			});
			// clean up after 1s
			setTimeout(function(){ try{ ctx.close(); }catch(e){} if(cardEl) cardEl.classList.remove('speaking'); }, 1000);
			if(cardEl) cardEl.classList.add('speaking');
		}catch(e){ console.error('beep failed', e); }
	}

	// Audio is disabled for now. Provide a small visual highlight on tap/click instead.
	document.querySelectorAll('.alpha-card').forEach(function(card){
		card.addEventListener('click', function(){
			card.classList.add('active');
			setTimeout(function(){ card.classList.remove('active'); }, 220);
		});
	});
})();
</script>

<?php include 'footer.php'; ?>

</body>
</html>
