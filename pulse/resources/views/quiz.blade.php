@extends('layouts.app')
@section('title', 'Daily News Quiz — TheTrueDefender')
@section('meta_description', 'Test yourself on this week\'s American news with our daily quiz.')

@section('content')
  @if(! $quiz)
    @push('head')<meta name="robots" content="noindex, follow" />@endpush
    <main class="page-main" style="max-width:760px">
      <div class="section-head"><h2><span class="head-accent">🧠</span> News Quiz</h2><div class="head-line"></div></div>
      <p class="page-sub">A new quiz will be available after we publish more stories.</p>
      <p style="margin-top:22px"><a href="{{ route('home') }}" style="color:var(--accent-2)">Back to the front page →</a></p>
    </main>
  @else
  <main class="page-main" style="max-width:760px">
    <div class="section-head"><h2><span class="head-accent">🧠</span> Daily News Quiz</h2><div class="head-line"></div></div>
    <p class="page-sub">How closely have you been following the news? Answer all {{ count($quiz['questions']) }} and see your score.</p>

    <form id="quizForm" class="quiz">
      @foreach($quiz['questions'] as $qi => $q)
        <div class="quiz-q" data-answer="{{ (int) $q['answer'] }}" data-explain="{{ $q['explain'] ?? '' }}">
          <h3>{{ $qi + 1 }}. {{ $q['question'] }}</h3>
          <div class="quiz-options">
            @foreach($q['options'] as $oi => $opt)
              <label class="quiz-option">
                <input type="radio" name="q{{ $qi }}" value="{{ $oi }}" />
                <span>{{ $opt }}</span>
              </label>
            @endforeach
          </div>
          <p class="quiz-explain" hidden></p>
        </div>
      @endforeach

      <div class="quiz-actions">
        <button type="submit" class="btn-checkout" style="max-width:260px">See my score</button>
        <span id="quizScore" class="quiz-score" hidden></span>
      </div>
    </form>

    {{-- Completion / streak panel (built after scoring) --}}
    <div class="quiz-result" id="quizResult" hidden>
      <div class="quiz-result-streak" id="quizStreak"></div>
      <p class="quiz-result-score" id="quizResultScore"></p>
      <p class="quiz-result-msg" id="quizResultMsg">Check back for the next quiz.</p>
      <div class="quiz-result-cta">
        <button type="button" class="quiz-remind" id="quizRemind">🔔 Notify me about the next quiz</button>
        <form class="quiz-email" data-subscribe data-source="quiz_completion" data-cta="newsletter" data-cta-location="quiz_completion">
          <input type="email" name="email" placeholder="your@email.com" required aria-label="Email address" />
          <button type="submit">Email me the next quiz</button>
        </form>
      </div>
      <span class="mb-trust">Only if you choose this. We'll tell you when a new quiz is available. No spam.</span>
    </div>

    <p style="margin-top:26px;color:var(--text-dim);font-size:.9rem">Prefer the headlines? <a href="{{ route('home') }}" style="color:var(--accent-2)">Back to the front page →</a></p>
  </main>

  <script>
    (function () {
      var form = document.getElementById('quizForm');
      if (!form) return;
      var started = false;
      form.addEventListener('change', function () {
        if (!started) { started = true; window.ttd && window.ttd.track('quiz_started'); }
      });

      function milestone(streak) {
        if (streak >= 30) return '🏆 ' + streak + '-day streak — you’re a news devotee.';
        if (streak >= 7) return '🔥 ' + streak + '-day streak — a full week strong!';
        if (streak >= 3) return '🔥 ' + streak + '-day streak — keep it rolling.';
        if (streak === 1) return '🔥 1-day streak started!';
        return '🔥 ' + streak + '-day streak';
      }

      form.addEventListener('submit', function (e) {
        e.preventDefault();
        var qs = form.querySelectorAll('.quiz-q'), correct = 0;
        qs.forEach(function (q, i) {
          var answer = parseInt(q.dataset.answer, 10);
          var picked = form.querySelector('input[name="q' + i + '"]:checked');
          q.querySelectorAll('.quiz-option').forEach(function (opt, oi) {
            opt.classList.remove('right', 'wrong');
            if (oi === answer) opt.classList.add('right');
            else if (picked && parseInt(picked.value, 10) === oi) opt.classList.add('wrong');
          });
          if (picked && parseInt(picked.value, 10) === answer) correct++;
          var ex = q.querySelector('.quiz-explain');
          if (ex && q.dataset.explain) { ex.textContent = '➜ ' + q.dataset.explain; ex.hidden = false; }
        });
        var total = qs.length;
        var score = document.getElementById('quizScore');
        score.textContent = 'You scored ' + correct + ' / ' + total;
        score.hidden = false;

        // Record the streak (anti-refresh: same day won't inflate it).
        var st = window.ttd ? window.ttd.quiz.record(correct, total) : { streak: 1, already: false };
        var panel = document.getElementById('quizResult');
        document.getElementById('quizStreak').textContent = milestone(st.streak || 1);
        document.getElementById('quizResultScore').textContent = 'You scored ' + correct + ' / ' + total;
        document.getElementById('quizResultMsg').textContent = st.already
          ? 'You’ve already played today. Check back for the next quiz.'
          : 'Check back for the next quiz.';
        panel.hidden = false;
        panel.scrollIntoView({ behavior: 'smooth', block: 'center' });
      });

      var remind = document.getElementById('quizRemind');
      if (remind) remind.addEventListener('click', async function () {
        window.ttd && window.ttd.track('quiz_reminder_click');
        remind.textContent = 'Enabling…';
        var ok = window.dpEnablePush ? await window.dpEnablePush() : false;
        remind.textContent = ok ? '✓ You’ll be reminded' : 'Couldn’t enable — try the email option';
      });
    })();
  </script>
  @endif
@endsection
