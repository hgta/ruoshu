<?php

declare(strict_types=1);

namespace App\Http\Controllers\Reading;

use App\Http\Controllers\Controller;
use App\Models\Book;

/**
 * 授权信息页（任务 11.1）：作品页授权徽章 → 本页展示协议全文 + 授权变更历史。
 * 变更历史来自 license_changes（链式 change_hash，作者不可抵赖）。
 */
class LicenseController extends Controller
{
    public function show(Book $book)
    {
        abort_unless(in_array($book->status, [Book::STATUS_ONGOING, Book::STATUS_FINISHED]), 404);

        $full = Book::LICENSE_FULL[$book->license] ?? ['name' => '未知协议', 'text' => ''];

        return view('books.license', [
            'book' => $book,
            'license' => $full,
            'changes' => $book->licenseChanges()->oldest('id')->get(),
        ]);
    }
}
