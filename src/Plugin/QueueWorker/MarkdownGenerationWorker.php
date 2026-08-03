<?php

declare(strict_types=1);

namespace Drupal\llm_content\Plugin\QueueWorker;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\Core\Queue\SuspendQueueException;
use Drupal\llm_content\Service\MarkdownConverterInterface;
use Drupal\llm_content\Service\MemoryGuardInterface;
use Drupal\node\NodeInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Processes markdown generation for nodes in the background.
 *
 * @QueueWorker(
 *   id = "llm_content_markdown_generation",
 *   title = @Translation("LLM Content Markdown Generation"),
 *   cron = {"time" = 60}
 * )
 */
final class MarkdownGenerationWorker extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected MarkdownConverterInterface $markdownConverter,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected LoggerInterface $logger,
    protected MemoryGuardInterface $memoryGuard,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): self {
    return new self(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get(MarkdownConverterInterface::class),
      $container->get('entity_type.manager'),
      $container->get('logger.factory')->get('llm_content'),
      $container->get(MemoryGuardInterface::class),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($data): void {
    // Stop before the render rather than after it. Converting one node costs
    // several MB and that memory is not reclaimed between items, so a process
    // that is already close to the limit has no headroom for another one —
    // checking afterwards would mean the damage is already done.
    //
    // Deliberately thrown without a delay. Core only re-runs a suspended
    // queue inside the same cron run when the exception is delayable
    // (\Drupal\Core\Cron::processQueues()), and it does so after a usleep()
    // in the same PHP process. Sleeping does not free memory, so a delayable
    // exception here would trip the guard again immediately and spin until
    // max_execution_time killed cron. With no delay the queue is skipped for
    // the rest of this run and resumes on the next one, in a fresh process.
    if ($this->memoryGuard->isNearLimit()) {
      throw new SuspendQueueException(sprintf(
        'Suspending markdown generation: %s is in use. The queue resumes on the next cron run; to drain a backlog sooner run `drush queue:run llm_content_markdown_generation --items-limit=100` repeatedly, so each batch gets a fresh process.',
        $this->memoryGuard->describe(),
      ));
    }

    if (empty($data['nid'])) {
      $this->logger->warning('Queue item missing nid, skipping.');
      return;
    }

    $nid = $data['nid'];
    $node = $this->entityTypeManager->getStorage('node')->load($nid);
    if (!$node instanceof NodeInterface) {
      $this->logger->notice('Node @nid not found, skipping.', ['@nid' => $nid]);
      return;
    }

    // Convert the language the item was queued for. Without this the
    // default translation is converted no matter which language was
    // queued, so a translation's row can never be rebuilt in bulk — it
    // is only ever regenerated on demand by a request to its own
    // /llm-md route.
    $langcode = $data['langcode'] ?? NULL;
    if (is_string($langcode) && $langcode !== '' && $node->hasTranslation($langcode)) {
      $node = $node->getTranslation($langcode);
    }

    // Checked after translation selection: each translation carries its
    // own published status.
    if (!$node->isPublished()) {
      $this->logger->notice('Node @nid is unpublished, skipping.', ['@nid' => $nid]);
      return;
    }

    $this->markdownConverter->convert($node);
    $this->entityTypeManager->getStorage('node')->resetCache([$nid]);
  }

}
