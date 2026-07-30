<?php

namespace Database\Seeders;

use App\Models\Poll;
use App\Models\PollOption;
use Illuminate\Database\Seeder;

class PollSeeder extends Seeder
{
    /**
     * Set the current live reader poll. Idempotent: deactivates any other polls,
     * upserts this question, and resets its options (votes start at 0 for the
     * new question). Safe to run in dev and on production.
     */
    public function run(): void
    {
        $question = 'What issue should be America’s top priority right now?';

        $options = [
            'Secure the border',
            'Fix the economy',
            'Protect our freedoms',
            'End foreign wars',
        ];

        // Only one active poll at a time.
        Poll::where('question', '!=', $question)->update(['is_active' => false]);

        $poll = Poll::updateOrCreate(
            ['question' => $question],
            ['is_active' => true],
        );

        // Replace options so labels/order match exactly (fresh question → fresh count).
        $poll->options()->delete();
        foreach ($options as $i => $label) {
            PollOption::create([
                'poll_id' => $poll->id,
                'label' => $label,
                'votes' => 0,
                'sort_order' => $i + 1,
            ]);
        }
    }
}
