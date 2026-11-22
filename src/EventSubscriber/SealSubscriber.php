<?php

declare(strict_types=1);

namespace Drupal\ar_docs\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Puts a doc response back exactly as the controller made it.
 *
 * A client site may rewrite every HTML response on the way out: md cloaks email
 * addresses into tokens, another site may link phone numbers or inject an
 * analytics tag. On a doc those rewrites change the doc itself, its prefilled
 * fields, and the saved answers inside its settings, so the recipient no longer
 * sees what was sent. Rather than ask every rewriter on every site to skip
 * these routes, this runs after all of them and restores the body and the
 * privacy headers the controller set.
 */
final class SealSubscriber implements EventSubscriberInterface {

  /**
   * The request attribute the controller leaves the response's body in.
   */
  public const BODY = '_ar_docs_body';

  /**
   * The request attribute holding the headers the controller set.
   */
  public const HEADERS = '_ar_docs_headers';

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    // Last of all: md's email cloak runs at -512.
    return [KernelEvents::RESPONSE => ['onResponse', -100000]];
  }

  /**
   * Restores the doc's body and headers if anything rewrote them.
   */
  public function onResponse(ResponseEvent $event): void {
    $attributes = $event->getRequest()->attributes;
    if (!$event->isMainRequest() || !$attributes->has(self::BODY)) {
      return;
    }
    $response = $event->getResponse();
    $body = $attributes->get(self::BODY);
    if ($response->getContent() !== $body) {
      $response->setContent($body);
    }
    foreach ($attributes->get(self::HEADERS, []) as $name => $value) {
      $response->headers->set($name, $value);
    }
    // Never let the edge hold a private doc, whatever set this.
    $response->headers->remove('Surrogate-Control');
  }

}
