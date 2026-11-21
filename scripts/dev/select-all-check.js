/* Live-Check 21.11. (85726): the head tick «Alle wählen» ticks only the row ticks «selected[]» — never a dialog switch, never a
   disabled box — and each change is announced, so the bulk bar counts at the first click.

       node scripts/dev/select-all-check.js        (needs Playwright; no WordPress) */
const { chromium } = require('playwright');
const path = require('path');

(async () => {
    const browser = await chromium.launch();
    const page    = await browser.newPage();
    let bad       = 0;
    const check   = (ok, what) => { console.log((ok ? '  ok   ' : '  FAIL ') + what); bad += ok ? 0 : 1; };

    await page.setContent(`<table class="taxmod-mf-ticks"><thead><tr><th>Alle wählen</th></tr></thead><tbody>
        <tr><td><input type="checkbox" name="selected[]"><input type="checkbox" class="taxmod-dialog-switch"></td></tr>
        <tr><td><input type="checkbox" name="selected[]" disabled><input type="checkbox" class="taxmod-dialog-switch"></td></tr>
        <tr><td><input type="checkbox" name="selected[]"></td></tr></tbody></table>`);
    await page.evaluate(() => { window.changes = 0; document.addEventListener('change', (e) => { if (e.target.name === 'selected[]') { window.changes++; } }); });
    await page.addScriptTag({ path: path.join(__dirname, '../../assets/manufacturing.js') });

    const state = () => page.evaluate(() => ({
        rows: [...document.querySelectorAll('[name="selected[]"]')].map((b) => b.checked),
        dialogs: [...document.querySelectorAll('.taxmod-dialog-switch')].map((b) => b.checked),
        changes: window.changes,
    }));

    await page.click('.taxmod-mf-all');
    let s = await state();
    check(JSON.stringify(s.rows) === '[true,false,true]', 'the head tick ticks the enabled row ticks, not the disabled one');
    check(s.dialogs.every((d) => !d), 'no dialog switch is ticked');
    check(s.changes === 2, 'each ticked row announces its change at the first click');

    await page.click('.taxmod-mf-all');
    s = await state();
    check(s.rows.every((r) => !r) && s.changes === 4, 'the second click unticks them again');

    await browser.close();
    console.log(bad === 0 ? '\nall green' : `\n${bad} failed`);
    process.exit(bad === 0 ? 0 : 1);
})();
