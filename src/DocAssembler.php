<?php

declare(strict_types=1);

namespace Drupal\ar_docs;

use Drupal\Core\Extension\ModuleExtensionList;

/**
 * Turns a stored doc into the page a recipient opens.
 *
 * The doc is served as it was built, with two additions: robots and referrer
 * meta in the head, and the hosted runtime (with its settings) placed just
 * ahead of the doc's own first script in the body, so it can put the saved
 * answers into the markup before the doc reads them.
 */
class DocAssembler {

  public function __construct(protected ModuleExtensionList $modules) {}

  /**
   * The page a recipient opens: the doc, with the runtime and its settings.
   */
  public function assemble(string $html, array $settings): string {
    // Keep the token out of search and out of the Referer header sent when the
    // recipient follows a link in the doc to a business's site.
    $meta = '<meta name="robots" content="noindex, nofollow"><meta name="referrer" content="no-referrer">';
    $html = preg_replace('/<head(\s[^>]*)?>/i', '$0' . $meta, $html, 1, $count);
    if (!$count) {
      $html = $meta . $html;
    }

    $settings['answers'] = (object) ($settings['answers'] ?? []);
    // HEX flags: no answer can close the script tag it's printed in.
    $json = json_encode($settings, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    $runtime = file_get_contents($this->modules->getPath('ar_docs') . '/js/runtime.js');
    $inject = "<script>window.AR_DOCS=$json;</script>\n<script>$runtime</script>\n";

    $body = stripos($html, '<body');
    $script = $body === FALSE ? FALSE : stripos($html, '<script', $body);
    if ($script !== FALSE) {
      return substr_replace($html, $inject, $script, 0);
    }
    $end = strripos($html, '</body>');
    return $end === FALSE ? $html . $inject : substr_replace($html, $inject, $end, 0);
  }

  /**
   * A short human label for each answerable element in a doc, keyed by id.
   *
   * For reading answers outside the doc (drush, the email): the question text,
   * the row's name, or a field's label.
   *
   * @return array<string, string>
   *   Labels keyed by element id.
   */
  public function labels(string $html): array {
    $dom = new \DOMDocument();
    libxml_use_internal_errors(TRUE);
    $dom->loadHTML('<?xml encoding="utf-8"?>' . $html, LIBXML_NOWARNING | LIBXML_NOERROR);
    libxml_clear_errors();
    $xpath = new \DOMXPath($dom);
    $text = fn (?\DOMNode $n) => $n ? trim(preg_replace('/\s+/u', ' ', $n->textContent)) : '';
    $first = function (\DOMNode $el, array $classes) use ($xpath, $text) {
      foreach ($classes as $class) {
        $n = $xpath->query(".//*[contains(concat(' ', normalize-space(@class), ' '), ' $class ')]", $el)->item(0);
        if ($n && $text($n) !== '') {
          return $n;
        }
      }
      return NULL;
    };
    $labels = [];
    foreach ($xpath->query('//*[@id]') as $el) {
      /** @var \DOMElement $el */
      $class = ' ' . $el->getAttribute('class') . ' ';
      $id = $el->getAttribute('id');
      if (preg_match('/ (answer|fix) /', $class) || in_array($el->nodeName, ['input', 'textarea', 'select'], TRUE)) {
        $label = $el->getAttribute('aria-label') ?: $el->getAttribute('placeholder');
      }
      elseif (preg_match('/ (yn|choice|ask) /', $class)) {
        $n = $first($el, ['qtext', 'name', 'nm']) ?? $xpath->query('.//h3', $el)->item(0);
        $label = $text($n);
        // A question's context line sits inside its text; keep the question.
        foreach ($n ? $xpath->query(".//*[contains(@class,'qctx')]", $n) : [] as $context) {
          $label = trim(str_replace($text($context), '', $label));
        }
      }
      else {
        continue;
      }
      $labels[$id] = mb_strimwidth($label, 0, 90, '…');
    }
    return $labels;
  }

}
