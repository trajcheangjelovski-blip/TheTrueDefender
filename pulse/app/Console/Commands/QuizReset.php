<?php

namespace App\Console\Commands;

use App\Models\Setting;
use App\Support\QuizAvailability;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Quiz-only reset: back up, then clear the stored daily quiz and move the browser-state epoch so
 * visitors' saved quiz progress from the old quizzes is discarded. Never regenerates questions
 * and never touches posts, users, subscribers or any other setting. Safe to run repeatedly.
 */
class QuizReset extends Command
{
    protected $signature = 'quiz:reset';

    protected $description = 'Back up and clear the stored daily quiz and invalidate old browser quiz progress. No API calls, no regeneration.';

    public function handle(): int
    {
        $stamp = now()->format('Ymd-His');
        $path = "backups/quiz-reset-{$stamp}.json";
        $quizRow = Setting::where('key', 'daily_quiz')->first();
        $epochBefore = QuizAvailability::epoch();

        Storage::disk('local')->put($path, json_encode([
            'generated_at' => now()->toIso8601String(),
            'daily_quiz' => $quizRow?->value,
            'quiz_epoch_before' => $epochBefore,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $this->info('Backup written: storage/app/private/' . $path);

        $hadQuiz = $quizRow && filled($quizRow->value);
        if ($quizRow) {
            $quizRow->delete();   // the model event also refreshes the settings cache
        }
        // Move the epoch only when there was something to invalidate (or none was ever set), so repeating
        // the command does not keep discarding progress people have made on a new quiz.
        if ($hadQuiz || ! Setting::where('key', 'quiz_epoch')->exists()) {
            Setting::put('quiz_epoch', $epochBefore + 1);
        }

        $this->info($hadQuiz ? 'Stored quiz cleared.' : 'No stored quiz was present.');
        $this->info('Quiz browser-state epoch: ' . QuizAvailability::epoch());

        return self::SUCCESS;
    }
}
