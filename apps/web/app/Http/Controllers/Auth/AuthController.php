<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Auth\WechatService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

class AuthController extends Controller
{
    public function __construct(private readonly WechatService $wechat) {}

    /** 登录页：主推微信扫码，密码登录为辅 */
    public function showLogin(Request $request)
    {
        return view('auth.login', [
            'wechatReady' => $this->wechat->isConfigured(),
            'wechatUrl' => $this->wechat->isConfigured()
                ? $this->wechat->qrConnectUrl(Str::random(16))
                : null,
            'intended' => $request->input('intended', '/'),
        ]);
    }

    /** 微信回调：登录/注册一体，体验红线：注册→回到阅读 ≤15s */
    public function wechatCallback(Request $request): RedirectResponse
    {
        $request->validate(['code' => 'required|string']);

        try {
            $user = $this->wechat->loginByCode($request->string('code'));
        } catch (\Throwable $e) {
            return redirect('/login')->with('error', '微信登录失败：'.$e->getMessage());
        }

        Auth::login($user, remember: true);

        return redirect($request->session()->pull('url.intended', '/'));
    }

    /** 密码注册（注册开关控制） */
    public function register(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:16', 'unique:users,name'],
            'email' => ['required', 'email', 'max:191', 'unique:users'],
            'password' => ['required', Password::min(8)],
        ]);

        $user = User::create([
            ...$validated,
            'password' => Hash::make($validated['password']),
        ]);
        $user->readerProfile()->create();

        Auth::login($user);

        return redirect($request->session()->pull('url.intended', '/'));
    }

    /** 密码登录 */
    public function login(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required'],
        ]);

        if (Auth::attempt($credentials, $request->boolean('remember'))) {
            $request->session()->regenerate();

            return redirect($request->session()->pull('url.intended', '/'));
        }

        return back()->withErrors(['email' => '邮箱或密码错误']);
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
    }
}
