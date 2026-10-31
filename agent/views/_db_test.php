<?php
/**
 * Script for a database form's "Test connection" button (#db-test), which posts the form
 * (#db-form) to $testUrl and shows the JSON result in #db-test-result.
 *
 * @var string $testUrl
 */
?>
<script>
    document.getElementById('db-test').addEventListener('click', async (event) => {
        const button = event.currentTarget;
        const result = document.getElementById('db-test-result');

        button.disabled = true;
        result.hidden = false;
        result.className = 'alert';
        result.textContent = 'Testing connection…';

        try {
            const response = await fetch(<?= json_encode(dodo_url($testUrl), JSON_HEX_TAG | JSON_UNESCAPED_SLASHES) ?>, {
                method: 'POST',
                body: new FormData(document.getElementById('db-form')),
            });
            // An expired form or session gets an HTML page back, not JSON.
            const data = (response.headers.get('Content-Type') || '').includes('application/json')
                ? await response.json()
                : { ok: false, message: 'The form has expired. Reload the page and try again.' };
            result.className = 'alert ' + (data.ok ? 'ok' : 'fail');
            result.textContent = data.message;
        } catch (error) {
            result.className = 'alert fail';
            result.textContent = 'The test request failed. Check your connection and try again.';
        } finally {
            button.disabled = false;
        }
    });
</script>
