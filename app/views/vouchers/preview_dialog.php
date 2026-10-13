<dialog id="voucher-preview" class="voucher-preview" aria-label="Voucher journal entry">
    <div class="voucher-preview-toolbar"><strong>Voucher journal entry</strong><button type="button" class="button soft" id="voucher-preview-close">Close</button></div>
    <iframe title="Voucher details and print" id="voucher-preview-frame"></iframe>
</dialog>
<script>
(function () {
    var dialog = document.getElementById('voucher-preview');
    var frame = document.getElementById('voucher-preview-frame');
    var opener;
    document.addEventListener('click', function (event) {
        var link = event.target.closest('[data-voucher-preview]');
        if (!link || event.ctrlKey || event.metaKey || event.shiftKey) { return; }
        event.preventDefault();
        opener = link;
        frame.src = link.href;
        dialog.showModal();
    });
    document.getElementById('voucher-preview-close').addEventListener('click', function () { dialog.close(); });
    dialog.addEventListener('close', function () { frame.src = 'about:blank'; if (opener) { opener.focus(); } });
})();
</script>
