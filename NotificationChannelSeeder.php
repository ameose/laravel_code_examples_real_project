<?php

use Illuminate\Database\Seeder;
use App\Models\NotificationChannel;

class NotificationChannelSeeder extends Seeder
{
    public function run()
    {
        // Добавляем новую запись только если записи с указанным id не существует
        if (!NotificationChannel::where('id', 3)->exists()) {
            NotificationChannel::create([
                'id' => 3,
                'slug' => 'vk',
                'name' => 'Vk',
            ]);
        }
        
        // Добавляем записи с id 1 и 2, если их еще нет
        $channelsToAdd = [
            ['id' => 1, 'slug' => 'push', 'name' => 'Push'],
            ['id' => 2, 'slug' => 'sms', 'name' => 'Sms'],
        ];
        
        foreach ($channelsToAdd as $data) {
            if (!NotificationChannel::where('id', $data['id'])->exists()) {
                NotificationChannel::create($data);
            }
        }
    }
}
