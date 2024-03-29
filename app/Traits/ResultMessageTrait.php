<?php

namespace App\Traits;

trait ResultMessageTrait
{
    // Выводит финольное сообщение с информацией
    public function getResultMessage(int $score, int $totalQuestions): array
    {
        if ($score <= 2) {
            $result = '🤓 Ученик.';
        } elseif ($score <= 5) {
            $result = '😏 Уверенный юзер.';
        } else {
            $result = '😎 Всевидящее око.';
        }

        $titleMessage = "<strong>Твоё звание: {$result}</strong>\n\n";

        $additionalMessage = "Правильные ответы: {$score}/{$totalQuestions}<strong>\n\n😳 Неожиданные результаты, верно?</strong>\n\nТеперь ты точно убедился, что нейросети - важная часть современного мира и сейчас самое время начать их изучать.\n\n🎁 А чтобы старт был легче, держи бонусные токены для  <a href=\"https://neuro-texter.ru/\">НейроТекстер</a>.\n\nС ними ты сможешь создать курсовую, рекламный пост, стихотворение, картинку и много чего еще.";
        return [
            'title' => $titleMessage,
            'additional' => $additionalMessage,
        ];
    }
}
