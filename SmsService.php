<?php

namespace App\Sms\Services;

use Log;
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
use App\Logger\Services\LoggerService;

class SmsService implements NotificationGatewayInterface
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
    public function send(string $to, string $message, bool $en = false): Sms
    {
        $code = 0;
        $sms_id = null;
        $code_text = null;

        if (strlen($to) === 0) {
            throw new SmsPhoneFormatException();
        }

        if ($en) {
            $message = (new Transliterator(Settings::LANG_RU, Settings::SYSTEM_GOST_2000_B))
                ->cyr2Lat($message);
        }

        try {
            $sms_id = $this->provider->send($to, $message, $this->sender->getName());
        } catch (SmsException $ex) {
            if (!$ex instanceof SmsGatewayException) {
                Log::error($ex->getMessage(), $ex->getTrace());
            }
            $code = $this->smsRepository::STATUS_ERROR;
            $code_text = $ex->getMessage();
        }

        $sms = $this->smsRepository->create(
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
     * @param string $to
     * @param string $message
     * @param bool $en
     * @return Sms
     * @throws SmsException
     */
    public function sendSms(string $to, string $message, bool $en = false): Sms
    {
        $this->logger->setLogPath("logs/messages");
        $this->logger->setType("dispatch_order_paid");
        $this->logger->info("smsService dispatch")->write();

        $code = 0;
        $sms_id = null;
        $result = null;
        $code_text = null;

        if (strlen($to) === 0) {
            throw new SmsPhoneFormatException();
        }

        if ($en) {
            $message = (new Transliterator(Settings::LANG_RU, Settings::SYSTEM_GOST_2000_B))
                ->cyr2Lat($message);
        }

        // $sms_id = "3792333148049466240";
        // $code_text = "OK";
        // $this->setExpire();

        try {
            $this->logger->info("Сервис SMS")->write();
            $result = $this->provider->sendSms($to, $message, $this->sender->getName());
            $sms_id = $result['result'][0]['messageId'];
            $code_text = $result['result'][0]['code'];
            $this->setExpire();
            $this->logger->info("result", $result)->write();
        } catch (SmsException $ex) {
            if (!$ex instanceof SmsGatewayException) {
                Log::error($ex->getMessage(), $ex->getTrace());
            }
            $code = $this->smsRepository::STATUS_ERROR;
            $code_text = $ex->getMessage();
        
        }
        $this->logger->info("Готовимся к записи в репозиторий")->write();

        $sms = $this->smsRepository->create(
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
        $user = User::find($notification->getUserId());
        /** @var City $city */
        $city = $user->getRegion()->getCities()->first();
        /** @var Cinema $cinema */
        $cinema = $city->getCinemas()->first();
        $sender = $cinema->getSmsSender();

        $this->setExpire();

        $this->logger->setLogPath("logs/messages");
        $this->logger->setType("dispatch");
        $this->logger->info("dispatch", [$user->getPhone(), $notification->getMessage()])->write();
        $sms = $this->setSender($sender)->sendSms($user->getPhone(), $notification->getMessage(), true);

        return $sms->getId();
    }
}
