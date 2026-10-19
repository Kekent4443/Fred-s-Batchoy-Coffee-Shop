<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

require_login('administrator');

$editId = (int) request('edit', 0);
$editFaq = $editId > 0 ? db_find('SELECT * FROM support_faqs WHERE id = ? LIMIT 1', 'i', [$editId]) : null;
$testMessage = trim((string) request('test_message', ''));
$testCategory = support_validate_category((string) request('test_category', 'general_inquiry'));
$testResult = '';
$faqErrors = [];
$submittedFaq = null;

if (is_post()) {
    $action = (string) post_scalar('action', 'save');

    try {
        $rawFaqId = (string) post_scalar('faq_id', '0');
        if (!ctype_digit($rawFaqId) || strlen($rawFaqId) > 10) {
            throw new InvalidArgumentException('FAQ rule ID is invalid.');
        }
        $faqId = (int) $rawFaqId;
        if ($action === 'delete') {
            support_disable_faq_rule($faqId, current_user());
            flash('support_faqs', 'FAQ rule disabled.', 'success');
            redirect('admin/support-faqs.php');
        }

        if ($action !== 'save') {
            throw new InvalidArgumentException('Unsupported FAQ action.');
        }

        $submittedFaq = [
            'id' => $faqId,
            'category' => (string) post_scalar('category', ''),
            'question' => (string) post_scalar('question', ''),
            'keywords' => (string) post_scalar('keywords', ''),
            'answer' => (string) post_scalar('answer', ''),
            'priority' => post_scalar('priority', ''),
            'status' => (string) post_scalar('status', ''),
        ];
        $payload = support_faq_payload($submittedFaq, $faqId > 0);
        $category = $payload['category'];
        $question = $payload['question'];
        $keywords = $payload['keywords'];
        $answer = $payload['answer'];
        $priority = $payload['priority'];
        $status = $payload['status'];

        if ($faqId > 0) {
            if (!db_find('SELECT id FROM support_faqs WHERE id = ? LIMIT 1', 'i', [$faqId])) {
                throw new InvalidArgumentException('FAQ rule was not found.');
            }
            db_execute(
                'UPDATE support_faqs SET category = ?, question = ?, keywords = ?, answer = ?, priority = ?, status = ?, updated_at = NOW() WHERE id = ?',
                'ssssisi',
                [$category, $question, $keywords, $answer, $priority, $status, $faqId]
            );
            flash('support_faqs', 'FAQ rule updated.', 'success');
        } else {
            db_execute(
                'INSERT INTO support_faqs (category, question, keywords, answer, priority, status, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())',
                'ssssis',
                [$category, $question, $keywords, $answer, $priority, $status]
            );
            flash('support_faqs', 'FAQ rule created.', 'success');
        }

        redirect('admin/support-faqs.php');
    } catch (InvalidArgumentException $exception) {
        flash('support_faqs', $exception->getMessage(), 'danger');
        $faqErrors[validation_error_field($exception->getMessage())] = $exception->getMessage();
        if ($submittedFaq !== null) {
            $editFaq = $submittedFaq;
        }
    } catch (mysqli_sql_exception $exception) {
        if ((int) $exception->getCode() !== 1062) {
            error_log('Support FAQ save failed: ' . $exception->getMessage());
        }
        $message = (int) $exception->getCode() === 1062
            ? 'An FAQ rule with that question already exists.'
            : 'Unable to save FAQ rule.';
        flash('support_faqs', $message, 'danger');
        $faqErrors['question'] = $message;
        if ($submittedFaq !== null) {
            $editFaq = $submittedFaq;
        }
    } catch (Throwable $exception) {
        error_log('Support FAQ save failed: ' . $exception->getMessage());
        flash('support_faqs', 'Unable to save FAQ rule.', 'danger');
        $faqErrors['form'] = 'Unable to save FAQ rule.';
        if ($submittedFaq !== null) {
            $editFaq = $submittedFaq;
        }
    }
}

if ($testMessage !== '') {
    $testResult = support_faq_reply(['category' => $testCategory], support_normalize_text($testMessage)) ?? 'No confident FAQ match. This message would be handed off if no verified database answer exists.';
}

