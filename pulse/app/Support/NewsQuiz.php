<?php

namespace App\Support;

use App\Models\Setting;

class NewsQuiz
{
    public static function current(): ?array
    {
        $quiz = json_decode((string) Setting::get('daily_quiz'), true);
        if (! is_array($quiz) || ($quiz['date'] ?? null) !== now()->toDateString()
            || ! is_array($quiz['questions'] ?? null) || ! count($quiz['questions'])) {
            return null;
        }
        foreach ($quiz['questions'] as $question) {
            if (! is_array($question) || ! is_string($question['question'] ?? null)
                || ! is_array($question['options'] ?? null) || count($question['options']) !== 4
                || ! is_int($question['answer'] ?? null) || $question['answer'] < 0 || $question['answer'] > 3
                || count(array_filter($question['options'], fn ($o) => is_string($o) && trim($o) !== '')) !== 4) {
                return null;
            }
        }
        return $quiz;
    }
}
