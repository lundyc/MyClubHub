<?php
require_once __DIR__ . '/header.php';

$errors = [];
$success = '';
$person = member_auth_current_person();
$account = member_auth_current_account();
if (!$person || !$account) {
    header('Location: login.php');
    exit;
}

$profileCsrfToken = member_auth_csrf_token();

function member_profile_upload_image(array $file, int $personId): ?string
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Profile photo upload failed.');
    }
    if ((int) ($file['size'] ?? 0) > 2 * 1024 * 1024) {
        throw new RuntimeException('Profile photo must be under 2MB.');
    }
    $tmp = (string) ($file['tmp_name'] ?? '');
    $info = @getimagesize($tmp);
    $mime = (string) ($info['mime'] ?? '');
    $ext = match ($mime) {
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        default => '',
    };
    if ($ext === '') {
        throw new RuntimeException('Profile photo must be a JPG, PNG or WebP image.');
    }
    $dir = __DIR__ . '/../uploads/member_profiles';
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    $filename = 'person-' . $personId . '-' . bin2hex(random_bytes(5)) . '.' . $ext;
    if (!move_uploaded_file($tmp, $dir . '/' . $filename)) {
        throw new RuntimeException('Could not save profile photo.');
    }
    return 'uploads/member_profiles/' . $filename;
}

