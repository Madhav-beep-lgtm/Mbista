            <footer class="admin-foot">
                <span>&copy; <?= e(date('Y')) ?> <?= e(setting('brand_name', 'M. Bista & Associates')) ?>. All rights reserved.</span>
                <?= powered_by_mbworld() ?>
                <span>v<?= e(setting('app_version', '2.0.0')) ?></span>
            </footer>
        </div>
    </section>
</div>
<script src="<?= e(asset_url('assets/js/mbw-charts.js')) ?>"></script>
<script src="<?= e(asset_url('assets/js/nepali-date.js')) ?>"></script>
<script src="<?= e(asset_url('assets/js/main.js')) ?>"></script>
<script src="<?= e(asset_url('assets/js/searchable-select.js')) ?>"></script>
<script src="<?= e(asset_url('assets/js/draggable-panel.js')) ?>"></script>
<script src="<?= e(asset_url('assets/js/form-popup.js')) ?>"></script>
<script src="/i18n-dict.php?v=20260719"></script>
<script src="<?= e(asset_url('assets/js/i18n.js')) ?>"></script>
</body>
</html>
