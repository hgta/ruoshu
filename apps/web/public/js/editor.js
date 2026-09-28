// 若书章节编辑器：10s 自动保存草稿 + 发布/修订
(() => {
    const box = document.querySelector('.editor');
    if (!box) return;

    const bookId = box.dataset.book;
    let chapterId = box.dataset.chapter || null;
    const title = document.getElementById('ch-title');
    const content = document.getElementById('ch-content');
    const status = document.getElementById('save-status');
    const csrf = document.querySelector('meta[name=csrf-token]').content;
    let timer;

    async function autosave() {
        if (!title.value.trim() || !content.value.trim()) return;
        try {
            const res = await fetch(`/author/books/${bookId}/chapters/autosave`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf },
                body: JSON.stringify({
                    chapter_id: chapterId ? Number(chapterId) : null,
                    title: title.value,
                    content: content.value,
                }),
            });
            const data = await res.json();
            if (data.chapter_id) {
                chapterId = String(data.chapter_id);
                box.dataset.chapter = chapterId;
                status.textContent = `草稿已保存 ${new Date().toLocaleTimeString()}`;
            }
        } catch {
            status.textContent = '保存失败，将在下次重试';
        }
    }

    content.addEventListener('input', () => {
        clearTimeout(timer);
        timer = setTimeout(autosave, 10000); // 10s 防抖
    });

    async function publish(revise = false) {
        await autosave(); // 先落草稿
        if (!chapterId) { alert('请先输入正文再发布'); return; }
        const url = revise
            ? `/author/books/${bookId}/chapters/${chapterId}/revise`
            : `/author/books/${bookId}/chapters/${chapterId}/publish`;
        const body = revise ? {} : {
            is_paid: document.getElementById('ch-paid').checked,
            price: Number(document.getElementById('ch-price').value),
            author_note: document.getElementById('ch-note').value,
        };
        const res = await fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
            body: JSON.stringify(body),
        });
        if (res.redirected) { location.href = res.url; return; }
        const data = await res.json();
        alert(data.message || '已提交');
    }

    document.getElementById('btn-publish')?.addEventListener('click', () => publish(false));
    document.getElementById('btn-revise')?.addEventListener('click', () => publish(true));
})();