$faqs = db_select('SELECT * FROM support_faqs ORDER BY FIELD(status, "active", "inactive"), priority DESC, category ASC, question ASC');
$pageTitle = 'Support FAQ Rules';
require_once APP_ROOT . '/app/includes/head.php';
require_once APP_ROOT . '/app/includes/topbar.php';
?>
<div class="app-shell">
    <?php require APP_ROOT . '/app/includes/sidebar.php'; ?>
    <main class="app-main">
        <?php require APP_ROOT . '/app/includes/alerts.php'; ?>
        <section class="section">
            <?php render_page_header('Support FAQ Rules', 'Manage rule-based automated responses. These are not AI responses.', 'help', 'Support', static function (): void { ?><a class="btn btn-secondary" href="<?= e(url('admin/support.php')) ?>">Back to Support</a><?php }); ?>
            <div class="content-grid support-faq-layout">
                <div class="page-panel">
                    <div class="card-head"><h2><?= $editFaq ? 'Edit FAQ Rule' : 'New FAQ Rule' ?></h2></div>
                    <form method="post" class="stack-form">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="save">
                        <input type="hidden" name="faq_id" value="<?= e((string) ($editFaq['id'] ?? 0)) ?>">
                        <label for="faq-category">Category</label>
                        <select id="faq-category" name="category" required>
                            <?php foreach (support_categories() as $value => $label): ?>
                                <option value="<?= e($value) ?>" <?= ($editFaq['category'] ?? '') === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <label for="faq-question">Question / Rule Name</label>
                        <input id="faq-question" type="text" name="question" maxlength="255" value="<?= e((string) ($editFaq['question'] ?? '')) ?>" required<?= form_error_attributes($faqErrors, 'question', 'faq') ?>>
                        <?= form_field_error($faqErrors, 'question', 'faq') ?>
                        <label for="faq-keywords">Keywords</label>
                        <textarea id="faq-keywords" name="keywords" rows="3" required<?= form_error_attributes($faqErrors, 'keywords', 'faq') ?>><?= e((string) ($editFaq['keywords'] ?? '')) ?></textarea>
                        <?= form_field_error($faqErrors, 'keywords', 'faq') ?>
                        <label for="faq-answer">Answer</label>
                        <textarea id="faq-answer" name="answer" rows="5" required<?= form_error_attributes($faqErrors, 'answer', 'faq') ?>><?= e((string) ($editFaq['answer'] ?? '')) ?></textarea>
                        <?= form_field_error($faqErrors, 'answer', 'faq') ?>
                        <label for="faq-priority">Priority</label>
                        <input id="faq-priority" type="number" name="priority" value="<?= e((string) ($editFaq['priority'] ?? 0)) ?>" required>
                        <?php if (!empty($editFaq['id'])): ?>
                            <label for="faq-status">Status</label>
                            <select id="faq-status" name="status">
                                <option value="active" <?= ($editFaq['status'] ?? 'active') === 'active' ? 'selected' : '' ?>>Active</option>
                                <option value="inactive" <?= ($editFaq['status'] ?? '') === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                            </select>
                        <?php endif; ?>
                        <button class="btn btn-primary" type="submit">Save FAQ Rule</button>
                    </form>
                    <form method="get" class="stack-form support-test-form">
                        <label for="faq-test-category">Test Matching Category</label>
                        <select id="faq-test-category" name="test_category">
                            <?php foreach (support_categories() as $value => $label): ?>
                                <option value="<?= e($value) ?>" <?= $testCategory === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <label for="faq-test-message">Customer message to test</label>
                        <textarea id="faq-test-message" name="test_message" rows="3" maxlength="2000" placeholder="Type a customer message to test..."><?= e($testMessage) ?></textarea>
                        <button class="btn btn-secondary" type="submit">Test</button>
                        <?php if ($testResult !== ''): ?>
                            <p class="muted"><?= e($testResult) ?></p>
                        <?php endif; ?>
                    </form>
                </div>
                <div class="page-panel">
                    <div class="table-responsive">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Question</th>
                                    <th>Category</th>
                                    <th>Priority</th>
                                    <th>Status</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($faqs as $faq): ?>
                                    <tr>
                                        <td><strong><?= e((string) $faq['question']) ?></strong><br><small><?= e((string) $faq['keywords']) ?></small></td>
                                        <td><?= e(support_categories()[$faq['category']] ?? 'Other') ?></td>
                                        <td><?= e((string) $faq['priority']) ?></td>
                                        <td><?= status_badge((string) $faq['status']) ?></td>
                                        <td>
                                            <a class="btn btn-ghost btn-sm" href="<?= e(url('admin/support-faqs.php?edit=' . $faq['id'])) ?>">Edit</a>
                                            <?php if (($faq['status'] ?? '') === 'active'): ?>
                                                <form method="post" class="inline-form" data-confirm-message="Disable this FAQ rule?" data-confirm-title="Disable FAQ Rule" data-confirm-label="Disable" data-confirm-variant="danger">
                                                    <?= csrf_field() ?>
                                                    <input type="hidden" name="action" value="delete">
                                                    <input type="hidden" name="faq_id" value="<?= e((string) $faq['id']) ?>">
                                                    <button class="btn btn-danger btn-sm" type="submit">Disable</button>
                                                </form>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php if ($faqs === []): ?>
                                    <tr><td colspan="5">No FAQ rules found.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </section>
    </main>
</div>
<?php require_once APP_ROOT . '/app/includes/footer.php'; ?>
