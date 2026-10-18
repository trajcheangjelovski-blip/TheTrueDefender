<?php

namespace App\Http\Controllers;

use App\Support\QuizAvailability;

class QuizController extends Controller
{
    public function show()
    {
        // null when there is no valid quiz today; the view then shows a plain "not available" message.
        return view('quiz', ['quiz' => QuizAvailability::current()]);
    }
}
