<?php

namespace App\Cinema\Services;

use DB;
use Cache;
use Exception;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Database\Eloquent\Model;

use App\Events\{SmsSendEvent, VkSendEvent, FlashCallEvent};
use App\Exceptions\AppException;
use App\Sms\Services\SmsService;
use App\Logger\Services\LoggerService;
use App\Cinema\Entities\{Cinema, PhoneVerification};
use App\Cinema\Repositories\PhoneVerificationRepository;
use App\Cinema\Exceptions\{
    PhoneVerificationCodeActivated,
    PhoneVerificationCodeExpired,
    PhoneVerificationCodeNotFount,
    PhoneVerificationIsNull,
    PhoneVerificationMaxCountIsLive,
    UserCreatePhoneNotVerified
};
use App\Listeners\VkSendListener;
use App\Listeners\SmsSendListener;

class PhoneVerificationService
{
    /**
     * 10 минут для активации, иначе запрошаиваем снова
     */
    public const EXPIRED_TIME = 60 * 15;
    /**
     * Максимальное кол-во смс которое может быть не просрочено.
     */
    public const MAX_COUNT_AT_ONCE = 4;

    /**
     * Максимальное кол-во за час
     */
    public const MAX_COUNT_PER_HOUR = 5;

    private $logger;

    protected $cinema;
    protected $smsService;
    protected $phoneVerificationRepository;
    protected $sendType; // Тип сервиса (sms, vk)
    protected $vkListener;
    protected $smsListener;

    public function __construct(
        LoggerService $logger,
        SmsService $smsService,
        PhoneVerificationRepository $phoneVerificationRepository,
        VkSendListener $vkListener,
        SmsSendListener $smsListener
    ) {
        $this->logger = $logger;
        $this->smsService = $smsService;
        $this->phoneVerificationRepository = $phoneVerificationRepository;
        $this->vkListener = $vkListener;
        $this->smsListener = $smsListener;
    }

    /**
     * @param Cinema $cinema
     *
     * @return $this
     */
    public function setCinema(Cinema $cinema): self
    {
        $this->cinema = $cinema;

        return $this;
    }

    /**
     * @return Cinema
     */
    public function getCinema(): Cinema
    {
        return $this->cinema;
    }

    /**
     * @param string $phone
     *
     * @return PhoneVerification|Model
     * @throws AppException
     * @throws PhoneVerificationMaxCountIsLive
     */
    public function create(string $phone, string $type = 'sms')
    {

        if (app()->environment() === 'production') {
            $this->verificationIsLive($phone);
        }
        try {
            DB::beginTransaction();

            $code = $this->phoneVerificationRepository->genCode();
            $sms_message = 'Ваш код на comfortkino.ru: ' . $code;
            $expired_at = Carbon::now()->addSeconds(self::EXPIRED_TIME)->toDateTimeString();
            $verify = $this->phoneVerificationRepository->create($phone, $code, $expired_at);

            if (app()->environment() !== 'local') {
                if ($this->shouldSendMessageViaVk($phone)) {
                    // Проверка и отправка через VK
                    if ($this->sendVk($phone, $code) != "OK") {
                        $this->logger->info("Отправка");
                        $this->sendSms($phone, $sms_message);
                    }
                } else {
                    $this->sendSms($phone, $sms_message);
                }
            }

            DB::commit();

            return ['verify' => $verify, 'sendType' => $this->sendType];
        } catch (Exception $ex) {
            DB::rollBack();
            if ($ex instanceof AppException) {
                throw new AppException($ex->getMessage(), $ex->getCode());
            }
            throw new AppException();
        }
    }

    protected function sendflashcall(string $phone, string $code)
    {
        event(new FlashCallEvent($phone, $code));
        $this->logger->error('FlashCall: phone=' . $phone . ', code=' . $code);
        $this->sendType = 'flashcall';
    }

    protected function sendVK(string $phone, string $code)
    {
        $template_id = 1;
        $this->logger->setLogPath("logs/messages");
        $this->logger->setType("sendVk");
        $this->logger->info("Начало отправки VK сообщения")->write();
        // Шаблон 1
        $msgData = [
            'codeauth' => $code
        ];
        // Шаблон 2
        // $msgData = [
        //     'cinema' => "Megapolis",
        //     'order' => "9999",
        //     'date' => "08.12.2023",
        //     'time' => "14:20",
        //     'hall' => "2",
        //     'row' => "5",
        //     'places' => "2"
        // ];
        // Шаблон 3
        // $msgData = [
        //     'order' => "9999",
        // ];
        // Шаблон 4
        // $msgData = [
        //     'film_name' => 'Вонка',
        //     'cinema' => 'Megapolis',
        //     'order' => '6666'
        // ];

        $event = new VkSendEvent(
            $this->getCinema()->getSmsSender(),
            $phone,
            $msgData,
            $template_id
        );
        $sms = $this->vkListener->handle($event);
        $attributes = $sms->getAttributes();
        $code_text = $attributes['code_text'];
        $this->logger->info("Attributes", $sms->getAttributes())->write();
        $this->logger->info("Код", $code_text)->write();
        // $this->logger->error('VK: phone=' . $phone . ', code=' . $code . ' - отправлено');
        $this->sendType = 'vk';
        return $code_text;
    }