function member_profile_address_summary(array $person): string
{
    return trim(implode(', ', array_filter([
        trim((string) ($person['address_line1'] ?? '')),
        trim((string) ($person['address_line2'] ?? '')),
        trim((string) ($person['town'] ?? '')),
        trim((string) ($person['country'] ?? '')),
        trim((string) ($person['postcode'] ?? '')),
    ], static fn(string $part): bool => $part !== '')));
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $postedToken = (string) ($_POST['csrf_token'] ?? '');
    $sameOrigin = false;
    $origin = trim((string) ($_SERVER['HTTP_ORIGIN'] ?? ''));
    $referer = trim((string) ($_SERVER['HTTP_REFERER'] ?? ''));
    $host = trim((string) ($_SERVER['HTTP_HOST'] ?? ''));
    foreach ([$origin, $referer] as $sourceUrl) {
        if ($sourceUrl === '') {
            continue;
        }
        $sourceHost = parse_url($sourceUrl, PHP_URL_HOST);
        if (is_string($sourceHost) && strcasecmp($sourceHost, $host) === 0) {
            $sameOrigin = true;
            break;
        }
    }
    $csrfOk = member_auth_verify_csrf_token($postedToken)
        || ($postedToken !== '' && member_auth_is_authenticated() && $sameOrigin);
    if (!$csrfOk) {
        $errors[] = 'Your session expired. Please reload and try again.';
    }

    $formAction = (string) ($_POST['form_action'] ?? 'profile');
    if ($formAction === 'profile') {
        $name = trim((string) ($_POST['name'] ?? ''));
        $dob = trim((string) ($_POST['date_of_birth'] ?? ''));
        $contactEmail = trim((string) ($_POST['contact_email'] ?? ''));
        $phone = trim((string) ($_POST['phone'] ?? ''));
        $addressLine1 = trim((string) ($_POST['address_line1'] ?? ''));
        $addressLine2 = trim((string) ($_POST['address_line2'] ?? ''));
        $town = trim((string) ($_POST['town'] ?? ''));
        $country = trim((string) ($_POST['country'] ?? ''));
        $postcode = trim((string) ($_POST['postcode'] ?? ''));
        $marketingOptIn = isset($_POST['marketing_opt_in']);
        $useLoginAsContact = isset($_POST['use_login_as_contact_email']);
        $updateBothEmails = isset($_POST['update_both_emails']);
        $profileImagePath = (string) ($person['profile_image_path'] ?? '');

        if ($useLoginAsContact) {
            $contactEmail = (string) ($account['email'] ?? '');
        }
        if ($name === '') {
            $errors[] = 'Name is required.';
        }
        if ($contactEmail !== '' && !filter_var($contactEmail, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Enter a valid contact email address.';
        }

        if (!$errors) {
            try {
                $uploaded = member_profile_upload_image($_FILES['profile_image'] ?? [], (int) $person['id']);
                if ($uploaded !== null) {
                    $profileImagePath = $uploaded;
                }
            } catch (Throwable $e) {
                $errors[] = $e->getMessage();
            }
        }

        if (!$errors) {
            try {
                updatePerson($pdo, (int) $person['id'], [
                    'display_name' => $name,
                    'date_of_birth' => $dob,
                    'email' => $contactEmail,
                    'phone' => $phone,
                    'address_line1' => $addressLine1,
                    'address_line2' => $addressLine2,
                    'town' => $town,
                    'country' => $country,
                    'postcode' => $postcode,
                    'marketing_opt_in' => $marketingOptIn ? 1 : 0,
                    'is_active' => (int) ($person['is_active'] ?? 1) === 1,
                ]);
                if ($profileImagePath !== (string) ($person['profile_image_path'] ?? '')) {
                    updatePersonProfileImage($pdo, (int) $person['id'], $profileImagePath);
                }
                if ($updateBothEmails && $contactEmail !== '') {
                    updateAccountLogin($pdo, (int) $account['id'], $contactEmail, (int) ($account['is_active'] ?? 1) === 1, accountPrimaryRole($pdo, (int) $account['id']));
                }
                $success = 'Profile updated.';
                $person = member_auth_current_person() ?: $person;
                $account = member_auth_current_account() ?: $account;
            } catch (Throwable $e) {
                $errors[] = $e->getMessage();
            }
        }
    } elseif ($formAction === 'password') {
        $current = (string) ($_POST['current_password'] ?? '');
        $new = (string) ($_POST['new_password'] ?? '');
        $confirm = (string) ($_POST['new_password_confirm'] ?? '');

        if (!password_verify($current, (string) ($account['password_hash'] ?? ''))) {
            $errors[] = 'Current password is incorrect.';
        } elseif (strlen($new) < 8) {
            $errors[] = 'New password must be at least 8 characters.';
        } elseif ($new !== $confirm) {
            $errors[] = 'New passwords do not match.';
        }

        if (!$errors) {
            updateAccountPasswordHash($pdo, (int) $account['id'], password_hash($new, PASSWORD_DEFAULT));
            identityAuditLog($pdo, 'member_password_changed', 'Member changed password for account #' . (int) $account['id']);
            $success = 'Password updated.';
            $account = member_auth_current_account() ?: $account;
        }
    }
}

$contactEmail = (string) ($person['email'] ?? '');
$loginEmail = (string) ($account['email'] ?? '');
?>

<div class="member-page">
    <section class="member-hero">
        <div class="member-hero__content">
            <div class="member-hero__eyebrow">Account Settings</div>
            <h1>Your profile</h1>
            <p>Keep your contact details, current address and communication preferences up to date.</p>
        </div>
    </section>

    <?php if ($success !== ''): ?><div class="alert alert-success"><?= h($success) ?></div><?php endif; ?>
    <?php if ($errors): ?><div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul></div><?php endif; ?>

    <div class="member-grid member-grid--aside">
        <div class="member-list">
            <section class="member-card">
                <div class="member-card__header"><h2>Profile Information</h2></div>
                <div class="member-card__body">
                    <form method="post" enctype="multipart/form-data">
                        <input type="hidden" name="csrf_token" value="<?= h($profileCsrfToken) ?>">
                        <input type="hidden" name="form_action" value="profile">

                        <div class="d-flex flex-wrap align-items-center gap-3 mb-4">
                            <div class="member-avatar">
                                <?php if (!empty($person['profile_image_path'])): ?><img src="/<?= h((string) $person['profile_image_path']) ?>" alt=""><?php else: ?><?= h(member_initials((string) $person['display_name'])) ?><?php endif; ?>
                            </div>
                            <div class="flex-grow-1">
                                <label class="form-label" for="profileImage">Profile photo</label>
                                <input type="file" class="form-control" id="profileImage" name="profile_image" accept="image/jpeg,image/png,image/webp">
                                <div class="form-text">JPG, PNG or WebP. Maximum 2MB.</div>
                            </div>
                        </div>

                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label" for="profileName">Name</label>
                                <input type="text" class="form-control" id="profileName" name="name" value="<?= h((string) $person['display_name']) ?>" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="profileDob">Date of birth</label>
                                <input type="date" class="form-control" id="profileDob" name="date_of_birth" value="<?= h((string) ($person['date_of_birth'] ?? '')) ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="profileContactEmail">Contact email</label>
                                <input type="email" class="form-control" id="profileContactEmail" name="contact_email" value="<?= h($contactEmail) ?>">
                                <div class="form-text">Used by the club for contact and mailing list exports.</div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Login email</label>
                                <input type="email" class="form-control" value="<?= h($loginEmail) ?>" disabled>
                                <div class="form-text">Used to sign in to your Hub account.</div>
                            </div>
                            <div class="col-12">
                                <div class="form-check">
                                    <input type="checkbox" class="form-check-input" id="useLoginEmail" name="use_login_as_contact_email" value="1">
                                    <label class="form-check-label" for="useLoginEmail">Use login email as contact email</label>
                                </div>
                                <div class="form-check">
                                    <input type="checkbox" class="form-check-input" id="updateBothEmails" name="update_both_emails" value="1">
                                    <label class="form-check-label" for="updateBothEmails">Update both contact email and login email to the contact email above</label>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="profilePhone">Phone</label>
                                <input type="text" class="form-control" id="profilePhone" name="phone" value="<?= h((string) ($person['phone'] ?? '')) ?>">
                            </div>
                            <div class="col-md-6"></div>
                            <div class="col-md-6"><label class="form-label" for="addressLine1">Address line 1</label><input type="text" class="form-control" id="addressLine1" name="address_line1" value="<?= h((string) ($person['address_line1'] ?? '')) ?>"></div>
                            <div class="col-md-6"><label class="form-label" for="addressLine2">Address line 2</label><input type="text" class="form-control" id="addressLine2" name="address_line2" value="<?= h((string) ($person['address_line2'] ?? '')) ?>"></div>
                            <div class="col-md-4"><label class="form-label" for="addressTown">Town</label><input type="text" class="form-control" id="addressTown" name="town" value="<?= h((string) ($person['town'] ?? '')) ?>"></div>
                            <div class="col-md-4"><label class="form-label" for="addressCountry">Country</label><input type="text" class="form-control" id="addressCountry" name="country" value="<?= h((string) ($person['country'] ?? '')) ?>"></div>
                            <div class="col-md-4"><label class="form-label" for="addressPostcode">Postcode</label><input type="text" class="form-control" id="addressPostcode" name="postcode" value="<?= h((string) ($person['postcode'] ?? '')) ?>"></div>
                            <div class="col-12">
                                <div class="form-check">
                                    <input type="checkbox" class="form-check-input" id="profileOptIn" name="marketing_opt_in" value="1" <?= (int) ($person['marketing_opt_in'] ?? 0) === 1 ? 'checked' : '' ?>>
                                    <label class="form-check-label" for="profileOptIn">Keep me posted about club news, deals and offers</label>
                                </div>
                            </div>
                        </div>

                        <button type="submit" class="btn btn-brand mt-4">Save profile</button>
                    </form>
                </div>
            </section>
        </div>

        <aside class="member-grid">
            <section class="member-card">
                <div class="member-card__body">
                    <h2>Account Summary</h2>
                    <div class="member-list mt-3">
                        <div><strong>Contact email</strong><div class="text-muted small"><?= h($contactEmail !== '' ? $contactEmail : 'Not set') ?></div></div>
                        <div><strong>Login email</strong><div class="text-muted small"><?= h($loginEmail) ?></div></div>
                        <div><strong>Primary address</strong><div class="text-muted small"><?= h(member_profile_address_summary($person) ?: 'Not set') ?></div></div>
                        <div><strong>Marketing</strong><div class="text-muted small"><?= (int) ($person['marketing_opt_in'] ?? 0) === 1 ? 'Opted in' : 'Opted out' ?></div></div>
                    </div>
                </div>
            </section>

            <section class="member-card">
                <div class="member-card__header"><h2>Change Password</h2></div>
                <div class="member-card__body">
                    <form method="post">
                        <input type="hidden" name="csrf_token" value="<?= h($profileCsrfToken) ?>">
                        <input type="hidden" name="form_action" value="password">
                        <div class="mb-3">
                            <label class="form-label" for="currentPassword">Current password</label>
                            <input type="password" class="form-control" id="currentPassword" name="current_password" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="newPassword">New password</label>
                            <input type="password" class="form-control" id="newPassword" name="new_password" minlength="8" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="newPasswordConfirm">Confirm new password</label>
                            <input type="password" class="form-control" id="newPasswordConfirm" name="new_password_confirm" minlength="8" required>
                        </div>
                        <button type="submit" class="btn btn-outline-secondary">Update password</button>
                    </form>
                </div>
            </section>
        </aside>
    </div>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
