/* Turn opt-in admin form cards into a consistent New/Edit modal editor. */
(function () {
    'use strict';
    function text(node) { return (node && node.textContent || '').replace(/\s+/g, ' ').trim(); }
    function setup(card) {
        if (card.dataset.formPopupReady) { return; }
        card.dataset.formPopupReady = '1';
        if (!window.HTMLDialogElement) {
            // No <dialog> here. The stylesheet has already hidden this card
            // waiting for us, so leaving now would hide the form for good --
            // it stays in the page instead, which is what it was before
            // anybody thought of putting it in a popup.
            card.style.display = '';
            return;
        }
        var heading = card.querySelector('.mbw-card-head h2, .jw-card-head h2');
        var title = text(heading) || 'Editor';
        var dialog = document.createElement('dialog');
        dialog.className = 'jw-order-dialog';
        dialog.setAttribute('aria-label', title);
        dialog.hidden = true;
        card.parentNode.insertBefore(dialog, card);
        dialog.appendChild(card);
        // The stylesheet hides popup sources before this script moves them,
        // preventing a one-frame in-page flash on direct popup URLs. It hides
        // them with display, so this is what puts the card back on screen.
        card.style.display = '';
        var close = document.createElement('button');
        close.type = 'button'; close.className = 'button secondary'; close.textContent = 'Close';
        var head = card.querySelector('.mbw-card-head, .jw-card-head');
        if (head) { head.appendChild(close); }
        var launch = document.createElement('button');
        launch.type = 'button'; launch.className = 'button';
        // Some form workspaces make every direct button fill the row. A popup
        // launcher is an action, not a form submit row, so keep it compact.
        launch.style.cssText = 'width:max-content!important;display:inline-flex!important;flex:0 0 auto;align-self:flex-start;margin:0 0 14px;';
        launch.textContent = card.dataset.popupLabel || title;
        dialog.parentNode.insertBefore(launch, dialog);
        function openDialog() { launch.hidden = true; dialog.hidden = false; dialog.showModal(); }
        close.addEventListener('click', function () { dialog.close(); });
        dialog.addEventListener('close', function () { dialog.hidden = true; launch.hidden = false; });
        dialog.addEventListener('click', function (event) { if (event.target === dialog) { dialog.close(); } });
        launch.addEventListener('click', openDialog);
        if (card.dataset.popupOpen === '1') { openDialog(); }
    }
    function boot() {
        document.querySelectorAll('[data-form-popup]').forEach(setup);
    }
    // This script is loaded at the foot of the document, so by the time it
    // runs the cards it is looking for have already been parsed. Waiting for
    // DOMContentLoaded on top of that only holds the form back further, and
    // the form is hidden until this runs.
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
