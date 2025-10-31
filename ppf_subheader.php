<?php
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/ppf_theme.php';

if (!function_exists('ppf_subheader_escape')) {
    function ppf_subheader_escape($value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('ppf_subheader')) {
    function ppf_subheader(array $options): void
    {
        static $injected = false;
        static $counter = 0;

        $titleHtml = '';
        $subtitleHtml = '';
        $actionsHtml = '';

        if (isset($options['title_html'])) {
            $titleHtml = (string)$options['title_html'];
        } else {
            $title = isset($options['title']) ? ppf_subheader_escape($options['title']) : '';
            if ($title !== '') {
                $titleHtml = '<div class="ppf-subheader__title">' . $title . '</div>';
            }
        }

        if (isset($options['subtitle_html'])) {
            $subtitleHtml = (string)$options['subtitle_html'];
        } elseif (isset($options['subtitle']) && $options['subtitle'] !== '') {
            $subtitleHtml = '<div class="ppf-subheader__subtitle">' . ppf_subheader_escape($options['subtitle']) . '</div>';
        }

        if (isset($options['actions_html'])) {
            $actionsHtml = (string)$options['actions_html'];
        } elseif (isset($options['actions']) && is_callable($options['actions'])) {
            ob_start();
            $options['actions']();
            $actionsHtml = ob_get_clean();
        } elseif (isset($options['actions'])) {
            $actionsHtml = (string)$options['actions'];
        }

        $extraClass = isset($options['class']) ? ' ' . trim((string)$options['class']) : '';

        $counter++;
        $actionsId = 'ppf-subheader-actions-' . $counter;

        if (!$injected) {
            $injected = true;
            echo <<<HTML
<style>
.ppf-subheader {
    position: sticky;
    top: 0;
    z-index: 40;
    background: rgba(9,14,28,0.72);
    border: 1px solid var(--line, rgba(148,163,184,0.26));
    border-radius: 12px;
    padding: 10px 12px;
    margin-bottom: 16px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    backdrop-filter: blur(8px);
}
.ppf-subheader__summary {
    display: flex;
    align-items: center;
    gap: 10px;
    min-width: 0;
}
.ppf-subheader__text {
    display: flex;
    align-items: center;
    gap: 10px;
    min-width: 0;
    flex-wrap: wrap;
}
.ppf-subheader__title {
    font-weight: 700;
    font-size: 20px;
    letter-spacing: 0.2px;
    color: var(--text, #f8fafc);
    line-height: 1.2;
}
.ppf-subheader__subtitle {
    color: var(--muted, rgba(148,163,184,0.85));
    font-size: 13px;
    line-height: 1.45;
    display: inline-flex;
    align-items: center;
    gap: 6px;
}
.ppf-subheader__summary-toggle {
    display: none;
    align-items: center;
    justify-content: center;
    border: none;
    background: rgba(15,23,42,0.55);
    color: var(--muted, #9ba4c2);
    border-radius: 10px;
    padding: 8px;
    cursor: pointer;
}
.ppf-subheader__summary-toggle svg {
    width: 18px;
    height: 18px;
}
.ppf-subheader__actions {
    display: flex;
    align-items: center;
    justify-content: flex-end;
    gap: 8px;
    flex-wrap: wrap;
    --ppf-subheader-action-width: clamp(120px, 17vw, 160px);
}
.ppf-subheader__actions-inner {
    display: flex;
    align-items: stretch;
    gap: 12px;
    flex-wrap: wrap;
    justify-content: flex-end;
    width: 100%;
}
.ppf-subheader__close {
    display: none;
    border: none;
    background: none;
    color: var(--muted, #9ba4c2);
    font-size: 24px;
    font-weight: 600;
    cursor: pointer;
    margin-left: auto;
}
.ppf-subheader .btnset {
    display: flex;
    gap: 12px;
    flex-wrap: wrap;
    align-items: stretch;
}
.ppf-subheader .btn {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    background: rgba(30,41,59,0.65);
    border: 1px solid var(--line, rgba(148,163,184,0.26));
    color: var(--text, #f8fafc);
    padding: 10px 18px;
    border-radius: 12px;
    cursor: pointer;
    text-decoration: none;
    white-space: nowrap;
    min-height: 36px;
    font-size: 14px;
    line-height: 1.2;
    flex: 0 0 var(--ppf-subheader-action-width);
    width: var(--ppf-subheader-action-width);
}
.ppf-subheader .btn.small {
    padding: 6px 10px;
    font-size: 13px;
    min-height: 30px;
    flex: 0 0 auto;
    width: auto;
}
.ppf-subheader .btn.brand {
    background: var(--brand, #38bdf8);
    border-color: var(--brand, #38bdf8);
    color: #fff;
}
.ppf-subheader .btn.warn {
    background: #2a1617;
    border-color: rgba(248,113,113,0.45);
    color: #f87171;
}
@media (max-width: 900px) {
    .ppf-subheader {
        gap: 12px;
        padding: 10px 12px;
    }
    .ppf-subheader__subtitle {
        font-size: 12px;
    }
}
@media (max-width: 720px) {
    .ppf-subheader {
        flex-direction: column;
        align-items: stretch;
        position: relative;
        padding: 12px 14px;
    }
    .ppf-subheader__summary {
        cursor: pointer;
        justify-content: space-between;
        align-items: flex-start;
    }
    .ppf-subheader__summary[role="button"]:focus {
        outline: 2px solid var(--brand, #38bdf8);
        outline-offset: 2px;
    }
    .ppf-subheader__summary-toggle {
        display: inline-flex;
    }
    .ppf-subheader__text {
        flex-direction: column;
        align-items: flex-start;
        gap: 6px;
        width: 100%;
    }
    .ppf-subheader__subtitle {
        white-space: normal;
        text-overflow: initial;
    }
    .ppf-subheader__actions {
        display: none;
        position: fixed;
        left: 16px;
        right: 16px;
        top: 18px;
        z-index: 4040;
        background: var(--panel, rgba(9,14,28,0.96));
        border: 1px solid var(--line, rgba(148,163,184,0.3));
        border-radius: 16px;
        padding: 18px;
        flex-direction: column;
        gap: 18px;
        box-shadow: 0 24px 56px rgba(2,6,23,0.55);
        max-height: 80vh;
        overflow-y: auto;
    }
    .ppf-subheader.is-open .ppf-subheader__actions {
        display: flex;
    }
    .ppf-subheader__actions-inner {
        width: 100%;
        flex-direction: column;
        align-items: stretch;
        gap: 12px;
    }
    .ppf-subheader__actions-inner > .btn,
    .ppf-subheader__actions-inner > .btnset,
    .ppf-subheader .btnset > .btn {
        min-width: 0;
    }
    .ppf-subheader__actions-inner > * {
        width: 100%;
    }
    .ppf-subheader .btnset {
        width: 100%;
        flex-direction: column;
        align-items: stretch;
    }
    .ppf-subheader .btnset > * {
        width: 100%;
    }
    .ppf-subheader .btn {
        width: 100%;
    }
    .ppf-subheader__close {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 36px;
        height: 36px;
        border-radius: 999px;
        background: rgba(15,23,42,0.6);
    }
    body.ppf-subheader-open {
        overflow: hidden;
    }
    .ppf-subheader__mask {
        position: fixed;
        inset: 0;
        background: rgba(2,6,23,0.55);
        z-index: 4030;
        opacity: 0;
        pointer-events: none;
        transition: opacity 0.2s ease;
    }
    .ppf-subheader__mask.is-visible {
        opacity: 1;
        pointer-events: auto;
    }
}
</style>
<script>
(function(){
    if (typeof window === 'undefined') return;
    var mask = null;
    function ensureMask(){
        if (mask) return mask;
        mask = document.createElement('div');
        mask.className = 'ppf-subheader__mask';
        mask.setAttribute('aria-hidden', 'true');
        document.body.appendChild(mask);
        return mask;
    }
    function closeSubheader(root){
        root.classList.remove('is-open');
        var summary = root.querySelector('[data-ppf-subheader-summary]');
        if (summary) {
            summary.setAttribute('aria-expanded', 'false');
        }
        var actions = root.querySelector('[data-ppf-subheader-actions]');
        if (actions) {
            actions.setAttribute('aria-hidden', 'true');
        }
        var maskEl = ensureMask();
        maskEl.classList.remove('is-visible');
        document.body.classList.remove('ppf-subheader-open');
    }
    function openSubheader(root){
        root.classList.add('is-open');
        var summary = root.querySelector('[data-ppf-subheader-summary]');
        if (summary) {
            summary.setAttribute('aria-expanded', 'true');
        }
        var actions = root.querySelector('[data-ppf-subheader-actions]');
        if (actions) {
            actions.setAttribute('aria-hidden', 'false');
        }
        var maskEl = ensureMask();
        maskEl.classList.add('is-visible');
        document.body.classList.add('ppf-subheader-open');
        maskEl.onclick = function(){ closeSubheader(root); };
    }
    function init(root){
        var summary = root.querySelector('[data-ppf-subheader-summary]');
        var actions = root.querySelector('[data-ppf-subheader-actions]');
        var closeBtn = root.querySelector('[data-ppf-subheader-close]');
        if (!summary || !actions) return;
        if (window.matchMedia('(max-width: 720px)').matches) {
            actions.setAttribute('aria-hidden', root.classList.contains('is-open') ? 'false' : 'true');
        } else {
            actions.setAttribute('aria-hidden', 'false');
            summary.setAttribute('aria-expanded', 'false');
        }
        summary.addEventListener('click', function(){
            if (!window.matchMedia('(max-width: 720px)').matches) return;
            if (root.classList.contains('is-open')) {
                closeSubheader(root);
            } else {
                openSubheader(root);
            }
        });
        summary.addEventListener('keydown', function(ev){
            if (!window.matchMedia('(max-width: 720px)').matches) return;
            if (ev.key === 'Enter' || ev.key === ' ' || ev.key === 'Spacebar') {
                ev.preventDefault();
                if (root.classList.contains('is-open')) {
                    closeSubheader(root);
                } else {
                    openSubheader(root);
                }
            }
        });
        if (closeBtn) {
            closeBtn.addEventListener('click', function(){ closeSubheader(root); });
        }
    }
    function setup(){
        document.querySelectorAll('[data-ppf-subheader]').forEach(init);
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', setup);
    } else {
        setup();
    }
    window.addEventListener('resize', function(){
        if (!window.matchMedia('(max-width: 720px)').matches) {
            var maskEl = ensureMask();
            maskEl.classList.remove('is-visible');
            document.body.classList.remove('ppf-subheader-open');
            document.querySelectorAll('[data-ppf-subheader]').forEach(function(root){
                var summary = root.querySelector('[data-ppf-subheader-summary]');
                if (summary) {
                    summary.setAttribute('aria-expanded', 'false');
                }
                root.classList.remove('is-open');
                var actions = root.querySelector('[data-ppf-subheader-actions]');
                if (actions) {
                    actions.setAttribute('aria-hidden', 'false');
                }
            });
        } else {
            document.querySelectorAll('[data-ppf-subheader]').forEach(function(root){
                var actions = root.querySelector('[data-ppf-subheader-actions]');
                if (actions) {
                    actions.setAttribute('aria-hidden', root.classList.contains('is-open') ? 'false' : 'true');
                }
                var summary = root.querySelector('[data-ppf-subheader-summary]');
                if (summary) {
                    summary.setAttribute('aria-expanded', root.classList.contains('is-open') ? 'true' : 'false');
                }
            });
        }
    });
})();
</script>
HTML;
        }

        $summaryAttributes = ' data-ppf-subheader-summary aria-expanded="false" role="button" tabindex="0" aria-controls="' . $actionsId . '"';
        $actionsAttributes = ' id="' . $actionsId . '" data-ppf-subheader-actions aria-hidden="true"';

        echo '<div class="ppf-subheader subheader' . $extraClass . '" data-ppf-subheader>';
        echo '<div class="ppf-subheader__summary"' . $summaryAttributes . '>';
        echo '<div class="ppf-subheader__text">';
        if ($titleHtml !== '') {
            echo $titleHtml;
        }
        if ($subtitleHtml !== '') {
            echo $subtitleHtml;
        }
        echo '</div>';
        echo '<div class="ppf-subheader__summary-toggle" aria-hidden="true">';
        echo '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"></polyline></svg>';
        echo '</div>';
        echo '</div>';
        echo '<div class="ppf-subheader__actions"' . $actionsAttributes . '>';
        echo '<button type="button" class="ppf-subheader__close" data-ppf-subheader-close aria-label="Close subheader actions">&times;</button>';
        echo '<div class="ppf-subheader__actions-inner">';
        echo $actionsHtml;
        echo '</div>';
        echo '</div>';
        echo '</div>';
    }
}
