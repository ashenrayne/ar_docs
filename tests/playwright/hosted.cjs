// A hosted doc in a real browser. Answer, press Save (the shield overlay holds
// the page), reload and find the answers back; then answer everything, see
// Save become Finish at 100%, and press it. Run against a fresh link issued
// with ar-docs:link.
//
// The item ids are from the first doc it was built against (a DMX locations
// review); point them at your own doc's ids to reuse it.
//
//   PW=<playwright package> node hosted.cjs <link> <chromium|firefox>
const pw = require(process.env.PW);
const [url, browserName = 'chromium'] = process.argv.slice(2);

(async () => {
  const browser = await pw[browserName].launch();
  const ctx = await browser.newContext({ ignoreHTTPSErrors: true });
  const p = await ctx.newPage();
  const errors = [];
  p.on('pageerror', e => errors.push(e.message));
  const res = await p.goto(url);
  const headers = res.headers();
  const saveText = () => p.textContent('.save .ar-label');
  const pct = () => p.textContent('.save .ar-pct');
  const waitIdle = () => p.waitForFunction(() => !document.documentElement.classList.contains('ar-busy-on'), null, { timeout: 15000 });

  const start = await saveText();
  const startPct = await pct();

  // One answer: Save turns gold; pressing it shows the overlay, then Saved.
  await p.click('details.acc:has(#q-southeast) summary');
  await p.click('#q-southeast .ynbtn.yes');
  await p.keyboard.press('Escape'); // the doc's first-answer note
  await p.fill('#q-southeast-website', 'https://example.com');
  const dirtyText = await saveText();
  await p.click('.save');
  const overlayShown = await p.evaluate(() => !document.querySelector('.ar-busy').hidden && document.documentElement.classList.contains('ar-busy-on'));
  await waitIdle();
  const savedText = await saveText();

  // Reload: the answers come back from the site. The doc's first-answer note
  // would open again on the next click; it's the doc's, so switch it off.
  await p.reload();
  await p.evaluate(() => { const n = document.querySelector('dialog.note'); if (n) n.showModal = () => {}; });
  const midPct = await pct();
  const back = await p.evaluate(() => ({
    yes: document.getElementById('q-southeast').getAttribute('data-answer'),
    field: document.getElementById('q-southeast-website').value,
    save: document.querySelector('.save').textContent,
  }));

  // Answer every question showing: a check on each check/X, the first option
  // of each choice, some text in each typed question.
  await p.evaluate(() => {
    document.querySelectorAll('details.acc').forEach(d => { d.open = true; });
    document.querySelectorAll('section .sec-body').forEach(b => { b.hidden = false; });
  });
  const visible = sel => p.$eval(sel, e => !e.closest('[data-gate]') || !e.closest('[data-gate]').hidden);
  for (const id of await p.$$eval('.yn', els => els.filter(e => !e.getAttribute('data-answer')).map(e => e.id))) {
    if (await visible(`#${id}`)) await p.click(`#${id} .ynbtn.yes`);
  }
  for (const id of await p.$$eval('.choice', els => els.filter(e => !e.getAttribute('data-answer')).map(e => e.id))) {
    await p.click(`#${id} .opt >> nth=0`);
  }
  for (const id of await p.$$eval('.ask:not(.choice)', els => els.map(e => e.id))) {
    if (!(await visible(`#${id}`))) continue;
    const empty = await p.$eval(`#${id}`, e => ![...e.querySelectorAll('.answer')].some(f => f.value.trim()));
    if (empty) await p.fill(`#${id} .answer >> nth=0`, 'test');
  }
  const completeText = await saveText();
  const fullPct = await pct();
  const shining = await p.$eval('.save', b => b.classList.contains('ar-complete') && getComputedStyle(b, '::after').animationName !== 'none');
  await p.click('.save');
  await waitIdle();
  const doneText = await saveText();

  const ok = start === 'Saved' && dirtyText === 'Save' && overlayShown && savedText === 'Saved'
    && back.yes === 'yes' && back.field === 'https://example.com' && back.save.startsWith('Saved')
    && startPct === '0%' && midPct !== '0%' && fullPct === '100%'
    && completeText === 'Finish' && shining && doneText === 'Finished'
    && /no-store/.test(headers['cache-control'] || '') && /noindex/.test(headers['x-robots-tag'] || '')
    && errors.length === 0;
  console.log(JSON.stringify({ browserName, startPct, midPct, fullPct, start, dirtyText, overlayShown, savedText, back, completeText, shining, doneText, errors, ok }));
  await browser.close();
  process.exit(ok ? 0 : 1);
})().catch(e => { console.error(e); process.exit(1); });
