<?php
namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Question;
use App\Models\Answer;

class QuizTableSeeder extends Seeder
{
    public function run()
    {
        $data = [
            [
                'text' => 'Какой тег отвечает за жирное начертание текста в HTML?',
                'answers' => [
                    ['text' => '<b>', 'is_correct' => true],
                    ['text' => '<i>', 'is_correct' => false],
                    ['text' => '<strong>', 'is_correct' => true],
                    ['text' => '<em>', 'is_correct' => false]
                ],
            ],
            [
                'text' => 'Что означает аббревиатура CSS?',
                'answers' => [
                    ['text' => 'Cascading Style Sheets', 'is_correct' => true],
                    ['text' => 'Computer Style Sheets', 'is_correct' => false],
                    ['text' => 'Creative Style Sheets', 'is_correct' => false],
                    ['text' => 'Colorful Style Sheets', 'is_correct' => false]
                ],
            ],
            [
                'text' => 'Какой из этих фреймворков не относится к PHP?',
                'answers' => [
                    ['text' => 'Symfony', 'is_correct' => false],
                    ['text' => 'Laravel', 'is_correct' => false],
                    ['text' => 'Django', 'is_correct' => true],
                    ['text' => 'Yii', 'is_correct' => false]
                ],
            ],
            [
                'text' => 'Какой HTTP-метод используется для создания новой записи?',
                'answers' => [
                    ['text' => 'GET', 'is_correct' => false],
                    ['text' => 'POST', 'is_correct' => true],
                    ['text' => 'PUT', 'is_correct' => false],
                    ['text' => 'DELETE', 'is_correct' => false]
                ],
            ],
        ];

        foreach ($data as $item) {
            $question = Question::create(['text' => $item['text']]);
            foreach ($item['answers'] as $answer) {
                Answer::create([
                    'question_id' => $question->id,
                    'text' => $answer['text'],
                    'is_correct' => $answer['is_correct'],
                ]);
            }
        }
    }
}