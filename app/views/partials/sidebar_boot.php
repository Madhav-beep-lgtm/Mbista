<?php
/**
 * Restores the collapsed sidebar before the first paint.
 *
 * This has to be inline and it has to be here, immediately inside <body>: the
 * class it sets lives on <body>, so the element must already exist, and if the
 * work waited for main.js in the footer then every single page load would paint
 * the full sidebar and then snap it shut. One flash per navigation is worse
 * than the sidebar not remembering anything at all.
 */
?>
<script>
(function () {
    try {
        if (localStorage.getItem('mbwSidebarCollapsed') === '1') {
            document.body.classList.add('sidebar-collapsed');
        }
    } catch (error) {
        // Private browsing refuses storage. The sidebar simply starts open.
    }
})();
</script>
