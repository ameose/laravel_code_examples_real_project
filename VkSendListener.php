<?php

namespace App\Listeners;

use App\Events\VkSendEvent;
use App\Logger\Services\LoggerService;
use App\Sms\Services\VkService;
use Exception;

class VkSendListener
{
    protected $logger;
    protected $vkService;

    public function __construct(LoggerService $logger, VkService $vkService)
    {
        $this->logger = $logger;
        $this->vkService = $vkService;
    }

    /**
     * @param VkSendEvent $event
     */
    public function handle(VkSendEvent $event)
    {
        $this->logger->info(__METHOD__)->write();
        $this->logger->info("Событие отправки VK")->write();
        try {
            $sms = $this->vkService->setSender($event->getSmsSender())
                ->sendVk(
                    $event->getPhone(),
                    $event->getMsgData(),
                    $event->getTemplate()
                );
            return $sms;
        } catch (Exception $e) {
            $this->logger->error('Vk error - ' . $e->getMessage());
        }
    }
}
