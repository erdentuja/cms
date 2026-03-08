</div><!-- /.content -->

<footer>
    <div class="container">
        <div class="footer-grid">
            <div class="footer-col">
                <h4>LEXODUS Kft.</h4>
                <p style="font-size: 0.9rem; line-height: 1.6; margin-bottom: 20px;">
                    Társaságunk az IFS magyarországi képviselete. Professzionális megoldások az élelmiszerbiztonság és
                    minőségirányítás területén.
                </p>
                <a href="/kapcsolat" class="btn-modern" style="padding: 10px 20px; font-size: 0.85rem;">Konzultáció
                    kérése</a>
            </div>

            <div class="footer-col">
                <h4>Navigáció</h4>
                <ul>
                    <li><a href="<?php echo UrlHelper::link(); ?>">Kezdőlap</a></li>
                    <li><a href="/rolunk">Rólunk</a></li>
                    <li><a href="/szolgaltatasok">Szolgáltatások</a></li>
                    <li><a href="/blog">Blog</a></li>
                    <li><a href="/kapcsolat">Kapcsolat</a></li>
                </ul>
            </div>

            <div class="footer-col">
                <h4>Kapcsolat</h4>
                <ul style="color: #cbd5e1; font-size: 0.9rem;">
                    <li>📍 1111 Budapest, Példa utca 1.</li>
                    <li>📞 +36 1 234 5678</li>
                    <li>📧 info@lexodus.hu</li>
                    <li>🌐 www.lexodus.hu</li>
                </ul>
            </div>
        </div>

        <div class="footer-bottom">
            <p>
                &copy; <?php echo date('Y'); ?>
                <?php echo htmlspecialchars($settings['site_info']['name'] ?? 'LEXODUS Kft.'); ?>.
                Minden jog fenntartva.
            </p>
        </div>
    </div>
</footer>

<script src="https://cdnjs.cloudflare.com/ajax/libs/fslightbox/3.4.1/index.min.js"></script>
</body>

</html>