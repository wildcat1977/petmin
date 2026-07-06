<!DOCTYPE html>
<html lang="zh-Hant">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>回覆您的聯絡訊息</title>
<style>
  body { margin: 0; padding: 0; background: #f1f5f9; font-family: -apple-system, BlinkMacSystemFont, 'Noto Sans TC', sans-serif; }
  .wrapper { max-width: 560px; margin: 32px auto; background: #ffffff; border-radius: 12px; overflow: hidden; box-shadow: 0 2px 12px rgba(0,0,0,0.08); }
  .header { background: #dc2626; padding: 28px 32px; }
  .header h1 { margin: 0; color: #fff; font-size: 20px; font-weight: 700; letter-spacing: 0.01em; }
  .header p { margin: 4px 0 0; color: rgba(255,255,255,0.75); font-size: 13px; }
  .body { padding: 28px 32px; }
  .greeting { font-size: 15px; color: #1e293b; margin: 0 0 16px; }
  .original { background: #f8fafc; border-left: 3px solid #e2e8f0; border-radius: 4px; padding: 12px 16px; margin: 0 0 20px; }
  .original .label { font-size: 11px; color: #94a3b8; text-transform: uppercase; letter-spacing: 0.06em; margin: 0 0 4px; }
  .original p { margin: 0; font-size: 13px; color: #64748b; line-height: 1.6; }
  .reply-box { font-size: 15px; color: #1e293b; line-height: 1.75; white-space: pre-wrap; }
  .footer { padding: 20px 32px; border-top: 1px solid #f1f5f9; }
  .footer p { margin: 0; font-size: 12px; color: #94a3b8; line-height: 1.6; }
  .footer a { color: #dc2626; text-decoration: none; }
</style>
</head>
<body>
<div class="wrapper">
  <div class="header">
    <h1>Hazdar 災情回報</h1>
    <p>管理員回覆您的聯絡訊息</p>
  </div>
  <div class="body">
    <p class="greeting">{{ $contact->name }} 您好，</p>

    <div class="original">
      <p class="label">您的來信主旨</p>
      <p>{{ $contact->subject }}</p>
    </div>

    <div class="reply-box">{{ $contact->reply }}</div>
  </div>
  <div class="footer">
    <p>
      此郵件由 <a href="{{ config('app.url') }}">Hazdar 災情回報</a> 管理員寄出，請勿直接回覆此郵件。<br>
      如有其他問題，請透過網站上的「聯絡管理員」表單再次聯繫。
    </p>
  </div>
</div>
</body>
</html>
