# AR Docs

Hosts Ashen Rayne client docs (the self-contained HTML the client-report skill builds) at
private links on a client's Drupal site. Each recipient answers in any browser, the answers
save to the site as they go, and pressing **Finished** emails us. No attachments, nothing to save.

```
composer require ashenrayne/ar_docs
drush en ar_docs
```

Then go to **Content → Docs**, upload a doc, add a recipient per person, and send each one their link.
