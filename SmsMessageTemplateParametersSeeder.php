<?php

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class SmsMessageTemplateParametersSeeder extends Seeder
{
    public function run()
    {
        $parameters = [
            [
                'id' => 1,
                'template_id' => 1,
                'name' => 'codeauth',
                'name_ru' => 'КодАвторизации',
                'data_type' => 'STRING',
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now()
            ],
            [
                'id' => 2,
                'template_id' => 2,
                'name' => 'cinema',
                'name_ru' => 'Кинотеатр',
                'data_type' => 'STRING',
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now()
            ],
            [
                'id' => 3,
                'template_id' => 2,
                'name' => 'order',
                'name_ru' => 'Заказ',
                'data_type' => 'STRING',
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now()
            ],
            [
                'id' => 4,
                'template_id' => 2,
                'name' => 'date',
                'name_ru' => 'Дата',
                'data_type' => 'DATE',
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now()
            ],
            [
                'id' => 5,
                'template_id' => 2,
                'name' => 'time',
                'name_ru' => 'Время',
                'data_type' => 'TIME',
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now()
            ],
            [
                'id' => 6,
                'template_id' => 2,
                'name' => 'hall',
                'name_ru' => 'Зал',
                'data_type' => 'STRING',
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now()
            ],
            [
                'id' => 7,
                'template_id' => 2,
                'name' => 'row',
                'name_ru' => 'Ряд',
                'data_type' => 'STRING',
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now()
            ],
            [
                'id' => 8,
                'template_id' => 2,
                'name' => 'places',
                'name_ru' => 'Места',
                'data_type' => 'STRING',
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now()
            ],
            [
                'id' => 9,
                'template_id' => 3,
                'name' => 'order',
                'name_ru' => 'Заказ',
                'data_type' => 'STRING',
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now()
            ],
            [
                'id' => 10,
                'template_id' => 4,
                'name' => 'film_name',
                'name_ru' => 'Название Фильма',
                'data_type' => 'STRING',
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now()
            ],
            [
                'id' => 11,
                'template_id' => 4,
                'name' => 'cinema',
                'name_ru' => 'Кинотеатр',
                'data_type' => 'STRING',
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now()
            ],
            [
                'id' => 12,
                'template_id' => 4,
                'name' => 'order',
                'name_ru' => 'Заказ',
                'data_type' => 'STRING',
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now()
            ]
        ];

        foreach ($parameters as $param) {
            // Проверяем существует ли уже такой параметр
            $exists = DB::table('sms_message_template_parameters')->where('template_id', $param['template_id'])
                                                                ->where('name', $param['name'])
                                                                ->exists();
            if (!$exists) {
                DB::table('sms_message_template_parameters')->insert($param);
            }
        }
    }
}
