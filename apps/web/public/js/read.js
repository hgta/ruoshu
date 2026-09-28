// 阅读页交互：书架收藏 + 进度上报 + 弹幕（WS 实时 / 5s 轮询降级 / 乐观上屏）
(() => {
    const page = document.querySelector('.read-page');
    if (!page) return;
    const csrf = document.querySelector('meta[name=csrf-token]').content;
    const userId = page.dataset.user;
    const chapterId = Number(page.dataset.chapter);

    // ===== 收藏 =====
    document.getElementById('btn-shelf')?.addEventListener('click', async (e) => {
        if (!userId) { location.href = '/login'; return; }
        const res = await fetch('/me/bookshelf/toggle', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf },
            body: JSON.stringify({ book_id: Number(page.dataset.book) }),
        });
        const data = await res.json();
        e.target.textContent = data.in_shelf ? '已收藏' : '收藏';
    });

    // ===== 进度上报 =====
    let paraNo = 0;
    const observer = new IntersectionObserver((entries) => {
        for (const en of entries) {
            if (en.isIntersecting) paraNo = Number(en.target.dataset.para);
        }
    });
    document.querySelectorAll('.para').forEach((p) => observer.observe(p));

    setInterval(() => {
        if (!userId || !paraNo) return;
        fetch('/me/progress', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf },
            body: JSON.stringify({ chapter_id: chapterId, para_no: paraNo, offset: 0 }),
            keepalive: true,
        }).catch(() => {});
    }, 30000);

    // ===== 弹幕渲染（漂浮）=====
    const layer = document.getElementById('danmu-layer');
    const statusEl = document.getElementById('danmu-status');
    const seenIds = new Set();
    let track = 0;

    function renderDanmu(d) {
        if (seenIds.has(d.id)) return;
        seenIds.add(d.id);
        const el = document.createElement('div');
        el.className = 'danmu-item';
        el.textContent = d.content;
        el.style.top = `${(track++ % 6) * 26 + 8}px`;
        layer.appendChild(el);
        setTimeout(() => el.remove(), 8000);
    }

    function setStatus(text) { if (statusEl) statusEl.textContent = text; }

    // 打赏特效（任务 10.6）
    function renderDonation(d) {
        const banner = document.createElement('div');
        banner.className = 'donation-flash';
        banner.textContent = `感谢 ${d.user} ${d.gift ? '送出' + d.gift : '打赏'} ¥${(d.amount / 100).toFixed(2)}`;
        layer.appendChild(banner);
        setTimeout(() => banner.remove(), 4000);
        const el = document.createElement('div');
        el.className = 'danmu-item donation';
        el.textContent = `🎁 ${d.user} 打赏了 ¥${(d.amount / 100).toFixed(2)}`;
        el.style.top = `${(track++ % 6) * 26 + 8}px`;
        layer.appendChild(el);
        setTimeout(() => el.remove(), 8000);
    }

    // ===== 轮询降级（任务 9.4：WS 断开 5s 轮询）=====
    let polling = null;
    let lastId = 0;

    async function pollOnce() {
        try {
            const res = await fetch(`/danmu?chapter_id=${chapterId}&after_id=${lastId}`, { headers: { 'Accept': 'application/json' } });
            const data = await res.json();
            (data.danmu || []).forEach((d) => { renderDanmu(d); lastId = Math.max(lastId, d.id); });
        } catch (e) { /* 网络抖动静默重试 */ }
    }

    function startPolling() {
        if (polling) return;
        setStatus('实时连接已断开，已切换 5s 轮询模式');
        pollOnce();
        polling = setInterval(pollOnce, 5000);
    }

    function stopPolling() {
        if (polling) { clearInterval(polling); polling = null; setStatus(''); }
    }

    // ===== WebSocket 接入（任务 9.2/9.5）=====
    async function connectWs() {
        if (!userId || !page.dataset.ws) { startPolling(); return; }
        let token;
        try {
            const res = await fetch('/realtime/token', { headers: { 'Accept': 'application/json' } });
            token = (await res.json()).token;
        } catch (e) { startPolling(); return; }
        if (!token) { startPolling(); return; }

        const url = page.dataset.ws
            + '?token=' + encodeURIComponent(token)
            + '&book_id=' + page.dataset.book
            + '&chapter_id=' + chapterId;
        const ws = new WebSocket(url);

        ws.onmessage = (ev) => {
            try {
                const msg = JSON.parse(ev.data);
                if (msg.type === 'danmu' && msg.payload) {
                    stopPolling();
                    renderDanmu(msg.payload);
                    lastId = Math.max(lastId, msg.payload.id);
                } else if (msg.type === 'donation' && msg.payload) {
                    // 打赏特效：全屏居中横幅 + 金色弹幕（任务 10.6 ≤3s 广播）
                    renderDonation(msg.payload);
                }
            } catch (e) { /* 忽略非法帧 */ }
        };
        ws.onclose = () => startPolling(); // 断开即降级（重连成功后 stopPolling）
        ws.onerror = () => { try { ws.close(); } catch (e) {} };
    }
    connectWs();

    // ===== 弹幕发送（任务 9.5：乐观上屏）=====
    const input = document.getElementById('danmu-input');
    document.getElementById('danmu-send')?.addEventListener('click', async () => {
        const content = input.value.trim();
        if (!content) return;
        if (!userId) { location.href = '/login'; return; }

        // 乐观上屏：pending 样式，失败移除
        const temp = document.createElement('div');
        temp.className = 'danmu-item pending';
        temp.textContent = content;
        temp.style.top = `${(track++ % 6) * 26 + 8}px`;
        layer.appendChild(temp);
        const inputCopy = content;
        input.value = '';

        try {
            const res = await fetch('/danmu', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
                body: JSON.stringify({ chapter_id: chapterId, content: inputCopy, paragraph_no: paraNo || 1 }),
            });
            if (res.status === 201) {
                const d = await res.json();
                temp.remove();
                renderDanmu(d); // 用服务端权威数据替换
                lastId = Math.max(lastId, d.id);
            } else {
                temp.remove();
                const err = await res.json().catch(() => ({}));
                setStatus(err.message || '弹幕发送失败');
            }
        } catch (e) {
            temp.remove();
            setStatus('网络异常，弹幕未发出');
        }
    });

    // ===== 打赏面板（任务 10.1：礼物选择 → 下单 → 收银台）=====
    const modal = document.getElementById('donate-modal');
    const giftGrid = document.getElementById('gift-grid');
    const donateStatus = document.getElementById('donate-status');
    let selectedGift = null;

    document.getElementById('btn-donate')?.addEventListener('click', async () => {
        if (!userId) { location.href = '/login'; return; }
        modal.classList.remove('hidden');
        if (giftGrid.dataset.loaded) return;
        try {
            const res = await fetch('/gifts', { headers: { 'Accept': 'application/json' } });
            const gifts = await res.json();
            giftGrid.innerHTML = '';
            gifts.forEach((g) => {
                const b = document.createElement('button');
                b.className = 'gift-item';
                b.innerHTML = `<span class="gift-icon">${g.icon || '🎁'}</span><span>${g.name}</span><b>¥${(g.price / 100).toFixed(2)}</b>`;
                b.addEventListener('click', () => {
                    selectedGift = g.id;
                    giftGrid.querySelectorAll('.gift-item').forEach((x) => x.classList.remove('active'));
                    b.classList.add('active');
                });
                giftGrid.appendChild(b);
            });
            giftGrid.dataset.loaded = '1';
        } catch (e) {
            giftGrid.innerHTML = '<span class="hint">礼物加载失败</span>';
        }
    });

    document.getElementById('donate-close')?.addEventListener('click', () => modal.classList.add('hidden'));
    modal?.addEventListener('click', (e) => { if (e.target === modal) modal.classList.add('hidden'); });

    document.querySelectorAll('.donate-channels button').forEach((btn) => {
        btn.addEventListener('click', async () => {
            const amountYuan = Number(document.getElementById('donate-amount').value);
            const payload = { book_id: Number(page.dataset.book), chapter_id: chapterId, channel: btn.dataset.channel };
            if (selectedGift) payload.gift_id = selectedGift;
            else if (amountYuan > 0) payload.amount = Math.round(amountYuan * 100);
            else { donateStatus.textContent = '请选择礼物或输入金额'; return; }

            try {
                const res = await fetch('/donate', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
                    body: JSON.stringify(payload),
                });
                if (res.status === 503) { donateStatus.textContent = '支付功能即将开放'; return; }
                if (!res.ok) { donateStatus.textContent = (await res.json().catch(() => ({}))).message || '下单失败'; return; }
                const { trade_no: tradeNo } = await res.json();
                window.open(`/pay/${encodeURIComponent(tradeNo)}`, '_blank', 'width=420,height=520');
                donateStatus.textContent = '已开收银台，支付完成后本页自动收到打赏特效';
            } catch (e) {
                donateStatus.textContent = '网络异常';
            }
        });
    });
})();
