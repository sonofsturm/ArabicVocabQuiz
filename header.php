 <?php

$isLoggedIn = isset($_SESSION['user_id']);

?>

<button id="hamburger" aria-label="Menu" aria-expanded="false" style="position:fixed; right:12px; top:8px; z-index:99999;">☰</button>

<div class="top-bar">

    <div class="nav-left">
        <a class="home-link" href="index.php" aria-label="Home">
            <img src="img/arabic_icon.png" alt="Home" class="home-img">
            <span class="home-text">Home</span>
        </a>
    </div>

    <div class="nav-right">
        <nav class="menu" aria-label="Main menu">

        <?php if ($isLoggedIn): ?>

            <a href="account.php">Account</a>
            <a href="alphabet.php">Alphabet</a>
            <a href="alphabet_quiz.php">Alphabet Quiz</a>
            <?php if (($_SESSION['role'] ?? '') === 'admin'): ?>
            <a href="admin_users.php">Admin Users</a>
            <?php endif; ?>
            <a href="progress.php">My Progress</a>

            <a href="logout.php">Logout</a>

        <?php else: ?>

            <?php if (basename($_SERVER['PHP_SELF']) !== 'login.php'): ?>
                <a href="login.php">Login</a>
            <?php endif; ?>

            <?php if (basename($_SERVER['PHP_SELF']) !== 'register.php'): ?>
                <a href="register.php">Create Account</a>
            <?php endif; ?>
            <a href="alphabet.php">Alphabet</a>
            <a href="alphabet_quiz.php">Alphabet Quiz</a>

        <?php endif; ?>

        </nav>
    </div>

</div>

<script>
// Simple hamburger toggle: adds/removes 'open' class on the menu and updates aria-expanded
(function(){
    var btn = document.getElementById('hamburger');
    var menu = document.querySelector('.top-bar .menu');
    if (!btn || !menu) return;
    btn.addEventListener('click', function(){
        var isOpen = menu.classList.toggle('open');
        btn.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
    });
    // close menu when clicking outside on small screens
    document.addEventListener('click', function(e){
        if (!menu.contains(e.target) && e.target !== btn) {
            if (menu.classList.contains('open')) {
                menu.classList.remove('open');
                btn.setAttribute('aria-expanded','false');
            }
        }
    });
})();
</script>

<script>
// Ensure the hamburger is a direct child of body and pinned to the viewport.
// This works around layout/transform issues where fixed positioning becomes relative to an ancestor.
(function(){
    var btn = document.getElementById('hamburger');
    if (!btn) return;
    // move to body if not already
    if (btn.parentNode !== document.body) {
        document.body.appendChild(btn);
    }
    // enforce fixed placement
    btn.style.position = 'fixed';
    btn.style.right = '12px';
    btn.style.top = '8px';
    btn.style.zIndex = '99999';
    btn.style.margin = '0';
})();
</script>

<script>
// Show/hide hamburger based on viewport width to ensure it does not appear on desktop
(function(){
    var btn = document.getElementById('hamburger');
    if(!btn) return;
    function update(){
        if(window.innerWidth >= 769){
            btn.style.display = 'none';
        } else {
            btn.style.display = 'block';
        }
    }
    update();
    window.addEventListener('resize', update);
})();
</script>

<script>
// Adjust body padding to match the actual height of the fixed top-bar.
// Call again after fonts load and with a small delay to handle layout shifts.
(function(){
    function adjustBodyPadding(){
        var top = document.querySelector('.top-bar');
        if(!top) return;
        var rect = top.getBoundingClientRect();
        var h = Math.ceil(rect.height || (rect.bottom - rect.top));
        // add a small buffer so large page titles don't touch the header
        var buffer = 8;
        document.body.style.paddingTop = (h + buffer) + 'px';
    }

    // Run at key moments
    window.addEventListener('load', function(){ adjustBodyPadding(); setTimeout(adjustBodyPadding, 300); });
    window.addEventListener('resize', adjustBodyPadding);

    // When fonts finish loading they can change header height
    if (document.fonts && document.fonts.ready) {
        document.fonts.ready.then(function(){ setTimeout(adjustBodyPadding, 100); });
    }

    var btn = document.getElementById('hamburger');
    if(btn){
        btn.addEventListener('click', function(){ setTimeout(adjustBodyPadding, 220); });
    }

    var menu = document.querySelector('.top-bar .menu');
    if(menu && typeof MutationObserver !== 'undefined'){
        new MutationObserver(function(){ setTimeout(adjustBodyPadding, 50); }).observe(menu, { attributes: true, childList: true, subtree: true });
    }

    // initial attempt immediately
    adjustBodyPadding();
    // another small delayed attempt to catch late layout shifts
    setTimeout(adjustBodyPadding, 600);
})();
</script>

<?php if ($isLoggedIn): ?>
    <div class="welcome-spot">
        Welcome, <?php echo htmlspecialchars($_SESSION['first_name']); ?>
    </div>
<?php endif; ?>
