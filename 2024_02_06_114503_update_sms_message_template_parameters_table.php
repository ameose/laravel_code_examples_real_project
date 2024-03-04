<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class UpdateSmsMessageTemplateParametersTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {

        DB::table('sms_message_template_parameters')
            ->where('id', 7)
            ->update([
                'name' => 'row_and_places',
                'name_ru' => 'Ряд и Места',
            ]);

        DB::table('sms_message_template_parameters')
            ->where('id', 8)
            ->update([
                'name' => 'url',
                'name_ru' => 'Ссылка',
            ]);
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {

        DB::table('sms_message_template_parameters')
            ->where('id', 7)
            ->update([
                'name' => 'row',
                'name_ru' => 'Ряд',
            ]);

        DB::table('sms_message_template_parameters')
            ->where('id', 8)
            ->update([
                'name' => 'places',
                'name_ru' => 'Места',
            ]);
    }
}
