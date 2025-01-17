<?php

namespace Wallabag\ImportBundle\Controller;

use Craue\ConfigBundle\Util\Config;
use Predis\Client;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Wallabag\ImportBundle\Import\ImportChain;

class ImportController extends Controller
{
    /**
     * @Route("/", name="import")
     */
    public function importAction()
    {
        return $this->render('@WallabagImport/Import/index.html.twig', [
            'imports' => $this->get(ImportChain::class)->getAll(),
        ]);
    }

    /**
     * Display how many messages are queue (both in Redis and RabbitMQ).
     * Only for admins.
     */
    public function checkQueueAction()
    {
        $nbRedisMessages = null;
        $nbRabbitMessages = null;
        $redisNotInstalled = false;
        $rabbitNotInstalled = false;

        if (!$this->get(AuthorizationCheckerInterface::class)->isGranted('ROLE_SUPER_ADMIN')) {
            return $this->render('@WallabagImport/Import/check_queue.html.twig');
        }

        if ($this->get(Config::class)->get('import_with_rabbitmq')) {
            // in case rabbit is activated but not installed
            try {
                $nbRabbitMessages = $this->getTotalMessageInRabbitQueue('pocket')
                    + $this->getTotalMessageInRabbitQueue('readability')
                    + $this->getTotalMessageInRabbitQueue('wallabag_v1')
                    + $this->getTotalMessageInRabbitQueue('wallabag_v2')
                    + $this->getTotalMessageInRabbitQueue('firefox')
                    + $this->getTotalMessageInRabbitQueue('chrome')
                    + $this->getTotalMessageInRabbitQueue('instapaper')
                    + $this->getTotalMessageInRabbitQueue('pinboard')
                    + $this->getTotalMessageInRabbitQueue('delicious')
                    + $this->getTotalMessageInRabbitQueue('elcurator')
                ;
            } catch (\Exception $e) {
                $rabbitNotInstalled = true;
            }
        } elseif ($this->get(Config::class)->get('import_with_redis')) {
            $redis = $this->get(Client::class);

            try {
                $nbRedisMessages = $redis->llen('wallabag.import.pocket')
                    + $redis->llen('wallabag.import.readability')
                    + $redis->llen('wallabag.import.wallabag_v1')
                    + $redis->llen('wallabag.import.wallabag_v2')
                    + $redis->llen('wallabag.import.firefox')
                    + $redis->llen('wallabag.import.chrome')
                    + $redis->llen('wallabag.import.instapaper')
                    + $redis->llen('wallabag.import.pinboard')
                    + $redis->llen('wallabag.import.delicious')
                    + $redis->llen('wallabag.import.elcurator')
                ;
            } catch (\Exception $e) {
                $redisNotInstalled = true;
            }
        }

        return $this->render('@WallabagImport/Import/check_queue.html.twig', [
            'nbRedisMessages' => $nbRedisMessages,
            'nbRabbitMessages' => $nbRabbitMessages,
            'redisNotInstalled' => $redisNotInstalled,
            'rabbitNotInstalled' => $rabbitNotInstalled,
        ]);
    }

    /**
     * Count message in RabbitMQ queue.
     * It get one message without acking it (so it'll stay in the queue)
     * which will include the total of *other* messages in the queue.
     * Adding one to that messages will result in the full total message.
     *
     * @param string $importService The import service related: pocket, readability, wallabag_v1 or wallabag_v2
     *
     * @return int
     */
    private function getTotalMessageInRabbitQueue($importService)
    {
        $message = $this
            ->get('old_sound_rabbit_mq.import_' . $importService . '_consumer')
            ->getChannel()
            ->basic_get('wallabag.import.' . $importService);

        if (null === $message) {
            return 0;
        }

        return $message->delivery_info['message_count'] + 1;
    }
}
