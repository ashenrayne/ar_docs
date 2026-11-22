<?php

declare(strict_types=1);

namespace Drupal\ar_docs\Drush\Commands;

use Consolidation\OutputFormatters\StructuredData\RowsOfFields;
use Drupal\ar_docs\DocAssembler;
use Drupal\ar_docs\DocStore;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Url;
use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Client docs from the command line: put a doc up, issue links, read answers.
 *
 * Recipients are named by the label they were given (Bud), so nobody has to
 * handle a token. Give the site's public address with --uri so the printed
 * links are the ones to send.
 */
final class ArDocsCommands extends DrushCommands {

  public function __construct(
    private readonly DocStore $store,
    private readonly DocAssembler $assembler,
    private readonly DateFormatterInterface $dates,
  ) {
    parent::__construct();
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('ar_docs.store'),
      $container->get('ar_docs.assembler'),
      $container->get('date.formatter'),
    );
  }

  /**
   * Put a new doc up, and issue a link per recipient.
   */
  #[CLI\Command(name: 'ar-docs:add')]
  #[CLI\Argument(name: 'slug', description: 'The doc’s id: lower-case words joined by hyphens, e.g. dmx-locations-bud.')]
  #[CLI\Argument(name: 'file', description: 'The doc’s .html file, or - to read it from standard input.')]
  #[CLI\Option(name: 'title', description: 'For us, in lists and the Finished email. Defaults to the doc’s <title>.')]
  #[CLI\Option(name: 'recipient', description: 'Who gets a link. Repeat or comma-separate for several.')]
  #[CLI\Usage(name: 'cat review.html | drush ar-docs:add dmx-locations-bud - --recipient=Bud', description: 'Pipe a doc in and issue Bud a link.')]
  #[CLI\FieldLabels(labels: ['recipient' => 'Recipient', 'url' => 'Link to send'])]
  public function add(string $slug, string $file = '-', array $options = ['title' => NULL, 'recipient' => []]): RowsOfFields {
    if ($this->store->getDoc($slug)) {
      throw new \RuntimeException("\"$slug\" already exists. ar-docs:update replaces its HTML.");
    }
    $html = $this->read($file);
    $this->store->putDoc($slug, $options['title'] ?: $this->title($html, $slug), $html);
    $rows = [];
    foreach ($this->names($options['recipient']) as $name) {
      $rows[] = ['recipient' => $name, 'url' => $this->url($this->store->addLink($slug, $name))];
    }
    $this->logger()->success("Added $slug.");
    return new RowsOfFields($rows);
  }

  /**
   * Replace a doc’s HTML with a new build. Links and answers are kept.
   */
  #[CLI\Command(name: 'ar-docs:update')]
  #[CLI\Argument(name: 'slug', description: 'The doc’s id.')]
  #[CLI\Argument(name: 'file', description: 'The new .html file, or - to read standard input.')]
  #[CLI\Option(name: 'title', description: 'A new title; omit to keep the current one.')]
  public function update(string $slug, string $file = '-', array $options = ['title' => NULL]): void {
    $doc = $this->doc($slug);
    $this->store->putDoc($slug, $options['title'] ?: $doc['title'], $this->read($file));
    $this->logger()->success("Updated $slug. Saved answers match the new build by element id.");
  }

  /**
   * Issue another recipient a link to a doc.
   */
  #[CLI\Command(name: 'ar-docs:link')]
  #[CLI\Argument(name: 'slug', description: 'The doc’s id.')]
  #[CLI\Argument(name: 'recipient', description: 'Who the link is for.')]
  public function link(string $slug, string $recipient): string {
    $this->doc($slug);
    return $this->url($this->store->addLink($slug, $recipient));
  }

  /**
   * Every doc and its recipients: how far each has got.
   */
  #[CLI\Command(name: 'ar-docs:list')]
  #[CLI\Argument(name: 'slug', description: 'Only this doc.')]
  #[CLI\FieldLabels(labels: [
    'doc' => 'Doc',
    'recipient' => 'Recipient',
    'answers' => 'Answers',
    'saved' => 'Last saved',
    'finished' => 'Finished',
    'active' => 'Active',
    'url' => 'Link',
  ])]
  #[CLI\DefaultTableFields(fields: ['doc', 'recipient', 'answers', 'saved', 'finished', 'active'])]
  public function list(?string $slug = NULL, array $options = ['format' => 'table']): RowsOfFields {
    $docs = $slug ? [$slug => $this->doc($slug)] : $this->store->allDocs();
    $rows = [];
    foreach ($docs as $id => $doc) {
      $links = $this->store->linksFor($id);
      if (!$links) {
        $blank = array_fill_keys(['answers', 'saved', 'finished', 'active', 'url'], '');
        $rows[] = ['doc' => $id, 'recipient' => '(none)'] + $blank;
      }
      foreach ($links as $token => $link) {
        $rows[] = [
          'doc' => $id,
          'recipient' => $link['label'],
          'answers' => count($link['answers']),
          'saved' => $this->when($link['saved']),
          'finished' => $this->when($link['finished']),
          'active' => $link['active'] ? 'yes' : 'revoked',
          'url' => $this->url($token),
        ];
      }
    }
    return new RowsOfFields($rows);
  }

  /**
   * Answers to a doc: everyone’s, or narrowed by recipient and item.
   */
  #[CLI\Command(name: 'ar-docs:answers')]
  #[CLI\Argument(name: 'slug', description: 'The doc’s id.')]
  #[CLI\Option(name: 'recipient', description: 'Only these recipients (comma-separated).')]
  #[CLI\Option(name: 'item', description: 'Only these items, by element id; wildcards work (q-*, c-other-*). Comma-separated.')]
  #[CLI\Usage(name: 'drush ar-docs:answers dmx-locations-bud --item=q-*', description: 'Bud’s answers to the Questions section.')]
  #[CLI\Usage(name: 'drush ar-docs:answers dmx-locations-bud --format=json', description: 'Everything, for a script.')]
  #[CLI\FieldLabels(labels: [
    'recipient' => 'Recipient',
    'item' => 'Item',
    'label' => 'What it is',
    'answer' => 'Answer',
  ])]
  public function answers(
    string $slug,
    array $options = ['recipient' => NULL, 'item' => NULL, 'format' => 'table'],
  ): RowsOfFields {
    $doc = $this->doc($slug);
    $labels = $this->assembler->labels($doc['html']);
    $who = $this->names($options['recipient']);
    $items = $this->names($options['item']);
    $rows = [];
    foreach ($this->store->linksFor($slug) as $link) {
      if ($who && !in_array(mb_strtolower($link['label']), array_map('mb_strtolower', $who), TRUE)) {
        continue;
      }
      foreach ($link['answers'] as $id => $answer) {
        if ($items && !array_filter($items, fn ($glob) => $this->matches($glob, $id))) {
          continue;
        }
        $rows[] = ['recipient' => $link['label'], 'item' => $id, 'label' => $labels[$id] ?? '', 'answer' => $answer];
      }
    }
    return new RowsOfFields($rows);
  }

  /**
   * The doc with one recipient’s answers in it, read-only, to open locally.
   */
  #[CLI\Command(name: 'ar-docs:render')]
  #[CLI\Argument(name: 'slug', description: 'The doc’s id.')]
  #[CLI\Option(name: 'recipient', description: 'Whose answers.')]
  #[CLI\Usage(name: 'drush ar-docs:render dmx-locations-bud --recipient=Bud > bud.html', description: 'Bud’s answers, in the doc.')]
  public function render(string $slug, array $options = ['recipient' => NULL]): void {
    $doc = $this->doc($slug);
    [, $link] = $this->find($slug, (string) $options['recipient']);
    $this->output()->write($this->assembler->assemble($doc['html'], [
      'mode' => 'view',
      'finished' => !empty($link['finished']),
      'recipient' => $link['label'],
      'answers' => $link['answers'],
    ]));
  }

  /**
   * Stop a recipient’s link opening. Their answers stay.
   */
  #[CLI\Command(name: 'ar-docs:revoke')]
  #[CLI\Argument(name: 'slug', description: 'The doc’s id.')]
  #[CLI\Argument(name: 'recipient', description: 'Whose link.')]
  #[CLI\Option(name: 'restore', description: 'Reopen a revoked link instead.')]
  public function revoke(string $slug, string $recipient, array $options = ['restore' => FALSE]): void {
    [$token] = $this->find($slug, $recipient);
    $this->store->setActive($token, (bool) $options['restore']);
    $this->logger()->success($options['restore'] ? "Reopened $recipient’s link." : "Revoked $recipient’s link.");
  }

  /**
   * Delete a doc, its links and their answers. For when the round is done.
   */
  #[CLI\Command(name: 'ar-docs:delete')]
  #[CLI\Argument(name: 'slug', description: 'The doc’s id.')]
  public function delete(string $slug): void {
    $this->doc($slug);
    $count = count($this->store->linksFor($slug));
    if (!$this->io()->confirm("Delete $slug, its $count link(s) and their answers?", FALSE)) {
      throw new \RuntimeException('Cancelled.');
    }
    $this->store->deleteDoc($slug);
    $this->logger()->success("Deleted $slug.");
  }

  /**
   * A doc by id, or an error naming the command that lists them.
   */
  private function doc(string $slug): array {
    return $this->store->getDoc($slug) ?? throw new \RuntimeException("No doc \"$slug\". ar-docs:list shows them.");
  }

  /**
   * A recipient’s link by name: [token, link].
   */
  private function find(string $slug, string $recipient): array {
    $matches = array_filter($this->store->linksFor($slug), fn ($l) => mb_strtolower($l['label']) === mb_strtolower(trim($recipient)));
    if (count($matches) !== 1) {
      throw new \RuntimeException($matches ? "More than one \"$recipient\" on $slug." : "No recipient \"$recipient\" on $slug.");
    }
    return [array_key_first($matches), reset($matches)];
  }

  /**
   * A doc's HTML from a file, or standard input for -.
   */
  private function read(string $file): string {
    $html = $file === '-' ? stream_get_contents(STDIN) : @file_get_contents($file);
    if ($html === FALSE || trim($html) === '') {
      throw new \RuntimeException($file === '-' ? 'Nothing on standard input.' : "Can’t read $file.");
    }
    return $html;
  }

  /**
   * The doc's own <title>, for when none is given.
   */
  private function title(string $html, string $fallback): string {
    return preg_match('/<title>(.*?)<\/title>/is', $html, $m) ? html_entity_decode(trim($m[1]), ENT_QUOTES | ENT_HTML5) : $fallback;
  }

  /**
   * Comma-separated or repeated option values, trimmed.
   */
  private function names(array|string|null $value): array {
    $parts = [];
    foreach ((array) $value as $v) {
      $parts = array_merge($parts, explode(',', (string) $v));
    }
    return array_values(array_filter(array_map('trim', $parts), 'strlen'));
  }

  /**
   * A recipient's link, absolute: the one to send.
   */
  private function url(string $token): string {
    return Url::fromRoute('ar_docs.doc', ['token' => $token], ['absolute' => TRUE])->toString();
  }

  /**
   * Whether an element id matches a pattern where * stands for anything.
   */
  private function matches(string $glob, string $id): bool {
    return (bool) preg_match('/^' . str_replace('\\*', '.*', preg_quote($glob, '/')) . '$/', $id);
  }

  /**
   * A timestamp for a table, or blank.
   */
  private function when(?int $time): string {
    return $time ? $this->dates->format($time, 'short') : '';
  }

}
