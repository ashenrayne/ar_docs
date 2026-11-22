<?php

declare(strict_types=1);

namespace Drupal\Tests\ar_docs\Kernel;

use Drupal\ar_docs\Controller\DocController;
use Drupal\ar_docs\DocStore;
use Drupal\Core\Test\AssertMailTrait;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * A recipient's link: what it serves, and what it will take.
 */
#[Group('ar')]
#[Group('ar_docs')]
#[RunTestsInSeparateProcesses]
class DocControllerTest extends KernelTestBase {

  use AssertMailTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user', 'ar_docs'];

  /**
   * A minimal client-report doc: one question, one field, one script.
   */
  protected const DOC = '<!DOCTYPE html><html><head><title>Review</title></head><body>'
    . '<ul><li class="yn" id="q-one"><span class="qtext">Still open?<span class="qctx">Context.</span></span>'
    . '<button class="ynbtn yes" data-val="yes"></button></li></ul>'
    . '<input type="text" class="answer" id="q-one-site" aria-label="Website" value="">'
    . '<script>window.docScript=1;</script></body></html>';

  /**
   * The store under test.
   */
  protected DocStore $store;

  /**
   * Bud's link.
   */
  protected string $token;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['system', 'ar_docs']);
    $this->config('system.mail')->set('interface.default', 'test_mail_collector')->save();
    $this->config('system.site')->set('mail', 'site@example.com')->save();
    $this->store = $this->container->get('ar_docs.store');
    $this->store->putDoc('review', 'Review', self::DOC);
    $this->token = $this->store->addLink('review', 'Bud');
  }

  /**
   * Sends a request through the kernel.
   */
  protected function request(string $method, string $path, string $body = '', array $server = []): Response {
    $request = Request::create($path, $method, [], [], [], $server + ['CONTENT_TYPE' => 'application/json'], $body);
    return $this->container->get('http_kernel')->handle($request);
  }

  /**
   * POSTs answers as the recipient's page would.
   */
  protected function post(array|string $data, array $server = []): Response {
    return $this->request('POST', "/doc/{$this->token}/answers", is_string($data) ? $data : json_encode($data), $server);
  }

  /**
   * The doc is served with the runtime ahead of its own script, never cached.
   */
  public function testServesTheDocUncached(): void {
    $this->store->saveAnswers($this->token, ['q-one' => 'yes']);
    $response = $this->request('GET', "/doc/{$this->token}");
    $this->assertSame(200, $response->getStatusCode());
    $html = $response->getContent();
    $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    $this->assertSame('noindex, nofollow', $response->headers->get('X-Robots-Tag'));
    $this->assertStringContainsString('<meta name="robots" content="noindex, nofollow">', $html);
    $runtime = strpos($html, 'window.AR_DOCS=');
    $this->assertNotFalse($runtime);
    $this->assertLessThan(strpos($html, 'window.docScript'), $runtime, 'The runtime runs before the doc’s script.');
    $this->assertStringContainsString('"q-one":"yes"', $html);
  }

  /**
   * A site's own response rewriting can't change a doc or its answers.
   *
   * Md cloaks email addresses on the way out; a doc's prefilled fields and
   * saved answers carry addresses, so the cloak would change what was sent.
   */
  public function testSiteRewritesCannotChangeTheDoc(): void {
    $this->store->putDoc('review', 'Review', str_replace('</ul>', '</ul><p>info@example.com</p>', self::DOC));
    $this->store->saveAnswers($this->token, ['q-one-site' => 'bud@example.com']);
    // Stands in for md's email cloak, at the priority it runs at.
    $this->container->get('event_dispatcher')->addListener(KernelEvents::RESPONSE, function (ResponseEvent $event) {
      $response = $event->getResponse();
      $response->setContent(str_replace('@', '[at]', (string) $response->getContent()));
      $response->headers->set('Cache-Control', 'public, max-age=3600');
      $response->headers->set('Surrogate-Control', 'max-age=604800');
    }, -512);

    $page = $this->request('GET', "/doc/{$this->token}");
    $this->assertStringContainsString('<p>info@example.com</p>', $page->getContent());
    $this->assertStringContainsString('bud@example.com', $page->getContent());
    $this->assertStringContainsString('no-store', $page->headers->get('Cache-Control'));
    $this->assertFalse($page->headers->has('Surrogate-Control'));

    $save = $this->post(['answers' => ['q-one-site' => 'bud@example.com']]);
    $this->assertSame(200, $save->getStatusCode());
    $this->assertStringContainsString('no-store', $save->headers->get('Cache-Control'));
  }

  /**
   * Only an active link to an existing doc opens.
   */
  public function testUnknownAndRevokedLinksAreNotFound(): void {
    // The POST first: within one kernel, a request after a handled 404
    // inherits its state, which real requests never do.
    $this->store->setActive($this->token, FALSE);
    $this->assertSame(404, $this->post(['answers' => ['q-one' => 'yes']])->getStatusCode());
    $this->assertSame(404, $this->request('GET', "/doc/{$this->token}")->getStatusCode());
    $this->assertSame(404, $this->request('GET', '/doc/' . str_repeat('a', 43))->getStatusCode());
    $this->store->setActive($this->token, TRUE);
    $this->assertSame(200, $this->request('GET', "/doc/{$this->token}")->getStatusCode());
  }

  /**
   * Answers replace the last save; Finished emails support once.
   */
  public function testSavesAnswersAndNotifiesOnceOnFinish(): void {
    $response = $this->post(['answers' => ['q-one' => 'yes', 'q-one-site' => 'https://example.com']]);
    $this->assertSame(200, $response->getStatusCode());
    $this->assertSame(['q-one' => 'yes', 'q-one-site' => 'https://example.com'], $this->store->getLink($this->token)['answers']);
    $this->assertCount(0, $this->getMails());

    $this->post(['answers' => ['q-one' => 'yes'], 'finished' => TRUE]);
    $this->assertSame(['q-one' => 'yes'], $this->store->getLink($this->token)['answers'], 'A save replaces the answers.');
    $mails = $this->getMails();
    $this->assertCount(1, $mails);
    $this->assertSame('support@ashenrayne.com', $mails[0]['to']);
    $this->assertStringContainsString('Bud finished Review', $mails[0]['subject']);

    $this->post(['answers' => ['q-one' => 'yes'], 'finished' => TRUE]);
    $this->assertCount(1, $this->getMails(), 'Finishing again does not email again.');
  }

  /**
   * Another site's page can't post answers, even holding the token.
   */
  public function testRefusesCrossSitePosts(): void {
    $body = ['answers' => ['q-one' => 'yes']];
    $this->assertSame(403, $this->post($body, ['HTTP_ORIGIN' => 'https://evil.example'])->getStatusCode());
    $this->assertSame(403, $this->post($body, ['HTTP_SEC_FETCH_SITE' => 'cross-site'])->getStatusCode());
    $same = ['HTTP_ORIGIN' => 'http://localhost', 'HTTP_SEC_FETCH_SITE' => 'same-origin'];
    $this->assertSame(200, $this->post($body, $same)->getStatusCode());
  }

  /**
   * Only an object of element ids to short strings is accepted.
   */
  public function testRejectsMalformedAnswers(): void {
    $this->assertSame(400, $this->post('not json')->getStatusCode());
    $this->assertSame(400, $this->post(['answers' => ['yes', 'no']])->getStatusCode(), 'A list, not ids.');
    $this->assertSame(400, $this->post(['answers' => ['bad id!' => 'yes']])->getStatusCode());
    $this->assertSame(400, $this->post(['answers' => ['q-one' => ['nested']]])->getStatusCode());
    $this->assertSame(413, $this->post(str_repeat('x', DocController::MAX_BYTES + 1))->getStatusCode());
    $this->assertSame([], $this->store->getLink($this->token)['answers']);
  }

  /**
   * Saves are rate-limited per link and address.
   */
  public function testRateLimitsSaves(): void {
    $flood = $this->container->get('flood');
    for ($i = 0; $i < DocController::FLOOD_LIMIT; $i++) {
      $flood->register('ar_docs.save', 3600, $this->token . ':127.0.0.1');
    }
    $this->assertSame(429, $this->post(['answers' => ['q-one' => 'yes']])->getStatusCode());
  }

  /**
   * An answer can't close the script tag it's printed into.
   */
  public function testAnswersCannotBreakOutOfTheScript(): void {
    $this->store->saveAnswers($this->token, ['q-one-site' => '</script><script>alert(1)</script>']);
    $html = $this->request('GET', "/doc/{$this->token}")->getContent();
    $this->assertStringNotContainsString('</script><script>alert(1)', $html);
    $this->assertStringContainsString(trim(json_encode('</script>', JSON_HEX_TAG), '"'), $html);
  }

  /**
   * Drush's answers carry each item's question text, without its context line.
   */
  public function testLabelsNameEachItem(): void {
    $labels = $this->container->get('ar_docs.assembler')->labels(self::DOC);
    $this->assertSame('Still open?', $labels['q-one']);
    $this->assertSame('Website', $labels['q-one-site']);
  }

}
