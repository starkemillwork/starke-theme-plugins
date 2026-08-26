/*!
* WP Grid Builder Caching Add-on
*
* @package   WP Grid Builder - Caching
* @author    Loïc Blascos
* @link      https://www.wpgridbuilder.com
* @copyright 2019-2024 Loïc Blascos
*
*/
(()=>{const t=document.querySelector("button.wpgb-caching-clear"),e=document.querySelector("p.wpgb-caching-stats"),n=t&&t.textContent,a=e&&e.textContent;let o,c,s;function r(){const t=document.querySelector(".wpgb-caching-stats"),e=new FormData;e.append("action","wpgb_caching"),e.append("method","cache_stats"),e.append("nonce",t&&t.dataset.nonce),t.textContent=a,t.classList.add(".wpgb-loading"),c&&c.abort(),c=null,c=new XMLHttpRequest,c.onload=c.onerror=e=>{const{responseText:n}=e.target,a=n&&JSON.parse(n);t.textContent=a&&a.content||e.target.statusText,t.classList.remove(".wpgb-loading")},c.open("POST",ajaxurl),c.send(e)}document.addEventListener("DOMContentLoaded",(()=>r())),document.addEventListener("click",(({target:t})=>{t.closest("button.wpgb-caching-clear")&&function(t){const e=new FormData;e.append("action","wpgb_caching"),e.append("method","clear_cache"),e.append("nonce",t.dataset.nonce),t.textContent=t.dataset.clear,o&&o.abort(),o=null,o=new XMLHttpRequest,o.onload=o.onerror=e=>{const{responseText:a}=e.target,o=a&&JSON.parse(a);t.textContent=o&&o.content||e.target.statusText,clearTimeout(s),s=setTimeout((()=>t.textContent=n),1500),r()},o.open("POST",ajaxurl),o.send(e)}(t)}))})();