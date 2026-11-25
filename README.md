# AR Docs

Hosts Ashen Rayne client docs (the self-contained HTML the client-report skill builds) at
private links on a client's Drupal site. Each recipient answers in any browser and presses
**Save**. The answers are stored on the site, and the first save with every question answered
(when Save reads **Finish**) emails support@ashenrayne.com. No attachments, no files to send back.

```
composer require ashenrayne/ar_docs
drush en ar_docs
```

Everything on our side is drush. Pass the site's public address with `--uri` so the printed
links are the ones to send.

| Command | Does |
|---|---|
| `cat doc.html \| drush ar-docs:add <slug> - --recipient=Bud --recipient=Daryl` | Store a doc, print each recipient’s link on its own line |
| `drush ar-docs:list [slug]` | Each doc's recipients: answers, last saved, finished, active |
| `drush ar-docs:answers <slug> [--recipient=Bud] [--item='q-*'] [--format=json]` | Answers, with each item's question text |
| `drush ar-docs:render <slug> --recipient=Bud > bud.html` | The doc with their answers in it, read-only, to open locally |
| `drush ar-docs:update <slug> -` | Swap in a rebuilt doc; answers are kept, matched by element id |
| `drush ar-docs:link <slug> <recipient>` | Another recipient's link |
| `drush ar-docs:revoke <slug> <recipient> [--restore]` | Stop (or reopen) a link; answers stay |
| `drush ar-docs:delete <slug>` | Remove a doc, its links and answers when the round is done |

## How it works

- Docs and links live in the key-value store: no entities and no schema. A link's token
  (32 random bytes) is the only credential.
- The doc is served as built, with a small runtime injected ahead of its own script. The
  runtime puts saved answers into the markup, then sends the markup's answers when Save is
  pressed. Any client-report doc works unchanged, because it already keeps its answers in
  markup.
- Doc pages and saves are `no-store`, `noindex` and `no-referrer`. A late response listener
  restores the doc exactly as served, so site-wide rewriting (email cloaking, phone links,
  tag injection) can't change it.
- Saves are same-site only, size-capped and rate-limited.

- **Open docs and reviews.** An open doc shows how much is answered, and Save becomes **Finish**
  at 100%. A review (a doc that ships with every answer already filled in, or one the recipient
  already finished) shows no percentage: a change makes it **Save**, a save makes it **Finish**,
  and Finish makes it **Finished**. A change saved after finishing reopens the doc on the site,
  so the next Finish emails again.

Finished emails go to `ar_docs.settings:notify` (default support@ashenrayne.com).
