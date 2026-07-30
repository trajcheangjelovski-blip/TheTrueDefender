<?php

namespace App\Http\Controllers;

use App\Models\Setting;

class QuizController extends Controller
{
    public function show()
    {
        $quiz = json_decode((string) Setting::get('daily_quiz'), true);
        abort_if(empty($quiz['questions']), 404, 'No quiz available yet.');

        return view('quiz', ['quiz' => $quiz]);
    }
}
