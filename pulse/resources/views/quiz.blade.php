@extends('layouts.app')
@section('title', 'Daily News Quiz — TheTrueDefender')
@section('meta_description', 'Test yourself on this week\'s American news with our daily quiz.')

@section('content')
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

    <p style="margin-top:26px;color:var(--text-dim);font-size:.9rem">A fresh quiz is published every day — come back tomorrow to keep your streak going. Prefer the headlines? <a href="{{ route('home') }}" style="color:var(--accent-2)">Back to the front page →</a></p>
  </main>

  <script>
    (function () {
      var form = document.getElementById('quizForm');
      if (!form) return;
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
        var score = document.getElementById('quizScore');
        score.textContent = 'You scored ' + correct + ' / ' + qs.length;
        score.hidden = false;
        score.scrollIntoView({ behavior: 'smooth', block: 'center' });
      });
    })();
  </script>
@endsection
