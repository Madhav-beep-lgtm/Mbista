/* Turn opt-in admin form cards into a consistent New/Edit modal editor. */
(function () {
    'use strict';
    function text(node) { return (node && node.textContent || '').replace(/\s+/g, ' ').trim(); }
    function setup(card) {
        if (card.dataset.formPopupReady || !window.HTMLDialogElement) { return; }
        card.dataset.formPopupReady = '1';
        var heading = card.querySelector('.mbw-card-head h2');
        var title = text(heading) || 'Editor';
        var dialog = document.createElement('dialog');
        dialog.className = 'jw-order-dialog';
        dialog.setAttribute('aria-label', title);
        dialog.hidden = true;
        card.parentNode.insertBefore(dialog, card);
        dialog.appendChild(card);
        // The stylesheet hides popup sources before this script moves them,
        // preventing a one-frame in-page flash on direct popup URLs.
        card.style.visibility = 'visible';
        var close = document.createElement('button');
        close.type = 'button'; close.className = 'button secondary'; close.textContent = 'Close';
        var head = card.querySelector('.mbw-card-head');
        if (head) { head.appendChild(close); }
        function openDialog() { dialog.hidden = false; dialog.showModal(); }
        close.addEventListener('click', function () { dialog.close(); });
        dialog.addEventListener('close', function () { dialog.hidden = true; });
        dialog.addEventListener('click', function (event) { if (event.target === dialog) { dialog.close(); } });
        if (card.dataset.popupOpen === '1') { openDialog(); return; }
        var launch = document.createElement('button');
        launch.type = 'button'; launch.className = 'button';
        // Some form workspaces make every direct button fill the row. A popup
        // launcher is an action, not a form submit row, so keep it compact.
        launch.style.cssText = 'width:max-content!important;display:inline-flex!important;flex:0 0 auto;align-self:flex-start;margin:0 0 14px;';
        launch.textContent = card.dataset.popupLabel || title;
        dialog.parentNode.insertBefore(launch, dialog);
        launch.addEventListener('click', openDialog);
    }
    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('[data-form-popup]').forEach(setup);
    });
})();
