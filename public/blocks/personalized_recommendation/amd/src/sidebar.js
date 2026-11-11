// This file is part of Moodle - http://moodle.org/

/**
 * Fetches and renders the Next Best Activity card. Uses DOM APIs only (textContent),
 * so reasons or names can never inject HTML.
 *
 * @module     block_personalized_recommendation/sidebar
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define(['core/ajax', 'core/str'], function(Ajax, Str) {
    var COMPONENT = 'block_personalized_recommendation';
    var KEYS = ['start', 'review', 'alsoconsider', 'minutes', 'unavailable'];
    var labels = {};

    var el = function(tag, className, text) {
        var node = document.createElement(tag);
        if (className) {
            node.className = className;
        }
        if (text !== undefined && text !== null) {
            node.textContent = text;
        }
        return node;
    };

    var meta = function(item) {
        var parts = [item.typelabel];
        if (item.topic) {
            parts.push(item.topic);
        }
        if (item.minutes !== null && item.minutes !== undefined) {
            parts.push(labels.minutes.replace('{$a}', item.minutes));
        }
        return parts.join(' \u00B7 ');
    };

    var renderHero = function(item) {
        var hero = el('div', 'block-pr-hero');
        hero.appendChild(el('div', 'block-pr-name', item.name));
        hero.appendChild(el('div', 'block-pr-meta small', meta(item)));
        if (item.isreview) {
            hero.appendChild(el('span', 'badge bg-secondary block-pr-badge', labels.review));
        }
        var start = el('a', 'btn btn-primary w-100 block-pr-start', labels.start);
        start.href = item.url;
        var wrap = el('div');
        wrap.appendChild(hero);
        wrap.appendChild(start);
        if (item.reason) {
            wrap.appendChild(el('p', 'block-pr-reason small', item.reason));
        }
        return wrap;
    };

    var renderOthers = function(items) {
        var section = el('div', 'block-pr-others');
        section.appendChild(el('div', 'block-pr-subheading small fw-bold', labels.alsoconsider));
        var list = el('ul', 'list-unstyled mb-0');
        items.forEach(function(item) {
            var li = el('li', 'block-pr-other');
            var a = el('a', null, item.name);
            a.href = item.url;
            li.appendChild(a);
            li.appendChild(el('div', 'small text-muted', meta(item)));
            list.appendChild(li);
        });
        section.appendChild(list);
        return section;
    };

    var render = function(box, data) {
        box.textContent = '';
        box.removeAttribute('aria-busy');
        if (data.status !== 'ok' || !data.items.length) {
            box.appendChild(el('p', 'text-muted small mb-0', data.message || labels.unavailable));
            return;
        }
        box.appendChild(renderHero(data.items[0]));
        if (data.items.length > 1) {
            box.appendChild(renderOthers(data.items.slice(1)));
        }
    };

    return {
        /**
         * @param {Object} config {rootid, courseid}
         */
        init: function(config) {
            var root = document.getElementById(config.rootid);
            if (!root) {
                return;
            }
            var box = root.querySelector('[data-region="content"]');
            Str.get_strings(KEYS.map(function(key) {
                return {key: key, component: COMPONENT};
            })).then(function(strings) {
                KEYS.forEach(function(key, i) {
                    labels[key] = strings[i];
                });
                return Ajax.call([{
                    methodname: 'block_personalized_recommendation_get_next_activity',
                    args: {courseid: config.courseid}
                }])[0];
            }).then(function(data) {
                render(box, data);
                return data;
            }).catch(function() {
                render(box, {status: 'unavailable', items: [], message: labels.unavailable || ''});
            });
        }
    };
});
