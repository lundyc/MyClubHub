(() => {
  const progress = document.getElementById('pdfProgress');
  let busy = false;
  const show = message => { progress.classList.remove('d-none'); progress.textContent = message; };
  async function post(action, fields = {}) {
    const body = new FormData(document.getElementById('pdfToken'));
    body.set('action', action); body.set('ajax', '1');
    Object.entries(fields).forEach(([key, value]) => body.set(key, value));
    const response = await fetch('/admin/pdf_importer.php', {method: 'POST', body, credentials: 'same-origin'});
    let data;
    try { data = await response.json(); } catch { throw new Error('Session expired or the server did not respond. Reload this page; completed work is saved.'); }
    if (!data.ok) throw new Error(data.message);
    return data;
  }
  async function run(task) {
    if (busy) return;
    busy = true;
    try { await task(); } catch (error) { show(error.message); } finally { busy = false; }
  }
  document.getElementById('processQueue')?.addEventListener('click', () => run(async () => {
    let count = 0;
    while (true) {
      show(`Processing reports… ${count} completed. You can leave this page; the background worker also processes pending files.`);
      const data = await post('process');
      if (data.message === 'Queue is up to date.') break;
      count++;
    }
    location.reload();
  }));
  document.getElementById('pdfUploads')?.addEventListener('change', event => run(async () => {
    const files = [...event.target.files];
    const failures = [];
    for (let i = 0; i < files.length; i++) {
      show(`Uploading ${i + 1} of ${files.length}: ${files[i].name}`);
      try { await post('upload', {pdf: files[i]}); } catch (error) { failures.push(`${files[i].name}: ${error.message}`); }
    }
    if (failures.length) show(`Uploads finished with issues: ${failures.join('; ')}. Refresh to see queued reports.`);
    else location.href = '/admin/pdf_importer.php';
  }));
  document.getElementById('selectReady')?.addEventListener('change', event => {
    document.querySelectorAll('.readyReport').forEach(input => { input.checked = event.target.checked; });
  });
  document.getElementById('importSelected')?.addEventListener('click', () => run(async () => {
    const selected = [...document.querySelectorAll('.readyReport:checked')];
    if (!selected.length) { show('Select at least one reviewed, ready report.'); return; }
    const failures = [];
    for (let i = 0; i < selected.length; i++) {
      show(`Importing reviewed report ${i + 1} of ${selected.length}…`);
      try { await post('apply', {id: selected[i].value}); selected[i].disabled = true; selected[i].checked = false; }
      catch (error) { failures.push(`Report #${selected[i].value}: ${error.message}`); }
    }
    if (failures.length) show(`Finished. ${failures.join('; ')}. Successful imports are saved; failed reports can be reviewed and retried.`);
    else location.reload();
  }));
  document.getElementById('fixtureChoice')?.addEventListener('change', event => {
    location.href = `/admin/pdf_importer.php?id=${event.target.dataset.importId}&fixture=${event.target.value}`;
  });
  document.getElementById('findFixture')?.addEventListener('click', event => {
    const value = Number(document.getElementById('manualFixture').value);
    if (value > 0) location.href = `/admin/pdf_importer.php?id=${event.target.dataset.importId}&fixture=${value}`;
  });
})();
