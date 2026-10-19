document.addEventListener('DOMContentLoaded', () => {
    const proofDialog = document.querySelector('[data-proof-dialog]');
    const proofImage = document.querySelector('[data-proof-dialog-image]');
    const proofTitle = document.querySelector('[data-proof-dialog-title]');
    if (proofDialog && proofImage && proofTitle) {
        document.querySelectorAll('[data-proof-open]').forEach((button) => {
            button.addEventListener('click', () => {
                proofImage.src = button.dataset.proofUrl;
                proofTitle.textContent = button.dataset.proofTitle || 'Payment Proof';
                proofDialog.showModal();
            });
        });
        document.querySelectorAll('[data-proof-close]').forEach((button) => {
            button.addEventListener('click', () => proofDialog.close());
        });
    }

    const rejectionDialog = document.querySelector('[data-rejection-dialog]');
    const rejectionId = document.querySelector('[data-rejection-transaction-id]');
    const rejectionReference = document.querySelector('[data-rejection-reference]');
    const rejectionForm = document.querySelector('[data-rejection-form]');
    if (rejectionDialog && rejectionId && rejectionReference && rejectionForm) {
        document.querySelectorAll('[data-reject-open]').forEach((button) => {
            button.addEventListener('click', () => {
                rejectionId.value = button.dataset.transactionId;
                rejectionReference.textContent = button.dataset.orderReference;
                rejectionForm.querySelector('textarea').value = '';
                rejectionDialog.showModal();
            });
        });
        document.querySelectorAll('[data-reject-close]').forEach((button) => {
            button.addEventListener('click', () => rejectionDialog.close());
        });
    }
});
