/**
 * 这个脚本会被注入到通过 iframe 预览的静态网页中
 * 用于接管所有带有 data-cms-type 属性的元素的交互
 */
(function() {
    // 防止重复注入
    if (window.__cmsEditorInitialized) return;
    window.__cmsEditorInitialized = true;

    // 1. 注入编辑器专属的 CSS，用于高亮显示可编辑区域
    const style = document.createElement('style');
    style.id = 'cms-editor-style';
    style.textContent = `
        [data-cms-type] {
            position: relative;
            transition: outline 0.2s ease-in-out;
        }
        [data-cms-type]:hover {
            outline: 2px dashed #2563eb !important;
            outline-offset: 2px;
            cursor: pointer;
        }
        [data-cms-type][contenteditable="true"] {
            outline: 2px solid #16a34a !important;
            outline-offset: 2px;
            cursor: text;
        }
        .cms-floating-toolbar {
            position: absolute;
            background: #1f2937;
            border-radius: 6px;
            padding: 4px;
            display: flex;
            gap: 4px;
            z-index: 99999;
            box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1);
            top: 0;
            right: 0;
            opacity: 0.3;
            transition: opacity 0.2s ease-in-out;
        }
        .cms-floating-toolbar:hover {
            opacity: 1;
        }
        .cms-floating-toolbar button {
            background: transparent;
            color: white;
            border: none;
            padding: 4px 8px;
            font-size: 12px;
            cursor: pointer;
            border-radius: 4px;
        }
        .cms-floating-toolbar button:hover {
            background: #374151;
        }
    `;
    document.head.appendChild(style);

    // 2. 初始化列表元素的常驻工具栏
    function initListToolbars() {
        document.querySelectorAll('[data-cms-type="li"], li[data-cms-type]').forEach(li => {
            // 如果已经存在工具栏则跳过
            if (li.querySelector(':scope > .cms-floating-toolbar')) return;

            const toolbar = document.createElement('div');
            toolbar.className = 'cms-floating-toolbar';
            toolbar.contentEditable = "false";
            toolbar.innerHTML = `
                <button type="button" class="cms-btn-dup">➕ 复制</button>
                <button type="button" class="cms-btn-del" style="color:#fca5a5;">🗑️ 删除</button>
            `;

            // 防止点击工具栏时触发外层的可编辑事件或者跳转
            toolbar.addEventListener('click', (ev) => {
                ev.preventDefault();
                ev.stopPropagation();
            });

            toolbar.querySelector('.cms-btn-dup').onclick = (ev) => {
                ev.preventDefault();
                ev.stopPropagation();
                const clone = li.cloneNode(true);
                clone.removeAttribute('data-cms-runtime-id');
                // 移除克隆带过来的旧工具栏，由初始化函数重新绑定
                const oldToolbar = clone.querySelector('.cms-floating-toolbar');
                if (oldToolbar) oldToolbar.remove();
                
                li.parentNode.insertBefore(clone, li.nextSibling);
                initListToolbars(); // 重新为新元素绑定工具栏
            };

            toolbar.querySelector('.cms-btn-del').onclick = (ev) => {
                ev.preventDefault();
                ev.stopPropagation();
                li.remove();
            };

            // 将工具栏直接插入到 li 内部
            li.appendChild(toolbar);
        });
    }

    // 页面加载完成后立即注入工具栏
    initListToolbars();

    // 提取并发送页面的 SEO Meta 数据给父窗口
    setTimeout(() => {
        function getMetaContent(selector, attr = 'content') {
            const el = document.querySelector(selector);
            return el ? el.getAttribute(attr) || '' : '';
        }
        const robots = getMetaContent('meta[name="robots"]');
        const schemaEl = document.querySelector('script[type="application/ld+json"]');
        window.parent.postMessage({
            action: 'load_seo',
            seoData: {
                title: document.title || '',
                description: getMetaContent('meta[name="description"]'),
                canonical: getMetaContent('link[rel="canonical"]', 'href'),
                robotsIndex: robots.includes('noindex') ? 'noindex' : 'index',
                robotsFollow: robots.includes('nofollow') ? 'nofollow' : 'follow',
                ogTitle: getMetaContent('meta[property="og:title"]'),
                ogDescription: getMetaContent('meta[property="og:description"]'),
                ogImage: getMetaContent('meta[property="og:image"]'),
                twitterCard: getMetaContent('meta[name="twitter:card"]') || 'summary_large_image',
                twitterSite: getMetaContent('meta[name="twitter:site"]'),
                fbPublisher: getMetaContent('meta[property="article:publisher"]'),
                schemaCode: schemaEl ? schemaEl.textContent : ''
            }
        }, '*');
    }, 500); // 稍微延迟以确保页面完全解析

    // 3. 绑定点击事件，处理不同类型元素的编辑逻辑
    const textTypes = ['p', 'span', 'b', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'td', 'th', 'div'];

    // 使用事件委托挂载到 body 上，这样后续克隆/新增的 <li> 等元素也能自动获得编辑能力
    document.body.addEventListener('click', function(e) {
        const target = e.target.closest('[data-cms-type]');
        if (!target) return;

        // 阻止默认行为（比如点击 a 标签不会直接跳走）
        e.preventDefault();
        e.stopPropagation();

        const type = target.getAttribute('data-cms-type').toLowerCase();
        const tagName = target.tagName.toLowerCase();

        // 分配一个运行时临时ID，以便父窗口修改后能找回这个元素
        if (!target.hasAttribute('data-cms-runtime-id')) {
            target.setAttribute('data-cms-runtime-id', 'cms-id-' + Math.random().toString(36).substr(2, 9));
        }
        const runtimeId = target.getAttribute('data-cms-runtime-id');

        // 处理文字类型的行内编辑
        if (textTypes.includes(type) || textTypes.includes(tagName)) {
            if (target.getAttribute('contenteditable') !== 'true') {
                target.setAttribute('contenteditable', 'true');
                target.focus();
                
                // 失去焦点时，结束编辑
                const blurHandler = () => {
                    target.removeAttribute('contenteditable');
                    target.removeEventListener('blur', blurHandler);
                    
                    // 通知父窗口 (CMS 后台) 内容有变动，可在此处加入防抖逻辑供后续点击“保存”时使用
                    console.log(`[CMS] 元素 ${target.getAttribute('data-cms-id') || '未命名'} 已修改`);
                };
                target.addEventListener('blur', blurHandler);
            }
        } 
        // 处理超链接的编辑
        else if (type === 'a' || tagName === 'a') {
            window.parent.postMessage({
                action: 'edit_link',
                runtimeId: runtimeId,
                href: target.getAttribute('href') || '',
                text: target.innerText || '',
                target: target.getAttribute('target') || '_self'
            }, '*');
        } 
        // 处理图片/视频的编辑
        else if (type === 'img' || type === 'video' || tagName === 'img' || tagName === 'video') {
            window.parent.postMessage({
                action: 'edit_media',
                runtimeId: runtimeId,
                tagName: tagName,
                src: target.getAttribute('src') || '',
                alt: target.getAttribute('alt') || ''
            }, '*');
        }
    });

    // 4. 监听来自父窗口 (CMS 后台) 的更新消息
    window.addEventListener('message', function(event) {
        const data = event.data;
        if (!data || !data.action) return;
        
        if (data.action === 'update_element') {
            const el = document.querySelector(`[data-cms-runtime-id="${data.runtimeId}"]`);
            if (el) {
                if (data.updates.href !== undefined) el.setAttribute('href', data.updates.href);
                if (data.updates.text !== undefined) el.innerText = data.updates.text;
                if (data.updates.target !== undefined) el.setAttribute('target', data.updates.target);
                if (data.updates.src !== undefined) el.setAttribute('src', data.updates.src);
                if (data.updates.alt !== undefined) el.setAttribute('alt', data.updates.alt);
            }
        } 
        else if (data.action === 'request_save') {
            // 在保存前，首先将父窗口传来的 SEO 数据回写到 head 中
            if (data.seoData) {
                const sd = data.seoData;
                document.title = sd.title || '';
                
                const setMeta = (selector, tag, attr, attrName, value) => {
                    let el = document.querySelector(selector);
                    if (value) {
                        if (!el) { el = document.createElement(tag); el.setAttribute(attr, attrName); document.head.appendChild(el); }
                        if (tag === 'link') el.setAttribute('href', value);
                        else el.setAttribute('content', value);
                    } else if (el) { 
                        el.remove(); 
                    }
                };

                setMeta('meta[name="description"]', 'meta', 'name', 'description', sd.description);
                setMeta('link[rel="canonical"]', 'link', 'rel', 'canonical', sd.canonical);
                setMeta('meta[name="robots"]', 'meta', 'name', 'robots', `${sd.robotsIndex}, ${sd.robotsFollow}`);
                setMeta('meta[property="og:title"]', 'meta', 'property', 'og:title', sd.ogTitle);
                setMeta('meta[property="og:description"]', 'meta', 'property', 'og:description', sd.ogDescription);
                setMeta('meta[property="og:image"]', 'meta', 'property', 'og:image', sd.ogImage);
                setMeta('meta[name="twitter:card"]', 'meta', 'name', 'twitter:card', sd.twitterCard);
                setMeta('meta[name="twitter:site"]', 'meta', 'name', 'twitter:site', sd.twitterSite);
                setMeta('meta[property="article:publisher"]', 'meta', 'property', 'article:publisher', sd.fbPublisher);
                
                // 回写 JSON-LD Schema
                let schemaEl = document.querySelector('script[type="application/ld+json"]');
                if (sd.schemaCode && sd.schemaCode.trim() !== '') {
                    if (!schemaEl) {
                        schemaEl = document.createElement('script');
                        schemaEl.setAttribute('type', 'application/ld+json');
                        document.head.appendChild(schemaEl);
                    }
                    schemaEl.textContent = sd.schemaCode;
                } else if (schemaEl) {
                    schemaEl.remove();
                }
            }

            // 深度克隆整个文档 DOM，防止清理操作破坏当前用户正在编辑的界面
            const docClone = document.documentElement.cloneNode(true);
            
            // 清理所有为了编辑器运行而注入的临时属性和元素
            docClone.querySelectorAll('[data-cms-runtime-id]').forEach(el => el.removeAttribute('data-cms-runtime-id'));
            docClone.querySelectorAll('[contenteditable]').forEach(el => el.removeAttribute('contenteditable'));
            docClone.querySelectorAll('.cms-floating-toolbar').forEach(el => el.remove());
            
            // 移除注入的 style 和 script
            const styleTag = docClone.querySelector('style#cms-editor-style');
            if (styleTag) styleTag.remove();
            const scriptTag = docClone.querySelector('script#cms-editor-inject');
            if (scriptTag) scriptTag.remove();
            
            const cleanHtml = '<!DOCTYPE html>\n' + docClone.outerHTML;
            window.parent.postMessage({ action: 'save_html', html: cleanHtml }, '*');
        }
    });

    console.log('CMS 注入脚本已成功加载并接管页面。');
})();