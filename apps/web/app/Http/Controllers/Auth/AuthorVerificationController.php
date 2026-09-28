<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\AuthorProfile;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * 作者实名认证：哈希存储 + 脱敏展示（隐私最小化原则）。
 * 明文姓名/身份证号绝不落库，只存 SHA-256(值 + 服务端盐) 与掩码。
 */
class AuthorVerificationController extends Controller
{
    public function show(Request $request)
    {
        $profile = $request->user()->authorProfile;

        return view('author.verify', ['profile' => $profile]);
    }

    public function submit(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'pen_name' => ['required', 'string', 'max:32'],
            'real_name' => ['required', 'string', 'max:32'],
            'id_number' => ['required', 'string', 'max:18', 'regex:/^\d{17}[\dXx]$/'],
        ]);

        $user = $request->user();
        $salt = config('app.key');

        $data = [
            'user_id' => $user->id,
            'pen_name' => $validated['pen_name'],
            'real_name_hash' => hash('sha256', $validated['real_name'].$salt),
            'real_name_masked' => $this->mask($validated['real_name']),
            'id_number_hash' => hash('sha256', strtoupper($validated['id_number']).$salt),
            'verify_status' => AuthorProfile::VERIFY_PENDING,
        ];

        $user->authorProfile()->updateOrCreate(['user_id' => $user->id], $data);
        $user->grantRole(User::ROLE_AUTHOR);

        return redirect('/author/dashboard')->with('status', '实名信息已提交，审核通过后即可发布付费作品');
    }

    private function mask(string $name): string
    {
        $len = mb_strlen($name);
        if ($len <= 1) {
            return $name;
        }
        if ($len === 2) {
            return mb_substr($name, 0, 1).'*';
        }

        return mb_substr($name, 0, 1).str_repeat('*', $len - 2).mb_substr($name, -1);
    }
}
