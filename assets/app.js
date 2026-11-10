// "Fetch title" buttons: read the URL field, ask the server for the page title.
document.querySelectorAll('[data-fetch-title]').forEach(btn => {
    btn.addEventListener('click', async () => {
        const url = document.querySelector(btn.dataset.fetchTitle).value.trim();
        const target = document.querySelector(btn.dataset.target);
        if (!url) return;
        const label = btn.textContent;
        btn.textContent = 'Fetching…';
        btn.disabled = true;
        try {
            const res = await fetch('fetch_title.php?url=' + encodeURIComponent(url));
            const data = await res.json();
            if (data.title) target.value = data.title;
            else alert('Could not read a title from that page.');
        } catch (e) {
            alert('Could not fetch the title.');
        }
        btn.textContent = label;
        btn.disabled = false;
    });
});

// Copy-to-clipboard buttons
document.querySelectorAll('[data-copy]').forEach(btn => {
    btn.addEventListener('click', async () => {
        await navigator.clipboard.writeText(btn.dataset.copy);
        const label = btn.textContent;
        btn.textContent = 'Copied!';
        setTimeout(() => (btn.textContent = label), 1200);
    });
});

// Show / hide a masked password
document.querySelectorAll('[data-reveal]').forEach(btn => {
    btn.addEventListener('click', () => {
        const secret = btn.parentElement.querySelector('.secret');
        if (!secret) return;
        const shown = secret.dataset.shown === '1';
        secret.textContent = shown ? '••••••••' : secret.dataset.secret;
        secret.dataset.shown = shown ? '0' : '1';
        btn.textContent = shown ? 'Show' : 'Hide';
    });
});
