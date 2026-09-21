<?php
declare(strict_types=1);

$pageHero = [
    'eyebrow' => 'Administration',
    'title' => 'Roles & Capabilities',
    'subtitle' => 'Each role grants a set of capabilities, WordPress-style. Assign roles to a person from their People & Users page.',
    'actions' => [
        ['label' => 'Roles & Positions', 'href' => '/admin/positions.php', 'class' => 'btn btn-outline-light btn-sm'],
    ],
];

require_once __DIR__ . '/header.php';
require_once __DIR__ . '/lib/access_roles.php';

if (!hub_auth_has_capability('admin_settings')) {
    http_response_code(403);
    echo '<div><div class="alert alert-danger">You do not have permission to manage roles & capabilities.</div></div>';
    require __DIR__ . '/footer.php';
    exit;
}

$formErrors = [];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $csrfToken = isset($_POST['csrf_token']) && is_string($_POST['csrf_token']) ? $_POST['csrf_token'] : '';
    if (!hub_auth_verify_csrf_token($csrfToken)) {
        $formErrors[] = 'Your session could not be verified. Reload the page and try again.';
    } else {
        $action = isset($_POST['action']) && is_string($_POST['action']) ? $_POST['action'] : '';

        if ($action === 'delete_role') {
            $roleId = (int) ($_POST['role_id'] ?? 0);
            $result = deleteAccessRole($pdo, $roleId);
            if ($result['ok']) {
                header('Location: access_roles.php?status=deleted');
                exit;
            }
            $formErrors[] = $result['error'];
        }
    }
}

$statusMessages = [
    'saved' => 'Role saved.',
    'created' => 'Role created.',
    'deleted' => 'Role deleted.',
];
$statusKey = isset($_GET['status']) && is_string($_GET['status']) ? $_GET['status'] : '';
$statusMessage = $statusMessages[$statusKey] ?? '';

$capabilityCount = count(getCapabilitiesCatalog($pdo));
$roles = getAccessRoles($pdo);
?>

<div class="access-roles-page">

    <?php if ($statusMessage !== ''): ?>
        <div class="alert alert-success"><?= htmlspecialchars($statusMessage, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>
    <?php if ($formErrors !== []): ?>
        <div class="alert alert-danger">
            <ul class="mb-0">
                <?php foreach ($formErrors as $formError): ?>
                    <li><?= htmlspecialchars($formError, ENT_QUOTES, 'UTF-8') ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <div class="hub-section-commandbar">
        <div><h2>Roles</h2><p>Click a role to grant or deny each of the <?= $capabilityCount ?> capabilities it has.</p></div>
        <div class="hub-local-actions">
            <a class="btn btn-brand" href="/admin/access_role_edit.php"><i class="fa-solid fa-plus me-1" aria-hidden="true"></i>Add role</a>
        </div>
    </div>

    <div class="card shadow-sm border-0 hub-table-card">
        <div class="table-responsive">
            <table class="table table-striped hub-data-table align-middle mb-0">
                <thead>
                    <tr>
                        <th>Role</th>
                        <th>Capabilities</th>
                        <th>People</th>
                        <th>Positions</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($roles as $role): ?>
                        <?php
                        $roleId = (int) $role['id'];
                        $bypassAll = (int) $role['bypass_all'] === 1;
                        $isSystem = (int) $role['is_system'] === 1;
                        $grantedCount = $bypassAll ? $capabilityCount : count(getAccessRoleCapabilitySlugs($pdo, $roleId));
                        $usage = getAccessRoleUsageCounts($pdo, $roleId);
                        ?>
                        <tr>
                            <td>
                                <a href="/admin/access_role_edit.php?id=<?= $roleId ?>"><strong><?= htmlspecialchars((string) $role['name'], ENT_QUOTES, 'UTF-8') ?></strong></a>
                                <?php if ($isSystem): ?><span class="badge text-bg-light ms-1">system</span><?php endif; ?>
                            </td>
                            <td>
                                <?php if ($bypassAll): ?>
                                    <span class="badge text-bg-success">All access</span>
                                <?php else: ?>
                                    <?= $grantedCount ?> of <?= $capabilityCount ?>
                                <?php endif; ?>
                            </td>
                            <td><?= (int) $usage['people'] ?></td>
                            <td><?= (int) $usage['positions'] ?></td>
                            <td class="text-end">
                                <div class="d-inline-flex gap-2 hub-actions hub-actions--end">
                                    <a class="btn btn-sm btn-outline-primary" href="/admin/access_role_edit.php?id=<?= $roleId ?>" title="Edit role"><i class="fa-solid fa-pen" aria-hidden="true"></i></a>
                                    <?php if (!$isSystem): ?>
                                        <form method="post" data-confirm="This cannot be undone." data-confirm-title="Delete this role?" data-confirm-action="Delete">
                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(hub_auth_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
                                            <input type="hidden" name="action" value="delete_role">
                                            <input type="hidden" name="role_id" value="<?= $roleId ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete role"><i class="fa-solid fa-trash" aria-hidden="true"></i></button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require __DIR__ . '/footer.php'; ?>
