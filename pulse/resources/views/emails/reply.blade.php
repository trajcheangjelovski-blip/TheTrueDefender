<!DOCTYPE html>
<html lang="en">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"></head>
<body style="margin:0;background:#0b0d16;font-family:Arial,Helvetica,sans-serif;color:#eef0f8">
  <div style="max-width:560px;margin:0 auto;padding:24px">
    <div style="text-align:center;padding:8px 0 18px">
      <span style="display:inline-block;background:linear-gradient(135deg,#e33b4e,#8b1e2c);color:#fff;font-weight:800;font-size:16px;padding:9px 15px;border-radius:10px">The True Defender</span>
    </div>

    <p style="font-size:15px">Hi {{ $parent->name }},</p>
    <p style="font-size:15px;line-height:1.5">Someone replied to your comment on <strong>{{ $postTitle }}</strong>.</p>

    <div style="background:#141726;border:1px solid rgba(255,255,255,.08);border-radius:10px;padding:14px 16px;margin:16px 0">
      <p style="color:#9aa0b5;font-size:12px;margin:0 0 6px">{{ $reply->name }} {{ $reply->surname }} wrote:</p>
      <p style="font-size:15px;line-height:1.5;margin:0">{{ \Illuminate\Support\Str::limit($reply->body, 300) }}</p>
    </div>

    <p style="text-align:center;margin:22px 0">
      <a href="{{ $postUrl }}" style="display:inline-block;background:linear-gradient(135deg,#e33b4e,#b02638);color:#fff;text-decoration:none;font-weight:700;font-size:14px;padding:11px 22px;border-radius:9px">View the reply →</a>
    </p>

    <p style="color:#6b7280;font-size:12px;text-align:center;line-height:1.6;margin-top:16px">
      You're receiving this because you commented on TheTrueDefender.<br>© {{ date('Y') }} TheTrueDefender
    </p>
  </div>
</body>
</html>
