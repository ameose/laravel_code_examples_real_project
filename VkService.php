<?php

namespace App\Sms\Services;

use Log;
use App\Logger\Services\LoggerService;
use App\Cinema\Entities\Cinema;
use App\Cinema\Entities\City;
use App\Cinema\Entities\User;
use Carbon\Carbon;

use App\SMs\Entities\{Sms, SmsSender};
use App\Sms\Repositories\SmsRepository;
use App\Sms\Contracts\SmsGatewayInterface;
use App\Sms\Exceptions\{
    SmsException,
    SmsGatewayException,
    SmsNotFoundException,
    SmsNotSendingException,
    SmsPhoneFormatException
};
use App\Interfaces\Notification\{NotificationGatewayInterface, NotificationInterface, NotificationModelInterface};
use Transliterator\Settings;
use Transliterator\Transliterator;
use DB;

class VkService implements NotificationGatewayInterface
{
    /**
     * @var SmsSender
     */
    protected $sender;
    /**
     * @var SmsGatewayRegistry
     */
    protected $gateway;
    /**
     * @var SmsGatewayInterface
     */
    protected $provider;

    /**
     * @var Carbon|null
     */
    protected $expire;
    /**
     * @var SmsRepository
     */
    protected $smsRepository;

    protected $logger;

    public function __construct(
        SmsGatewayRegistry $gateway,
        SmsRepository $smsRepository,
        LoggerService $logger
    ) {
        $this->gateway = $gateway;
        $this->smsRepository = $smsRepository;
        $this->logger = $logger;
    }

    /**
     * @param SmsSender $sender
     *
     * @return $this
     * @throws SmsException
     */
    public function setSender(SmsSender $sender): self
    {
        $this->sender = $sender;
        $this->setProvider($sender);

        return $this;
    }

    /**
     * @param SmsSender $sender
     *
     * @throws SmsException
     */
    public function setProvider(SmsSender $sender): void
    {
        $this->provider = $this->gateway->get($sender);
    }

    public function setExpire(): NotificationGatewayInterface
    {
        $this->expire = Carbon::now()->addSeconds(config('sms.expiredSec'));

        return $this;
    }

    /**
     * @param string $to
     * @param string $message
     * @param bool $en
     * @return Sms
     * @throws SmsException
     */
    public function sendVk(
        string $to,
        array $msgData,
        $template_id
    ): Sms {
        $code = 0;
        $sms_status_code = null;
        $result = null;
        $sms_id = null;
        $code_text = null;
        $message = null;

        if (strlen($to) === 0) {
            throw new SmsPhoneFormatException();
        }

        try {
            $this->logger->info("Сервис VK")->write();
            $this->setExpire();
            $result = $this->provider->sendVk(
                $to,
                $msgData,
                $this->sender->getName(),
                $template_id
            );
            $sms_id = $result['result'][0]['messageId'];
            $code_text = $result['result'][0]['code'];

            $this->logger->info("result", $result)->write();
        } catch (SmsException $ex) {
            if (!$ex instanceof SmsGatewayException) {
                Log::error($ex->getMessage(), $ex->getTrace());
            }
            $code = $this->smsRepository::STATUS_ERROR;
            $code_text = $ex->getMessage();
        }

        if ($template_id == 1) {
            $message = "Ваш код на comfortkino.ru: " . $msgData['codeauth'];
        } elseif ($template_id == 2) {
            $message = "{$msgData['cinema']}," .
                " Заказ {$msgData['order']}," .
                " {$msgData['date']} {$msgData['time']}," .
                " Зал {$msgData['hall']}," .
                " {$msgData['row_and_places']}," .
                " {$msgData['url']}";
        } elseif ($template_id == 3) {
            $message = "Заказ {$msgData['order']} отменен";
        } elseif ($template_id == 4) {
            $message = "К сожалению, показ фильма «{$msgData['film_name']}» " .
                "в кинотеатре «{$msgData['cinema']}» не состоится " .
                "и ваш заказ {$msgData['order']} был отменен. " .
                "Приносим свои извинения, " .
                "возврат средств на карту будет " .
                "осуществлен в срок от 3 до 10 банковских дней. " .
                "Банки, как правило, не уведомляют о возврате. " .
                "Но движение по счету вы можете уточнить, " .
                "обратившись в банк выпустивший вашу карту.";
        }

        $sms = $this->smsRepository->createVk(
            $this->sender->getId(),
            $to,
            $message,
            $sms_id,
            $code,
            $code_text,
            $this->expire ? $this->expire->toDateTimeString() : null
        );

        if ($code) {
            throw new SmsNotSendingException();
        }

        return $sms;
    }

    /**
     * @param Sms $sms
     * @return Sms
     */
    public function status(Sms $sms): Sms
    {
        try {
            $this->setSender($sms->getSender());
            $delivered = $this->provider->status($sms->getExpId());
            if (!$delivered) {
                return $sms;
            }

            return $this->smsRepository->updateDelivered($sms, true);
        } catch (SmsException $ex) {
            if (!$ex instanceof SmsGatewayException) {
                Log::error($ex->getMessage());
            }

            return $this->smsRepository->updateError($sms, $ex->getMessage());
        }
    }

    /**
     * @param $id
     * @return NotificationModelInterface
     * @throws SmsNotFoundException
     */
    public function find($id): NotificationModelInterface
    {
        /** @var NotificationModelInterface $sms */
        $sms = $this->smsRepository->find($id);
        if ($sms) {
            return $sms;
        }

        throw new SmsNotFoundException();
    }

    /**
     * @param NotificationInterface $notification
     * @return string
     * @throws SmsException
     */
    public function dispatch(NotificationInterface $notification, array $msgData = [], $template_id = ''): string
    {
        $this->logger->setLogPath("logs/messages");
        $this->logger->setType("dispatch_order_paid");
        $this->logger->info("VkService dispatch", $msgData)->write();

        $user = User::find($notification->getUserId());
        /** @var City $city */
        $city = $user->getRegion()->getCities()->first();
        /** @var Cinema $cinema */
        $cinema = $city->getCinemas()->first();
        $sender = $cinema->getSmsSender();

        $this->setExpire();

        $sms = $this->setSender($sender)->sendVK(
            $user->getPhone(),
            $msgData,
            $template_id
        );

        return $sms->getId();
    }

}
