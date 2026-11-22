<?php

declare(strict_types=1);

namespace Drupal\ar_docs\Controller;

use Drupal\ar_docs\DocAssembler;
use Drupal\ar_docs\DocStore;
use Drupal\ar_docs\EventSubscriber\SealSubscriber;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Flood\FloodInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Core\PageCache\ResponsePolicy\KillSwitch;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Serves a recipient their doc, and takes their answers.
 */
class DocController extends ControllerBase {

  /**
   * Largest answers payload accepted, in bytes.
   *
   * A long doc answered in full is a few kilobytes; this is room to spare.
   */
  public const MAX_BYTES = 262144;

  /**
   * Saves allowed per link and IP in an hour.
   *
   * Autosave is debounced, so even a fast typist on a long doc stays under it.
   */
  public const FLOOD_LIMIT = 600;

  public function __construct(
    protected DocStore $store,
    protected DocAssembler $assembler,
    protected FloodInterface $flood,
    protected MailManagerInterface $mail,
    protected KillSwitch $killSwitch,
    ConfigFactoryInterface $config,
  ) {
    $this->configFactory = $config;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('ar_docs.store'),
      $container->get('ar_docs.assembler'),
      $container->get('flood'),
      $container->get('plugin.manager.mail'),
      $container->get('page_cache_kill_switch'),
      $container->get('config.factory'),
    );
  }

  /**
   * GET /doc/{token}: the doc, with the recipient's answers so far.
   */
  public function view(string $token, Request $request): Response {
    $link = $this->store->activeLink($token) ?? throw new NotFoundHttpException();
    return $this->page($request, $link, [
      'mode' => 'edit',
      'save' => Url::fromRoute('ar_docs.save', ['token' => $token])->toString(),
      'logo' => Url::fromUri('base:' . $this->moduleHandler()->getModule('ar_docs')->getPath() . '/images/shield.svg')->toString(),
      'finished' => !empty($link['finished']),
      'recipient' => $link['label'],
      'answers' => $link['answers'],
    ]);
  }

  /**
   * POST /doc/{token}/answers: replace the recipient's answers.
   *
   * Body: {"answers": {"<element id>": "<answer>", ...}, "finished": bool}.
   */
  public function save(string $token, Request $request): JsonResponse {
    $this->killSwitch->trigger();
    if (!$this->store->activeLink($token)) {
      return $this->json($request, ['error' => 'Not found'], 404);
    }
    // Same-site only. The token is the credential, but no other site's page
    // should be able to post answers with it.
    $origin = $request->headers->get('Origin');
    if ($origin !== NULL && parse_url($origin, PHP_URL_HOST) !== $request->getHost()) {
      return $this->json($request, ['error' => 'Cross-site'], 403);
    }
    $site = $request->headers->get('Sec-Fetch-Site');
    if ($site !== NULL && $site !== 'same-origin') {
      return $this->json($request, ['error' => 'Cross-site'], 403);
    }
    $content = $request->getContent();
    if (strlen($content) > self::MAX_BYTES) {
      return $this->json($request, ['error' => 'Too large'], 413);
    }
    $flood_id = $token . ':' . $request->getClientIp();
    if (!$this->flood->isAllowed('ar_docs.save', self::FLOOD_LIMIT, 3600, $flood_id)) {
      return $this->json($request, ['error' => 'Too many saves'], 429);
    }
    $this->flood->register('ar_docs.save', 3600, $flood_id);

    $data = json_decode($content, TRUE);
    $answers = is_array($data) ? ($data['answers'] ?? NULL) : NULL;
    if (!is_array($answers) || ($answers && array_is_list($answers)) || !$this->valid($answers)) {
      return $this->json($request, ['error' => 'Bad answers'], 400);
    }

    if ($this->store->saveAnswers($token, $answers, !empty($data['finished']))) {
      $this->notify($token);
    }
    $link = $this->store->getLink($token);
    return $this->json($request, ['saved' => $link['saved'], 'finished' => !empty($link['finished'])]);
  }

  /**
   * Answers are element ids mapped to short strings, nothing else.
   */
  protected function valid(array $answers): bool {
    if (count($answers) > 2000) {
      return FALSE;
    }
    foreach ($answers as $id => $value) {
      if (!is_string($value) || mb_strlen($value) > 10000 || !preg_match('/^[A-Za-z][A-Za-z0-9_:.-]{0,127}$/', (string) $id)) {
        return FALSE;
      }
    }
    return TRUE;
  }

  /**
   * Emails whoever the settings name that a recipient has finished.
   */
  protected function notify(string $token): void {
    $to = $this->config('ar_docs.settings')->get('notify') ?: $this->config('system.site')->get('mail');
    if ($to) {
      $this->mail->mail('ar_docs', 'finished', $to, $this->languageManager()->getDefaultLanguage()->getId(), [
        'token' => $token,
        'link' => $this->store->getLink($token),
        'doc' => $this->store->getDoc($this->store->getLink($token)['doc']),
      ]);
    }
  }

  /**
   * The assembled doc as an uncacheable, unindexed response.
   */
  protected function page(Request $request, array $link, array $settings): Response {
    $this->killSwitch->trigger();
    $doc = $this->store->getDoc($link['doc']);
    $response = new Response($this->assembler->assemble($doc['html'], $settings), 200, [
      'Content-Type' => 'text/html; charset=utf-8',
    ] + $this->headers());
    return $this->seal($request, $response);
  }

  /**
   * A JSON response with the same headers.
   */
  protected function json(Request $request, array $data, int $status = 200): JsonResponse {
    return $this->seal($request, new JsonResponse($data, $status, $this->headers()));
  }

  /**
   * Leaves the response for SealSubscriber to restore after site rewrites.
   */
  protected function seal(Request $request, Response $response): Response {
    $request->attributes->set(SealSubscriber::BODY, $response->getContent());
    $request->attributes->set(SealSubscriber::HEADERS, $this->headers());
    return $response;
  }

  /**
   * Never cached, at Drupal, the edge or the browser; never indexed.
   */
  protected function headers(): array {
    return [
      'Cache-Control' => 'no-store, private, max-age=0',
      'X-Robots-Tag' => 'noindex, nofollow',
      'Referrer-Policy' => 'no-referrer',
    ];
  }

}
