<?php
/**
 * The collapse control for the sidebar.
 *
 * It sits in the topbar and not in the sidebar itself. Once collapsed the
 * sidebar is a 62px rail with no room for a control that still reads as one,
 * and a button that moves the moment you press it is hard to press twice.
 * Here it keeps the same place whether the sidebar is open or shut.
 *
 * The label says what the press will DO, not what the state is, and main.js
 * rewrites it on every toggle.
 */
?>
<button type="button" class="admin-icon-button admin-sidebar-toggle" data-sidebar-toggle
        aria-controls="adminSidebar" aria-expanded="true"
        aria-label="Collapse sidebar" title="Collapse sidebar (Ctrl + B)"><?= icon('sidebar') ?></button>
