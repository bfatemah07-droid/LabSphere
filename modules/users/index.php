<?php

require '../../config/database.php';
require '../../includes/auth.php';

require_role(['Admin']);

/*
|--------------------------------------------------------------------------
| Delete User
|--------------------------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_user'])) {

    verify_csrf();

    $deleteId = (int) ($_POST['delete_id'] ?? 0);

    /*
     * Prevent the admin from deleting the account
     * currently being used.
     */
    if ($deleteId === (int) user()['id']) {

        flash(
            'danger',
            'You cannot delete your currently logged-in account.'
        );

        header('Location: index.php');
        exit;
    }

    /*
     * Check that the user exists.
     */
    $statement = $pdo->prepare(
        'SELECT id, name, email
         FROM users
         WHERE id = ?'
    );

    $statement->execute([$deleteId]);

    $userToDelete = $statement->fetch();

    if (!$userToDelete) {

        flash('danger', 'User not found.');

        header('Location: index.php');
        exit;
    }

    try {

        /*
         * Delete the selected user.
         */
        $statement = $pdo->prepare(
            'DELETE FROM users WHERE id = ?'
        );

        $statement->execute([$deleteId]);

        /*
         * Save the action in audit logs.
         */
        audit(
            $pdo,
            'DELETE',
            'Users',
            $userToDelete['email']
        );

        flash('success', 'User deleted successfully.');

    } catch (PDOException $exception) {

        /*
         * This may happen if the user has reservations
         * or other connected records.
         */
        flash(
            'danger',
            'This user cannot be deleted because they have related records.'
        );
    }

    header('Location: index.php');
    exit;
}

/*
|--------------------------------------------------------------------------
| Get All Users
|--------------------------------------------------------------------------
*/
$rows = $pdo
    ->query('SELECT * FROM users ORDER BY id DESC')
    ->fetchAll();

$page_title = 'Users';

require '../../includes/header.php';

?>

<div class="d-flex justify-content-between align-items-center mb-4">

    <h2 class="mb-0">
        Users
    </h2>

    <a
        href="create.php"
        class="btn btn-primary"
    >
        <i class="bi bi-plus-lg"></i>

        Add User
    </a>

</div>

<div class="card p-3">

    <div class="table-responsive">

        <table class="table align-middle mb-0">

            <thead>

                <tr>

                    <th>Name</th>

                    <th>Email</th>

                    <th>Role</th>

                    <th>Active</th>

                    <th class="text-end">
                        Actions
                    </th>

                </tr>

            </thead>

            <tbody>

                <?php if (empty($rows)): ?>

                    <tr>

                        <td
                            colspan="5"
                            class="text-center text-muted py-4"
                        >
                            No users found.
                        </td>

                    </tr>

                <?php else: ?>

                    <?php foreach ($rows as $row): ?>

                        <tr>

                            <td>
                                <?= e($row['name']) ?>
                            </td>

                            <td>
                                <?= e($row['email']) ?>
                            </td>

                            <td>
                                <?= e($row['role']) ?>
                            </td>

                            <td>

                                <?php if ($row['active']): ?>

                                    <span class="badge text-bg-success">
                                        Yes
                                    </span>

                                <?php else: ?>

                                    <span class="badge text-bg-secondary">
                                        No
                                    </span>

                                <?php endif; ?>

                            </td>

                            <td class="text-end">

                                <div class="d-inline-flex gap-2">

                                    <!-- Edit button -->

                                    <a
                                        href="create.php?edit=<?= $row['id'] ?>"
                                        class="btn btn-sm btn-outline-primary"
                                    >
                                        <i class="bi bi-pencil"></i>

                                        Edit
                                    </a>

                                    <!-- Delete button -->

                                    <?php if (
                                        (int) $row['id'] !==
                                        (int) user()['id']
                                    ): ?>

                                        <form
                                            method="post"
                                            class="d-inline"
                                            onsubmit="
                                                return confirm(
                                                    'Are you sure you want to delete this user?'
                                                );
                                            "
                                        >

                                            <input
                                                type="hidden"
                                                name="csrf"
                                                value="<?= csrf_token() ?>"
                                            >

                                            <input
                                                type="hidden"
                                                name="delete_id"
                                                value="<?= $row['id'] ?>"
                                            >

                                            <button
                                                type="submit"
                                                name="delete_user"
                                                class="btn btn-sm btn-outline-danger"
                                            >
                                                <i class="bi bi-trash"></i>

                                                Delete
                                            </button>

                                        </form>

                                    <?php endif; ?>

                                </div>

                            </td>

                        </tr>

                    <?php endforeach; ?>

                <?php endif; ?>

            </tbody>

        </table>

    </div>

</div>

<?php require '../../includes/footer.php'; ?>