    /**
     * Определяет, нужно ли отправлять сообщение через VK.
     *
     * @param string $phone
     * @return bool
     */
    protected function shouldSendMessageViaVk(string $phone): bool
    {
        $key = 'vk_message_sent:' . $phone;
        $lastSent = Cache::get($key);

        // Устанавливаем интервал в 5 минут
        $interval = Carbon::now()->subMinutes(5);

        if (!$lastSent || $lastSent < $interval) {
            // Обновляем время последней отправки сообщения
            Cache::put($key, Carbon::now(), Carbon::now()->addMinutes(5));
            return true;
        }

        return false;
    }


    protected function sendSms(string $phone, string $sms_message)
    {
        $event = new SmsSendEvent(
            $this->getCinema()->getSmsSender(),
            $phone,
            $sms_message
        );
        $this->smsListener->handle($event);
        $this->logger->error('Sms: phone=' . $phone . ', message=' . $sms_message . ' - отправлено');
        $this->sendType = 'sms';
    }


    /**
     * @param string $phone
     * @param string $code
     *
     * @return PhoneVerification
     * @throws AppException
     */
    public function confirm(string $phone, string $code): ?PhoneVerification
    {
        try {
            DB::beginTransaction();

            $verify = $this->phoneVerificationRepository->getByPhoneAndCode($phone, $code);

            if (!$verify) {
                $this->logger->error('Sms: phone=' . $phone . ', code=' . $code . ' - не найдено');
                throw new PhoneVerificationCodeNotFount();
            }

            if ($verify->isActivated()) {
                $this->logger->error('Sms: phone=' . $phone . ', code=' . $code . ' - уже использоватли');
                throw new PhoneVerificationCodeActivated();
            }

            if ($verify->isExpired()) {
                $this->logger->error('Sms: phone=' . $phone . ', code=' . $code . ' - просрочено');
                throw new PhoneVerificationCodeExpired();
            }

            Db::commit();

            return $verify;
        } catch (Exception $ex) {
            Db::rollBack();
            throw new AppException($ex->getMessage(), $ex->getCode());
        }
    }

    /**
     * @param string $phone
     * @param string $code_uuid
     *
     * @return PhoneVerification
     * @throws AppException
     */
    public function verify(string $phone, string $code_uuid = null): ?PhoneVerification
    {
        try {
            DB::beginTransaction();

            if (!$code_uuid) {
                throw new PhoneVerificationIsNull();
            }

            $verify = $this->phoneVerificationRepository->getByUuid($code_uuid);

            if (!$verify || $verify->isActivated()) {
                $this->logger->error('Sms: phone=' . $phone . ', uuid=' . $code_uuid . ' - не найдено');
                throw new PhoneVerificationCodeNotFount();
            }

            if ($verify->getPhone() !== $phone) {
                $this->logger->error('Sms: phone=' . $phone . ', phone=' . $verify->getPhone() . ' - не совпадают телефоны');
                throw new UserCreatePhoneNotVerified();
            }

            Db::commit();

            return $verify;
        } catch (Exception $ex) {
            Db::rollBack();
            throw new AppException($ex->getMessage(), $ex->getCode());
        }
    }

    /**
     * @param int $id
     */
    public function updateActive(int $id): void
    {
        $this->phoneVerificationRepository->updateActive($id);
    }

    /**
     * @param string $phone
     *
     * @throws PhoneVerificationMaxCountIsLive
     */
    private function verificationIsLive(string $phone): void
    {
        $key = 'phone_verification:' . $phone;
        $verifications = Cache::get($key);

        if (!$verifications) {
            $verifications = $this->phoneVerificationRepository->verificationsInHour($phone);
        }

        if (
            $verifications->count() >= self::MAX_COUNT_PER_HOUR ||
            optional($this->getActiveVerifications($verifications))->count() >= self::MAX_COUNT_AT_ONCE
        ) {
            Cache::put($key, $verifications, 1);
            throw new PhoneVerificationMaxCountIsLive();
        }
    }

    /**
     * @param Collection $collection
     *
     * @return Collection|null
     */
    private function getActiveVerifications(Collection $collection): ?Collection
    {
        return $collection->filter(static function (PhoneVerification $item) {
            return !$item->isActivated() && !$item->isExpired();
        });
    }
}
