// This file is part of Moodle - http://moodle.org/

/**
 * Instructor dashboard: loads the heatmap through Moodle web services and
 * renders it with DOM APIs (textContent only, so no HTML injection is possible).
 *
 * @module     local_instructor_analytics/dashboard
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define(['core/ajax', 'core/str'], function(Ajax, Str) {
    var BANDS = ['high', 'medium', 'low', 'unknown'];
    var TREND_EPSILON = 0.005;
    var labels = {};

    var call = function(methodname, args) {
        return Ajax.call([{methodname: methodname, args: args}])[0];
    };

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

    var pct = function(v) {
        return Math.round(v * 100) + '%';
    };

    var trendGlyph = function(trend) {
        if (trend === null || trend === undefined) {
            return null;
        }
        if (trend > TREND_EPSILON) {
            return {glyph: '\u25B2', label: labels.trend_up, cls: 'up'};
        }
        if (trend < -TREND_EPSILON) {
            return {glyph: '\u25BC', label: labels.trend_down, cls: 'down'};
        }
        return {glyph: '\u25AC', label: labels.trend_flat, cls: 'flat'};
    };

    var buildCell = function(cell) {
        var td = el('td', 'local-ia-cell');
        if (!cell) {
            td.classList.add('local-ia-cell--none');
            td.textContent = '\u2014';
            td.setAttribute('aria-label', labels.nodata);
            return td;
        }
        td.classList.add('local-ia-cell--' + cell.mastery);
        var indicator = el('span', 'local-ia-indicator local-ia-indicator--' + cell.mastery);
        indicator.setAttribute('aria-hidden', 'true');
        td.appendChild(indicator);
        td.appendChild(el('span', 'local-ia-band', labels['band_' + cell.mastery]));
        var t = trendGlyph(cell.scoretrend);
        if (t) {
            var arrow = el('span', 'local-ia-trend local-ia-trend--' + t.cls, ' ' + t.glyph);
            arrow.setAttribute('aria-label', labels.trend + ': ' + t.label);
            td.appendChild(arrow);
        }
        var tip = [];
        if (cell.bestscore !== null && cell.bestscore !== undefined) {
            tip.push(labels.bestscore + ': ' + pct(cell.bestscore));
        }
        if (cell.scoretrend !== null && cell.scoretrend !== undefined) {
            tip.push(labels.trend + ': ' + (cell.scoretrend > 0 ? '+' : '') + Math.round(cell.scoretrend * 100) + ' pts');
        }
        if (tip.length) {
            td.title = tip.join(' | ');
        }
        return td;
    };

    var renderTopicChart = function(root, data) {
        var box = root.querySelector('[data-region="topic-chart"]');
        box.textContent = '';

        var learnerCells = data.learners.map(function(learner) {
            var cells = {};
            learner.cells.forEach(function(cell) {
                cells[cell.topic] = cell;
            });
            return cells;
        });
        var hasData = data.topics.some(function(topic) {
            return learnerCells.some(function(cells) {
                return !!cells[topic];
            });
        });
        if (!hasData) {
            box.appendChild(el('p', 'text-muted small mb-0', labels.topicmasterynodata));
            return;
        }

        var chart = el('div', 'local-ia-topic-chart-grid');
        data.topics.forEach(function(topic) {
            var counts = {high: 0, medium: 0, low: 0, unknown: 0};
            learnerCells.forEach(function(cells) {
                var cell = cells[topic];
                if (cell && BANDS.indexOf(cell.mastery) !== -1) {
                    counts[cell.mastery]++;
                }
            });
            var total = BANDS.reduce(function(sum, band) {
                return sum + counts[band];
            }, 0);
            if (total === 0) {
                return;
            }

            var card = el('div', 'local-ia-topic-chart-card');
            card.appendChild(el('div', 'local-ia-topic-chart-label', topic));
            var pie = el('div', 'local-ia-topic-chart-pie');
            var colors = {
                high: '#198754',
                medium: '#b58105',
                low: '#dc3545',
                unknown: '#6c757d'
            };
            var offset = 0;
            var slices = [];
            BANDS.forEach(function(band) {
                var count = counts[band];
                if (!count) {
                    return;
                }
                var end = offset + count / total * 100;
                slices.push(colors[band] + ' ' + offset + '% ' + end + '%');
                offset = end;
            });
            pie.style.background = 'conic-gradient(' + slices.join(', ') + ')';
            pie.setAttribute('role', 'img');
            var ariaLabel = labels.topicmasteryaria
                .replace('{$a->topic}', topic)
                .replace('{$a->high}', counts.high)
                .replace('{$a->medium}', counts.medium)
                .replace('{$a->low}', counts.low)
                .replace('{$a->unknown}', counts.unknown)
                .replace('{$a->total}', total);
            pie.setAttribute('aria-label', ariaLabel);
            pie.title = ariaLabel;
            card.appendChild(pie);

            var legend = el('ul', 'local-ia-topic-chart-legend list-unstyled mb-0');
            BANDS.forEach(function(band) {
                var count = counts[band];
                var percent = count / total * 100;
                var item = el('li', 'local-ia-topic-chart-legend-item');
                var indicator = el('span', 'local-ia-indicator local-ia-indicator--' + band);
                indicator.setAttribute('aria-hidden', 'true');
                item.appendChild(indicator);
                item.appendChild(document.createTextNode(
                    labels['band_' + band] + ': ' + count + ' (' + Math.round(percent) + '%)'
                ));
                item.title = labels.topicmasterysegment
                    .replace('{$a->band}', labels['band_' + band])
                    .replace('{$a->count}', count)
                    .replace('{$a->percent}', Math.round(percent));
                legend.appendChild(item);
            });
            card.appendChild(legend);
            chart.appendChild(card);
        });
        box.appendChild(chart);
    };

    var renderSummary = function(root, data) {
        var box = root.querySelector('[data-region="summary"]');
        box.textContent = '';
        data.summary.forEach(function(s) {
            if (BANDS.indexOf(s.band) === -1) {
                return;
            }
            var chip = el('span', 'local-ia-chip local-ia-cell--' + s.band);
            var indicator = el('span', 'local-ia-indicator local-ia-indicator--' + s.band);
            indicator.setAttribute('aria-hidden', 'true');
            chip.appendChild(indicator);
            chip.appendChild(document.createTextNode(labels['band_' + s.band] + ': ' + s.count));
            box.appendChild(chip);
        });
    };

    var renderHeatmap = function(root, data) {
        var box = root.querySelector('[data-region="heatmap"]');
        box.textContent = '';
        box.removeAttribute('aria-busy');

        if (data.status !== 'ok') {
            renderTopicChart(root, {topics: [], learners: []});
            var alert = el('div', 'alert alert-warning mb-0', data.message);
            alert.setAttribute('role', 'alert');
            box.appendChild(alert);
            return;
        }
        renderTopicChart(root, data);
        if (!data.learners.length) {
            box.appendChild(el('p', 'text-muted mb-0', labels.nolearners));
            return;
        }

        var wrap = el('div', 'table-responsive');
        var table = el('table', 'table local-ia-table');
        var head = el('tr');
        var corner = el('th', null, labels.learner);
        corner.scope = 'col';
        head.appendChild(corner);
        data.topics.forEach(function(topic) {
            var th = el('th', 'text-center', topic);
            th.scope = 'col';
            head.appendChild(th);
        });
        var thead = el('thead');
        thead.appendChild(head);
        table.appendChild(thead);

        var tbody = el('tbody');
        data.learners.forEach(function(learner) {
            var tr = el('tr');
            var th = el('th', 'local-ia-learner-cell');
            th.scope = 'row';
            var learnerinfo = el('div', 'local-ia-learner');
            if (learner.avatarurl) {
                var avatar = el('img', 'local-ia-avatar');
                avatar.src = learner.avatarurl;
                avatar.alt = '';
                avatar.loading = 'lazy';
                avatar.decoding = 'async';
                learnerinfo.appendChild(avatar);
            }
            var name;
            if (learner.profileurl) {
                name = el('a', 'local-ia-learner-name', learner.name);
                name.href = learner.profileurl;
            } else {
                name = el('span', 'local-ia-learner-name', learner.name);
            }
            learnerinfo.appendChild(name);
            th.appendChild(learnerinfo);
            tr.appendChild(th);
            var bytopic = {};
            learner.cells.forEach(function(c) {
                bytopic[c.topic] = c;
            });
            data.topics.forEach(function(topic) {
                tr.appendChild(buildCell(bytopic[topic]));
            });
            tbody.appendChild(tr);
        });
        table.appendChild(tbody);
        wrap.appendChild(table);
        box.appendChild(wrap);
        renderSummary(root, data);
    };

    var showError = function(root, message) {
        var box = root.querySelector('[data-region="heatmap"]');
        box.textContent = '';
        box.removeAttribute('aria-busy');
        var alert = el('div', 'alert alert-danger mb-0', message);
        alert.setAttribute('role', 'alert');
        box.appendChild(alert);
    };

    var load = function(root, courseid) {
        return call('local_instructor_analytics_get_heatmap', {courseid: courseid})
            .then(function(data) {
                renderHeatmap(root, data);
                return data;
            })
            .catch(function(err) {
                showError(root, labels.loadfailed.replace('{$a}', err && err.message ? err.message : ''));
            });
    };

    var wirePipeline = function(root, courseid) {
        var button = root.querySelector('[data-action="run-pipeline"]');
        if (!button) {
            return;
        }
        var status = root.querySelector('[data-region="status"]');
        button.addEventListener('click', function() {
            button.disabled = true;
            status.textContent = labels.running;
            call('local_instructor_analytics_run_pipeline', {courseid: courseid})
                .then(function(res) {
                    if (res.status !== 'success') {
                        status.textContent = labels.pipelinefailed.replace('{$a}', res.message);
                        return null;
                    }
                    status.textContent = labels.updated;
                    return load(root, courseid);
                })
                .catch(function(err) {
                    status.textContent = labels.pipelinefailed.replace('{$a}', err && err.message ? err.message : '');
                })
                .then(function() {
                    button.disabled = false;
                });
        });
    };

    var KEYS = ['band_high', 'band_medium', 'band_low', 'band_unknown', 'trend_up', 'trend_down', 'trend_flat',
        'trend', 'bestscore', 'learner', 'nolearners', 'nodata', 'running', 'updated', 'pipelinefailed', 'loadfailed',
        'topicmasteryaria', 'topicmasterysegment', 'topicmasterynodata'];

    return {
        /**
         * @param {Object} config {courseid, canrun}
         */
        init: function(config) {
            var root = document.querySelector('[data-region="local-ia"]');
            if (!root) {
                return;
            }
            Str.get_strings(KEYS.map(function(key) {
                return {key: key, component: 'local_instructor_analytics'};
            })).then(function(strings) {
                KEYS.forEach(function(key, i) {
                    labels[key] = strings[i];
                });
                wirePipeline(root, config.courseid);
                return load(root, config.courseid);
            }).catch(function() {
                showError(root, 'Could not load interface strings.');
            });
        }
    };
});
