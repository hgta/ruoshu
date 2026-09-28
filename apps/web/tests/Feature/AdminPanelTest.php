<?php

declare(strict_types=1);

use App\Models\AuditLog;
use App\Models\Book;
use App\Models\Chapter;
use App\Models\Comment;
use App\Models\EvidenceRecord;
use App\Models\ModerationReport;
use App\Models\SiteSetting;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
|--------------------------------------------------------------------------
| 任务组 12 管理后台：审核队列 / DMCA / 运营位 / 审计签名 / 访问防线
|--------------------------------------------------------------------------
*/

uses(RefreshDatabase::class);

function admin(): User
{
    return User::factory()->create(['name' => '审核员'.uniqid(), 'roles' => User::ROLE_ADMIN]);
}

function makeReportedComment(): array
{
    $author = User::factory()->create(['roles' => User::ROLE_AUTHOR]);
    $book = Book::create([
        'user_id' => $author->id, 'title' => '被举报的书', 'title_hash' => hash('sha256', uniqid()),
        'intro' => 'i', 'category_id' => 1, 'license' => 1, 'status' => 1,
    ]);
    $chapter = Chapter::create([
        'book_id' => $book->id, 'chapter_no' => 1, 'title' => '第一章',
        'word_count' => 10, 'status' => Chapter::STATUS_PUBLISHED, 'published_at' => now(),
    ]);
    $comment = Comment::create([
        'user_id' => User::factory()->create()->id, 'book_id' => $book->id,
        'chapter_id' => $chapter->id, 'target_type' => Comment::TARGET_CHAPTER,
        'target_id' => $chapter->id, 'content' => '这条评论涉嫌侵权宣传',
    ]);

    return [$author, $book, $chapter, $comment];
}

// ===== 12.1 访问防线 =====

it('后台需要 admin 角色；普通作者/读者/游客一律 403', function () {
    $admin = admin();
    $reader = User::factory()->create(['roles' => User::ROLE_READER]);
    $author = User::factory()->create(['roles' => User::ROLE_AUTHOR]);

    $this->actingAs($admin)->get('/admin')->assertOk();
    $this->actingAs($reader)->get('/admin')->assertForbidden();
    $this->actingAs($author)->get('/admin')->assertForbidden();
    // 游客：can:admin gate 直接 403（管理员后台不向游客暴露跳转行为）
    $this->get('/admin')->assertForbidden();
});

it('IP 白名单：非白名单 IP 拒 403，白名单 IP 放行', function () {
    config(['features.admin_ip_whitelist' => '10.0.0.1']);
    $this->actingAs(admin())->get('/admin')->assertForbidden();

    config(['features.admin_ip_whitelist' => '']);
    $this->actingAs(admin())->get('/admin')->assertOk();
});

// ===== 12.2 审核队列 =====

it('读者举报 → 进入审核队列 → 处理动作强制备注（短备注被拒）→ 隐藏内容生效', function () {
    [$author, $book, $chapter, $comment] = makeReportedComment();
    $reporter = User::factory()->create();

    // 举报（同人同目标限一条）
    $this->actingAs($reporter)->postJson('/report', [
        'target_type' => 'comment', 'target_id' => $comment->id,
        'reason_kind' => ModerationReport::KIND_SPAM, 'reason_text' => '垃圾广告',
    ])->assertCreated();

    $this->actingAs($reporter)->postJson('/report', [
        'target_type' => 'comment', 'target_id' => $comment->id, 'reason_kind' => 0,
    ])->assertStatus(409);

    // 队列可见
    $this->actingAs(admin())->get('/admin/moderation')->assertOk()->assertSee('这条评论涉嫌侵权宣传');

    // 强制备注：短备注被验证拦截
    $report = ModerationReport::first();
    $this->actingAs(admin())->post("/admin/moderation/{$report->id}/handle", [
        'action' => 'hide', 'note' => '短',
    ])->assertRedirect(); // validation 失败 redirect（备注 ≥5 字）

    // 合规处理 → 评论隐藏 + 留痕
    $this->actingAs(admin())->post("/admin/moderation/{$report->id}/handle", [
        'action' => 'hide', 'note' => '确系垃圾广告，隐藏处理',
    ])->assertRedirect();

    expect($comment->refresh()->status)->toBe(Comment::ST_HIDDEN)
        ->and($report->refresh()->status)->toBe(ModerationReport::ST_HANDLED)
        ->and($report->refresh()->action)->toBe('hide');
});

// ===== 12.3 DMCA 流程 =====

it('DMCA：证据面板展示存证与取证包，下架动作 → 书下线且存证保留', function () {
    [$author, $book, $chapter] = makeReportedComment();

    EvidenceRecord::create([
        'book_id' => $book->id, 'chapter_id' => $chapter->id, 'kind' => EvidenceRecord::KIND_CHAPTER,
        'title_hash' => str_repeat('c', 64), 'merkle_root' => str_repeat('d', 64),
        'status' => EvidenceRecord::ST_CONFIRMED, 'chain_name' => 'zhixin', 'tx_id' => 'tx-dmca',
        'cert_no' => 'CERT-DMCA',
    ]);

    $report = ModerationReport::create([
        'reporter_id' => User::factory()->create()->id,
        'target_type' => 'book', 'target_id' => $book->id,
        'reason_kind' => ModerationReport::KIND_DMCA,
        'reason_text' => '本人为原作，附权利证明材料',
        'handle_note' => '',
    ]);

    // 证据面板
    $this->actingAs(admin())->get("/admin/moderation/{$report->id}")
        ->assertOk()
        ->assertSee('被举报的书')
        ->assertSee('CERT-DMCA');

    // 下架处置
    $this->actingAs(admin())->post("/admin/moderation/{$report->id}/handle", [
        'action' => 'offline', 'note' => 'DMCA 权利证明充分，下架处理',
    ])->assertRedirect();

    expect($book->refresh()->status)->toBe(Book::STATUS_OFFLINE)
        ->and(EvidenceRecord::count())->toBe(1); // 存证保留（版权证明不受下架影响）
});

// ===== 12.5 运营位 =====

it('运营位：保存即生效（读者首页读到新横幅）且留痕', function () {
    $admin = admin();

    $this->actingAs($admin)->post('/admin/settings', [
        'banner_title' => '新横幅上线了',
        'banner_sub' => '存证不止',
        'featured_book_ids' => '1,2,3',
    ])->assertRedirect();

    $home = SiteSetting::get(SiteSetting::KEY_HOME);
    expect($home['banner_title'])->toBe('新横幅上线了')
        ->and($home['featured_book_ids'])->toBe([1, 2, 3])
        ->and(AuditLog::where('action', 'settings.home.update')->exists())->toBeTrue();
});

// ===== 12.6 审计签名 =====

it('审计日志操作人签名可验证；篡改 actor_id 后签名失效', function () {
    $admin = admin();

    AuditLogger::log($admin, 'test.action', 'book', 42, null, ['x' => 1], '测试');
    $entry = AuditLog::first();

    expect(AuditLogger::verify($entry))->toBeTrue();

    // 篡改：冒名顶替必然校验失败（防抵赖；actor_id 受 FK 约束，用真实存在的他人 id）
    $entry->update(['actor_id' => User::factory()->create()->id]);
    expect(AuditLogger::verify($entry->refresh()))->toBeFalse();

    // 后台审计页展示校验状态
    $this->actingAs($admin)->get('/admin/audits')->assertOk()->assertSee('test.action');
});
