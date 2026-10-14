<?php if (!empty($profile['company_id'])): ?>
<div class="actions">
 <a class="button secondary" href="<?= e(url('admin/workspace.php?view=clients&mode=view&client_id=' . (int)$profile['id'])) ?>">View</a>
 <a class="button secondary" href="<?= e(url('admin/workspace.php?view=clients&mode=edit&client_id=' . (int)$profile['id'])) ?>">Edit</a>
 <form method="post" action="<?= e(url('admin/workspace.php?view=clients')) ?>">
  <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="client_id" value="<?= (int)$profile['id'] ?>">
  <button class="button secondary" name="action" value="<?= ($profile['client_status'] ?? 'active') === 'active' ? 'suspend_client' : 'activate_client' ?>"><?= ($profile['client_status'] ?? 'active') === 'active' ? 'Suspend' : 'Activate' ?></button>
 </form>
 <form method="post" action="<?= e(url('admin/workspace.php?view=clients')) ?>" onsubmit="return confirm('Delete this client? Related records will cause a safe suspension instead.');">
  <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="client_id" value="<?= (int)$profile['id'] ?>">
  <button class="button secondary" name="action" value="delete_client">Delete</button>
 </form>
</div>
<?php endif; ?>
