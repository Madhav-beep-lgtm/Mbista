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
    // ON A PHONE THE SIDEBAR STARTS SHUT, whatever the desktop remembers.
    //
    // Below 900px the shell is one column, so an open sidebar is not a column
    // beside the page — it IS the page, and every visit began with a full
    // screen of navigation that had to be scrolled past before a single figure
    // appeared. The stored preference is a decision somebody made about a rail
    // on a wide monitor; it has no bearing on a 390px screen, and applying it
    // there was the whole problem.
    //
    // matchMedia, not an innerWidth read, because this runs before first paint
    // and matchMedia is the thing the CSS below agrees with.
    try {
        var narrow = window.matchMedia && window.matchMedia('(max-width: 900px)').matches;
        if (narrow || localStorage.getItem('mbwSidebarCollapsed') === '1') {
            document.body.classList.add('sidebar-collapsed');
        }
    } catch (error) {
        // Private browsing refuses storage. The sidebar simply starts open.
    }
})();
</script>
