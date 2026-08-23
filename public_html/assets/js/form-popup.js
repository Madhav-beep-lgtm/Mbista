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
        card.parentNode.insertBefore(dialog, card);
        dialog.appendChild(card);
        var close = document.createElement('button');
        close.type = 'button'; close.className = 'button secondary'; close.textContent = 'Close';
        var head = card.querySelector('.mbw-card-head');
        if (head) { head.appendChild(close); }
        close.addEventListener('click', function () { dialog.close(); });
        dialog.addEventListener('click', function (event) { if (event.target === dialog) { dialog.close(); } });
        if (card.dataset.popupOpen === '1') { dialog.showModal(); return; }
        var launch = document.createElement('button');
        launch.type = 'button'; launch.className = 'button';
        // Some form workspaces make every direct button fill the row. A popup
        // launcher is an action, not a form submit row, so keep it compact.
        launch.style.cssText = 'width:max-content!important;display:inline-flex!important;flex:0 0 auto;align-self:flex-start;margin:0 0 14px;';
        launch.textContent = card.dataset.popupLabel || title;
        dialog.parentNode.insertBefore(launch, dialog);
        launch.addEventListener('click', function () { dialog.showModal(); });
    }
    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('[data-form-popup]').forEach(setup);
    });
})();
