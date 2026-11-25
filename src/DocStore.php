<?php

declare(strict_types=1);

namespace Drupal\ar_docs;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\KeyValueStore\KeyValueFactoryInterface;
use Drupal\Core\KeyValueStore\KeyValueStoreInterface;

/**
 * Docs and their recipients' links, in the key-value store.
 *
 * These docs are temporary, a round of questions for a client, so they get no
 * entities or schema of their own:
 * - ar_docs.doc, keyed by slug: title, html, created, changed.
 * - ar_docs.link, keyed by token: doc, label, active, answers, saved, finished,
 *   created.
 * The token is the only credential a recipient has, so it is 32 random bytes.
 */
class DocStore {

  /**
   * A doc slug: lower-case words joined by hyphens.
   */
  public const SLUG = '/^[a-z0-9]+(?:-[a-z0-9]+)*$/';

  public function __construct(
    protected KeyValueFactoryInterface $keyValue,
    protected TimeInterface $time,
  ) {}

  /**
   * The docs collection.
   */
  protected function docs(): KeyValueStoreInterface {
    return $this->keyValue->get('ar_docs.doc');
  }

  /**
   * The links collection.
   */
  protected function links(): KeyValueStoreInterface {
    return $this->keyValue->get('ar_docs.link');
  }

  /**
   * Stores a doc, replacing the HTML if the slug exists. Answers are untouched.
   */
  public function putDoc(string $slug, string $title, string $html): void {
    if (!preg_match(self::SLUG, $slug)) {
      throw new \InvalidArgumentException("Doc ids are lower-case words joined by hyphens, not \"$slug\".");
    }
    if (!preg_match('/<html[\s>]/i', $html) || !preg_match('/<body[\s>]/i', $html)) {
      throw new \InvalidArgumentException('That isn’t a whole HTML document.');
    }
    $now = $this->time->getRequestTime();
    $doc = $this->getDoc($slug) ?? ['created' => $now];
    $this->docs()->set($slug, ['title' => $title ?: ($doc['title'] ?? $slug), 'html' => $html, 'changed' => $now] + $doc);
  }

  /**
   * A doc by slug, or NULL.
   */
  public function getDoc(string $slug): ?array {
    return $this->docs()->get($slug);
  }

  /**
   * Every doc, keyed by slug.
   */
  public function allDocs(): array {
    $docs = $this->docs()->getAll();
    ksort($docs);
    return $docs;
  }

  /**
   * Deletes a doc and every link to it.
   */
  public function deleteDoc(string $slug): void {
    $this->links()->deleteMultiple(array_keys($this->linksFor($slug)));
    $this->docs()->delete($slug);
  }

  /**
   * Issues a recipient a link to a doc, and returns its token.
   */
  public function addLink(string $slug, string $label): string {
    if (!$this->getDoc($slug)) {
      throw new \InvalidArgumentException("No doc \"$slug\".");
    }
    $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    $this->links()->set($token, [
      'doc' => $slug,
      'label' => $label,
      'active' => TRUE,
      'answers' => [],
      'saved' => NULL,
      'finished' => NULL,
      'created' => $this->time->getRequestTime(),
    ]);
    return $token;
  }

  /**
   * A link, active or not, or NULL.
   */
  public function getLink(string $token): ?array {
    return $this->links()->get($token);
  }

  /**
   * A link a recipient may use: it exists, is active, and its doc does too.
   */
  public function activeLink(string $token): ?array {
    $link = $this->getLink($token);
    return $link && $link['active'] && $this->getDoc($link['doc']) ? $link : NULL;
  }

  /**
   * The links to a doc, keyed by token, oldest first.
   */
  public function linksFor(string $slug): array {
    $links = array_filter($this->links()->getAll(), fn ($l) => $l['doc'] === $slug);
    uasort($links, fn ($a, $b) => $a['created'] <=> $b['created']);
    return $links;
  }

  /**
   * Replaces a link's answers. Returns TRUE if this save is the first Finished.
   */
  public function saveAnswers(string $token, array $answers, bool $finished = FALSE): bool {
    $link = $this->getLink($token);
    $now = $this->time->getRequestTime();
    // A change saved after finishing reopens the doc: the recipient is going
    // back through it, and their next Finish is news again.
    if (!$finished && !empty($link['finished']) && ($link['answers'] ?? []) != $answers) {
      unset($link['finished']);
    }
    $link['answers'] = $answers;
    $link['saved'] = $now;
    $first = $finished && empty($link['finished']);
    if ($first) {
      $link['finished'] = $now;
    }
    $this->links()->set($token, $link);
    return $first;
  }

  /**
   * Revokes a link, or reopens one.
   */
  public function setActive(string $token, bool $active): void {
    $link = $this->getLink($token);
    $link['active'] = $active;
    $this->links()->set($token, $link);
  }

}
