<!DOCTYPE html>
<html lang="en">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"></head>
<body style="margin:0;background:#0b0d16;font-family:Arial,Helvetica,sans-serif;color:#eef0f8">
  <div style="max-width:600px;margin:0 auto;padding:24px">
    <div style="text-align:center;padding:8px 0 20px">
      <span style="display:inline-block;background:linear-gradient(135deg,#e33b4e,#8b1e2c);color:#fff;font-weight:800;font-size:18px;padding:10px 16px;border-radius:10px">The True Defender</span>
      <p style="color:#9aa0b5;font-size:13px;margin:12px 0 0">Today's top American headlines</p>
    </div>

    @foreach($posts as $post)
      <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#141726;border:1px solid rgba(255,255,255,.08);border-radius:12px;margin-bottom:14px">
        <tr>
          <td style="padding:18px">
            @if($url = $post->imageUrl('card'))
              <a href="{{ route('post.show', $post) }}"><img src="{{ $url }}" alt="" width="100%" style="border-radius:8px;display:block;margin-bottom:12px;max-width:100%"></a>
            @endif
            <a href="{{ route('post.show', $post) }}" style="color:#fff;text-decoration:none;font-size:18px;font-weight:700;line-height:1.3">{{ $post->title }}</a>
            <p style="color:#c3c8da;font-size:14px;line-height:1.5;margin:8px 0 14px">{{ \Illuminate\Support\Str::limit(strip_tags($post->excerpt), 160) }}</p>
            <a href="{{ route('post.show', $post) }}" style="display:inline-block;background:linear-gradient(135deg,#e33b4e,#b02638);color:#fff;text-decoration:none;font-weight:700;font-size:13px;padding:9px 18px;border-radius:8px">Read the story →</a>
          </td>
        </tr>
      </table>
    @endforeach

    <div style="text-align:center;padding:20px 0">
      <a href="{{ url('/') }}" style="color:#ff6b7d;text-decoration:none;font-weight:700">Visit TheTrueDefender →</a>
    </div>

    <p style="color:#6b7280;font-size:12px;text-align:center;line-height:1.6;margin-top:16px">
      You're receiving this because you subscribed at thetruedefender.news.<br>
      <a href="{{ $unsubscribeUrl }}" style="color:#9aa0b5">Unsubscribe</a> &nbsp;·&nbsp; © {{ date('Y') }} TheTrueDefender
    </p>
  </div>
</body>
</html>
