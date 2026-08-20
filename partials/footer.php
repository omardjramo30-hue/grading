    </main>
    <footer class="site-footer">
        <span>&copy; <?= date('Y') ?> <?= e(config('app')['name']) ?></span>
        <span>Secure academic records</span>
    </footer>
    <script>
        const toggle = document.querySelector('.nav-toggle');
        const nav = document.querySelector('#main-nav');
        if (toggle && nav) {
            toggle.addEventListener('click', () => {
                const open = toggle.getAttribute('aria-expanded') === 'true';
                toggle.setAttribute('aria-expanded', String(!open));
                nav.classList.toggle('open', !open);
            });
        }
    </script>
</body>
</html>
