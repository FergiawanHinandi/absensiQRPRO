<!DOCTYPE html>
<html>
<head>
    <title>Reset Akses Admin Sekolah</title>
</head>
<body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333;">
    <div style="max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #eee; border-radius: 10px;">
        <div style="text-align: center; margin-bottom: 20px;">
            <h2 style="color: #2563EB;">AbsensiQR Pro</h2>
        </div>
        
        <p>Halo <strong>{{ $user->name }}</strong>,</p>
        
        <p>Password akun Admin Sekolah Anda telah direset oleh Super Administrator.</p>
        
        <div style="background-color: #F3F4F6; padding: 15px; border-radius: 5px; margin: 20px 0;">
            <p style="margin: 0;"><strong>Sekolah:</strong> {{ $user->school->name ?? '-' }}</p>
            <p style="margin: 0;"><strong>Username:</strong> {{ $user->username }}</p>
            <p style="margin: 0;"><strong>Email:</strong> {{ $user->email }}</p>
            <p style="margin: 10px 0 0 0; font-size: 18px; color: #2563EB;">
                <strong>Password Baru: {{ $newPassword }}</strong>
            </p>
        </div>
        
        <p>Silakan login menggunakan password baru tersebut dan segera ganti password Anda setelah berhasil login demi keamanan.</p>
        
        <div style="text-align: center; margin-top: 30px;">
            <a href="{{ config('app.frontend_url', 'http://localhost:5173') }}/login" style="background-color: #2563EB; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px; display: inline-block;">Login Sekarang</a>
        </div>
        
        <p style="margin-top: 30px; font-size: 12px; color: #666;">
            Jika Anda mengalami kendala saat login, silakan hubungi tim support kami.
        </p>
        
        <hr style="border: none; border-top: 1px solid #eee; margin: 20px 0;">
        
        <p style="font-size: 12px; color: #999; text-align: center;">
            &copy; {{ now()->year }} AbsensiQR Pro. All rights reserved.
        </p>
    </div>
</body>
</html>
