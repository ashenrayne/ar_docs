/**
 * @file
 * Hosted mode for a client doc. Injected ahead of the doc's own script.
 *
 * A client-report doc keeps every answer in its markup: data-answer on the item
 * answered, a value on each .answer field. That is what lets a saved copy reopen
 * as it was. So hosting it takes no changes to the doc: put the saved answers
 * into the markup before the doc's script reads it, and when the recipient
 * presses Save, send the markup's answers to the site instead of writing a file.
 *
 * Save keeps the doc's own convention: "Saved" when the site has every answer,
 * gold "Save" when something has changed, with the share of questions
 * answered on its right. At 100% it becomes "Finish", with a shine across it;
 * the first save with everything answered is the one that emails us. While
 * a save is in flight the studio shield fades in over the page, which takes no
 * input until it has faded out again.
 */
(function () {
  'use strict';
  var C = window.AR_DOCS;
  if (!C) return;
  var root = document.documentElement;
  root.classList.add('ar-hosted');

  var logo = String(C.logo || '').replace(/["\\]/g, '');
  var css = document.createElement('style');
  css.textContent =
    // The "open in Chrome" notice is for the file version.
    '.ar-hosted .nosave{display:none!important}' +
    '.ar-readonly .save{display:none!important}' +
    '.acc-n.ar-done{background:#2e7d32!important;color:#fff!important}' +
    '.save .ar-pct{margin-left:8px;font-size:.85em;font-variant-numeric:tabular-nums;opacity:.8}' +
    // Every question answered: Save becomes Finish and asks to be pressed.
    '.save.ar-complete{position:relative;overflow:hidden;isolation:isolate;background:#2e7d32!important;color:#fff!important;box-shadow:0 0 0 3px rgba(46,125,50,.25)}' +
    '.save.ar-complete::after{content:"";position:absolute;top:0;bottom:0;left:-60%;width:40%;z-index:-1;transform:skewX(-20deg);' +
    'background:linear-gradient(90deg,rgba(255,255,255,0),rgba(255,255,255,.55),rgba(255,255,255,0));animation:ar-shine 2.2s ease-in-out infinite}' +
    '@keyframes ar-shine{0%{left:-60%}70%,100%{left:125%}}' +
    '@media (prefers-reduced-motion:reduce){.save.ar-complete::after{display:none}}' +
    '.ar-floating{position:fixed;right:16px;bottom:16px;z-index:1000;border:0;border-radius:999px;padding:10px 18px;font:700 15px/1.2 system-ui,sans-serif;background:#2e7d32;color:#fff;box-shadow:0 6px 24px rgba(0,0,0,.18);cursor:pointer}' +
    '.ar-floating.dirty{background:#e0a800;color:#1f2933}' +
    '.ar-viewing{position:sticky;top:0;z-index:1001;background:#fff8e1;border-bottom:1px solid #e0a800;padding:10px 16px;font:600 15px/1.4 system-ui,sans-serif;color:#3d2e00;text-align:center}' +
    '.ar-readonly main,.ar-readonly .sheet{pointer-events:none}' +
    // Saving: the shield fades in and out; the page holds still meanwhile.
    'html.ar-busy-on,html.ar-busy-on body{overflow:hidden!important}' +
    '.ar-busy{position:fixed;inset:0;z-index:2147483000;display:flex;flex-direction:column;align-items:center;justify-content:center;background:transparent;cursor:progress;opacity:0;transition:opacity .5s ease}' +
    '.ar-busy[hidden]{display:none}.ar-busy.is-on{opacity:1}' +
    '.ar-shield{width:min(38vw,190px);aspect-ratio:452/573}' +
    '.ar-shield img{display:block;width:100%;height:100%}' +
    'dialog.ar-confirm{max-width:28rem;border:0;border-radius:12px;padding:24px 26px;box-shadow:0 18px 50px rgba(0,0,0,.25);font:16px/1.5 system-ui,sans-serif}' +
    'dialog.ar-confirm::backdrop{background:rgba(0,0,0,.35)}' +
    'dialog.ar-confirm h3{margin:0 0 8px;font-size:19px}dialog.ar-confirm p{margin:0 0 18px}' +
    'dialog.ar-confirm .ar-actions{display:flex;gap:10px;justify-content:flex-end}' +
    'dialog.ar-confirm button{border:0;border-radius:999px;padding:8px 18px;font:700 15px/1.4 system-ui,sans-serif;cursor:pointer}' +
    'dialog.ar-confirm .ar-go{background:#2e7d32;color:#fff}dialog.ar-confirm .ar-back{background:#eef0f3;color:#1f2933}';
  document.head.appendChild(css);

  // What each field shipped with. A field is an answer only once it differs:
  // the editable rows a Yes opens come pre-filled with what's on file.
  [].forEach.call(document.querySelectorAll('input.answer[id],textarea.answer[id],select.answer[id]'), function (el) {
    el.setAttribute('data-ar-shipped', el.tagName === 'TEXTAREA' ? el.textContent : (el.getAttribute('value') || ''));
  });

  // The element an answer belongs to owns the buttons nearest it.
  function owns(el, b) { return b.parentElement && b.parentElement.closest('[id]') === el; }

  function apply(answers) {
    Object.keys(answers).forEach(function (id) {
      var el = document.getElementById(id), v = String(answers[id]);
      if (!el) return;
      if (/^(INPUT|TEXTAREA|SELECT)$/.test(el.tagName)) {
        el.value = v;
        if (el.tagName === 'TEXTAREA') el.textContent = v; else el.setAttribute('value', v);
        return;
      }
      el.setAttribute('data-answer', v);
      [].forEach.call(el.querySelectorAll('[data-val]'), function (b) {
        if (owns(el, b)) b.setAttribute('aria-pressed', b.getAttribute('data-val') === v ? 'true' : 'false');
      });
    });
  }

  function collect() {
    var out = {};
    [].forEach.call(document.querySelectorAll('[id][data-answer]'), function (el) {
      var v = el.getAttribute('data-answer');
      if (v) out[el.id] = v;
    });
    [].forEach.call(document.querySelectorAll('input.answer[id],textarea.answer[id],select.answer[id]'), function (el) {
      if (el.value.trim() !== '' && el.value !== el.getAttribute('data-ar-shipped')) out[el.id] = el.value;
    });
    return out;
  }

  // A review: a doc that ships with every question already answered (a record
  // to correct, not a form to fill), or one the recipient already finished and
  // is going back over. A percentage measures nothing there, so Save, Finish
  // and Finished take turns instead; an open doc keeps its tally. Decided from
  // the doc as built, before saved answers go in.
  var review = !!C.finished || (function () {
    var items = [].filter.call(document.querySelectorAll('.yn, .choice, .ask'), function (el) {
      var g = el.closest('[data-gate]');
      return !g || !g.hidden;
    });
    return items.length > 0 && items.every(function (el) {
      if (el.classList.contains('yn') || el.classList.contains('choice')) return !!el.getAttribute('data-answer');
      return [].some.call(el.querySelectorAll('.answer'), function (f) { return f.value.trim() !== ''; });
    });
  })();

  // The DOM is parsed up to this script, which sits just ahead of the doc's
  // own; everything the doc's script will read is already here.
  apply(C.answers || {});

  var last = null, busy = false;
  function dirty() { return last !== null && JSON.stringify(collect()) !== last; }

  // Ahead of the doc's own listener, which tracks a file save that never
  // happens here. Ours asks only when the site is missing an answer.
  window.addEventListener('beforeunload', function (e) {
    e.stopImmediatePropagation();
    if (busy || dirty()) { e.preventDefault(); e.returnValue = ''; }
  });

  // Save goes to the site. Caught on the way down, before the doc's own
  // handler on the button can open a file dialog.
  var save = null;
  document.addEventListener('click', function (e) {
    var b = e.target.closest && e.target.closest('.save');
    if (!b || C.mode === 'view') return;
    e.preventDefault();
    e.stopImmediatePropagation();
    if (busy) return;
    // A review saves first, then finishes on the next press.
    if (review) {
      if (dirty()) store(false);
      else if (!finished) store(true);
    }
    else if (!finished && complete()) store(true);
    else if (dirty()) store(false);
  }, true);

  // Nothing reaches the page while it's saving: no keys, no wheel, no touch.
  ['keydown', 'wheel', 'touchmove'].forEach(function (type) {
    window.addEventListener(type, function (e) { if (busy) { e.preventDefault(); e.stopPropagation(); } }, { capture: true, passive: false });
  });

  var overlay, dialog, finished = !!C.finished;

  // Gated content a "no" keeps closed isn't a question yet.
  function shown(el) {
    var g = el.closest('[data-gate]');
    return !g || !g.hidden;
  }

  // Every question showing has a response: each check/X and choice is marked,
  // each question with fields has something typed. The editable rows a Yes
  // opens are corrections, not questions, so they don't count.
  // How many of the questions showing have a response. A check/X or choice
  // counts once marked; a question with fields once something is typed. The
  // editable rows a Yes opens are corrections, not questions.
  function answered(el) {
    if (el.classList.contains('yn') || el.classList.contains('choice')) return !!el.getAttribute('data-answer');
    return [].some.call(el.querySelectorAll('.answer'), function (f) { return f.value.trim() !== ''; });
  }

  function progress(within) {
    var items = [].filter.call((within || document).querySelectorAll('.yn, .choice, .ask'), shown);
    return { total: items.length, done: items.filter(answered).length };
  }

  // A group whose questions all have a response says so on its count.
  function paintGroups() {
    [].forEach.call(document.querySelectorAll('details.acc'), function (group) {
      var count = group.querySelector(':scope > summary .acc-n');
      if (!count) return;
      var p = progress(group);
      count.classList.toggle('ar-done', p.total > 0 && p.done === p.total);
    });
  }

  function complete() {
    var p = progress();
    return p.total > 0 && p.done === p.total;
  }

  // The label and tally are built once and only their text changes: replacing
  // them mid-click (a field's change event fires as Save is pressed) would
  // drop the click.
  var label, tally;
  function setText(el, text) { if (el.textContent !== text) el.textContent = text; }
  function paint() {
    if (!save) return;
    if (!label || label.parentNode !== save) {
      save.textContent = '';
      label = document.createElement('span');
      label.className = 'ar-label';
      tally = document.createElement('span');
      tally.className = 'ar-pct';
      save.appendChild(label);
      save.appendChild(tally);
    }
    var p = progress(), d = dirty();
    if (review) {
      // Save (yellow) → Finish (yellow) → Finished (green); a change starts
      // it over. Tracking what's done is the reviewer's own, so no tally.
      var step = d ? 'Save' : finished ? 'Finished' : 'Finish';
      save.classList.remove('ar-complete');
      save.classList.toggle('dirty', step !== 'Finished');
      save.setAttribute('aria-label', step);
      setText(label, step);
      setText(tally, '');
      tally.hidden = true;
      paintGroups();
      return;
    }
    var ready = !finished && p.total > 0 && p.done === p.total;
    var pct = p.total ? Math.floor(100 * p.done / p.total) : 0;
    var text = ready ? 'Finish' : d ? 'Save' : finished ? 'Finished' : 'Saved';
    save.classList.toggle('ar-complete', ready);
    save.classList.toggle('dirty', d && !ready);
    save.setAttribute('aria-label', text + ', ' + pct + '% answered');
    setText(label, text);
    setText(tally, pct + '%');
    paintGroups();
  }

  function busyOn() {
    busy = true;
    root.classList.add('ar-busy-on');
    overlay.hidden = false;
    // Next frame, so the fade runs.
    requestAnimationFrame(function () { overlay.classList.add('is-on'); });
    overlay.focus({ preventScroll: true });
  }

  // Fades out, then lets the page go. Matches the .5s transition.
  function busyOff(then) {
    overlay.classList.remove('is-on');
    setTimeout(function () {
      overlay.hidden = true;
      root.classList.remove('ar-busy-on');
      busy = false;
      if (then) then();
    }, 500);
  }

  function ask(title, text, go, back, onGo) {
    dialog.querySelector('h3').textContent = title;
    dialog.querySelector('p').textContent = text;
    var g = dialog.querySelector('.ar-go'), b = dialog.querySelector('.ar-back');
    g.textContent = go;
    b.textContent = back || '';
    b.hidden = !back;
    g.onclick = function () { dialog.close(); if (onGo) onGo(); };
    if (dialog.showModal) dialog.showModal(); else if (onGo) onGo();
  }

  // Sends the answers. The shield finishes fading in before it fades out, so a
  // fast save still reads as one rather than a flicker.
  function store(markFinished) {
    var answers = collect(), snapshot = JSON.stringify(answers), started = Date.now(), failed = false;
    busyOn();
    fetch(C.save, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ answers: answers, finished: !!markFinished }),
    }).then(function (r) {
      if (!r.ok) throw new Error(r.status);
      return r.json();
    }).then(function (res) {
      last = snapshot;
      // The site's word: a change saved after finishing reopens the doc.
      finished = !!res.finished;
    }).catch(function () {
      failed = true;
    }).then(function () {
      paint();
      setTimeout(function () {
        busyOff(function () {
          if (failed) {
            ask('Not saved', 'Your answers are still on this page, but they didn’t reach us. Check your connection and press Save again.', 'OK');
          }
        });
      }, Math.max(0, 500 - (Date.now() - started)));
    });
  }

  document.addEventListener('DOMContentLoaded', function () {
    var note = document.querySelector('dialog.note p');

    if (C.mode === 'view') {
      root.classList.add('ar-readonly');
      var bar = document.createElement('div');
      bar.className = 'ar-viewing';
      bar.textContent = (C.recipient ? C.recipient + '’s answers' : 'Answers') + (C.finished ? ' (finished)' : ' (not finished yet)') + '. Read-only.';
      document.body.insertBefore(bar, document.body.firstChild);
      return;
    }
    if (note) {
      note.textContent = 'Press Save in the bar at the top to keep your answers; you can come back and carry on any time.';
      var more = document.createElement('p');
      more.textContent = review
        ? 'Once saved, Save becomes Finish: press it to let us know you’re done.'
        : 'At 100% Save becomes Finish: press it to let us know you’re done.';
      note.insertAdjacentElement('afterend', more);
    }

    last = JSON.stringify(collect());

    overlay = document.createElement('div');
    overlay.className = 'ar-busy';
    overlay.hidden = true;
    overlay.tabIndex = -1;
    overlay.setAttribute('role', 'status');
    overlay.setAttribute('aria-live', 'assertive');
    overlay.setAttribute('aria-label', 'Saving');
    overlay.innerHTML = '<div class="ar-shield"><img alt="" src="' + logo + '"></div>';
    document.body.appendChild(overlay);

    dialog = document.createElement('dialog');
    dialog.className = 'ar-confirm';
    dialog.innerHTML = '<h3></h3><p></p><div class="ar-actions"><button type="button" class="ar-back"></button><button type="button" class="ar-go"></button></div>';
    dialog.querySelector('.ar-back').addEventListener('click', function () { dialog.close(); });
    document.body.appendChild(dialog);

    // A doc with no Save bar gets one, floating.
    save = document.querySelector('.save');
    if (!save) {
      save = document.createElement('button');
      save.type = 'button';
      save.className = 'save ar-floating';
      document.body.appendChild(save);
    }
    paint();

    // After the doc's own handlers have run, which repaint Save by their own
    // reckoning: ours is what the site has.
    ['click', 'input', 'change'].forEach(function (type) {
      document.addEventListener(type, function () { paint(); setTimeout(paint, 0); });
    });
  });
})();
