/**
 * 这个脚本会被注入到通过 iframe 预览的静态网页中
 * 用于接管所有带有 data-cms-type 属性的元素的交互
 */
(function() {
    // 防止重复注入
    if (window.__cmsEditorInitialized) return;
    window.__cmsEditorInitialized = true;

    // 辅助函数：尝试智能提取多媒体配置（适配原生未经过 CMS 保存过的占位符元素）
    function getMediaConfig(target, type) {
        let config = null;
        const configStr = target.getAttribute('data-cms-config');
        if (configStr) {
            try { config = JSON.parse(configStr); } catch(e) { console.error(e); }
        }
        
        // 如果没有读取到已存的配置，则通过读取现有 DOM 节点动态反推生成配置信息
        if (!config) {
            if (type === 'video') {
                const tagName = target.tagName.toLowerCase();
                if (tagName === 'iframe') {
                    config = { type: 'embed', src: '', iframeCode: target.outerHTML, autoplay: false, loop: false, muted: false, controls: true };
                } else if (tagName === 'video') {
                    config = { type: 'local', src: target.getAttribute('src') || '', iframeCode: '', autoplay: target.autoplay, loop: target.loop, muted: target.muted, controls: target.controls };
                } else {
                    const iframe = target.querySelector('iframe');
                    if (iframe) {
                        config = { type: 'embed', src: '', iframeCode: iframe.outerHTML, autoplay: false, loop: false, muted: false, controls: true };
                    } else {
                        const vid = target.querySelector('video');
                        if (vid) config = { type: 'local', src: vid.getAttribute('src') || '', iframeCode: '', autoplay: vid.autoplay, loop: vid.loop, muted: vid.muted, controls: vid.controls };
                    }
                }
                // 后备安全默认值
                if (!config) config = { type: 'local', src: '', iframeCode: '', autoplay: true, loop: true, muted: true, controls: false };
            } else if (type === 'slider') {
                let slides = [];
                const slideEls = target.querySelectorAll('.swiper-slide');
                if (slideEls.length > 0) {
                    slideEls.forEach((slide, idx) => {
                        const img = slide.querySelector('img');
                        const h2 = slide.querySelector('h2');
                        const p = slide.querySelector('p');
                        slides.push({
                            id: Date.now() + idx,
                            src: img ? img.getAttribute('src') : '',
                            title: h2 ? h2.innerText : '',
                            description: p ? p.innerText : '',
                            textPosition: 'center-center'
                        });
                    });
                }
                config = {
                    width: target.style.width || '100%',
                    height: target.style.height || '400px',
                    pagination: !!target.querySelector('.swiper-pagination'),
                    slides: slides
                };
            }
        }
        return config;
    }

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

    // 初始化多媒体元素(轮播/视频)的常驻悬浮工具栏
    function initMediaToolbars() {
        document.querySelectorAll('[data-cms-type="slider"], [data-cms-type="video"]').forEach(el => {
            // 检查父元素是否已经是 wrapper，避免重复包装
            if (el.parentElement && el.parentElement.classList.contains('cms-media-wrapper')) return;
            
            // 清理元素本身可能存在的旧 toolbar
            const oldToolbar = el.querySelector(':scope > .cms-media-toolbar');
            if (oldToolbar) oldToolbar.remove();

            const toolbar = document.createElement('div');
            toolbar.className = 'cms-floating-toolbar cms-media-toolbar';
            toolbar.contentEditable = "false";
            
            // 覆盖默认样式，使其一直显示并在左上角，不受外部 iframe 点击拦截的影响
            toolbar.style.opacity = '1';
            toolbar.style.top = '8px';
            toolbar.style.left = '8px';
            toolbar.style.right = 'auto';
            toolbar.style.zIndex = '99999';
            toolbar.style.backgroundColor = '#4f46e5';

            const type = el.getAttribute('data-cms-type');
            const typeName = type === 'slider' ? '轮播' : '视频';

            toolbar.innerHTML = `<button type="button" class="cms-btn-edit" style="color: white; font-weight: bold;">⚙️ 编辑${typeName}</button>`;

            // 阻止点击冒泡触发外层的别的选中事件
            toolbar.addEventListener('click', (ev) => {
                ev.preventDefault();
                ev.stopPropagation();
            });

            toolbar.querySelector('.cms-btn-edit').onclick = (ev) => {
                ev.preventDefault();
                ev.stopPropagation();
                if (!el.hasAttribute('data-cms-runtime-id')) {
                    el.setAttribute('data-cms-runtime-id', 'cms-id-' + Math.random().toString(36).substr(2, 9));
                }
                const config = getMediaConfig(el, type);
                window.parent.postMessage({
                    action: type === 'slider' ? 'edit_slider' : 'edit_video',
                    runtimeId: el.getAttribute('data-cms-runtime-id'),
                    config: config,
                    src: el.tagName.toLowerCase() === 'img' ? el.getAttribute('src') : ''
                }, '*');
            };

            // 对于 iframe、video、img 等无法容纳 HTML 子元素的标签，必须外层套一个 div 才能放置悬浮按钮
            const tagName = el.tagName.toLowerCase();
            if (['img', 'video', 'iframe'].includes(tagName)) {
                const wrapper = document.createElement('div');
                wrapper.className = 'cms-media-wrapper';
                wrapper.style.position = 'relative';
                wrapper.style.display = window.getComputedStyle(el).display.includes('inline') ? 'inline-block' : 'block';
                el.parentNode.insertBefore(wrapper, el);
                wrapper.appendChild(el);
                wrapper.appendChild(toolbar);
            } else {
                // 其他可以正常容纳子元素的标签（如 div）直接放入内部
                el.style.position = 'relative';
                el.appendChild(toolbar);
            }
        });
    }
    // 初始执行一次
    initMediaToolbars();

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
        else if (type === 'img' || tagName === 'img') {
            window.parent.postMessage({
                action: 'edit_media',
                runtimeId: runtimeId,
                tagName: tagName,
                src: target.getAttribute('src') || '',
                alt: target.getAttribute('alt') || ''
            }, '*');
        }else if (type === 'video') {
            const config = getMediaConfig(target, 'video');
            window.parent.postMessage({
                action: 'edit_video',
                runtimeId: runtimeId,
                config: config
            }, '*');
        }
        // 处理slider
        else if (type === 'slider') {
            const config = getMediaConfig(target, 'slider');
            window.parent.postMessage({
                action: 'edit_slider',
                runtimeId: runtimeId,
                config: config,
                src: tagName === 'img' ? target.getAttribute('src') : ''
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
        else if (data.action === 'replace_with_video') {
             // 寻找要被替换的元素，不仅可以是 img，也可能是现有的 video wrapper
            const targetEl = document.querySelector(`[data-cms-runtime-id="${data.runtimeId}"]`) || window.activeElement; 
        
            if (targetEl) {
                // 如果被临时 wrapper 包裹着，我们需要连同 wrapper 一起替换掉，避免嵌套冗余
                const replaceTarget = targetEl.parentElement && targetEl.parentElement.classList.contains('cms-media-wrapper') ? targetEl.parentElement : targetEl;

                // 建立一个包裹器，确保工具栏能有一个相对定位的父级空间
                const wrapper = document.createElement('div');
                wrapper.setAttribute('data-cms-type', 'video');
                if (targetEl.hasAttribute('data-cms-runtime-id')) wrapper.setAttribute('data-cms-runtime-id', targetEl.getAttribute('data-cms-runtime-id'));
                wrapper.setAttribute('data-cms-config', JSON.stringify(data.videoData)); // 注入配置留作下次编辑
                
                wrapper.className = targetEl.className;
                if (targetEl.getAttribute('style')) wrapper.setAttribute('style', targetEl.getAttribute('style'));
                wrapper.style.position = 'relative';
                let newVideoEl;
                
                if (data.videoData.type === 'local') {
                    newVideoEl = document.createElement('video');
                    newVideoEl.src = data.videoData.src;
                    if (data.videoData.autoplay) newVideoEl.autoplay = true;
                    if (data.videoData.loop) newVideoEl.loop = true;
                    if (data.videoData.muted) newVideoEl.muted = true;
                    if (data.videoData.controls) newVideoEl.controls = true;
                    newVideoEl.setAttribute('playsinline', ''); // 增加移动端内联播放支持
                    newVideoEl.style.width = '100%';
                    newVideoEl.style.height = '100%';
                    newVideoEl.style.objectFit = 'cover';
                } else if (data.videoData.type === 'embed') {
                    const tempDiv = document.createElement('div');
                    tempDiv.innerHTML = data.videoData.iframeCode.trim();
                    newVideoEl = tempDiv.firstElementChild; 
                    if (newVideoEl) {
                        newVideoEl.style.width = '100%';
                        newVideoEl.style.height = '100%';
                    }
                }

                if (newVideoEl) {
                    wrapper.appendChild(newVideoEl);
                    replaceTarget.parentNode.replaceChild(wrapper, replaceTarget);
                    
                    // 重新挂载悬浮工具栏
                    initMediaToolbars();
                }
            }
        } else if (data.action === 'replace_with_slider') {
            const targetEl = document.querySelector(`[data-cms-runtime-id="${data.runtimeId}"]`) || window.activeElement;
            
            if (targetEl) {
                // 同样，如果目标元素本身外层有为了显示 toolbar 临时加的 wrapper，把 wrapper 作为替换目标
                const replaceTarget = targetEl.parentElement && targetEl.parentElement.classList.contains('cms-media-wrapper') ? targetEl.parentElement : targetEl;

                const sd = data.sliderData;
                
                // 1. 注入 Swiper CSS 文件外链
                if (!document.querySelector('link#swiper-css-lib')) {
                    const swiperCss = document.createElement('link');
                    swiperCss.id = 'swiper-css-lib';
                    swiperCss.rel = 'stylesheet';
                    swiperCss.href = 'https://cdn.jsdelivr.net/npm/swiper@12/swiper-bundle.min.css';
                    document.head.appendChild(swiperCss);
                }
                
                // 2. 注入 Swiper JS 文件外链
                if (!document.querySelector('script#swiper-js-lib')) {
                    const swiperJs = document.createElement('script');
                    swiperJs.id = 'swiper-js-lib';
                    swiperJs.src = 'https://cdn.jsdelivr.net/npm/swiper@12/swiper-bundle.min.js';
                    document.head.appendChild(swiperJs);
                }
                
                // 3. 创建 Swiper Container (经典 DOM 结构)
                const swiperId = 'swiper-' + Math.random().toString(36).substr(2, 9);
                const swiperContainer = document.createElement('div');
                swiperContainer.className = 'swiper';
                swiperContainer.id = swiperId;
                swiperContainer.style.width = sd.width;
                swiperContainer.style.height = sd.height;
                swiperContainer.style.position = 'relative';
                
                // 继承 CMS 属性，并使它本身作为 slider 可以被识别
                swiperContainer.setAttribute('data-cms-type', 'slider');
                if (targetEl.hasAttribute('data-cms-runtime-id')) {
                    swiperContainer.setAttribute('data-cms-runtime-id', targetEl.getAttribute('data-cms-runtime-id'));
                }
                swiperContainer.setAttribute('data-cms-config', JSON.stringify(sd)); // 注入配置留作下次编辑

                const swiperWrapper = document.createElement('div');
                swiperWrapper.className = 'swiper-wrapper';
                swiperContainer.appendChild(swiperWrapper);

                // 排版位置到 Flex 样式的映射
                const posMap = {
                    'top-left': 'align-items: flex-start; justify-content: flex-start; text-align: left;',
                    'top-center': 'align-items: flex-start; justify-content: center; text-align: center;',
                    'top-right': 'align-items: flex-start; justify-content: flex-end; text-align: right;',
                    'center-left': 'align-items: center; justify-content: flex-start; text-align: left;',
                    'center-center': 'align-items: center; justify-content: center; text-align: center;',
                    'center-right': 'align-items: center; justify-content: flex-end; text-align: right;',
                    'bottom-left': 'align-items: flex-end; justify-content: flex-start; text-align: left;',
                    'bottom-center': 'align-items: flex-end; justify-content: center; text-align: center;',
                    'bottom-right': 'align-items: flex-end; justify-content: flex-end; text-align: right;'
                };

                // 4. 循环构建每个 Slide
                sd.slides.forEach(slide => {
                    const slideEl = document.createElement('div');
                    slideEl.className = 'swiper-slide';
                    slideEl.style.position = 'relative';
                    
                    // 构建背景图和文字层 HTML
                    const bgHtml = `<img src="${slide.src}" style="width: 100%; height: 100%; object-fit: cover; display: block;" alt="${slide.title}">`;
                    const textHtml = (slide.title || slide.description) ? `
                        <div style="position: absolute; top: 0; left: 0; width: 100%; height: 100%; display: flex; padding: 3rem; box-sizing: border-box; pointer-events: none; text-shadow: 0 2px 6px rgba(0,0,0,0.7); color: white; ${posMap[slide.textPosition]}">
                            <div style="max-width: 80%; pointer-events: auto;">
                                ${slide.title ? `<h2 style="font-size: 2rem; font-weight: bold; margin-bottom: 0.5rem; line-height: 1.2;">${slide.title}</h2>` : ''}
                                ${slide.description ? `<p style="font-size: 1.125rem; line-height: 1.5; opacity: 0.9;">${slide.description}</p>` : ''}
                            </div>
                        </div>
                    ` : '';
                    
                    slideEl.innerHTML = bgHtml + textHtml;
                    swiperWrapper.appendChild(slideEl);
                });

                if (sd.pagination) {
                    const paginationEl = document.createElement('div');
                    paginationEl.className = 'swiper-pagination';
                    swiperContainer.appendChild(paginationEl);
                }

                // 5. 生成初始化 JS 脚本并插入
                const initScript = document.createElement('script');
                initScript.textContent = `
                    (function() {
                        var init = function() {
                            new Swiper('#${swiperId}', {
                                loop: true,
                                ${sd.pagination ? `pagination: { el: "#${swiperId} .swiper-pagination", clickable: true },` : ''}
                                autoplay: { delay: 5000, disableOnInteraction: false },
                            });
                        };
                        if (document.readyState === 'loading') {
                            document.addEventListener('DOMContentLoaded', init);
                        } else {
                            if (typeof Swiper !== 'undefined') init();
                            else document.querySelector('script#swiper-js-lib').addEventListener('load', init);
                        }
                    })();
                `;

                // 6. 替换真实的 DOM 元素，并紧跟着插入初始化脚本
                replaceTarget.parentNode.insertBefore(swiperContainer, replaceTarget);
                replaceTarget.parentNode.insertBefore(initScript, replaceTarget);
                
                // 清理可能存在的旧版本初始化脚本，防止多重初始化冲突
                if (replaceTarget.getAttribute('data-cms-type') === 'slider' || targetEl.getAttribute('data-cms-type') === 'slider') {
                    let next = replaceTarget.nextElementSibling;
                    while (next) {
                        if (next.tagName.toLowerCase() === 'script' && next.textContent.includes('new Swiper')) {
                            next.remove();
                            break;
                        }
                        next = next.nextElementSibling;
                    }
                }
                
                replaceTarget.remove();
                setTimeout(initMediaToolbars, 50); // 延时重载工具栏
            }
        }
    });

    console.log('CMS 注入脚本已成功加载并接管页面。');
})();
