/* 廠商 API 文檔用：/api/factory/token 簽名計算工具。
 * sign = HMAC-SHA256(app_secret, "app_id|timestamp|nonce")，小寫 hex。
 * 全程在瀏覽器本機計算，app_secret 不會送出任何請求。
 * 優先用 crypto.subtle（僅 HTTPS／localhost 可用），否則退回下方純 JS 實作。 */
(function () {
    'use strict';

    // ---- 純 JS SHA-256 / HMAC（http 內網環境備援）----
    var K = new Uint32Array([
        0x428a2f98, 0x71374491, 0xb5c0fbcf, 0xe9b5dba5, 0x3956c25b, 0x59f111f1, 0x923f82a4, 0xab1c5ed5,
        0xd807aa98, 0x12835b01, 0x243185be, 0x550c7dc3, 0x72be5d74, 0x80deb1fe, 0x9bdc06a7, 0xc19bf174,
        0xe49b69c1, 0xefbe4786, 0x0fc19dc6, 0x240ca1cc, 0x2de92c6f, 0x4a7484aa, 0x5cb0a9dc, 0x76f988da,
        0x983e5152, 0xa831c66d, 0xb00327c8, 0xbf597fc7, 0xc6e00bf3, 0xd5a79147, 0x06ca6351, 0x14292967,
        0x27b70a85, 0x2e1b2138, 0x4d2c6dfc, 0x53380d13, 0x650a7354, 0x766a0abb, 0x81c2c92e, 0x92722c85,
        0xa2bfe8a1, 0xa81a664b, 0xc24b8b70, 0xc76c51a3, 0xd192e819, 0xd6990624, 0xf40e3585, 0x106aa070,
        0x19a4c116, 0x1e376c08, 0x2748774c, 0x34b0bcb5, 0x391c0cb3, 0x4ed8aa4a, 0x5b9cca4f, 0x682e6ff3,
        0x748f82ee, 0x78a5636f, 0x84c87814, 0x8cc70208, 0x90befffa, 0xa4506ceb, 0xbef9a3f7, 0xc67178f2
    ]);

    function sha256(bytes) {
        var h = new Uint32Array([0x6a09e667, 0xbb67ae85, 0x3c6ef372, 0xa54ff53a, 0x510e527f, 0x9b05688c, 0x1f83d9ab, 0x5be0cd19]);
        var len = bytes.length;
        var padLen = ((len + 9 + 63) >> 6) << 6;
        var buf = new Uint8Array(padLen);
        buf.set(bytes);
        buf[len] = 0x80;
        var dv = new DataView(buf.buffer);
        dv.setUint32(padLen - 8, Math.floor((len * 8) / 0x100000000));
        dv.setUint32(padLen - 4, (len * 8) >>> 0);
        var w = new Uint32Array(64);
        for (var off = 0; off < padLen; off += 64) {
            for (var i = 0; i < 16; i++) w[i] = dv.getUint32(off + i * 4);
            for (i = 16; i < 64; i++) {
                var a = w[i - 15], b = w[i - 2];
                var s0 = ((a >>> 7) | (a << 25)) ^ ((a >>> 18) | (a << 14)) ^ (a >>> 3);
                var s1 = ((b >>> 17) | (b << 15)) ^ ((b >>> 19) | (b << 13)) ^ (b >>> 10);
                w[i] = (w[i - 16] + s0 + w[i - 7] + s1) | 0;
            }
            var v = h.slice();
            for (i = 0; i < 64; i++) {
                var e = v[4], ar = v[0];
                var S1 = ((e >>> 6) | (e << 26)) ^ ((e >>> 11) | (e << 21)) ^ ((e >>> 25) | (e << 7));
                var ch = (e & v[5]) ^ (~e & v[6]);
                var t1 = (v[7] + S1 + ch + K[i] + w[i]) | 0;
                var S0 = ((ar >>> 2) | (ar << 30)) ^ ((ar >>> 13) | (ar << 19)) ^ ((ar >>> 22) | (ar << 10));
                var mj = (ar & v[1]) ^ (ar & v[2]) ^ (v[1] & v[2]);
                var t2 = (S0 + mj) | 0;
                v[7] = v[6]; v[6] = v[5]; v[5] = v[4]; v[4] = (v[3] + t1) | 0;
                v[3] = v[2]; v[2] = v[1]; v[1] = v[0]; v[0] = (t1 + t2) | 0;
            }
            for (i = 0; i < 8; i++) h[i] = (h[i] + v[i]) | 0;
        }
        var out = new Uint8Array(32);
        var odv = new DataView(out.buffer);
        for (i = 0; i < 8; i++) odv.setUint32(i * 4, h[i]);
        return out;
    }

    function hmacJs(keyBytes, msgBytes) {
        if (keyBytes.length > 64) keyBytes = sha256(keyBytes);
        var ipad = new Uint8Array(64 + msgBytes.length);
        var opad = new Uint8Array(64 + 32);
        for (var i = 0; i < 64; i++) {
            var kb = i < keyBytes.length ? keyBytes[i] : 0;
            ipad[i] = kb ^ 0x36;
            opad[i] = kb ^ 0x5c;
        }
        ipad.set(msgBytes, 64);
        opad.set(sha256(ipad), 64);
        return sha256(opad);
    }

    function toHex(bytes) {
        return Array.prototype.map.call(bytes, function (b) {
            return ('0' + b.toString(16)).slice(-2);
        }).join('');
    }

    async function hmacHex(secret, message) {
        var enc = new TextEncoder();
        if (window.crypto && window.crypto.subtle) {
            var key = await crypto.subtle.importKey('raw', enc.encode(secret), { name: 'HMAC', hash: 'SHA-256' }, false, ['sign']);
            return toHex(new Uint8Array(await crypto.subtle.sign('HMAC', key, enc.encode(message))));
        }
        return toHex(hmacJs(enc.encode(secret), enc.encode(message)));
    }

    function randomNonce(len) {
        var chars = 'abcdefghijklmnopqrstuvwxyz0123456789';
        var arr = new Uint8Array(len);
        crypto.getRandomValues(arr);
        var s = '';
        for (var i = 0; i < len; i++) s += chars[arr[i] % chars.length];
        return s;
    }

    function $(id) { return document.getElementById(id); }

    async function calc() {
        var appId = $('fs-app-id').value.trim();
        var secret = $('fs-secret').value;
        var ts = $('fs-ts').value.trim();
        var nonce = $('fs-nonce').value.trim();
        var err = $('fs-error');
        err.textContent = '';
        if (!appId || !secret || !ts || !/^[A-Za-z0-9]{16,64}$/.test(nonce)) {
            err.textContent = '請填 app_id、app_secret，nonce 需為 16～64 位英數字。';
            return;
        }
        var sign = await hmacHex(secret, appId + '|' + ts + '|' + nonce);
        $('fs-out').value = JSON.stringify({ app_id: appId, timestamp: parseInt(ts, 10), nonce: nonce, sign: sign }, null, 2);
    }

    // 把產生的 JSON 填進 Swagger UI 的 token 接口 Request body（並自動展開、開啟 Try it out）
    function fillSwagger() {
        var out = $('fs-out');
        var err = $('fs-error');
        if (!out.value) { err.textContent = '請先計算簽名。'; return; }
        err.textContent = '';
        var pathEl = document.querySelector('.opblock-summary-path[data-path="/api/factory/token"]');
        var block = pathEl && pathEl.closest('.opblock');
        if (!block || !window.ui) { err.textContent = '找不到 token 接口，請稍後再試。'; return; }
        if (!block.classList.contains('is-open')) {
            block.querySelector('.opblock-summary-control').click();
        }
        setTimeout(function () {
            var tryBtn = block.querySelector('.try-out__btn');
            if (tryBtn && !tryBtn.classList.contains('cancel')) tryBtn.click();
            setTimeout(function () {
                window.ui.getSystem().oas3Actions.setRequestBodyValue({
                    value: out.value,
                    pathMethod: ['/api/factory/token', 'post']
                });
                block.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }, 100);
        }, 100);
    }

    function refresh() {
        $('fs-ts').value = Math.floor(Date.now() / 1000);
        $('fs-nonce').value = randomNonce(24);
    }

    document.addEventListener('DOMContentLoaded', function () {
        refresh();
        $('fs-refresh').addEventListener('click', function () { refresh(); calc(); });
        $('fs-calc').addEventListener('click', calc);
        $('fs-fill').addEventListener('click', async function () {
            refresh(); // 每次填入都換新的 timestamp／nonce，避免 nonce 重複被拒
            await calc();
            fillSwagger();
        });
        $('fs-copy').addEventListener('click', function () {
            var t = $('fs-out');
            if (!t.value) return;
            t.select();
            if (navigator.clipboard) navigator.clipboard.writeText(t.value); else document.execCommand('copy');
        });
        $('fs-toggle').addEventListener('click', function () {
            $('fs-body').classList.toggle('fs-hidden');
        });
    });
})();
