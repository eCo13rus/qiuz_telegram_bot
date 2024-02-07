<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Question;
use App\Models\Answer;
use App\Models\Picture;
use Illuminate\Support\Facades\DB;

class QuizTableSeeder extends Seeder
{
    public function run()
    {
        DB::statement('SET FOREIGN_KEY_CHECKS=0;');
        DB::table('questions')->delete();
        DB::table('answers')->delete();
        DB::statement('SET FOREIGN_KEY_CHECKS=1;');

        $data = [
            [
                'text' => 'Уберите лишнее:',
                
                'answers' => [
                    ['text' => 'ChatGPT', 'is_correct' => false],
                    ['text' => 'YandexGPT', 'is_correct' => false],
                    ['text' => 'Kadinsky', 'is_correct' => true],
                    ['text' => 'GigaChat', 'is_correct' => false],
                    ['text' => 'НейроТекстер', 'is_correct' => false],
                    ['text' => 'Claude', 'is_correct' => false],
                ],
                'explanation' => 'Основной функцией всех перечисленных нейросетей является генерация текста. Исключение - Kadinsky, предназначенный для создания картинок.'
            ],
            [
                'text' => 'Какая картинка создана в нейросети?',
                'pictures' => [

                    ['path' => 'questions/photo1.png'],
                    ['path' => 'questions/photo7.png'],

                ],
                'answers' => [
                    ['text' => 'Левая', 'is_correct' => true],
                    ['text' => 'Правая', 'is_correct' => false],
                    ['text' => 'Обе', 'is_correct' => false],
                    ['text' => 'Никакая', 'is_correct' => false],
                ],
            ],
            [
                'text' => 'Какой стих создан нейросетью?
                
Первый: 
                
Опять весна. Опять цветут сады.
Но мне до них какое дело?
В душе моей все чувства черствы,
А сердце холодом обледенело.

Второй:
                
Весна идёт - ручьи журчат,
В снегу проталинки блестят.
Природа ожила, цветёт,
Весна в душе моей поёт.',
                'answers' => [
                    ['text' => 'Первый', 'is_correct' => false],
                    ['text' => 'Второй', 'is_correct' => false],
                    ['text' => 'Оба', 'is_correct' => true],
                    ['text' => 'Никакой', 'is_correct' => false]
                ],
            ],
            [
                'text' => 'Какую нейросеть создал Илон Маск?',
                'pictures' => [
                    ['path' => 'questions/photo3.png'],
                ],
                'answers' => [
                    ['text' => 'Perplexity', 'is_correct' => false],
                    ['text' => 'DALL-E', 'is_correct' => false],
                    ['text' => 'Grok', 'is_correct' => true],
                    ['text' => 'Gemini', 'is_correct' => false],
                    ['text' => 'Bing', 'is_correct' => false],
                ],
                'explanation' => 'Илон Маск представил нейросеть Grok в 2023 году. Ее особенностью является «едкий» стиль общения и прямой доступ к соцсети X.',

            ],
            [
                'text' => 'Когда была создана первая нейросеть?',
                'answers' => [
                    ['text' => '1920 год', 'is_correct' => false],
                    ['text' => '1943 год', 'is_correct' => true],
                    ['text' => '1988 год', 'is_correct' => false],
                ],
                'explanation' => 'В 1943 году Американские ученые Уоррен Мак-Каллок и Уолтер Питтс представили миру модель под названием «логический нейрон». С помощью математики она имитировала функционирование нейронов в головном мозге.'
            ],
            [
                'text' => 'Какой фильм мы превратили в Японскую гравюру?',
                'pictures' => [
                    ['path' => 'questions/photo2.png'],
                ],
                'answers' => [
                    ['text' => 'Броненосец «Потёмкин»', 'is_correct' => false],
                    ['text' => 'Титаник', 'is_correct' => true],
                    ['text' => 'Бриллиантовая рука', 'is_correct' => false],
                    ['text' => 'Пираты Карибского моря', 'is_correct' => false],
                ],
                'explanation' => 'В 1943 году Американские ученые Уоррен Мак-Каллок и Уолтер Питтс представили миру модель под названием «логический нейрон». С помощью математики она имитировала функционирование нейронов в головном мозге.'
            ],
        ];

        foreach ($data as $item) {
            // Создание вопроса с дополнительным ключом explanation
            $question = Question::create([
                'text' => $item['text'],
                'explanation' => $item['explanation'] ?? null, // Используем оператор объединения с null для случаев, когда объяснение отсутствует
            ]);

            // Создание ответов для вопроса
            foreach ($item['answers'] as $answer) {
                Answer::create([
                    'question_id' => $question->id,
                    'text' => $answer['text'],
                    'is_correct' => $answer['is_correct'],
                ]);
            }

            // Создание связанных картинок для вопроса
            if (isset($item['pictures'])) {
                foreach ($item['pictures'] as $picture) {
                    if (isset($picture['path'])) { // Добавляем проверку на наличие ключа 'path'
                        Picture::create([
                            'question_id' => $question->id,
                            'path' => $picture['path'],
                        ]);
                    }
                }
            }
        }
    }
}
