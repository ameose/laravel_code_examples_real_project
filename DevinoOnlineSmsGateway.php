<?php

namespace App\Sms\Services;

use Exception;
use App\Traits\HasHttpRequest;
use Illuminate\Support\Facades\DB;

class DevinoOnlineSmsGateway extends SmsGatewayAbstract
{
    use HasHttpRequest;

    protected $logger;

    public function send(string $to, string $message, string $from = null)
    {

        $endpoint = config('sms.devino_online.endpoint');
        $url = $endpoint . '/vk/messages';
        $token = config('sms.devino_online.token');
    }

    public function sendSms(string $to, string $message, string $from = null)
    {
        $this->logger->setLogPath("logs/messages");
        $this->logger->setType("sendSms");

        $endpoint = config('sms.devino_online.endpoint');
        $url = $endpoint . '/sms/messages'; // Путь для отправки SMS

        $token = config('sms.devino_online.token');
        $phone = "7" . $to;

        $data = [
            "messages" => [
                [
                    "from" => "ComfortKino",
                    "to" => $phone,
                    "text" => $message
                ]
            ]
        ];

        $headers = [
            'Authorization' => 'Key ' . $token,
            'Content-Type' => 'application/json',
        ];

        $this->logger->info('Проверка перед отправкой SMS', [
            'url' => $url,
            'json' => $data
        ])->write();


        $response = $this->post_json($url, $data, $headers);

        $decodedBody = json_decode($response->getBody()->getContents(), true);

        // Логирование заголовков запроса
        $this->logger->info('Headers', [
            'method' => 'POST',
            'url' => $url,
            'headers' => [
                'Authorization' => 'Key MASKED_TOKEN',
                'Content-Type' => 'application/json',
            ],
            'json' => $data
        ])->write();

        // Логирование успешной отправки SMS
        $this->logger->info('Успешно отправлено SMS', [
            'to' => $to,
            'message' => $message,
            'response' => $decodedBody
        ])->write();

        // Вывод или дальнейшая обработка JSON-кода
        return $decodedBody;
    }


    public function sendVk(
        string $to,
        array $msgData,
        string $from = null,
        int $template_id
    ) {
        $this->logger->setLogPath("logs/messages");
        $this->logger->setType("sendVk");
        
        $templateName = $this->getTemplateNameById($template_id);
        $templateData = $this->getTemplateData($template_id);
        $tmplData = [];

        if (empty($templateData)) {
            throw new Exception("Данные шаблона с ID $template_id не найдены.");
        }

        foreach ($templateData as $parameter) {
            $name = $parameter->name;
            if (isset($msgData[$name])) {
                $tmplData[$name] = $msgData[$name];
            }
        }

        // if (!empty($templateData)) {
        //     $name = $templateData[0]->name; // Получаем значение поля name из первого элемента массива
        //     // Теперь у вас есть значение $name, которое содержит "codeauth"
        // } else {
        //     // Обработка ситуации, когда $templateData пустой
        // }

        // echo $name; die(); // Тестирование

        $endpoint = config('sms.devino_online.endpoint');
        $url = $endpoint . '/vk/messages';
        $token = config('sms.devino_online.token');
        $phone = "7" . $to;

        $data = [
            [
                "deliveryPolicy" => "ANY",
                "phone" => $phone,
                "routes" => ["VK"],
                "service" => "devinotelecom_krk_megapolis",
                "tmpl" => $templateName,
                "tmplData" => $tmplData,
                "ttl" => 600
            ]
        ];

        // print_r($data);die();

        $headers = [
            'Authorization' => 'Key ' . $token,
            'Content-Type' => 'application/json',
        ];

        $this->logger->info('Проверка перед отправкой данных', [
            'url' => $url,
            'json' => $data
        ])->write();

        $response = $this->post_json($url, $data, $headers);

        $decodedBody = json_decode($response->getBody()->getContents(), true);

        // Логирование заголовков запроса
        $this->logger->info('Headers', [
            'method' => 'POST',
            'url' => $url,
            'headers' => [
                'Authorization' => 'Key MASKED_TOKEN',
                'Content-Type' => 'application/json',
            ],
            'json' => $data
        ])->write();

        // Логирование успешной отправки
        $this->logger->info('Успешно отправлено сообщение VK', [
            'to' => $to,
            // 'code' => $code,
            'response' => $decodedBody
        ])->write();

        // Запись логов в файл
        // $this->logger->write();

        // Вывод или дальнейшая обработка JSON-кода
        return $decodedBody;
    }

    public function status(string $id): bool
    {
        return false;
    }

    private function exception(Exception $ex)
    {
    }

    public function getTemplateData(int $template_id)
    {
        try {
            // Выполните запрос к вашей базе данных, чтобы получить все параметры шаблона по его ID
            $templateData = DB::table('sms_message_template_parameters')->where('template_id', $template_id)->get();

            if ($templateData->isEmpty()) {
                // Если результат запроса пустой, верните null или выбросьте исключение
                return null; // Или выбросьте исключение с сообщением об ошибке
            }

            // Преобразуем коллекцию в массив
            $templateParameters = $templateData->toArray();

            return $templateParameters;
        } catch (Exception $e) {
            // Обработка ошибок, если произошла ошибка при запросе к базе данных
            // Можно логировать ошибку и выбрасывать исключение, если нужно
            return null; // Или выбросьте исключение с сообщением об ошибке
        }
    }

    public function getTemplateNameById(int $template_id)
    {
        try {
            $template = DB::table('sms_message_templates')->where('id', $template_id)->first();

            if (!$template) {
                return null;
            }
            return $template->name;
        } catch (Exception $e) {
            return null;
        }
    }

}
