<?php

namespace App\Http\Controllers;

use App\Models\Setting;

class QuizController extends Controller
{
    public function show()
    {
        $quiz = \App\Support\NewsQuiz::current();
        abort_if($quiz === null, 404, 'No current quiz available.');

        return view('quiz', ['quiz' => $quiz]);
    }
}
